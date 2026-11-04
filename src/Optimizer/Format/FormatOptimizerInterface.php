<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Optimizer\Format;

use StudioAstra\ImageOptimizerBundle\ImageFormat;
use StudioAstra\ImageOptimizerBundle\Optimizer\OptimizerOptions;

/**
 * Lossless optimizer for a given format: the decoded pixels of $target must be identical to $source.
 */
interface FormatOptimizerInterface
{
    public function supports(ImageFormat $format): bool;

    /**
     * Writes an optimized copy of $source into $target.
     *
     * @return string|null a short description of what was done, or null when nothing could be done
     */
    public function optimize(string $source, string $target, OptimizerOptions $options): ?string;
}
