<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\EventListener;

use StudioAstra\ImageOptimizerBundle\Upload\UploadedFileOptimizer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Optimizes every image uploaded in the request (forms, API Platform, VichUploader,
 * plain controllers, multiple/nested file fields...) before the application sees it.
 *
 * Registered on kernel.request, after the firewall (so anonymous requests rejected by
 * security never cost CPU) and before API Platform's deserialization and the controller.
 */
final class OptimizeUploadedImagesListener
{
    /**
     * @param list<string> $paths         regexes of request paths to handle (empty = all)
     * @param list<string> $excludedPaths regexes of request paths to ignore
     */
    public function __construct(
        private readonly UploadedFileOptimizer $optimizer,
        private readonly array $paths = [],
        private readonly array $excludedPaths = [],
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (0 === $request->files->count() || !$this->matches($request->getPathInfo())) {
            return;
        }

        foreach ($request->files->all() as $key => $value) {
            $request->files->set($key, $this->process($value));
        }
    }

    private function process(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return $this->optimizer->optimize($value);
        }

        if (\is_array($value)) {
            return array_map($this->process(...), $value);
        }

        return $value;
    }

    private function matches(string $path): bool
    {
        foreach ($this->excludedPaths as $pattern) {
            if (preg_match('{' . $pattern . '}', $path)) {
                return false;
            }
        }

        if ([] === $this->paths) {
            return true;
        }

        foreach ($this->paths as $pattern) {
            if (preg_match('{' . $pattern . '}', $path)) {
                return true;
            }
        }

        return false;
    }
}
