<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle;

/**
 * Detects the real format of an image from its content (magic bytes),
 * never from the client-provided file name or MIME type.
 */
final class FormatDetector
{
    public function detect(string $path): ?ImageFormat
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return null;
        }

        $head = (string) fread($handle, 4096);
        fclose($handle);

        return $this->detectFromBytes($head);
    }

    public function detectFromBytes(string $head): ?ImageFormat
    {
        if (\strlen($head) < 12) {
            return $this->detectSvg($head);
        }

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => ImageFormat::Jpeg,
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => ImageFormat::Png,
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => ImageFormat::Gif,
            str_starts_with($head, 'RIFF') && 'WEBP' === substr($head, 8, 4) => ImageFormat::Webp,
            str_starts_with($head, 'BM') => ImageFormat::Bmp,
            str_starts_with($head, "II*\x00"), str_starts_with($head, "MM\x00*") => ImageFormat::Tiff,
            'ftyp' === substr($head, 4, 4) => $this->detectIsoBmff($head),
            default => $this->detectSvg($head),
        };
    }

    private function detectIsoBmff(string $head): ?ImageFormat
    {
        $boxSize = unpack('N', substr($head, 0, 4))[1];
        $brands = [substr($head, 8, 4)];
        for ($i = 16; $i + 4 <= min($boxSize, \strlen($head)); $i += 4) {
            $brands[] = substr($head, $i, 4);
        }

        if (array_intersect($brands, ['avif', 'avis'])) {
            return ImageFormat::Avif;
        }
        if (array_intersect($brands, ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'mif1', 'msf1'])) {
            return ImageFormat::Heic;
        }

        return null;
    }

    private function detectSvg(string $head): ?ImageFormat
    {
        $text = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);
        if ('' === $text || '<' !== $text[0]) {
            return null;
        }

        return preg_match('/<svg[\s>]/i', $text) ? ImageFormat::Svg : null;
    }
}
