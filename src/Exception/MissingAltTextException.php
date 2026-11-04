<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Exception;

/**
 * Thrown (when image_optimizer.responsive.require_alt is enabled, by default in debug)
 * when an image is rendered without a text alternative and without being marked decorative.
 */
final class MissingAltTextException extends \LogicException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf(
            'Image "%s" has no text alternative (WCAG 1.1.1). Pass {alt: "…"} describing it, '
            . 'or {decorative: true} if it is purely decorative. You can also embed an IPTC '
            . '"Alt Text (Accessibility)" in the file.',
            $path,
        ));
    }

    public static function decorativeWithAlt(string $path): self
    {
        return new self(sprintf('Image "%s" is marked decorative but has a non-empty alt text: choose one.', $path));
    }
}
