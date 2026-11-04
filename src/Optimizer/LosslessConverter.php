<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Optimizer;

use RomainGenin\ImageOptimizerBundle\Binary\BinaryRunner;
use RomainGenin\ImageOptimizerBundle\ImageFormat;
use RomainGenin\ImageOptimizerBundle\Metadata\ExifOrientation;
use RomainGenin\ImageOptimizerBundle\Metadata\PngFile;

/**
 * Strictly lossless format conversions (PNG/GIF/BMP/TIFF -> lossless WebP, BMP -> PNG).
 *
 * A conversion is refused whenever the target could not represent the source exactly
 * (16-bit channels, gamma chunks, CMYK...), so the pixels are always preserved.
 * JPEG is never converted: any JPEG -> WebP/AVIF conversion is either lossy or bigger.
 */
final class LosslessConverter
{
    public function __construct(private readonly BinaryRunner $binaries)
    {
    }

    public function supports(ImageFormat $from, ImageFormat $to): bool
    {
        return match ($to) {
            ImageFormat::Webp => \in_array($from, [ImageFormat::Png, ImageFormat::Gif, ImageFormat::Bmp, ImageFormat::Tiff], true),
            ImageFormat::Png => \in_array($from, [ImageFormat::Bmp, ImageFormat::Gif], true),
            default => false,
        };
    }

    /**
     * @return string|null description of the conversion, null when it was not possible
     */
    public function convert(string $source, ImageFormat $from, string $target, ImageFormat $to, OptimizerOptions $options): ?string
    {
        if (!$this->supports($from, $to)) {
            return null;
        }

        return match ($to) {
            ImageFormat::Webp => match ($from) {
                ImageFormat::Png => $this->pngToWebp($source, $target, $options),
                ImageFormat::Gif => $this->gifToWebp($source, $target),
                ImageFormat::Bmp => $this->bmpToWebp($source, $target),
                ImageFormat::Tiff => $this->tiffToWebp($source, $target, $options),
                default => null,
            },
            ImageFormat::Png => $this->gdToPng($source, $from, $target),
            default => null,
        };
    }

    private function pngToWebp(string $source, string $target, OptimizerOptions $options): ?string
    {
        $png = PngFile::parse((string) file_get_contents($source));
        if (null === $png
            || $png->bitDepth() > 8          // WebP is 8 bits per channel
            || $png->isAnimated()            // APNG
            || $png->hasNonStandardColorimetry()
            || $png->orientation() > 1
        ) {
            return null;
        }

        if ($this->cwebp($source, $target, $options)) {
            return 'png -> webp lossless (cwebp)';
        }

        // GD stores alpha on 7 bits and drops ICC profiles: only use it when that cannot matter.
        if ($png->has('iCCP') || \in_array($png->colorType(), [4, 6], true)) {
            return null;
        }
        if ($png->has('tRNS') && !(3 === $png->colorType() && $this->isBinaryPaletteAlpha($source))) {
            return null;
        }

        return $this->gdToWebp(@imagecreatefrompng($source), $target) ? 'png -> webp lossless (gd)' : null;
    }

    private function gifToWebp(string $source, string $target): ?string
    {
        // gif2webp keeps animations and is lossless by default.
        if ($this->binaries->run('gif2webp', ['-quiet', '-m', '6', $source, '-o', $target])) {
            return 'gif -> webp lossless (gif2webp)';
        }

        if (self::isAnimatedGif($source) || !\function_exists('imagecreatefromgif')) {
            return null;
        }

        // GIF transparency is binary: GD's 7-bit alpha represents it exactly.
        return $this->gdToWebp(@imagecreatefromgif($source), $target) ? 'gif -> webp lossless (gd)' : null;
    }

    private function bmpToWebp(string $source, string $target): ?string
    {
        if (!\function_exists('imagecreatefrombmp') || $this->bmpBitCount($source) > 24) {
            return null;
        }

        return $this->gdToWebp(@imagecreatefrombmp($source), $target) ? 'bmp -> webp lossless (gd)' : null;
    }

    private function tiffToWebp(string $source, string $target, OptimizerOptions $options): ?string
    {
        $header = (string) file_get_contents($source, false, null, 0, 65536);
        $bits = ExifOrientation::readShortTag($header, ExifOrientation::TAG_BITS_PER_SAMPLE) ?? 1;
        $photometric = ExifOrientation::readShortTag($header, ExifOrientation::TAG_PHOTOMETRIC);

        // Only 8-bit grey / RGB / palette TIFFs can be represented exactly in WebP.
        if ($bits > 8 || !\in_array($photometric, [0, 1, 2, 3], true) || ExifOrientation::read($header) > 1) {
            return null;
        }

        return $this->cwebp($source, $target, $options) ? 'tiff -> webp lossless (cwebp)' : null;
    }

    private function gdToPng(string $source, ImageFormat $from, string $target): ?string
    {
        $image = match ($from) {
            ImageFormat::Bmp => \function_exists('imagecreatefrombmp') && $this->bmpBitCount($source) <= 24 ? @imagecreatefrombmp($source) : false,
            ImageFormat::Gif => \function_exists('imagecreatefromgif') && !self::isAnimatedGif($source) ? @imagecreatefromgif($source) : false,
            default => false,
        };

        if (!$image instanceof \GdImage) {
            return null;
        }

        imagesavealpha($image, true);
        $ok = imagepng($image, $target, 9);
        imagedestroy($image);

        return $ok ? sprintf('%s -> png (gd)', $from->value) : null;
    }

    private function cwebp(string $source, string $target, OptimizerOptions $options): bool
    {
        return $this->binaries->run('cwebp', [
            '-quiet', '-lossless', '-exact', '-z', '9',
            '-metadata', $options->stripMetadata ? 'icc' : 'all',
            $source, '-o', $target,
        ]);
    }

    private function gdToWebp(\GdImage|false $image, string $target): bool
    {
        if (!$image instanceof \GdImage || !\function_exists('imagewebp')) {
            return false;
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $ok = imagewebp($image, $target, \defined('IMG_WEBP_LOSSLESS') ? \IMG_WEBP_LOSSLESS : 101);
        imagedestroy($image);

        return $ok && filesize($target) > 0;
    }

    private function isBinaryPaletteAlpha(string $source): bool
    {
        $bytes = (string) file_get_contents($source);
        $pos = strpos($bytes, 'tRNS');
        if (false === $pos || $pos < 4) {
            return false;
        }
        $length = unpack('N', substr($bytes, $pos - 4, 4))[1];

        return '' === trim(substr($bytes, $pos + 4, $length), "\x00\xFF");
    }

    private function bmpBitCount(string $source): int
    {
        $header = (string) file_get_contents($source, false, null, 0, 30);

        return \strlen($header) >= 30 ? unpack('v', substr($header, 28, 2))[1] : 32;
    }

    public static function isAnimatedGif(string $path): bool
    {
        $bytes = (string) file_get_contents($path);

        // Count Graphic Control Extensions followed by an image descriptor.
        return preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $bytes) > 1;
    }
}
