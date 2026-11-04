<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Responsive;

use StudioAstra\ImageOptimizerBundle\FormatDetector;
use StudioAstra\ImageOptimizerBundle\ImageFormat;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds <picture> markup so that each device downloads the smallest suitable file:
 * the browser picks the format (AVIF > WebP > original) and the width (srcset + sizes,
 * taking screen size and pixel density into account).
 */
final class ResponsiveImageRenderer
{
    public const ROUTE_VARIANT = 'image_optimizer_variant';
    public const ROUTE_AUTO = 'image_optimizer_auto';

    public function __construct(
        private readonly VariantStorage $storage,
        private readonly VariantGenerator $generator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $defaultSizes = '100vw',
        private readonly FormatDetector $detector = new FormatDetector(),
    ) {
    }

    public function variantUrl(string $path, int $width, ImageFormat|string|null $format = null): string
    {
        $normalized = VariantStorage::normalizePath($path) ?? ltrim($path, '/');
        $format = \is_string($format) ? ImageFormat::fromName($format) : $format;
        $format ??= $this->fallbackFormat($this->storage->resolveSource($normalized) ?? '') ?? ImageFormat::Jpeg;

        return $this->urlGenerator->generate(self::ROUTE_VARIANT, [
            'width' => $this->storage->closestWidth($width),
            'path' => $normalized,
            'format' => $format->extension(),
        ]);
    }

    /**
     * URL negotiating format (Accept) and width (Client Hints) on the server side.
     * Handy where <picture> is not possible (CSS backgrounds, Open Graph, e-mails...).
     */
    public function autoUrl(string $path): string
    {
        return $this->urlGenerator->generate(self::ROUTE_AUTO, ['path' => VariantStorage::normalizePath($path) ?? ltrim($path, '/')]);
    }

    /**
     * @param array<string, scalar|null> $attributes HTML attributes for the <img> tag; "sizes" and "widths" are special
     */
    public function render(string $path, array $attributes = []): string
    {
        $sizes = (string) ($attributes['sizes'] ?? $this->defaultSizes);
        $maxWidth = isset($attributes['max_width']) ? (int) $attributes['max_width'] : null;
        unset($attributes['sizes'], $attributes['max_width']);

        $attributes += ['alt' => '', 'loading' => 'lazy', 'decoding' => 'async'];
        $source = $this->storage->resolveSource($path);
        $fallback = null !== $source ? $this->fallbackFormat($source) : null;
        $dimensions = null !== $source ? $this->generator->dimensions($source) : null;

        // Vector, animated, unsupported or missing images: plain <img>.
        if (null === $source || null === $fallback || null === $dimensions || !$this->generator->supportsSource($source)) {
            $attributes = ['src' => '/' . ltrim((string) (VariantStorage::normalizePath($path) ?? $path), '/')] + $attributes;
            if (null !== $dimensions) {
                $attributes += ['width' => $dimensions[0], 'height' => $dimensions[1]];
            }

            return '<img' . self::attributes($attributes) . '>';
        }

        $candidates = $this->candidateWidths($dimensions[0], $maxWidth);
        $html = '<picture>';

        $available = $this->generator->availableFormats();
        foreach ($this->storage->preferredFormats() as $format) {
            if ($format === $fallback || !\in_array($format, $available, true)) {
                continue;
            }
            $html .= '<source' . self::attributes([
                'type' => $format->mimeType(),
                'srcset' => $this->srcset($path, $candidates, $format),
                'sizes' => $sizes,
            ]) . '>';
        }

        $largest = $candidates[array_key_last($candidates)];
        $html .= '<img' . self::attributes([
            'src' => $this->url($path, $largest[0], $fallback),
            'srcset' => $this->srcset($path, $candidates, $fallback),
            'sizes' => $sizes,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ] + $attributes) . '>';

        return $html . '</picture>';
    }

    /**
     * @return list<array{0: int, 1: int}> [allowed width used in the URL, real width of the file]
     */
    private function candidateWidths(int $sourceWidth, ?int $maxWidth): array
    {
        $limit = null !== $maxWidth ? min($sourceWidth, $maxWidth) : $sourceWidth;
        $candidates = [];

        foreach ($this->storage->widths() as $width) {
            if ($width < $limit) {
                $candidates[] = [$width, $width];
                continue;
            }
            // First allowed width >= limit: generated at the source width at most (no upscaling).
            $candidates[] = [$width, min($width, $sourceWidth)];
            break;
        }

        return $candidates;
    }

    /**
     * @param list<array{0: int, 1: int}> $candidates
     */
    private function srcset(string $path, array $candidates, ImageFormat $format): string
    {
        return implode(', ', array_map(fn (array $c): string => $this->url($path, $c[0], $format) . ' ' . $c[1] . 'w', $candidates));
    }

    private function url(string $path, int $width, ImageFormat $format): string
    {
        return $this->urlGenerator->generate(self::ROUTE_VARIANT, [
            'width' => $width,
            'path' => VariantStorage::normalizePath($path),
            'format' => $format->extension(),
        ]);
    }

    /**
     * Format of the <img> fallback, universally supported.
     */
    public function fallbackFormat(string $source): ?ImageFormat
    {
        return match ($this->detector->detect($source)) {
            ImageFormat::Jpeg => ImageFormat::Jpeg,
            ImageFormat::Png, ImageFormat::Bmp, ImageFormat::Gif => ImageFormat::Png,
            ImageFormat::Webp, ImageFormat::Avif => ImageFormat::Webp,
            default => null,
        };
    }

    /**
     * @param array<string, scalar|null> $attributes
     */
    private static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if (null === $value || false === $value || !preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $name)) {
                continue;
            }
            $html .= true === $value
                ? ' ' . $name
                : ' ' . $name . '="' . htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8') . '"';
        }

        return $html;
    }
}
