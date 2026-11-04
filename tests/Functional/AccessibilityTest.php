<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Tests\Functional;

use RomainGenin\ImageOptimizerBundle\Binary\BinaryRunner;
use RomainGenin\ImageOptimizerBundle\Exception\MissingAltTextException;
use RomainGenin\ImageOptimizerBundle\Responsive\ResponsiveImageRenderer;
use RomainGenin\ImageOptimizerBundle\Tests\ImageFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

final class AccessibilityTest extends WebTestCase
{
    private static string $workDir;

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel(self::$workDir);
    }

    public static function setUpBeforeClass(): void
    {
        self::$workDir = ImageFactory::dir() . '/a11y-app';
        @mkdir(self::$workDir . '/public/uploads', 0o777, true);
        @mkdir(self::$workDir . '/templates', 0o777, true);
        copy(ImageFactory::jpeg(ImageFactory::photo(800, 600)), self::$workDir . '/public/uploads/photo.jpg');
    }

    public static function tearDownAfterClass(): void
    {
        ImageFactory::cleanup();
    }

    private function renderer(): ResponsiveImageRenderer
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        return self::getContainer()->get(ResponsiveImageRenderer::class);
    }

    public function testMissingAltTextIsRejected(): void
    {
        $this->expectException(MissingAltTextException::class);
        $this->expectExceptionMessage('WCAG 1.1.1');

        $this->renderer()->render('uploads/photo.jpg');
    }

    public function testEmptyAltIsNotMistakenForDecorative(): void
    {
        $this->expectException(MissingAltTextException::class);

        // Typically {alt: product.imageAlt} with an empty database value.
        $this->renderer()->render('uploads/photo.jpg', ['alt' => '  ']);
    }

    public function testDecorativeImagesGetEmptyAlt(): void
    {
        $html = $this->renderer()->render('uploads/photo.jpg', ['decorative' => true]);

        self::assertStringContainsString(' alt=""', $html);
        self::assertStringNotContainsString('decorative', $html);
    }

    public function testDecorativeWithAltIsContradictory(): void
    {
        $this->expectException(MissingAltTextException::class);
        $this->renderer()->render('uploads/photo.jpg', ['decorative' => true, 'alt' => 'A photo']);
    }

    public function testAltTextEmbeddedInTheFileIsUsedInTheCurrentLocale(): void
    {
        $path = self::$workDir . '/public/uploads/cat.jpg';
        copy(ImageFactory::jpeg(ImageFactory::photo()), $path);
        ImageFactory::addJpegXmp($path, ImageFactory::accessibilityXmp());

        $renderer = $this->renderer();
        self::assertStringContainsString('alt="A black cat on a red sofa"', $renderer->render('uploads/cat.jpg'));

        $request = Request::create('/');
        $request->setLocale('fr');
        self::getContainer()->get('request_stack')->push($request);
        self::assertStringContainsString('alt="Un chat noir sur un canapé rouge"', $renderer->render('uploads/cat.jpg'));

        // An explicit alt always wins.
        self::assertStringContainsString('alt="Minou"', $renderer->render('uploads/cat.jpg', ['alt' => 'Minou']));

        self::assertSame('The cat is sleeping, curled up, next to a blue cushion.', $renderer->accessibility('uploads/cat.jpg')?->extendedDescription());
    }

    public function testAnimatedImagesHaveAStaticAlternativeForReducedMotion(): void
    {
        $gif = ImageFactory::animatedGif();
        if (null === $gif) {
            self::markTestSkipped('gifsicle is needed to build an animated GIF fixture');
        }
        copy($gif, self::$workDir . '/public/uploads/anim.gif');

        $html = $this->renderer()->render('uploads/anim.gif', ['alt' => 'Loading animation']);

        self::assertStringStartsWith('<picture><source media="(prefers-reduced-motion: reduce)" type="image/webp" srcset="/_image/320/uploads/anim.gif.webp 120w"', $html);
        self::assertStringContainsString('<img src="/uploads/anim.gif" width="120" height="80" alt="Loading animation"', $html);

        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request('GET', '/_image/320/uploads/anim.gif.webp');
        self::assertResponseIsSuccessful();

        $static = self::$workDir . '/public/_image/320/uploads/anim.gif.webp';
        self::assertStringNotContainsString('ANIM', (string) file_get_contents($static), 'The reduced-motion variant must not be animated');
        self::assertSame([120, 80], \array_slice(getimagesize($static), 0, 2));
    }

    public function testAnimatedWebpUsesItsFirstFrame(): void
    {
        $gif = ImageFactory::animatedGif();
        $runner = new BinaryRunner();
        if (null === $gif || !$runner->has('webpmux')) {
            self::markTestSkipped('gifsicle, gif2webp and webpmux are needed');
        }
        $webp = self::$workDir . '/public/uploads/anim.webp';
        if (!$runner->run('gif2webp', ['-quiet', $gif, '-o', $webp])) {
            self::markTestSkipped('gif2webp is needed');
        }

        self::ensureKernelShutdown();
        $client = self::createClient();
        $client->request('GET', '/_image/320/uploads/anim.webp.webp');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('ANIM', (string) file_get_contents(self::$workDir . '/public/_image/320/uploads/anim.webp.webp'));
    }
}
