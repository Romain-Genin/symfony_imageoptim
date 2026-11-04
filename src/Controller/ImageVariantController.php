<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Controller;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StudioAstra\ImageOptimizerBundle\ImageFormat;
use StudioAstra\ImageOptimizerBundle\Responsive\ResponsiveImageRenderer;
use StudioAstra\ImageOptimizerBundle\Responsive\VariantGenerator;
use StudioAstra\ImageOptimizerBundle\Responsive\VariantStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Generates variants on first request. The file is stored where its URL points, so
 * subsequent requests are served directly by the web server.
 */
final class ImageVariantController
{
    public const CLIENT_HINTS = ['Sec-CH-Width', 'Sec-CH-DPR', 'Sec-CH-Viewport-Width'];

    public function __construct(
        private readonly VariantStorage $storage,
        private readonly VariantGenerator $generator,
        private readonly ResponsiveImageRenderer $renderer,
        private readonly int $maxAge = 2_592_000,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * /{prefix}/{width}/{path}.{format}
     */
    public function variant(string $width, string $path, string $format): Response
    {
        if (!ctype_digit($width) || !$this->storage->isAllowedWidth((int) $width)) {
            throw new NotFoundHttpException('Width not allowed.');
        }

        try {
            $imageFormat = ImageFormat::fromName($format);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Unknown format.');
        }
        if ($imageFormat->extension() !== $format) {
            throw new NotFoundHttpException('Unknown format.');
        }

        $response = $this->serve($path, (int) $width, $imageFormat);
        $response->setPublic();
        $response->setMaxAge($this->maxAge);

        return $response;
    }

    /**
     * /{prefix}/auto/{path}: picks format from the Accept header and width from Client Hints.
     */
    public function auto(Request $request, string $path): Response
    {
        $source = $this->storage->resolveSource($path) ?? throw new NotFoundHttpException('Image not found.');
        $fallback = $this->renderer->fallbackFormat($source) ?? throw new NotFoundHttpException('Unsupported image.');

        $accept = (string) $request->headers->get('Accept', '');
        $available = $this->generator->availableFormats();
        $format = $fallback;
        foreach ($this->storage->preferredFormats() as $candidate) {
            if (\in_array($candidate, $available, true) && str_contains($accept, $candidate->mimeType())) {
                $format = $candidate;
                break;
            }
        }

        $response = $this->serve($path, $this->storage->closestWidth($this->wantedWidth($request)), $format);
        $response->setPublic();
        $response->setMaxAge($this->maxAge);
        $response->headers->set('Vary', implode(', ', ['Accept', ...self::CLIENT_HINTS]));
        $response->headers->set('Accept-CH', implode(', ', self::CLIENT_HINTS));

        return $response;
    }

    private function wantedWidth(Request $request): int
    {
        $width = (int) ($request->headers->get('Sec-CH-Width') ?? $request->headers->get('Width') ?? 0);
        if ($width > 0) {
            return $width;
        }

        $dpr = (float) ($request->headers->get('Sec-CH-DPR') ?? $request->headers->get('DPR') ?? 1);
        $viewport = (int) ($request->headers->get('Sec-CH-Viewport-Width') ?? $request->headers->get('Viewport-Width') ?? 0);
        if ($viewport > 0) {
            return (int) ceil($viewport * max(1.0, min(4.0, $dpr)));
        }

        $query = $request->query->getInt('w');

        return $query > 0 ? $query : $this->storage->defaultWidth();
    }

    private function serve(string $path, int $width, ImageFormat $format): BinaryFileResponse
    {
        $source = $this->storage->resolveSource($path) ?? throw new NotFoundHttpException('Image not found.');
        if (!$this->generator->supportsSource($source)) {
            throw new NotFoundHttpException('Unsupported image.');
        }
        if (!\in_array($format, $this->generator->availableFormats(), true)) {
            throw new NotFoundHttpException('Format not available.');
        }

        $target = $this->storage->variantPath($path, $width, $format->extension());

        if (!is_file($target) || filemtime($target) < filemtime($source)) {
            try {
                $this->generator->generate($source, $width, $format, $target);
            } catch (\Throwable $e) {
                $this->logger->error('Unable to generate image variant "{path}": {message}', ['path' => $target, 'message' => $e->getMessage(), 'exception' => $e]);

                throw new NotFoundHttpException('Unable to generate image.', $e);
            }
        }

        $response = new BinaryFileResponse($target, 200, ['Content-Type' => $format->mimeType()], true, null, true, true);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
