<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Optimizer\Format;

use StudioAstra\ImageOptimizerBundle\Binary\BinaryRunner;
use StudioAstra\ImageOptimizerBundle\ImageFormat;
use StudioAstra\ImageOptimizerBundle\Metadata\AccessibilityMetadata;
use StudioAstra\ImageOptimizerBundle\Metadata\JpegFile;
use StudioAstra\ImageOptimizerBundle\Optimizer\OptimizerOptions;
use StudioAstra\ImageOptimizerBundle\Optimizer\Workspace;

/**
 * Lossless JPEG optimization.
 *
 *  - jpegtran (libjpeg-turbo / mozjpeg) when available: optimal Huffman tables, progressive
 *    encoding and lossless physical rotation according to the EXIF orientation. The DCT
 *    coefficients are kept as-is, so there is zero generation loss.
 *  - pure PHP metadata removal (EXIF/GPS, XMP, IPTC, thumbnails, comments), keeping the ICC
 *    profile and the orientation.
 *
 * The JPEG is never decoded/re-encoded.
 */
final class JpegOptimizer implements FormatOptimizerInterface
{
    private const TRANSFORMS = [
        2 => ['-flip', 'horizontal'],
        3 => ['-rotate', '180'],
        4 => ['-flip', 'vertical'],
        5 => ['-transpose'],
        6 => ['-rotate', '90'],
        7 => ['-transverse'],
        8 => ['-rotate', '270'],
    ];

    public function __construct(private readonly BinaryRunner $binaries)
    {
    }

    public function supports(ImageFormat $format): bool
    {
        return ImageFormat::Jpeg === $format;
    }

    public function optimize(string $source, string $target, OptimizerOptions $options): ?string
    {
        $jpeg = JpegFile::parse((string) file_get_contents($source));
        if (null === $jpeg || $jpeg->hasMultiPicture()) {
            // Gain maps / multi-picture files (HDR photos) are left untouched to preserve them.
            return null;
        }

        $xmp = $options->keepAccessibilityMetadata ? AccessibilityMetadata::fromXmp($jpeg->xmp())?->toXmp() : null;
        $steps = [];
        $bytes = null;
        $rotated = false;
        $workspace = new Workspace();

        if ($this->binaries->has('jpegtran')) {
            $orientation = $jpeg->orientation();
            $transform = $options->autoOrient && $options->stripMetadata ? (self::TRANSFORMS[$orientation] ?? []) : [];

            if ([] !== $transform) {
                $bytes = $this->jpegtran($source, [...$transform, '-perfect'], $workspace);
                $rotated = null !== $bytes;
            }
            $bytes ??= $this->jpegtran($source, [], $workspace);

            if (null !== $bytes) {
                $steps[] = $rotated ? 'jpegtran (huffman, progressive, lossless auto-orient)' : 'jpegtran (huffman, progressive)';
                $jpeg = JpegFile::parse($bytes) ?? $jpeg;
            }
        }

        if ($options->stripMetadata) {
            $jpeg = $jpeg->stripMetadata(keepOrientation: !$rotated, xmp: $xmp);
            $steps[] = 'metadata stripped';
        }

        if ([] === $steps) {
            return null;
        }

        file_put_contents($target, $jpeg->toBytes());

        return implode(', ', $steps);
    }

    /**
     * Tries progressive and baseline encodings, returns the smallest.
     *
     * @param list<string> $transform
     */
    private function jpegtran(string $source, array $transform, Workspace $workspace): ?string
    {
        $best = null;

        foreach ([['-progressive'], []] as $mode) {
            $out = $workspace->path('jpg');
            if (!$this->binaries->run('jpegtran', ['-copy', 'all', '-optimize', ...$mode, ...$transform, '-outfile', $out, $source])) {
                continue;
            }
            $bytes = (string) @file_get_contents($out);
            if ('' !== $bytes && (null === $best || \strlen($bytes) < \strlen($best))) {
                $best = $bytes;
            }
        }

        return $best;
    }
}
