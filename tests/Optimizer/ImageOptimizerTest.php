<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Tests\Optimizer;

use PHPUnit\Framework\TestCase;
use StudioAstra\ImageOptimizerBundle\Binary\BinaryRunner;
use StudioAstra\ImageOptimizerBundle\FormatDetector;
use StudioAstra\ImageOptimizerBundle\ImageFormat;
use StudioAstra\ImageOptimizerBundle\Metadata\AccessibilityMetadataReader;
use StudioAstra\ImageOptimizerBundle\Metadata\JpegFile;
use StudioAstra\ImageOptimizerBundle\Optimizer\Format\GifOptimizer;
use StudioAstra\ImageOptimizerBundle\Optimizer\Format\JpegOptimizer;
use StudioAstra\ImageOptimizerBundle\Optimizer\Format\PngOptimizer;
use StudioAstra\ImageOptimizerBundle\Optimizer\Format\SvgOptimizer;
use StudioAstra\ImageOptimizerBundle\Optimizer\ImageOptimizer;
use StudioAstra\ImageOptimizerBundle\Optimizer\LosslessConverter;
use StudioAstra\ImageOptimizerBundle\Optimizer\OptimizerOptions;
use StudioAstra\ImageOptimizerBundle\Tests\ImageFactory;

final class ImageOptimizerTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        ImageFactory::cleanup();
    }

    /**
     * @param bool $withBinaries false = pure PHP / GD only (no CLI tool installed)
     */
    private function optimizer(bool $withBinaries = true, ?OptimizerOptions $options = null): ImageOptimizer
    {
        $binaries = new BinaryRunner($withBinaries ? [] : array_fill_keys(['jpegtran', 'oxipng', 'optipng', 'gifsicle', 'gif2webp', 'cwebp', 'avifenc', 'svgo'], false));

        return new ImageOptimizer(
            [new JpegOptimizer($binaries), new PngOptimizer($binaries), new GifOptimizer($binaries), new SvgOptimizer($binaries)],
            new LosslessConverter($binaries),
            $options ?? new OptimizerOptions(),
        );
    }

    public static function binaryModes(): iterable
    {
        yield 'with CLI tools' => [true];
        yield 'pure PHP / GD' => [false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('binaryModes')]
    public function testJpegIsLosslesslyOptimizedAndMetadataStripped(bool $withBinaries): void
    {
        if ($withBinaries && !(new BinaryRunner())->has('jpegtran')) {
            self::markTestSkipped('jpegtran not installed');
        }

        $path = ImageFactory::jpeg(ImageFactory::photo());
        ImageFactory::addJpegMetadata($path);
        $before = ImageFactory::load($path);
        $size = filesize($path);

        $result = $this->optimizer($withBinaries)->optimize($path);

        self::assertTrue($result->isOptimized());
        self::assertSame(ImageFormat::Jpeg, $result->format);
        self::assertLessThan($size, filesize($path));
        self::assertSame(0, ImageFactory::diff($before, ImageFactory::load($path)), 'Pixels must be identical');

        $bytes = (string) file_get_contents($path);
        self::assertStringNotContainsString('GPS-AND-MAKER-NOTES', $bytes);
        self::assertStringNotContainsString('xmpmeta', $bytes);
        self::assertStringNotContainsString('comment comment', $bytes);
        self::assertStringContainsString('ICC_PROFILE', $bytes, 'Colour profile must be kept');
    }

    public function testJpegOrientationIsKeptWithoutJpegtran(): void
    {
        $path = ImageFactory::jpeg(ImageFactory::photo(250, 190));
        ImageFactory::addJpegMetadata($path, orientation: 6);

        $this->optimizer(false)->optimize($path);

        self::assertSame(6, JpegFile::parse((string) file_get_contents($path))?->orientation());
        self::assertStringNotContainsString('GPS-AND-MAKER-NOTES', (string) file_get_contents($path));
    }

    public function testJpegIsLosslesslyRotatedWithJpegtran(): void
    {
        if (!(new BinaryRunner())->has('jpegtran')) {
            self::markTestSkipped('jpegtran not installed');
        }

        // Dimensions multiple of the MCU size: rotation is perfect.
        $path = ImageFactory::jpeg(ImageFactory::photo(256, 192));
        ImageFactory::addJpegMetadata($path, orientation: 6);

        $this->optimizer()->optimize($path);

        self::assertSame(1, JpegFile::parse((string) file_get_contents($path))?->orientation());
        self::assertSame([192, 256], \array_slice(getimagesize($path), 0, 2));
    }

    public function testHdrGainMapJpegIsLeftUntouched(): void
    {
        $path = ImageFactory::jpeg(ImageFactory::photo());
        ImageFactory::addJpegMetadata($path, mpf: true);
        $hash = md5_file($path);

        $result = $this->optimizer()->optimize($path);

        self::assertFalse($result->isOptimized());
        self::assertSame($hash, md5_file($path));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('binaryModes')]
    public function testOpaquePngIsConvertedToLosslessWebp(bool $withBinaries): void
    {
        $path = ImageFactory::png(ImageFactory::graphic(alpha: false));
        ImageFactory::addPngText($path);
        $before = ImageFactory::load($path);

        $result = $this->optimizer($withBinaries)->optimize($path);

        self::assertSame(ImageFormat::Png, $result->originalFormat);
        self::assertSame(ImageFormat::Webp, $result->format);
        self::assertTrue($result->isConverted());
        self::assertSame(ImageFormat::Webp, (new FormatDetector())->detect($path));
        self::assertSame(0, ImageFactory::diff($before, ImageFactory::load($path)));
    }

    public function testTranslucentPngIsConvertedLosslesslyWithCwebp(): void
    {
        if (!(new BinaryRunner())->has('cwebp')) {
            self::markTestSkipped('cwebp not installed');
        }

        $path = ImageFactory::png(ImageFactory::graphic(alpha: true));
        $before = ImageFactory::load($path);

        $result = $this->optimizer()->optimize($path);

        self::assertSame(ImageFormat::Webp, $result->format);
        self::assertSame(0, ImageFactory::diff($before, ImageFactory::load($path)));
    }

    public function testTranslucentPngWithoutCwebpStaysPngButIsOptimized(): void
    {
        $path = ImageFactory::png(ImageFactory::graphic(alpha: true));
        ImageFactory::addPngText($path);
        $before = ImageFactory::load($path);

        $result = $this->optimizer(false)->optimize($path);

        // GD would reduce alpha precision: conversion is refused, PNG is recompressed instead.
        self::assertSame(ImageFormat::Png, $result->format);
        self::assertTrue($result->isOptimized());
        self::assertStringNotContainsString('Made with some editor', (string) file_get_contents($path));
        self::assertSame(0, ImageFactory::diff($before, ImageFactory::load($path)));
    }

    public function testConversionCanBeDisabled(): void
    {
        $path = ImageFactory::png(ImageFactory::graphic(alpha: false));

        $result = $this->optimizer()->optimize($path, allowConversion: false);
        self::assertSame(ImageFormat::Png, $result->format);

        $result = $this->optimizer(options: new OptimizerOptions(conversions: ['png' => false]))->optimize($path);
        self::assertSame(ImageFormat::Png, $result->format);
    }

    public function testStaticGifIsConvertedToWebp(): void
    {
        $image = imagecreate(120, 80);
        imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 10, 10, 60, 60, imagecolorallocate($image, 200, 0, 0));
        $path = ImageFactory::path('anim.gif');
        imagegif($image, $path);
        $before = ImageFactory::load($path);

        $result = $this->optimizer()->optimize($path);

        self::assertSame(ImageFormat::Webp, $result->format);
        self::assertSame(0, ImageFactory::diff($before, ImageFactory::load($path)));
    }

    public function testBmpIsConvertedToWebp(): void
    {
        $path = ImageFactory::path('scan.bmp');
        imagebmp(ImageFactory::graphic(alpha: false), $path, false);
        $before = ImageFactory::load($path);

        $result = $this->optimizer(false)->optimize($path);

        self::assertSame(ImageFormat::Webp, $result->format);
        self::assertLessThan($result->originalSize / 2, $result->size);
        self::assertSame(0, ImageFactory::diff($before, ImageFactory::load($path)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('binaryModes')]
    public function testJpegKeepsAccessibilityTextButNotPersonalData(bool $withBinaries): void
    {
        $path = ImageFactory::jpeg(ImageFactory::photo());
        ImageFactory::addJpegXmp($path, ImageFactory::accessibilityXmp());
        $before = ImageFactory::load($path);

        $this->optimizer($withBinaries)->optimize($path);

        $metadata = (new AccessibilityMetadataReader())->read($path);
        self::assertSame('Un chat noir sur un canapé rouge', $metadata?->altText('fr'));
        self::assertSame('The cat is sleeping, curled up, next to a blue cushion.', $metadata?->extendedDescription());
        self::assertStringNotContainsString('jane@example.com', (string) file_get_contents($path));
        self::assertStringNotContainsString('GPSLatitude', (string) file_get_contents($path));
        self::assertSame(0, ImageFactory::diff($before, ImageFactory::load($path)));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('binaryModes')]
    public function testAccessibilityTextSurvivesPngToWebpConversion(bool $withBinaries): void
    {
        $path = ImageFactory::png(ImageFactory::graphic(alpha: false));
        ImageFactory::addPngXmp($path, ImageFactory::accessibilityXmp());
        $before = ImageFactory::load($path);

        $result = $this->optimizer($withBinaries)->optimize($path);

        self::assertSame(ImageFormat::Webp, $result->format);
        self::assertSame('A black cat on a red sofa', (new AccessibilityMetadataReader())->read($path)?->altText());
        self::assertStringNotContainsString('jane@example.com', (string) file_get_contents($path));
        self::assertSame([200, 120], \array_slice(getimagesize($path), 0, 2));
        self::assertSame(0, ImageFactory::diff($before, ImageFactory::load($path)));
    }

    public function testPngKeepsAccessibilityText(): void
    {
        $path = ImageFactory::png(ImageFactory::graphic(alpha: false));
        ImageFactory::addPngXmp($path, ImageFactory::accessibilityXmp());

        $this->optimizer()->optimize($path, allowConversion: false);

        self::assertSame('A black cat on a red sofa', (new AccessibilityMetadataReader())->read($path)?->altText());
        self::assertStringNotContainsString('jane@example.com', (string) file_get_contents($path));
    }

    public function testAccessibilityMetadataCanBeDropped(): void
    {
        $path = ImageFactory::jpeg(ImageFactory::photo());
        ImageFactory::addJpegXmp($path, ImageFactory::accessibilityXmp());

        $this->optimizer(options: new OptimizerOptions(keepAccessibilityMetadata: false))->optimize($path);

        self::assertNull((new AccessibilityMetadataReader())->read($path));
    }

    public function testNonImagesAreIgnored(): void
    {
        $path = ImageFactory::path('doc.pdf');
        file_put_contents($path, '%PDF-1.4 not an image');

        $result = $this->optimizer()->optimize($path);

        self::assertNull($result->format);
        self::assertSame('not a supported image', $result->skippedReason);
        self::assertSame('%PDF-1.4 not an image', file_get_contents($path));
    }

    public function testCorruptedImageIsLeftUntouched(): void
    {
        $path = ImageFactory::path('broken.jpg');
        file_put_contents($path, "\xFF\xD8\xFF\xE0garbage");

        $result = $this->optimizer()->optimize($path);

        self::assertFalse($result->isOptimized());
        self::assertSame("\xFF\xD8\xFF\xE0garbage", file_get_contents($path));
    }
}
