<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Responsive;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RomainGenin\ImageOptimizerBundle\Exception\MissingAltTextException;
use RomainGenin\ImageOptimizerBundle\FormatDetector;
use RomainGenin\ImageOptimizerBundle\ImageFormat;
use RomainGenin\ImageOptimizerBundle\Metadata\AccessibilityMetadata;
use RomainGenin\ImageOptimizerBundle\Metadata\AccessibilityMetadataReader;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds <picture> markup so that each device downloads the smallest suitable file:
 * the browser picks the format (AVIF > WebP > original) and the width (srcset + sizes,
 * taking screen size and pixel density into account).
 *
 * Accessibility:
 *  - every image needs a text alternative: explicit "alt", the IPTC alt text embedded in
 *    the file, or an explicit {decorative: true} (rendered as alt="");
 *  - animated images get a static first frame for users who enabled "reduce motion".
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
        private readonly AccessibilityMetadataReader $accessibilityReader = new AccessibilityMetadataReader(),
        private readonly ?RequestStack $requestStack = null,
        private readonly bool $requireAlt = true,
        private readonly bool $reducedMotion = true,
        private readonly LoggerInterface $logger = new NullLogger(),
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
     * Text alternatives embedded in the image file (IPTC accessibility fields, SVG <title>/<desc>).
     */
    public function accessibility(string $path): ?AccessibilityMetadata
    {
        $source = $this->storage->resolveSource($path);

        return null !== $source ? $this->accessibilityReader->read($source) : null;
    }

    /**
     * @param array<string, scalar|null> $attributes HTML attributes for the <img> tag; "sizes", "max_width"
     *                                               and "decorative" are special
     */
    public function render(string $path, array $attributes = []): string
    {
        $sizes = (string) ($attributes['sizes'] ?? $this->defaultSizes);
        $maxWidth = isset($attributes['max_width']) ? (int) $attributes['max_width'] : null;
        $decorative = (bool) ($attributes['decorative'] ?? false);
        unset($attributes['sizes'], $attributes['max_width'], $attributes['decorative']);

        $source = $this->storage->resolveSource($path);
        $attributes = ['alt' => $this->altText($path, $source, $attributes['alt'] ?? null, $decorative)]
            + $attributes
            + ['loading' => 'lazy', 'decoding' => 'async'];

        $fallback = null !== $source ? $this->fallbackFormat($source) : null;
        $dimensions = null !== $source ? $this->generator->dimensions($source) : null;
        $publicUrl = '/' . ltrim((string) (VariantStorage::normalizePath($path) ?? $path), '/');

        if (null !== $source && null !== $dimensions && $this->generator->isAnimated($source)) {
            return $this->renderAnimated($path, $source, $publicUrl, $dimensions, $sizes, $maxWidth, $attributes);
        }

        // Vector, unsupported or missing images: plain <img>.
        if (null === $source || null === $fallback || null === $dimensions || !$this->generator->supportsSource($source)) {
            $attributes = ['src' => $publicUrl] + $attributes;
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
     * Animated GIF/WebP/APNG: the original animation, plus a static first frame chosen by the
     * browser when the user asked the system to reduce motion (WCAG 2.2.2 / 2.3.3).
     *
     * @param array{0: int, 1: int}      $dimensions
     * @param array<string, scalar|null> $attributes
     */
    private function renderAnimated(string $path, string $source, string $publicUrl, array $dimensions, string $sizes, ?int $maxWidth, array $attributes): string
    {
        $img = '<img' . self::attributes(['src' => $publicUrl, 'width' => $dimensions[0], 'height' => $dimensions[1]] + $attributes) . '>';

        $available = $this->generator->availableFormats();
        $static = \in_array(ImageFormat::Webp, $available, true) ? ImageFormat::Webp : ImageFormat::Png;

        if (!$this->reducedMotion || !\in_array($static, $available, true) || !$this->generator->supportsSource($source, allowAnimated: true)) {
            if ($this->reducedMotion) {
                $this->logger->warning('Animated image "{path}" has no static alternative for reduced motion (install webpmux for animated WebP).', ['path' => $path]);
            }

            return $img;
        }

        return '<picture>'
            . '<source' . self::attributes([
                'media' => '(prefers-reduced-motion: reduce)',
                'type' => $static->mimeType(),
                'srcset' => $this->srcset($path, $this->candidateWidths($dimensions[0], $maxWidth), $static),
                'sizes' => $sizes,
            ]) . '>'
            . $img
            . '</picture>';
    }

    private function altText(string $path, ?string $source, mixed $alt, bool $decorative): string
    {
        $alt = \is_scalar($alt) ? trim((string) $alt) : '';

        if ($decorative) {
            if ('' !== $alt) {
                $this->fail(MissingAltTextException::decorativeWithAlt($path));
            }

            return '';
        }

        if ('' !== $alt) {
            return $alt;
        }

        $embedded = null !== $source ? $this->accessibilityReader->read($source)?->altText($this->locale()) : null;
        if (null !== $embedded && '' !== $embedded) {
            return $embedded;
        }

        $this->fail(MissingAltTextException::forPath($path));

        return '';
    }

    private function fail(MissingAltTextException $exception): void
    {
        if ($this->requireAlt) {
            throw $exception;
        }

        $this->logger->warning($exception->getMessage());
    }

    private function locale(): ?string
    {
        return $this->requestStack?->getCurrentRequest()?->getLocale();
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
            // alt="" must be kept: it is how decorative images are announced as such.
            $html .= true === $value
                ? ' ' . $name
                : ' ' . $name . '="' . htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8') . '"';
        }

        return $html;
    }
}
