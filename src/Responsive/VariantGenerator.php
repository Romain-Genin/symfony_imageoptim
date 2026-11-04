<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Responsive;

use RomainGenin\ImageOptimizerBundle\Binary\BinaryRunner;
use RomainGenin\ImageOptimizerBundle\FormatDetector;
use RomainGenin\ImageOptimizerBundle\ImageFormat;
use RomainGenin\ImageOptimizerBundle\Metadata\JpegFile;
use RomainGenin\ImageOptimizerBundle\Metadata\PngFile;
use RomainGenin\ImageOptimizerBundle\Metadata\WebpFile;
use RomainGenin\ImageOptimizerBundle\Optimizer\ImageOptimizerInterface;
use RomainGenin\ImageOptimizerBundle\Optimizer\LosslessConverter;
use RomainGenin\ImageOptimizerBundle\Optimizer\Workspace;

/**
 * Generates device-sized variants (width + modern format) of an original image.
 *
 * The original upload is never modified. Variants from lossless sources (PNG, GIF, lossless
 * WebP) are encoded losslessly; photos are encoded with a high, configurable quality.
 */
final class VariantGenerator
{
    /**
     * @param array{webp?: int, avif?: int, jpeg?: int} $quality
     */
    public function __construct(
        private readonly BinaryRunner $binaries,
        private readonly ImageOptimizerInterface $optimizer,
        private readonly array $quality = [],
        private readonly int $maxPixels = 50_000_000,
        private readonly FormatDetector $detector = new FormatDetector(),
    ) {
    }

    /**
     * @return list<ImageFormat>
     */
    public function availableFormats(): array
    {
        if (!\extension_loaded('gd')) {
            return [];
        }

        $formats = [ImageFormat::Jpeg, ImageFormat::Png];
        if (\function_exists('imagewebp')) {
            $formats[] = ImageFormat::Webp;
        }
        if ((\function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false)) || $this->binaries->has('avifenc')) {
            $formats[] = ImageFormat::Avif;
        }

        return $formats;
    }

    /**
     * @param bool $allowAnimated animated sources are supported as a static first frame
     *                            (used for prefers-reduced-motion)
     */
    public function supportsSource(string $path, bool $allowAnimated = false): bool
    {
        $format = $this->detector->detect($path);
        if (null === $format || !$format->isGdReadable()) {
            return false;
        }
        if (!$this->isAnimated($path, $format)) {
            return true;
        }

        return $allowAnimated && (ImageFormat::Webp !== $format || $this->binaries->has('webpmux'));
    }

    /**
     * Returns [width, height] as displayed (EXIF orientation applied), or null.
     *
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(string $path): ?array
    {
        $size = @getimagesize($path);
        if (!\is_array($size) || $size[0] <= 0) {
            return null;
        }

        return $this->orientation($path, $this->detector->detect($path)) >= 5 ? [$size[1], $size[0]] : [$size[0], $size[1]];
    }

    public function isAnimated(string $path, ?ImageFormat $format = null): bool
    {
        $format ??= $this->detector->detect($path);

        return match ($format) {
            ImageFormat::Gif => LosslessConverter::isAnimatedGif($path),
            ImageFormat::Png => (bool) PngFile::parse((string) file_get_contents($path))?->isAnimated(),
            ImageFormat::Webp => (bool) WebpFile::parse((string) file_get_contents($path))?->isAnimated(),
            default => false,
        };
    }

    public function generate(string $source, int $width, ImageFormat $format, string $target): void
    {
        $sourceFormat = $this->detector->detect($source);
        if (null === $sourceFormat || !$sourceFormat->isGdReadable()) {
            throw new \InvalidArgumentException(sprintf('Unsupported source image "%s".', $source));
        }
        if (!\in_array($format, $this->availableFormats(), true)) {
            throw new \InvalidArgumentException(sprintf('Output format "%s" is not available on this server.', $format->value));
        }

        [$srcWidth, $srcHeight] = $this->dimensions($source) ?? throw new \InvalidArgumentException('Unreadable image.');
        if ($srcWidth * $srcHeight > $this->maxPixels) {
            throw new \InvalidArgumentException('Image too large.');
        }

        $dir = \dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create "%s".', $dir));
        }

        $workspace = new Workspace($dir);
        $tmp = $workspace->path($format->extension());
        $width = max(1, min($width, $srcWidth));

        $animated = $this->isAnimated($source, $sourceFormat);

        if ($width === $srcWidth && $format === $sourceFormat && !$animated && 1 === $this->orientation($source, $sourceFormat)) {
            // Nothing to resize or convert: the (already optimized) original is the best variant.
            copy($source, $tmp);
        } else {
            // Animated sources: the variant is the static first frame (prefers-reduced-motion).
            $image = $this->load($animated && ImageFormat::Webp === $sourceFormat ? $this->firstWebpFrame($source, $workspace) : $source, $sourceFormat);
            if ($width !== imagesx($image)) {
                $image = $this->resize($image, $width, max(1, (int) round($srcHeight * $width / $srcWidth)));
            }
            $this->encode($image, $format, $tmp, $this->isLosslessSource($source, $sourceFormat), $workspace);
        }

        if (!rename($tmp, $target)) {
            throw new \RuntimeException(sprintf('Unable to write "%s".', $target));
        }
    }

    private function firstWebpFrame(string $source, Workspace $workspace): string
    {
        $frame = $workspace->path('webp');
        if (!$this->binaries->run('webpmux', ['-get', 'frame', '1', $source, '-o', $frame])) {
            throw new \RuntimeException('Unable to extract the first frame of an animated WebP (webpmux is required).');
        }

        return $frame;
    }

    private function load(string $path, ImageFormat $format): \GdImage
    {
        $image = match ($format) {
            ImageFormat::Jpeg => @imagecreatefromjpeg($path),
            ImageFormat::Png => @imagecreatefrompng($path),
            ImageFormat::Gif => @imagecreatefromgif($path),
            ImageFormat::Webp => @imagecreatefromwebp($path),
            ImageFormat::Bmp => @imagecreatefrombmp($path),
            ImageFormat::Avif => @imagecreatefromavif($path),
            default => false,
        };

        if (!$image instanceof \GdImage) {
            throw new \RuntimeException(sprintf('Unable to decode "%s".', $path));
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $this->applyOrientation($image, $this->orientation($path, $format));
    }

    private function orientation(string $path, ?ImageFormat $format): int
    {
        return match ($format) {
            ImageFormat::Jpeg => JpegFile::parse((string) file_get_contents($path))?->orientation() ?? 1,
            ImageFormat::Png => PngFile::parse((string) file_get_contents($path))?->orientation() ?? 1,
            default => 1,
        };
    }

    private function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        if (\in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, \IMG_FLIP_HORIZONTAL);
        } elseif (4 === $orientation) {
            imageflip($image, \IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => 270,
            default => 0,
        };

        if (0 !== $angle) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated instanceof \GdImage) {
                imagealphablending($rotated, false);
                imagesavealpha($rotated, true);
                $image = $rotated;
            }
        }

        return $image;
    }

    private function resize(\GdImage $image, int $width, int $height): \GdImage
    {
        $resized = imagecreatetruecolor($width, $height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $resized;
    }

    private function encode(\GdImage $image, ImageFormat $format, string $target, bool $lossless, Workspace $workspace): void
    {
        $ok = match ($format) {
            ImageFormat::Webp => imagewebp($image, $target, $lossless ? (\defined('IMG_WEBP_LOSSLESS') ? \IMG_WEBP_LOSSLESS : 101) : $this->quality('webp', 90)),
            ImageFormat::Avif => $this->encodeAvif($image, $target, $lossless, $workspace),
            ImageFormat::Jpeg => $this->encodeJpeg($image, $target),
            ImageFormat::Png => imagepng($image, $target, 9),
            default => false,
        };

        if (!$ok || !is_file($target) || 0 === filesize($target)) {
            throw new \RuntimeException(sprintf('Unable to encode %s variant.', $format->value));
        }

        if (ImageFormat::Jpeg === $format || ImageFormat::Png === $format) {
            $this->optimizer->optimize($target, false);
        }
    }

    private function encodeJpeg(\GdImage $image, string $target): bool
    {
        // JPEG has no alpha channel: flatten on white.
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imageinterlace($flat, true);

        return imagejpeg($flat, $target, $this->quality('jpeg', 90));
    }

    private function encodeAvif(\GdImage $image, string $target, bool $lossless, Workspace $workspace): bool
    {
        $quality = $lossless ? 100 : $this->quality('avif', 80);

        if (\function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false)) {
            return imageavif($image, $target, $quality, 4);
        }

        $png = $workspace->path('png');
        imagepng($image, $png, 1);
        $arguments = $lossless ? ['--lossless'] : ['-q', (string) $quality];

        return $this->binaries->run('avifenc', [...$arguments, '-s', '6', '-j', 'all', $png, $target]);
    }

    private function isLosslessSource(string $path, ImageFormat $format): bool
    {
        return match ($format) {
            ImageFormat::Png, ImageFormat::Gif, ImageFormat::Bmp => true,
            ImageFormat::Webp => (bool) WebpFile::parse((string) file_get_contents($path))?->isLossless(),
            default => false,
        };
    }

    private function quality(string $format, int $default): int
    {
        return max(1, min(100, (int) ($this->quality[$format] ?? $default)));
    }
}
