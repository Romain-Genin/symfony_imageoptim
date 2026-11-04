<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Optimizer\Format;

use StudioAstra\ImageOptimizerBundle\Binary\BinaryRunner;
use StudioAstra\ImageOptimizerBundle\ImageFormat;
use StudioAstra\ImageOptimizerBundle\Optimizer\OptimizerOptions;

/**
 * Lossless GIF optimization with gifsicle (-O3, without its lossy mode). Animations are kept.
 */
final class GifOptimizer implements FormatOptimizerInterface
{
    public function __construct(private readonly BinaryRunner $binaries)
    {
    }

    public function supports(ImageFormat $format): bool
    {
        return ImageFormat::Gif === $format;
    }

    public function optimize(string $source, string $target, OptimizerOptions $options): ?string
    {
        $arguments = ['-O3', '--no-warnings'];
        if ($options->stripMetadata) {
            $arguments[] = '--no-comments';
            $arguments[] = '--no-names';
        }

        return $this->binaries->run('gifsicle', [...$arguments, '-o', $target, $source]) ? 'gifsicle -O3' : null;
    }
}
