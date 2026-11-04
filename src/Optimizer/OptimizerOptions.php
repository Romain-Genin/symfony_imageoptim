<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Optimizer;

use StudioAstra\ImageOptimizerBundle\ImageFormat;

final class OptimizerOptions
{
    /**
     * @param array<string, string|null|false> $conversions source format => lossless target format ("webp", "png") or null/false
     */
    public function __construct(
        public readonly bool $stripMetadata = true,
        public readonly bool $autoOrient = true,
        public readonly array $conversions = ['png' => 'webp', 'gif' => 'webp', 'bmp' => 'webp', 'tiff' => 'webp'],
        public readonly float $minConversionGain = 0.05,
        public readonly int $maxPixels = 50_000_000,
    ) {
    }

    public function conversionTarget(ImageFormat $format): ?ImageFormat
    {
        $target = $this->conversions[$format->value] ?? null;

        return \is_string($target) && '' !== $target ? ImageFormat::fromName($target) : null;
    }
}
