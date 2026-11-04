<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Metadata;

/**
 * Pure PHP, lossless PNG chunk manipulation (metadata removal + maximum zlib recompression).
 */
final class PngFile
{
    private const SIGNATURE = "\x89PNG\r\n\x1A\n";
    private const XMP_KEYWORD = 'XML:com.adobe.xmp';

    /** Ancillary chunks that never affect rendering. */
    private const METADATA_CHUNKS = ['tEXt', 'zTXt', 'iTXt', 'tIME', 'dSIG', 'vpAg', 'caNv', 'orNT', 'prVW', 'mkBF', 'mkTS', 'mkBS', 'mkBT', 'iDOT'];

    /**
     * @param list<array{type: string, data: string}> $chunks
     */
    private function __construct(private readonly array $chunks)
    {
    }

    public static function parse(string $bytes): ?self
    {
        if (!str_starts_with($bytes, self::SIGNATURE)) {
            return null;
        }

        $chunks = [];
        $offset = 8;
        $length = \strlen($bytes);

        while ($offset + 12 <= $length) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            if ($offset + 12 + $size > $length) {
                return null;
            }
            $chunks[] = ['type' => $type, 'data' => substr($bytes, $offset + 8, $size)];
            $offset += 12 + $size;

            if ('IEND' === $type) {
                break;
            }
        }

        if ([] === $chunks || 'IHDR' !== $chunks[0]['type'] || 'IEND' !== $chunks[array_key_last($chunks)]['type']) {
            return null;
        }

        return new self($chunks);
    }

    public function width(): int
    {
        return unpack('N', substr($this->chunks[0]['data'], 0, 4))[1];
    }

    public function height(): int
    {
        return unpack('N', substr($this->chunks[0]['data'], 4, 4))[1];
    }

    public function bitDepth(): int
    {
        return \ord($this->chunks[0]['data'][8]);
    }

    /**
     * 0 = grey, 2 = RGB, 3 = palette, 4 = grey + alpha, 6 = RGBA.
     */
    public function colorType(): int
    {
        return \ord($this->chunks[0]['data'][9]);
    }

    public function has(string $type): bool
    {
        foreach ($this->chunks as $chunk) {
            if ($chunk['type'] === $type) {
                return true;
            }
        }

        return false;
    }

    public function isAnimated(): bool
    {
        return $this->has('acTL');
    }

    public function hasAlpha(): bool
    {
        return \in_array($this->colorType(), [4, 6], true) || $this->has('tRNS');
    }

    /**
     * Whether a gAMA/cHRM chunk requests a non-sRGB rendering. Formats like WebP do not
     * support those chunks, so converting such a file would shift its colours.
     */
    public function hasNonStandardColorimetry(): bool
    {
        if ($this->has('sRGB') || $this->has('iCCP') || $this->has('cICP')) {
            return false;
        }

        foreach ($this->chunks as $chunk) {
            if ('gAMA' === $chunk['type']) {
                $gamma = unpack('N', $chunk['data'])[1];

                return abs($gamma - 45455) > 100;
            }
            if ('cHRM' === $chunk['type']) {
                return true;
            }
        }

        return false;
    }

    public function orientation(): int
    {
        foreach ($this->chunks as $chunk) {
            if ('eXIf' === $chunk['type']) {
                return ExifOrientation::read($chunk['data']);
            }
        }

        return 1;
    }

    public function xmp(): ?string
    {
        foreach ($this->chunks as $chunk) {
            if ('iTXt' !== $chunk['type'] || !str_starts_with($chunk['data'], self::XMP_KEYWORD . "\x00")) {
                continue;
            }
            $data = substr($chunk['data'], \strlen(self::XMP_KEYWORD) + 1);
            $compressed = "\x01" === ($data[0] ?? '');
            // Skip compression flag/method, then language tag and translated keyword (both NUL-terminated).
            $parts = explode("\x00", substr($data, 2), 3);
            $text = $parts[2] ?? '';

            return $compressed ? (@zlib_decode($text) ?: null) : $text;
        }

        return null;
    }

    public function stripMetadata(bool $keepOrientation = true, ?string $xmp = null): self
    {
        $orientation = $keepOrientation ? $this->orientation() : 1;
        $chunks = [];

        foreach ($this->chunks as $chunk) {
            if (\in_array($chunk['type'], self::METADATA_CHUNKS, true)) {
                continue;
            }
            if ('eXIf' === $chunk['type']) {
                if ($orientation > 1) {
                    $chunks[] = ['type' => 'eXIf', 'data' => ExifOrientation::buildTiff($orientation)];
                }
                continue;
            }
            $chunks[] = $chunk;
        }

        if (null !== $xmp) {
            array_splice($chunks, -1, 0, [['type' => 'iTXt', 'data' => self::XMP_KEYWORD . "\x00\x00\x00\x00\x00" . $xmp]]);
        }

        return new self($chunks);
    }

    /**
     * Re-deflates the image data at maximum compression and merges IDAT chunks.
     * The filtered scanlines are unchanged, so pixels are strictly identical.
     */
    public function recompress(): self
    {
        $idat = '';
        $first = null;
        $chunks = [];

        foreach ($this->chunks as $chunk) {
            if ('IDAT' === $chunk['type']) {
                $idat .= $chunk['data'];
                $first ??= \count($chunks);
                continue;
            }
            $chunks[] = $chunk;
        }

        if (null === $first) {
            return $this;
        }

        $raw = @zlib_decode($idat);
        if (false === $raw) {
            return $this;
        }

        $compressed = zlib_encode($raw, \ZLIB_ENCODING_DEFLATE, 9);
        if (false === $compressed || \strlen($compressed) >= \strlen($idat)) {
            return $this;
        }

        array_splice($chunks, $first, 0, [['type' => 'IDAT', 'data' => $compressed]]);

        return new self($chunks);
    }

    public function toBytes(): string
    {
        $bytes = self::SIGNATURE;
        foreach ($this->chunks as $chunk) {
            $bytes .= pack('N', \strlen($chunk['data'])) . $chunk['type'] . $chunk['data'] . pack('N', crc32($chunk['type'] . $chunk['data']));
        }

        return $bytes;
    }
}
