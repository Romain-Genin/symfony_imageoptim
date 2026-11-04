<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Optimizer\Format;

use StudioAstra\ImageOptimizerBundle\Binary\BinaryRunner;
use StudioAstra\ImageOptimizerBundle\ImageFormat;
use StudioAstra\ImageOptimizerBundle\Metadata\AccessibilityMetadata;
use StudioAstra\ImageOptimizerBundle\Metadata\PngFile;
use StudioAstra\ImageOptimizerBundle\Optimizer\OptimizerOptions;
use StudioAstra\ImageOptimizerBundle\Optimizer\Workspace;

/**
 * Lossless PNG optimization: metadata removal + zlib level 9 (pure PHP), then oxipng or
 * optipng when installed (filter/compression search, lossless bit depth / palette reduction).
 * pngquant is intentionally NOT used: it is lossy.
 */
final class PngOptimizer implements FormatOptimizerInterface
{
    public function __construct(private readonly BinaryRunner $binaries)
    {
    }

    public function supports(ImageFormat $format): bool
    {
        return ImageFormat::Png === $format;
    }

    public function optimize(string $source, string $target, OptimizerOptions $options): ?string
    {
        $png = PngFile::parse((string) file_get_contents($source));
        if (null === $png) {
            return null;
        }

        $steps = [];
        if ($options->stripMetadata) {
            $xmp = $options->keepAccessibilityMetadata ? AccessibilityMetadata::fromXmp($png->xmp())?->toXmp() : null;
            $png = $png->stripMetadata(xmp: $xmp);
            $steps[] = 'metadata stripped';
        }
        $png = $png->recompress();
        $steps[] = 'zlib -9';

        $bytes = $png->toBytes();

        // APNG chunks are not understood by every tool: keep the pure PHP result for animations.
        if (!$png->isAnimated()) {
            $workspace = new Workspace();
            $in = $workspace->path('png');
            file_put_contents($in, $bytes);

            $out = $workspace->path('png');
            $tool = match (true) {
                $this->binaries->has('oxipng') && $this->binaries->run('oxipng', ['-o', '4', '-q', '--out', $out, $in]) => 'oxipng',
                $this->binaries->has('optipng') && $this->binaries->run('optipng', ['-o2', '-quiet', '-out', $out, $in]) => 'optipng',
                default => null,
            };

            if (null !== $tool) {
                $candidate = (string) @file_get_contents($out);
                if ('' !== $candidate && \strlen($candidate) < \strlen($bytes) && null !== PngFile::parse($candidate)) {
                    $bytes = $candidate;
                    $steps[] = $tool;
                }
            }
        }

        file_put_contents($target, $bytes);

        return implode(', ', $steps);
    }
}
