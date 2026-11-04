<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Metadata;

use RomainGenin\ImageOptimizerBundle\FormatDetector;
use RomainGenin\ImageOptimizerBundle\ImageFormat;

/**
 * Reads the text alternatives embedded in an image (IPTC accessibility fields in XMP,
 * or <title>/<desc> for SVG). Works on uploads before and after optimization: the bundle
 * keeps those fields when it strips the other metadata.
 *
 *     $alt = $reader->read($uploadedFile->getPathname())?->altText($request->getLocale());
 */
final class AccessibilityMetadataReader
{
    public function __construct(private readonly FormatDetector $detector = new FormatDetector())
    {
    }

    public function read(string $path): ?AccessibilityMetadata
    {
        $format = is_file($path) ? $this->detector->detect($path) : null;
        if (null === $format) {
            return null;
        }

        $bytes = (string) @file_get_contents($path);

        return match ($format) {
            ImageFormat::Jpeg => AccessibilityMetadata::fromXmp(JpegFile::parse($bytes)?->xmp()),
            ImageFormat::Png => AccessibilityMetadata::fromXmp(PngFile::parse($bytes)?->xmp()),
            ImageFormat::Webp => AccessibilityMetadata::fromXmp(WebpFile::parse($bytes)?->xmp()),
            ImageFormat::Svg => self::fromSvg($bytes) ?? AccessibilityMetadata::fromXmp(self::embeddedXmp($bytes)),
            default => AccessibilityMetadata::fromXmp(self::embeddedXmp($bytes)),
        };
    }

    /**
     * XMP packets are plain text inside GIF/TIFF/AVIF files: locate the packet.
     */
    private static function embeddedXmp(string $bytes): ?string
    {
        $start = strpos($bytes, '<x:xmpmeta');
        $end = false !== $start ? strpos($bytes, '</x:xmpmeta>', $start) : false;

        return false !== $start && false !== $end ? substr($bytes, $start, $end + 12 - $start) : null;
    }

    private static function fromSvg(string $bytes): ?AccessibilityMetadata
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($bytes, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $document->documentElement : null;
        if (null === $root) {
            return null;
        }

        $title = $desc = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && 'title' === $child->localName && null === $title) {
                $title = trim($child->textContent);
            } elseif ($child instanceof \DOMElement && 'desc' === $child->localName && null === $desc) {
                $desc = trim($child->textContent);
            }
        }

        $alt = '' !== (string) $title ? (string) $title : trim($root->getAttribute('aria-label'));
        if ('' === $alt) {
            return null;
        }

        return new AccessibilityMetadata(['x-default' => $alt], '' !== (string) $desc ? ['x-default' => (string) $desc] : []);
    }
}
