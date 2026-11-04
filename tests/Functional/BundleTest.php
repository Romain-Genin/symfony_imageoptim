<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Tests\Functional;

use StudioAstra\ImageOptimizerBundle\Binary\BinaryRunner;
use StudioAstra\ImageOptimizerBundle\FormatDetector;
use StudioAstra\ImageOptimizerBundle\ImageFormat;
use StudioAstra\ImageOptimizerBundle\Optimizer\ImageOptimizerInterface;
use StudioAstra\ImageOptimizerBundle\Tests\ImageFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

final class BundleTest extends WebTestCase
{
    private static string $workDir;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel(self::$workDir);
    }

    public static function setUpBeforeClass(): void
    {
        self::$workDir = ImageFactory::dir() . '/app';
        @mkdir(self::$workDir . '/public/uploads', 0o777, true);
        @mkdir(self::$workDir . '/templates', 0o777, true);
    }

    public static function tearDownAfterClass(): void
    {
        ImageFactory::cleanup();
    }

    private function client(): KernelBrowser
    {
        self::ensureKernelShutdown();

        return self::createClient();
    }

    public function testServiceIsAvailable(): void
    {
        self::bootKernel();
        self::assertInstanceOf(ImageOptimizerInterface::class, self::getContainer()->get(ImageOptimizerInterface::class));
    }

    public function testEveryUploadedImageIsOptimizedBeforeReachingTheController(): void
    {
        $client = $this->client();

        $png = ImageFactory::png(ImageFactory::graphic(alpha: false));
        $jpeg = ImageFactory::jpeg(ImageFactory::photo());
        ImageFactory::addJpegMetadata($jpeg);
        $text = ImageFactory::path('notes.txt');
        file_put_contents($text, 'hello');
        $jpegSize = filesize($jpeg);

        $client->request('POST', '/upload', [], [
            'logo' => new UploadedFile($png, 'logo.PNG', 'image/png', null, true),
            // Nested, like Symfony forms: product[gallery][0]
            'product' => ['gallery' => [new UploadedFile($jpeg, 'IMG_0001.jpeg', 'application/octet-stream', null, true)]],
            'doc' => new UploadedFile($text, 'notes.txt', 'text/plain', null, true),
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('logo.webp', $data['logo']['name']);
        self::assertSame('image/webp', $data['logo']['mime']);
        self::assertSame('webp', $data['logo']['guessed']);
        self::assertTrue($data['logo']['valid']);

        self::assertSame('IMG_0001.jpeg', $data[0]['name']);
        self::assertLessThan($jpegSize, $data[0]['size']);

        self::assertSame('notes.txt', $data['doc']['name']);
        self::assertSame('hello', file_get_contents($text));
    }

    public function testResponsiveImageMarkupAndVariants(): void
    {
        $client = $this->client();
        $source = self::$workDir . '/public/uploads/photo.jpg';
        copy(ImageFactory::jpeg(ImageFactory::photo(1000, 750)), $source);

        $client->request('GET', '/page', ['tpl' => "{{ responsive_image('uploads/photo.jpg', {alt: 'Une photo', sizes: '(min-width: 800px) 50vw, 100vw', class: 'hero'}) }}"]);
        $html = (string) $client->getResponse()->getContent();

        // AVIF is offered first when this server can encode it (GD with libavif, or avifenc).
        $avif = self::avifAvailable();
        self::assertStringStartsWith($avif ? '<picture><source type="image/avif" srcset="/_image/320/uploads/photo.jpg.avif 320w, ' : '<picture><source type="image/webp"', $html);
        self::assertStringContainsString('<source type="image/webp" srcset="/_image/320/uploads/photo.jpg.webp 320w, ', $html);
        self::assertStringContainsString('/_image/1280/uploads/photo.jpg.webp 1000w"', $html, 'No upscaling: largest candidate is the source width');
        self::assertStringContainsString('sizes="(min-width: 800px) 50vw, 100vw"', $html);
        self::assertStringContainsString('<img src="/_image/1280/uploads/photo.jpg.jpg"', $html);
        self::assertStringContainsString('width="1000" height="750" alt="Une photo" class="hero" loading="lazy" decoding="async"', $html);
        self::assertSame('Sec-CH-Width, Sec-CH-DPR, Sec-CH-Viewport-Width', $client->getResponse()->headers->get('Accept-CH'));

        $client->request('GET', '/_image/480/uploads/photo.jpg.webp');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/webp');
        $variant = self::$workDir . '/public/_image/480/uploads/photo.jpg.webp';
        self::assertFileExists($variant, 'Variant is stored where its URL points (served statically afterwards)');
        self::assertSame([480, 360], \array_slice(getimagesize($variant), 0, 2));

        $client->request('GET', '/_image/1280/uploads/photo.jpg.jpg');
        self::assertResponseIsSuccessful();
        self::assertSame([1000, 750], \array_slice(getimagesize(self::$workDir . '/public/_image/1280/uploads/photo.jpg.jpg'), 0, 2));
    }

    public function testAutoUrlUsesAcceptHeaderAndClientHints(): void
    {
        $client = $this->client();
        copy(ImageFactory::png(ImageFactory::graphic(alpha: true)), self::$workDir . '/public/uploads/logo.png');
        copy(ImageFactory::jpeg(ImageFactory::photo(1600, 900)), self::$workDir . '/public/uploads/wide.jpg');

        $client->request('GET', '/_image/auto/uploads/wide.jpg', [], [], [
            'HTTP_ACCEPT' => 'image/webp,*/*',
            'HTTP_SEC_CH_VIEWPORT_WIDTH' => '390',
            'HTTP_SEC_CH_DPR' => '3',
        ]);
        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertStringContainsString('Accept', (string) $response->headers->get('Vary'));
        // 390 CSS px * 3 = 1170 physical px -> 1280 allowed width.
        self::assertSame(1280, getimagesizefromstring((string) file_get_contents($response->getFile()->getPathname()))[0]);

        $client->request('GET', '/_image/auto/uploads/logo.png', [], [], ['HTTP_ACCEPT' => 'image/png']);
        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
    }

    public function testAvifIsPreferredWhenSupported(): void
    {
        if (!self::avifAvailable()) {
            self::markTestSkipped('No AVIF encoder (GD with libavif or avifenc) on this machine');
        }

        $client = $this->client();
        copy(ImageFactory::jpeg(ImageFactory::photo(800, 450)), self::$workDir . '/public/uploads/avif.jpg');

        $client->request('GET', '/_image/auto/uploads/avif.jpg', [], [], ['HTTP_ACCEPT' => 'image/avif,image/webp,*/*']);
        self::assertResponseIsSuccessful();
        self::assertSame('image/avif', $client->getResponse()->headers->get('Content-Type'));
        self::assertSame(ImageFormat::Avif, (new FormatDetector())->detect($client->getResponse()->getFile()->getPathname()));
    }

    private static function avifAvailable(): bool
    {
        return (\function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false)) || (new BinaryRunner())->has('avifenc');
    }

    public function testVariantSecurity(): void
    {
        $client = $this->client();
        copy(ImageFactory::jpeg(ImageFactory::photo()), self::$workDir . '/public/uploads/a.jpg');
        file_put_contents(self::$workDir . '/secret.jpg', file_get_contents(self::$workDir . '/public/uploads/a.jpg'));

        foreach ([
            '/_image/333/uploads/a.jpg.webp',               // width not allowed
            '/_image/320/uploads/missing.jpg.webp',         // missing
            '/_image/320/uploads/../../secret.jpg.webp',    // traversal
            '/_image/320/uploads/%2E%2E/%2E%2E/secret.jpg.webp',
            '/_image/320/_image/320/uploads/a.jpg.webp.webp', // variant of variant
        ] as $url) {
            $client->request('GET', $url);
            self::assertSame(404, $client->getResponse()->getStatusCode(), $url);
        }
    }

    public function testLosslessSourceGivesLosslessVariant(): void
    {
        $client = $this->client();
        copy(ImageFactory::png(ImageFactory::graphic(alpha: true)), self::$workDir . '/public/uploads/icon.png');

        $client->request('GET', '/_image/320/uploads/icon.png.webp');
        self::assertResponseIsSuccessful();

        $file = self::$workDir . '/public/_image/320/uploads/icon.png.webp';
        self::assertSame(ImageFormat::Webp, (new FormatDetector())->detect($file));
        self::assertStringContainsString('VP8L', (string) file_get_contents($file, false, null, 0, 64), 'lossless WebP bitstream');
    }
}
