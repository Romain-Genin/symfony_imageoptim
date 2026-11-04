<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Tests\Functional;

use RomainGenin\ImageOptimizerBundle\ImageOptimizerBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $workDir)
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TwigBundle(), new ImageOptimizerBundle()];
    }

    public function getProjectDir(): string
    {
        return $this->workDir;
    }

    public function getCacheDir(): string
    {
        return $this->workDir . '/var/cache';
    }

    public function getLogDir(): string
    {
        return $this->workDir . '/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['test' => true, 'secret' => 'test', 'http_method_override' => false, 'router' => ['utf8' => true]]);
        $container->extension('twig', ['default_path' => '%kernel.project_dir%/templates']);
        $container->extension('image_optimizer', []);
        $container->services()->set('logger', \Psr\Log\NullLogger::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('.', 'image_optimizer');
        $routes->add('upload', '/upload')->controller('kernel::upload')->methods(['POST']);
        $routes->add('page', '/page')->controller('kernel::page');
    }

    /**
     * Mimics an application controller: reports what it receives.
     */
    public function upload(Request $request): JsonResponse
    {
        $describe = static fn (UploadedFile $f): array => [
            'name' => $f->getClientOriginalName(),
            'mime' => $f->getClientMimeType(),
            'guessed' => $f->guessExtension(),
            'size' => $f->getSize(),
            'valid' => $f->isValid(),
        ];

        $out = [];
        $all = $request->files->all();
        array_walk_recursive($all, static function ($file, $key) use (&$out, $describe): void {
            $out[$key] = $describe($file);
        });

        return new JsonResponse($out);
    }

    public function page(Request $request): Response
    {
        /** @var \Twig\Environment $twig */
        $twig = $this->getContainer()->get('test.service_container')->get('twig');

        return new Response($twig->createTemplate((string) $request->query->get('tpl'))->render());
    }
}
