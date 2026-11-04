<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Routing;

use StudioAstra\ImageOptimizerBundle\Controller\ImageVariantController;
use StudioAstra\ImageOptimizerBundle\Responsive\ResponsiveImageRenderer;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Usage (config/routes/image_optimizer.yaml):
 *
 *     image_optimizer:
 *         resource: .
 *         type: image_optimizer
 */
final class ImageRouteLoader extends Loader
{
    public function __construct(private readonly string $prefix)
    {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $prefix = '/' . trim($this->prefix, '/');
        $routes = new RouteCollection();

        $routes->add(ResponsiveImageRenderer::ROUTE_AUTO, (new Route(
            $prefix . '/auto/{path}',
            ['_controller' => ImageVariantController::class . '::auto'],
            ['path' => '.+'],
        ))->setMethods(['GET', 'HEAD']));

        $routes->add(ResponsiveImageRenderer::ROUTE_VARIANT, (new Route(
            $prefix . '/{width}/{path}.{format}',
            ['_controller' => ImageVariantController::class . '::variant'],
            ['width' => '\d+', 'path' => '.+', 'format' => 'avif|webp|jpg|png'],
        ))->setMethods(['GET', 'HEAD']));

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'image_optimizer' === $type;
    }
}
