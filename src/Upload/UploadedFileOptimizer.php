<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Upload;

use StudioAstra\ImageOptimizerBundle\Optimizer\ImageOptimizerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Optimizes an UploadedFile in place (its temporary file) and, when the format changed,
 * returns a new UploadedFile carrying the right client name / MIME type.
 *
 * The temporary path is preserved, so is_uploaded_file()/move_uploaded_file() keep working.
 */
final class UploadedFileOptimizer
{
    public function __construct(
        private readonly ImageOptimizerInterface $optimizer,
        private readonly bool $allowConversion = true,
        private readonly int $maxFileSize = 52_428_800,
    ) {
    }

    public function optimize(UploadedFile $file, ?bool $allowConversion = null): UploadedFile
    {
        if (!$file->isValid() || ($this->maxFileSize > 0 && $file->getSize() > $this->maxFileSize)) {
            return $file;
        }

        $result = $this->optimizer->optimize($file->getPathname(), $allowConversion ?? $this->allowConversion);

        if (!$result->isConverted() || null === $result->format) {
            return $file;
        }

        return new UploadedFile(
            $file->getPathname(),
            self::replaceExtension($file->getClientOriginalName(), $result->format->extension()),
            $result->format->mimeType(),
            $file->getError(),
            self::isTestFile($file),
        );
    }

    public static function replaceExtension(string $name, string $extension): string
    {
        $base = pathinfo($name, \PATHINFO_FILENAME);

        return ('' === $base ? 'image' : $base) . '.' . $extension;
    }

    private static function isTestFile(UploadedFile $file): bool
    {
        $property = new \ReflectionProperty(UploadedFile::class, 'test');

        return (bool) $property->getValue($file);
    }
}
