<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use StudioAstra\ImageOptimizerBundle\ImageOptimizerBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ConfigurationTest extends TestCase
{
    private function process(array $config): array
    {
        $extension = (new ImageOptimizerBundle())->getContainerExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());

        return (new Processor())->processConfiguration($configuration, [$config]);
    }

    public function testDefaults(): void
    {
        $config = $this->process([]);

        self::assertTrue($config['upload']['enabled']);
        self::assertSame(['png' => 'webp', 'gif' => 'webp', 'bmp' => 'webp', 'tiff' => 'webp'], $config['optimization']['conversions']);
        self::assertSame(['avif', 'webp'], $config['responsive']['formats']);
        self::assertNull($config['binaries']['jpegtran']);
    }

    public function testDisablingOneConversionKeepsTheOthers(): void
    {
        $config = $this->process(['optimization' => ['conversions' => ['png' => false]]]);

        self::assertSame(['png' => false, 'gif' => 'webp', 'bmp' => 'webp', 'tiff' => 'webp'], $config['optimization']['conversions']);
    }

    public function testLossyConversionsAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process(['optimization' => ['conversions' => ['jpeg' => 'webp']]]);
    }
}
