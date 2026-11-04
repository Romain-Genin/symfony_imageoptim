<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Optimizer;

interface ImageOptimizerInterface
{
    /**
     * Losslessly optimizes the image located at $path, in place.
     *
     * When $allowConversion is true the file content may be converted to another format
     * (e.g. PNG -> lossless WebP). The file name is NOT changed: check
     * OptimizationResult::isConverted() / $result->format to rename it.
     *
     * Never throws: on any failure the original file is left untouched.
     */
    public function optimize(string $path, bool $allowConversion = true): OptimizationResult;
}
