<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Metadata;

/**
 * Pure PHP WebP (RIFF) container manipulation: read / write the XMP chunk.
 * The image bitstream (VP8 / VP8L / ANIM) is copied untouched.
 */
final class WebpFile
{
    private const FLAG_ANIMATION = 0x02;
    private const FLAG_XMP = 0x04;
    private const FLAG_ALPHA = 0x10;

    /**
     * @param list<array{type: string, data: string}> $chunks
     */
    private function __construct(private readonly array $chunks)
    {
    }

    public static function parse(string $bytes): ?self
    {
        if (\strlen($bytes) < 20 || !str_starts_with($bytes, 'RIFF') || 'WEBP' !== substr($bytes, 8, 4)) {
            return null;
        }

        $end = min(\strlen($bytes), 8 + unpack('V', substr($bytes, 4, 4))[1]);
        $chunks = [];
        $offset = 12;

        while ($offset + 8 <= $end) {
            $type = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
            if ($offset + 8 + $size > $end) {
                return null;
            }
            $chunks[] = ['type' => $type, 'data' => substr($bytes, $offset + 8, $size)];
            $offset += 8 + $size + ($size & 1);
        }

        return [] === $chunks ? null : new self($chunks);
    }

    public function isAnimated(): bool
    {
        return 'VP8X' === $this->chunks[0]['type'] && (\ord($this->chunks[0]['data'][0]) & self::FLAG_ANIMATION) > 0;
    }

    public function isLossless(): bool
    {
        foreach ($this->chunks as $chunk) {
            if ('VP8L' === $chunk['type']) {
                return true;
            }
            if ('VP8 ' === $chunk['type']) {
                return false;
            }
        }

        return false;
    }

    public function xmp(): ?string
    {
        foreach ($this->chunks as $chunk) {
            if ('XMP ' === $chunk['type']) {
                return $chunk['data'];
            }
        }

        return null;
    }

    public function withXmp(string $xmp): ?self
    {
        $chunks = array_values(array_filter($this->chunks, static fn (array $c): bool => 'XMP ' !== $c['type']));

        if ('VP8X' !== $chunks[0]['type']) {
            $header = $this->simpleHeader($chunks[0]);
            if (null === $header) {
                return null;
            }
            [$width, $height, $alpha] = $header;
            array_unshift($chunks, [
                'type' => 'VP8X',
                'data' => \chr($alpha ? self::FLAG_ALPHA : 0) . "\x00\x00\x00" . self::u24($width - 1) . self::u24($height - 1),
            ]);
        }

        $vp8x = $chunks[0]['data'];
        $vp8x[0] = \chr(\ord($vp8x[0]) | self::FLAG_XMP);
        $chunks[0]['data'] = $vp8x;
        $chunks[] = ['type' => 'XMP ', 'data' => $xmp];

        return new self($chunks);
    }

    public function toBytes(): string
    {
        $body = 'WEBP';
        foreach ($this->chunks as $chunk) {
            $size = \strlen($chunk['data']);
            $body .= $chunk['type'] . pack('V', $size) . $chunk['data'] . ($size & 1 ? "\x00" : '');
        }

        return 'RIFF' . pack('V', \strlen($body)) . $body;
    }

    /**
     * @param array{type: string, data: string} $chunk
     *
     * @return array{0: int, 1: int, 2: bool}|null width, height, alpha
     */
    private function simpleHeader(array $chunk): ?array
    {
        $data = $chunk['data'];

        if ('VP8L' === $chunk['type'] && \strlen($data) >= 5 && "\x2F" === $data[0]) {
            $bits = unpack('V', substr($data, 1, 4))[1];

            return [($bits & 0x3FFF) + 1, (($bits >> 14) & 0x3FFF) + 1, (bool) (($bits >> 28) & 1)];
        }

        if ('VP8 ' === $chunk['type'] && \strlen($data) >= 10 && "\x9D\x01\x2A" === substr($data, 3, 3)) {
            return [unpack('v', substr($data, 6, 2))[1] & 0x3FFF, unpack('v', substr($data, 8, 2))[1] & 0x3FFF, false];
        }

        return null;
    }

    private static function u24(int $value): string
    {
        return substr(pack('V', $value), 0, 3);
    }
}
