<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Optimizer;

use RomainGenin\ImageOptimizerBundle\ImageFormat;

final class OptimizationResult
{
    /**
     * @param list<string> $steps human readable list of applied steps
     */
    public function __construct(
        public readonly string $path,
        public readonly ?ImageFormat $originalFormat,
        public readonly ?ImageFormat $format,
        public readonly int $originalSize,
        public readonly int $size,
        public readonly array $steps = [],
        public readonly ?string $skippedReason = null,
    ) {
    }

    public static function skipped(string $path, ?ImageFormat $format, int $size, string $reason): self
    {
        return new self($path, $format, $format, $size, $size, [], $reason);
    }

    public function isOptimized(): bool
    {
        return $this->size < $this->originalSize;
    }

    public function isConverted(): bool
    {
        return null !== $this->format && $this->format !== $this->originalFormat;
    }

    public function savedBytes(): int
    {
        return $this->originalSize - $this->size;
    }

    public function savedRatio(): float
    {
        return $this->originalSize > 0 ? $this->savedBytes() / $this->originalSize : 0.0;
    }
}
