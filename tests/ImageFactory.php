<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Tests;

use StudioAstra\ImageOptimizerBundle\Metadata\ExifOrientation;

/**
 * Generates test images on the fly.
 */
final class ImageFactory
{
    public static function dir(): string
    {
        $dir = __DIR__ . '/var/' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        return $dir;
    }

    public static function path(string $name): string
    {
        return self::dir() . '/' . bin2hex(random_bytes(4)) . '_' . $name;
    }

    public static function photo(int $width = 256, int $height = 192): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        mt_srand(42);
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $r = (int) (($x / $width) * 200) + mt_rand(0, 55);
                $g = (int) (($y / $height) * 200) + mt_rand(0, 55);
                $b = (int) (128 + 100 * sin(($x + $y) / 15)) + mt_rand(-20, 20);
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $r, $g, max(0, min(255, $b))));
            }
        }

        return $image;
    }

    /**
     * Graphics-like image (flat areas, alpha) typical of PNG uploads.
     */
    public static function graphic(int $width = 200, int $height = 120, bool $alpha = true): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, $alpha);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, $alpha ? 127 : 0));
        imagefilledrectangle($image, 10, 10, 90, 60, imagecolorallocatealpha($image, 220, 30, 30, 0));
        imagefilledellipse($image, 140, 60, 80, 80, imagecolorallocatealpha($image, 30, 90, 220, $alpha ? 40 : 0));
        imagefilledrectangle($image, 20, 80, 180, 110, imagecolorallocatealpha($image, 20, 160, 60, 0));

        return $image;
    }

    public static function jpeg(\GdImage $image, int $quality = 92): string
    {
        $path = self::path('photo.jpg');
        imagejpeg($image, $path, $quality);

        return $path;
    }

    public static function png(\GdImage $image): string
    {
        $path = self::path('image.png');
        imagepng($image, $path, 0); // uncompressed, like many exports

        return $path;
    }

    /**
     * Adds camera-like metadata to a JPEG: EXIF (orientation + fake GPS/maker blob), XMP, comment, ICC.
     */
    public static function addJpegMetadata(string $path, int $orientation = 1, bool $icc = true, bool $mpf = false): void
    {
        $bytes = (string) file_get_contents($path);
        $exif = "Exif\x00\x00" . substr(ExifOrientation::buildTiff($orientation), 0, -4) . pack('N', 0) . str_repeat('GPS-AND-MAKER-NOTES', 300);
        $xmp = "http://ns.adobe.com/xap/1.0/\x00" . '<x:xmpmeta>' . str_repeat('<rdf:Description/>', 200) . '</x:xmpmeta>';
        $segments = self::segment(0xE1, $exif) . self::segment(0xE1, $xmp) . self::segment(0xFE, str_repeat('comment ', 100));
        if ($icc) {
            $segments .= self::segment(0xE2, "ICC_PROFILE\x00\x01\x01" . str_repeat("\x42", 500));
        }
        if ($mpf) {
            $segments .= self::segment(0xE2, "MPF\x00MM\x00\x2A\x00\x00\x00\x08");
        }

        // Insert right after APP0 (JFIF).
        $app0Length = unpack('n', substr($bytes, 4, 2))[1];
        $insertAt = 4 + $app0Length;
        file_put_contents($path, substr($bytes, 0, $insertAt) . $segments . substr($bytes, $insertAt));
    }

    public static function addPngText(string $path): void
    {
        $bytes = (string) file_get_contents($path);
        $data = "Comment\x00" . str_repeat('Made with some editor. ', 100);
        $chunk = pack('N', \strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));
        // After IHDR (8 signature + 25 IHDR chunk).
        file_put_contents($path, substr($bytes, 0, 33) . $chunk . substr($bytes, 33));
    }

    /**
     * Lightroom-like XMP packet: IPTC accessibility fields + personal data that must be removed.
     */
    public static function accessibilityXmp(): string
    {
        return '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description rdf:about="" xmlns:Iptc4xmpCore="http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:exif="http://ns.adobe.com/exif/1.0/"'
            . ' exif:GPSLatitude="48,51.5N" exif:GPSLongitude="2,17.4E">'
            . '<dc:creator><rdf:Seq><rdf:li>Jane Doe, jane@example.com</rdf:li></rdf:Seq></dc:creator>'
            . '<Iptc4xmpCore:AltTextAccessibility><rdf:Alt>'
            . '<rdf:li xml:lang="x-default">A black cat on a red sofa</rdf:li>'
            . '<rdf:li xml:lang="fr">Un chat noir sur un canapé rouge</rdf:li>'
            . '</rdf:Alt></Iptc4xmpCore:AltTextAccessibility>'
            . '<Iptc4xmpCore:ExtDescrAccessibility><rdf:Alt>'
            . '<rdf:li xml:lang="x-default">The cat is sleeping, curled up, next to a blue cushion.</rdf:li>'
            . '</rdf:Alt></Iptc4xmpCore:ExtDescrAccessibility>'
            . '</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }

    public static function addJpegXmp(string $path, string $xmp): void
    {
        $bytes = (string) file_get_contents($path);
        $app0Length = unpack('n', substr($bytes, 4, 2))[1];
        $insertAt = 4 + $app0Length;
        file_put_contents($path, substr($bytes, 0, $insertAt) . self::segment(0xE1, "http://ns.adobe.com/xap/1.0/\x00" . $xmp) . substr($bytes, $insertAt));
    }

    public static function addPngXmp(string $path, string $xmp): void
    {
        $bytes = (string) file_get_contents($path);
        $data = "XML:com.adobe.xmp\x00\x00\x00\x00\x00" . $xmp;
        $chunk = pack('N', \strlen($data)) . 'iTXt' . $data . pack('N', crc32('iTXt' . $data));
        file_put_contents($path, substr($bytes, 0, 33) . $chunk . substr($bytes, 33));
    }

    /**
     * Two-frame animated GIF (requires gifsicle), or null.
     */
    public static function animatedGif(): ?string
    {
        $frames = [];
        foreach ([[200, 0, 0], [0, 0, 200]] as $i => [$r, $g, $b]) {
            $image = imagecreate(120, 80);
            imagecolorallocate($image, 255, 255, 255);
            imagefilledrectangle($image, 10 + $i * 40, 10, 60 + $i * 40, 60, imagecolorallocate($image, $r, $g, $b));
            $frames[] = $frame = self::path("frame$i.gif");
            imagegif($image, $frame);
        }

        $path = self::path('anim.gif');
        $runner = new \StudioAstra\ImageOptimizerBundle\Binary\BinaryRunner();
        if (!$runner->run('gifsicle', ['--loop', '--delay', '50', ...$frames, '-o', $path])) {
            return null;
        }

        return $path;
    }

    private static function segment(int $marker, string $data): string
    {
        return "\xFF" . \chr($marker) . pack('n', \strlen($data) + 2) . $data;
    }

    public static function load(string $path): \GdImage
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('Cannot decode ' . $path);
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /**
     * Number of pixels that differ (RGBA, exact match). Fully transparent pixels are compared on alpha only.
     */
    public static function diff(\GdImage $a, \GdImage $b): int
    {
        if (imagesx($a) !== imagesx($b) || imagesy($a) !== imagesy($b)) {
            return \PHP_INT_MAX;
        }

        $diff = 0;
        for ($y = 0, $h = imagesy($a); $y < $h; ++$y) {
            for ($x = 0, $w = imagesx($a); $x < $w; ++$x) {
                $ca = imagecolorat($a, $x, $y);
                $cb = imagecolorat($b, $x, $y);
                if ($ca === $cb) {
                    continue;
                }
                if (127 === ($ca >> 24 & 0x7F) && 127 === ($cb >> 24 & 0x7F)) {
                    continue;
                }
                ++$diff;
            }
        }

        return $diff;
    }

    public static function cleanup(): void
    {
        $dir = __DIR__ . '/var';
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }
}
