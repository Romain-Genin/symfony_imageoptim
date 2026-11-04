<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Optimizer;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StudioAstra\ImageOptimizerBundle\FormatDetector;
use StudioAstra\ImageOptimizerBundle\ImageFormat;
use StudioAstra\ImageOptimizerBundle\Metadata\AccessibilityMetadata;
use StudioAstra\ImageOptimizerBundle\Metadata\AccessibilityMetadataReader;
use StudioAstra\ImageOptimizerBundle\Metadata\PngFile;
use StudioAstra\ImageOptimizerBundle\Metadata\WebpFile;
use StudioAstra\ImageOptimizerBundle\Optimizer\Format\FormatOptimizerInterface;

final class ImageOptimizer implements ImageOptimizerInterface
{
    /**
     * @param iterable<FormatOptimizerInterface> $optimizers
     */
    public function __construct(
        private readonly iterable $optimizers,
        private readonly LosslessConverter $converter,
        private readonly OptimizerOptions $options = new OptimizerOptions(),
        private readonly FormatDetector $detector = new FormatDetector(),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly AccessibilityMetadataReader $accessibilityReader = new AccessibilityMetadataReader(),
    ) {
    }

    public function optimize(string $path, bool $allowConversion = true): OptimizationResult
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : 0;
        $format = $size > 0 ? $this->detector->detect($path) : null;

        if (null === $format) {
            return OptimizationResult::skipped($path, null, $size, 'not a supported image');
        }

        try {
            $result = $this->doOptimize($path, $format, $size, $allowConversion);
        } catch (\Throwable $e) {
            $this->logger->error('Image optimization failed for "{path}": {message}', ['path' => $path, 'message' => $e->getMessage(), 'exception' => $e]);

            return OptimizationResult::skipped($path, $format, $size, 'error: ' . $e->getMessage());
        }

        if ($result->isOptimized()) {
            $this->logger->info('Image optimized: {path} {from} -> {to}, {before} -> {after} bytes (-{ratio}%)', [
                'path' => $path,
                'from' => $result->originalFormat?->value,
                'to' => $result->format?->value,
                'before' => $result->originalSize,
                'after' => $result->size,
                'ratio' => round($result->savedRatio() * 100, 1),
            ]);
        }

        return $result;
    }

    private function doOptimize(string $path, ImageFormat $format, int $size, bool $allowConversion): OptimizationResult
    {
        $dimensions = ImageFormat::Svg === $format ? null : @getimagesize($path);
        if (\is_array($dimensions) && $dimensions[0] * $dimensions[1] > $this->options->maxPixels) {
            return OptimizationResult::skipped($path, $format, $size, 'image too large');
        }

        $accessibility = $this->options->keepAccessibilityMetadata ? $this->accessibilityReader->read($path) : null;
        $workspace = new Workspace();
        $bestPath = $path;
        $bestSize = $size;
        $bestFormat = $format;
        $steps = [];

        // 1. Lossless optimization in the original format.
        if (null !== ($candidate = $this->runOptimizer($format, $path, $workspace))) {
            [$candidatePath, $step] = $candidate;
            $candidateSize = (int) filesize($candidatePath);
            if ($candidateSize > 0 && $candidateSize < $bestSize && $this->isSameImage($candidatePath, $format, $dimensions)) {
                [$bestPath, $bestSize] = [$candidatePath, $candidateSize];
                $steps[] = $step;
            }
        }

        // 2. Lossless conversion to a more efficient format, kept only if it is significantly smaller.
        $target = $allowConversion ? $this->options->conversionTarget($format) : null;
        if (null !== $target && $this->converter->supports($format, $target)) {
            $converted = $workspace->path($target->extension());
            $step = $this->converter->convert($bestPath, $format, $converted, $target, $this->options);

            if (null !== $step && is_file($converted)) {
                $convertedSteps = [$step];
                if (null !== ($candidate = $this->runOptimizer($target, $converted, $workspace))) {
                    [$optimizedPath, $optimizedStep] = $candidate;
                    if ((int) filesize($optimizedPath) > 0 && filesize($optimizedPath) < filesize($converted)) {
                        $converted = $optimizedPath;
                        $convertedSteps[] = $optimizedStep;
                    }
                }

                if (null !== $accessibility) {
                    $this->embedAccessibility($converted, $target, $accessibility);
                }

                clearstatcache(true, $converted);
                $convertedSize = (int) filesize($converted);
                if ($convertedSize > 0
                    && $convertedSize <= $bestSize * (1 - $this->options->minConversionGain)
                    && $this->isSameImage($converted, $target, $dimensions)
                ) {
                    [$bestPath, $bestSize, $bestFormat] = [$converted, $convertedSize, $target];
                    array_push($steps, ...$convertedSteps);
                }
            }
        }

        if ($bestPath !== $path) {
            // Overwrite the content (not the inode): keeps permissions and is_uploaded_file() validity.
            if (false === @copy($bestPath, $path)) {
                throw new \RuntimeException(sprintf('Unable to write "%s".', $path));
            }
            clearstatcache(true, $path);
        }

        $workspace->cleanup();

        return new OptimizationResult($path, $format, $bestFormat, $size, $bestSize, $steps);
    }

    /**
     * Converters do not carry XMP over: re-attach the text alternatives to the new file.
     */
    private function embedAccessibility(string $path, ImageFormat $format, AccessibilityMetadata $metadata): void
    {
        $bytes = (string) file_get_contents($path);
        $updated = match ($format) {
            ImageFormat::Webp => WebpFile::parse($bytes)?->withXmp($metadata->toXmp())?->toBytes(),
            ImageFormat::Png => PngFile::parse($bytes)?->stripMetadata(xmp: $metadata->toXmp())->toBytes(),
            default => null,
        };

        if (null !== $updated) {
            file_put_contents($path, $updated);
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function runOptimizer(ImageFormat $format, string $source, Workspace $workspace): ?array
    {
        foreach ($this->optimizers as $optimizer) {
            if (!$optimizer->supports($format)) {
                continue;
            }
            $target = $workspace->path($format->extension());
            $step = $optimizer->optimize($source, $target, $this->options);
            clearstatcache(true, $target);

            if (null !== $step && is_file($target) && filesize($target) > 0) {
                return [$target, $step];
            }
        }

        return null;
    }

    /**
     * Sanity check: the candidate must be a valid image of the expected format and size
     * (orientation-aware: a lossless rotation may swap width and height).
     *
     * @param array<int|string, mixed>|false|null $dimensions
     */
    private function isSameImage(string $candidate, ImageFormat $expectedFormat, array|false|null $dimensions): bool
    {
        if ($this->detector->detect($candidate) !== $expectedFormat) {
            return false;
        }
        if (!\is_array($dimensions)) {
            return true;
        }

        $new = @getimagesize($candidate);
        if (!\is_array($new)) {
            // getimagesize() does not know every format (e.g. AVIF on old PHP versions).
            return !\in_array($expectedFormat, [ImageFormat::Jpeg, ImageFormat::Png, ImageFormat::Gif, ImageFormat::Webp], true);
        }

        return [$new[0], $new[1]] === [$dimensions[0], $dimensions[1]]
            || [$new[0], $new[1]] === [$dimensions[1], $dimensions[0]];
    }
}
