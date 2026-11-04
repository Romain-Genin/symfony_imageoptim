<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Optimizer\Format;

use StudioAstra\ImageOptimizerBundle\Binary\BinaryRunner;
use StudioAstra\ImageOptimizerBundle\ImageFormat;
use StudioAstra\ImageOptimizerBundle\Optimizer\OptimizerOptions;

/**
 * SVG minification with svgo (default preset only renders-identical transforms).
 */
final class SvgOptimizer implements FormatOptimizerInterface
{
    public function __construct(private readonly BinaryRunner $binaries)
    {
    }

    public function supports(ImageFormat $format): bool
    {
        return ImageFormat::Svg === $format;
    }

    public function optimize(string $source, string $target, OptimizerOptions $options): ?string
    {
        return $this->binaries->run('svgo', ['--multipass', '--quiet', '-i', $source, '-o', $target]) ? 'svgo' : null;
    }
}
