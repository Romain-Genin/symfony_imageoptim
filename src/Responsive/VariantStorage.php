<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Responsive;

use StudioAstra\ImageOptimizerBundle\ImageFormat;

/**
 * Resolves original images and their cached variants on disk.
 *
 * Variants are written to "<public_dir><route_prefix>/<width>/<path>.<ext>", i.e. exactly
 * where the variant URL points to: once generated, the web server serves them as static
 * files and PHP is not involved anymore.
 */
final class VariantStorage
{
    /**
     * @param list<int>    $widths  allowed widths (anything else is rejected)
     * @param list<string> $formats preferred modern formats, best first (e.g. ["avif", "webp"])
     */
    public function __construct(
        private readonly string $sourceDir,
        private readonly string $publicDir,
        private readonly string $routePrefix,
        private readonly array $widths,
        private readonly array $formats,
        private readonly int $defaultWidth = 1280,
    ) {
    }

    /**
     * @return list<int>
     */
    public function widths(): array
    {
        $widths = array_values(array_unique(array_map('intval', $this->widths)));
        sort($widths);

        return $widths;
    }

    /**
     * @return list<ImageFormat>
     */
    public function preferredFormats(): array
    {
        return array_map(ImageFormat::fromName(...), $this->formats);
    }

    public function defaultWidth(): int
    {
        return $this->defaultWidth;
    }

    public function isAllowedWidth(int $width): bool
    {
        return \in_array($width, $this->widths(), true);
    }

    /**
     * Smallest allowed width >= $wanted (or the largest one).
     */
    public function closestWidth(int $wanted): int
    {
        $widths = $this->widths();
        foreach ($widths as $width) {
            if ($width >= $wanted) {
                return $width;
            }
        }

        return $widths[array_key_last($widths)] ?? $wanted;
    }

    public static function normalizePath(string $path): ?string
    {
        $path = ltrim(rawurldecode(parse_url($path, \PHP_URL_PATH) ?: $path), '/');

        if ('' === $path || str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return null;
            }
        }

        return $path;
    }

    /**
     * Absolute path of an original image, or null when it does not exist / is outside the source directory.
     */
    public function resolveSource(string $path): ?string
    {
        $path = self::normalizePath($path);
        $base = realpath($this->sourceDir);
        if (null === $path || false === $base) {
            return null;
        }

        $real = realpath($base . '/' . $path);
        if (false === $real || !is_file($real) || !str_starts_with($real, $base . \DIRECTORY_SEPARATOR)) {
            return null;
        }

        // Never create variants of variants.
        $cache = realpath($this->cacheDir());
        if (false !== $cache && str_starts_with($real, $cache . \DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    public function cacheDir(): string
    {
        return rtrim($this->publicDir, '/') . '/' . trim($this->routePrefix, '/');
    }

    public function variantPath(string $path, int $width, string $extension): string
    {
        return $this->cacheDir() . '/' . $width . '/' . self::normalizePath($path) . '.' . $extension;
    }
}
