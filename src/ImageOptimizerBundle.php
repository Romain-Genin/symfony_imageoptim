<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle;

use RomainGenin\ImageOptimizerBundle\Binary\BinaryRunner;
use RomainGenin\ImageOptimizerBundle\Command\OptimizeImagesCommand;
use RomainGenin\ImageOptimizerBundle\Controller\ImageVariantController;
use RomainGenin\ImageOptimizerBundle\EventListener\ClientHintsListener;
use RomainGenin\ImageOptimizerBundle\EventListener\OptimizeUploadedImagesListener;
use RomainGenin\ImageOptimizerBundle\Metadata\AccessibilityMetadataReader;
use RomainGenin\ImageOptimizerBundle\Optimizer\Format\GifOptimizer;
use RomainGenin\ImageOptimizerBundle\Optimizer\Format\JpegOptimizer;
use RomainGenin\ImageOptimizerBundle\Optimizer\Format\PngOptimizer;
use RomainGenin\ImageOptimizerBundle\Optimizer\Format\SvgOptimizer;
use RomainGenin\ImageOptimizerBundle\Optimizer\ImageOptimizer;
use RomainGenin\ImageOptimizerBundle\Optimizer\ImageOptimizerInterface;
use RomainGenin\ImageOptimizerBundle\Optimizer\LosslessConverter;
use RomainGenin\ImageOptimizerBundle\Optimizer\OptimizerOptions;
use RomainGenin\ImageOptimizerBundle\Responsive\ResponsiveImageRenderer;
use RomainGenin\ImageOptimizerBundle\Responsive\VariantGenerator;
use RomainGenin\ImageOptimizerBundle\Responsive\VariantStorage;
use RomainGenin\ImageOptimizerBundle\Routing\ImageRouteLoader;
use RomainGenin\ImageOptimizerBundle\Twig\ImageOptimizerExtension;
use RomainGenin\ImageOptimizerBundle\Upload\UploadedFileOptimizer;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\HttpKernel\KernelEvents;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

final class ImageOptimizerBundle extends AbstractBundle
{
    private const BINARIES = ['jpegtran', 'oxipng', 'optipng', 'gifsicle', 'gif2webp', 'cwebp', 'avifenc', 'svgo', 'webpmux'];

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode()->children();

        $root->arrayNode('upload')
            ->addDefaultsIfNotSet()
            ->info('Automatic optimization of every uploaded image, whatever the way it is uploaded.')
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->booleanNode('convert')->defaultTrue()->info('Allow lossless format changes (PNG -> WebP...). The UploadedFile gets the new extension / MIME type.')->end()
                ->integerNode('max_file_size')->defaultValue(50 * 1024 * 1024)->info('Bytes; bigger files are left untouched (0 = no limit).')->end()
                ->arrayNode('paths')->scalarPrototype()->end()->info('Regexes of request paths to handle (empty = all).')->end()
                ->arrayNode('exclude_paths')->scalarPrototype()->end()->info('Regexes of request paths to ignore.')->end()
                ->integerNode('listener_priority')->defaultValue(7)->info('kernel.request priority: after the firewall (8), before API Platform (4).')->end()
            ->end();

        $root->arrayNode('optimization')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('strip_metadata')->defaultTrue()->info('Remove EXIF/GPS/XMP/IPTC/comments (ICC profile and orientation are always kept).')->end()
                ->booleanNode('auto_orient')->defaultTrue()->info('Losslessly rotate JPEGs according to EXIF orientation (jpegtran -perfect).')->end()
                ->floatNode('min_conversion_gain')->defaultValue(0.05)->min(0)->max(1)->info('A format conversion is kept only if it saves at least this ratio.')->end()
                ->integerNode('max_pixels')->defaultValue(50_000_000)->info('Images above this pixel count are skipped (memory guard).')->end()
                ->booleanNode('keep_accessibility_metadata')->defaultTrue()->info('Keep the IPTC "Alt Text (Accessibility)" / "Extended Description" fields when stripping metadata.')->end()
                ->arrayNode('conversions')
                    ->addDefaultsIfNotSet()
                    ->info('Lossless format conversions (false = keep the format). JPEG is never converted: it would lose quality.')
                    ->children()
                        ->enumNode('png')->values(['webp', false])->defaultValue('webp')->end()
                        ->enumNode('gif')->values(['webp', 'png', false])->defaultValue('webp')->end()
                        ->enumNode('bmp')->values(['webp', 'png', false])->defaultValue('webp')->end()
                        ->enumNode('tiff')->values(['webp', false])->defaultValue('webp')->end()
                    ->end()
                ->end()
            ->end();

        $binaries = $root->arrayNode('binaries')
            ->addDefaultsIfNotSet()
            ->info('Optional CLI tools: null = auto-detect in PATH, false = disabled, or an absolute path.')
            ->children()
                ->floatNode('timeout')->defaultValue(60)->end();
        foreach (self::BINARIES as $binary) {
            $binaries->variableNode($binary)->defaultNull()->end();
        }
        $binaries->end();

        $root->arrayNode('responsive')
            ->addDefaultsIfNotSet()
            ->info('Device-adapted delivery: <picture> with AVIF/WebP + srcset, generated on demand.')
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('route_prefix')->defaultValue('/_image')->end()
                ->scalarNode('public_dir')->defaultValue('%kernel.project_dir%/public')->info('Web root: variants are stored in <public_dir><route_prefix>.')->end()
                ->scalarNode('source_dir')->defaultValue('%kernel.project_dir%/public')->info('Paths given to responsive_image() are relative to this directory.')->end()
                ->arrayNode('widths')->integerPrototype()->end()->defaultValue([320, 480, 640, 768, 960, 1280, 1600, 1920, 2560])->end()
                ->integerNode('default_width')->defaultValue(1280)->info('Width used by the "auto" URL when the browser sends no Client Hints.')->end()
                ->arrayNode('formats')->scalarPrototype()->end()->defaultValue(['avif', 'webp'])->info('Modern formats offered to browsers, best first.')->end()
                ->arrayNode('quality')
                    ->addDefaultsIfNotSet()
                    ->info('Encoding quality of resized variants of photos (lossless sources always give lossless variants).')
                    ->children()
                        ->integerNode('webp')->defaultValue(90)->min(1)->max(100)->end()
                        ->integerNode('avif')->defaultValue(80)->min(1)->max(100)->end()
                        ->integerNode('jpeg')->defaultValue(90)->min(1)->max(100)->end()
                    ->end()
                ->end()
                ->scalarNode('default_sizes')->defaultValue('100vw')->end()
                ->integerNode('cache_max_age')->defaultValue(2_592_000)->end()
                ->scalarNode('require_alt')->defaultValue('%kernel.debug%')->info('Throw when responsive_image() gets no alt text and no {decorative: true} (otherwise a warning is logged).')->end()
                ->booleanNode('reduced_motion')->defaultTrue()->info('Offer a static first frame of animated images to users who prefer reduced motion.')->end()
                ->booleanNode('client_hints')->defaultTrue()->info('Send Accept-CH on HTML responses so browsers report DPR / viewport width.')->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()->defaults()->private();

        $binaries = array_intersect_key($config['binaries'], array_flip(self::BINARIES));
        $services->set(BinaryRunner::class)
            ->args([$binaries, (float) $config['binaries']['timeout'], service('logger')->nullOnInvalid()])
            ->tag('monolog.logger', ['channel' => 'image_optimizer']);

        $services->set(FormatDetector::class);

        foreach ([JpegOptimizer::class, PngOptimizer::class, GifOptimizer::class, SvgOptimizer::class] as $class) {
            $services->set($class)->args([service(BinaryRunner::class)])->tag('image_optimizer.format_optimizer');
        }

        $services->set(LosslessConverter::class)->args([service(BinaryRunner::class)]);

        $optimization = $config['optimization'];
        $services->set(OptimizerOptions::class)->args([
            $optimization['strip_metadata'],
            $optimization['auto_orient'],
            $optimization['conversions'],
            $optimization['min_conversion_gain'],
            $optimization['max_pixels'],
            $optimization['keep_accessibility_metadata'],
        ]);

        $services->set(AccessibilityMetadataReader::class)->args([service(FormatDetector::class)])->public();

        $services->set(ImageOptimizer::class)
            ->args([
                tagged_iterator('image_optimizer.format_optimizer'),
                service(LosslessConverter::class),
                service(OptimizerOptions::class),
                service(FormatDetector::class),
                service('logger')->nullOnInvalid(),
                service(AccessibilityMetadataReader::class),
            ])
            ->tag('monolog.logger', ['channel' => 'image_optimizer']);
        $services->alias(ImageOptimizerInterface::class, ImageOptimizer::class)->public();
        $services->alias('image_optimizer', ImageOptimizer::class)->public();

        $services->set(UploadedFileOptimizer::class)
            ->args([service(ImageOptimizerInterface::class), $config['upload']['convert'], $config['upload']['max_file_size']])
            ->public();

        if ($config['upload']['enabled']) {
            $services->set(OptimizeUploadedImagesListener::class)
                ->args([service(UploadedFileOptimizer::class), $config['upload']['paths'], $config['upload']['exclude_paths']])
                ->tag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'priority' => $config['upload']['listener_priority']]);
        }

        $services->set(OptimizeImagesCommand::class)
            ->args([service(ImageOptimizerInterface::class)])
            ->tag('console.command');

        if (!$config['responsive']['enabled']) {
            return;
        }

        $responsive = $config['responsive'];
        foreach ($responsive['formats'] as $format) {
            if (!\in_array(ImageFormat::fromName($format), [ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg, ImageFormat::Png], true)) {
                throw new \InvalidArgumentException(sprintf('image_optimizer.responsive.formats: unsupported format "%s".', $format));
            }
        }

        $services->set(VariantStorage::class)->args([
            $responsive['source_dir'],
            $responsive['public_dir'],
            $responsive['route_prefix'],
            $responsive['widths'],
            $responsive['formats'],
            $responsive['default_width'],
        ]);

        $services->set(VariantGenerator::class)->args([
            service(BinaryRunner::class),
            service(ImageOptimizerInterface::class),
            $responsive['quality'],
            $optimization['max_pixels'],
            service(FormatDetector::class),
        ]);

        $services->set(ResponsiveImageRenderer::class)
            ->args([
                service(VariantStorage::class),
                service(VariantGenerator::class),
                service('router'),
                $responsive['default_sizes'],
                service(FormatDetector::class),
                service(AccessibilityMetadataReader::class),
                service('request_stack')->nullOnInvalid(),
                $responsive['require_alt'],
                $responsive['reduced_motion'],
                service('logger')->nullOnInvalid(),
            ])
            ->public()
            ->tag('monolog.logger', ['channel' => 'image_optimizer']);

        $services->set(ImageVariantController::class)
            ->args([
                service(VariantStorage::class),
                service(VariantGenerator::class),
                service(ResponsiveImageRenderer::class),
                $responsive['cache_max_age'],
                service('logger')->nullOnInvalid(),
            ])
            ->public()
            ->tag('controller.service_arguments')
            ->tag('monolog.logger', ['channel' => 'image_optimizer']);

        $services->set(ImageRouteLoader::class)
            ->args([$responsive['route_prefix']])
            ->tag('routing.loader');

        if ($responsive['client_hints']) {
            $services->set(ClientHintsListener::class)
                ->tag('kernel.event_listener', ['event' => KernelEvents::RESPONSE, 'priority' => -10]);
        }

        if (interface_exists(\Twig\Extension\ExtensionInterface::class)) {
            $services->set(ImageOptimizerExtension::class)
                ->args([service(ResponsiveImageRenderer::class)])
                ->tag('twig.extension');
        }
    }
}
