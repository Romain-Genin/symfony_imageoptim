<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle;

/**
 * Image formats known by the bundle.
 */
enum ImageFormat: string
{
    case Jpeg = 'jpeg';
    case Png = 'png';
    case Gif = 'gif';
    case Webp = 'webp';
    case Avif = 'avif';
    case Heic = 'heic';
    case Bmp = 'bmp';
    case Tiff = 'tiff';
    case Svg = 'svg';

    public function mimeType(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Png => 'image/png',
            self::Gif => 'image/gif',
            self::Webp => 'image/webp',
            self::Avif => 'image/avif',
            self::Heic => 'image/heic',
            self::Bmp => 'image/bmp',
            self::Tiff => 'image/tiff',
            self::Svg => 'image/svg+xml',
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Jpeg => 'jpg',
            self::Tiff => 'tif',
            default => $this->value,
        };
    }

    /**
     * @return list<string>
     */
    public function knownExtensions(): array
    {
        return match ($this) {
            self::Jpeg => ['jpg', 'jpeg', 'jpe', 'jfif'],
            self::Tiff => ['tif', 'tiff'],
            self::Heic => ['heic', 'heif'],
            self::Svg => ['svg', 'svgz'],
            default => [$this->value],
        };
    }

    /**
     * Raster formats GD is able to decode.
     */
    public function isGdReadable(): bool
    {
        return match ($this) {
            self::Jpeg => \function_exists('imagecreatefromjpeg'),
            self::Png => \function_exists('imagecreatefrompng'),
            self::Gif => \function_exists('imagecreatefromgif'),
            self::Webp => \function_exists('imagecreatefromwebp'),
            self::Bmp => \function_exists('imagecreatefrombmp'),
            self::Avif => \function_exists('imagecreatefromavif') && (gd_info()['AVIF Support'] ?? false),
            default => false,
        };
    }

    public static function fromName(string $name): self
    {
        $name = strtolower(trim($name));

        foreach (self::cases() as $case) {
            if (\in_array($name, $case->knownExtensions(), true) || $case->value === $name) {
                return $case;
            }
        }

        throw new \InvalidArgumentException(sprintf('Unknown image format "%s".', $name));
    }
}
