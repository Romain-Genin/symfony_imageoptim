<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Twig;

use StudioAstra\ImageOptimizerBundle\Responsive\ResponsiveImageRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ImageOptimizerExtension extends AbstractExtension
{
    public function __construct(private readonly ResponsiveImageRenderer $renderer)
    {
    }

    public function getFunctions(): array
    {
        return [
            // {{ responsive_image('uploads/photo.jpg', {alt: 'Photo', sizes: '(min-width: 768px) 50vw, 100vw'}) }}
            new TwigFunction('responsive_image', $this->renderer->render(...), ['is_safe' => ['html']]),
            // {{ image_variant('uploads/photo.jpg', 640, 'webp') }}
            new TwigFunction('image_variant', $this->renderer->variantUrl(...)),
            // {{ image_auto('uploads/photo.jpg') }} (format + width negotiated per device)
            new TwigFunction('image_auto', $this->renderer->autoUrl(...)),
        ];
    }
}
