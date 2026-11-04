<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Metadata;

/**
 * Pure PHP, lossless JPEG marker manipulation.
 *
 * The entropy-coded image data is never touched: only metadata segments are
 * removed, so the decoded pixels are bit-for-bit identical.
 */
final class JpegFile
{
    private const XMP_HEADER = "http://ns.adobe.com/xap/1.0/\x00";

    /**
     * @param list<array{marker: int, data: string}> $segments segments located before the first SOS (data excludes marker + length)
     */
    private function __construct(
        private array $segments,
        private readonly string $scan,
        private readonly string $trailer,
    ) {
    }

    public static function parse(string $bytes): ?self
    {
        $length = \strlen($bytes);
        if ($length < 4 || "\xFF\xD8" !== substr($bytes, 0, 2)) {
            return null;
        }

        $segments = [];
        $offset = 2;

        while ($offset + 4 <= $length) {
            if ("\xFF" !== $bytes[$offset]) {
                return null;
            }
            $marker = \ord($bytes[$offset + 1]);

            // Fill bytes.
            if (0xFF === $marker) {
                ++$offset;
                continue;
            }

            if (0xDA === $marker) { // SOS: image data until EOI.
                $end = self::findEndOfImage($bytes, $offset);
                if (null === $end) {
                    return null;
                }

                return new self($segments, substr($bytes, $offset, $end - $offset), (string) substr($bytes, $end));
            }

            $segmentLength = unpack('n', substr($bytes, $offset + 2, 2))[1];
            if ($segmentLength < 2 || $offset + 2 + $segmentLength > $length) {
                return null;
            }

            $segments[] = ['marker' => $marker, 'data' => substr($bytes, $offset + 4, $segmentLength - 2)];
            $offset += 2 + $segmentLength;
        }

        return null;
    }

    /**
     * Walks scans (and the tables between progressive scans) and returns the offset right after EOI.
     */
    private static function findEndOfImage(string $bytes, int $offset): ?int
    {
        $length = \strlen($bytes);

        while ($offset + 2 <= $length) {
            $marker = \ord($bytes[$offset + 1]);

            if (0xD9 === $marker) {
                return $offset + 2;
            }

            if ($offset + 4 > $length) {
                return null;
            }
            $segmentLength = unpack('n', substr($bytes, $offset + 2, 2))[1];
            $offset += 2 + $segmentLength;

            if (0xDA !== $marker) {
                continue;
            }

            // Entropy-coded data: stops at the first marker that is not a stuffed byte or a RST.
            while (true) {
                $offset = strpos($bytes, "\xFF", $offset);
                if (false === $offset || $offset + 1 >= $length) {
                    return null;
                }
                $next = \ord($bytes[$offset + 1]);
                if (0x00 === $next || 0xFF === $next || ($next >= 0xD0 && $next <= 0xD7)) {
                    ++$offset;
                    continue;
                }
                break;
            }
        }

        return null;
    }

    public function xmp(): ?string
    {
        foreach ($this->segments as $segment) {
            if (0xE1 === $segment['marker'] && str_starts_with($segment['data'], self::XMP_HEADER)) {
                return substr($segment['data'], \strlen(self::XMP_HEADER));
            }
        }

        return null;
    }

    public function orientation(): int
    {
        foreach ($this->segments as $segment) {
            if (0xE1 === $segment['marker'] && str_starts_with($segment['data'], "Exif\x00\x00")) {
                return ExifOrientation::read(substr($segment['data'], 6));
            }
        }

        return 1;
    }

    /**
     * Multi-Picture (MPF) JPEGs embed secondary images after EOI (HDR gain maps from
     * iPhone / Android "Ultra HDR", depth maps...). Their offsets depend on the exact
     * byte layout, so such files must not be rewritten.
     */
    public function hasMultiPicture(): bool
    {
        foreach ($this->segments as $segment) {
            if (0xE2 === $segment['marker'] && str_starts_with($segment['data'], "MPF\x00")) {
                return true;
            }
            if (0xE1 === $segment['marker'] && str_contains($segment['data'], 'hdrgm')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Removes EXIF (GPS, camera, thumbnails), XMP, IPTC, comments and vendor blocks.
     * Keeps everything that affects rendering: JFIF, ICC profile (APP2), Adobe (APP14),
     * tables and frame headers, plus the orientation as a minimal EXIF block and,
     * optionally, a replacement XMP packet (accessibility text alternatives).
     */
    public function stripMetadata(bool $keepOrientation = true, ?string $xmp = null): self
    {
        $orientation = $keepOrientation ? $this->orientation() : 1;
        $kept = [];

        foreach ($this->segments as $segment) {
            $marker = $segment['marker'];
            $keep = match (true) {
                0xE0 === $marker => str_starts_with($segment['data'], "JFIF\x00"),
                0xE2 === $marker => str_starts_with($segment['data'], "ICC_PROFILE\x00"),
                0xEE === $marker => str_starts_with($segment['data'], 'Adobe'),
                $marker >= 0xE0 && $marker <= 0xEF => false, // other APPn
                0xFE === $marker => false, // COM
                default => true,
            };

            if ($keep) {
                $kept[] = $segment;
            }
        }

        $insertAt = isset($kept[0]) && 0xE0 === $kept[0]['marker'] ? 1 : 0;
        $inserted = [];
        if ($orientation > 1) {
            $inserted[] = ['marker' => 0xE1, 'data' => "Exif\x00\x00" . ExifOrientation::buildTiff($orientation)];
        }
        if (null !== $xmp && \strlen($xmp) < 65000) {
            $inserted[] = ['marker' => 0xE1, 'data' => self::XMP_HEADER . $xmp];
        }
        array_splice($kept, $insertAt, 0, $inserted);

        return new self($kept, $this->scan, '');
    }

    public function toBytes(): string
    {
        $bytes = "\xFF\xD8";
        foreach ($this->segments as $segment) {
            $bytes .= "\xFF" . \chr($segment['marker']) . pack('n', \strlen($segment['data']) + 2) . $segment['data'];
        }

        return $bytes . $this->scan . $this->trailer;
    }
}
