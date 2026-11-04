<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Metadata;

/**
 * Minimal TIFF/EXIF helpers: reads IFD0 tags (Orientation, BitsPerSample...) and builds a
 * tiny EXIF block containing only the orientation (so stripping metadata never makes a
 * photo appear rotated).
 */
final class ExifOrientation
{
    public const TAG_ORIENTATION = 0x0112;
    public const TAG_BITS_PER_SAMPLE = 0x0102;
    public const TAG_PHOTOMETRIC = 0x0106;

    /**
     * @param string $tiff raw TIFF structure (what follows "Exif\0\0" in a JPEG APP1 segment, a PNG eXIf chunk, or a TIFF file)
     */
    public static function read(string $tiff): int
    {
        $value = self::readShortTag($tiff, self::TAG_ORIENTATION);

        return null !== $value && $value >= 1 && $value <= 8 ? $value : 1;
    }

    /**
     * Returns the first SHORT/LONG value of an IFD0 tag, or null when absent.
     */
    public static function readShortTag(string $tiff, int $tag): ?int
    {
        $length = \strlen($tiff);
        if ($length < 8) {
            return null;
        }

        $order = substr($tiff, 0, 2);
        if ('II' === $order) {
            [$s, $l] = ['v', 'V'];
        } elseif ('MM' === $order) {
            [$s, $l] = ['n', 'N'];
        } else {
            return null;
        }

        $u16 = static fn (int $o): ?int => $o + 2 <= $length ? unpack($s, substr($tiff, $o, 2))[1] : null;
        $u32 = static fn (int $o): ?int => $o + 4 <= $length ? unpack($l, substr($tiff, $o, 4))[1] : null;

        $ifd = $u32(4);
        $count = null !== $ifd ? $u16($ifd) : null;
        if (null === $count) {
            return null;
        }

        for ($i = 0; $i < $count; ++$i) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > $length) {
                break;
            }
            if ($tag !== $u16($entry)) {
                continue;
            }

            $type = $u16($entry + 2);
            $valueCount = $u32($entry + 4);
            if (3 === $type) { // SHORT
                return $valueCount <= 2 ? $u16($entry + 8) : $u16((int) $u32($entry + 8));
            }
            if (4 === $type) { // LONG
                return $valueCount <= 1 ? $u32($entry + 8) : $u32((int) $u32($entry + 8));
            }

            return null;
        }

        return null;
    }

    /**
     * Big-endian TIFF structure with a single IFD0 entry: Orientation.
     */
    public static function buildTiff(int $orientation): string
    {
        return "MM\x00\x2A" . pack('N', 8)
            . pack('n', 1)
            . pack('nnN', self::TAG_ORIENTATION, 3, 1) . pack('nn', $orientation, 0)
            . pack('N', 0);
    }
}
