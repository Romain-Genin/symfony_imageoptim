<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\EventListener;

use RomainGenin\ImageOptimizerBundle\Controller\ImageVariantController;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Asks browsers to send device Client Hints (DPR, viewport and image width) on following
 * requests, so the "auto" image URL can return a file sized for the device.
 */
final class ClientHintsListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if ($response->headers->has('Accept-CH') || !str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'html')) {
            return;
        }

        $response->headers->set('Accept-CH', implode(', ', ImageVariantController::CLIENT_HINTS));
    }
}
