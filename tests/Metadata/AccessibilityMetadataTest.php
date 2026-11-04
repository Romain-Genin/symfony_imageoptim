<?php

declare(strict_types=1);

namespace StudioAstra\ImageOptimizerBundle\Tests\Metadata;

use PHPUnit\Framework\TestCase;
use StudioAstra\ImageOptimizerBundle\Metadata\AccessibilityMetadata;
use StudioAstra\ImageOptimizerBundle\Metadata\AccessibilityMetadataReader;
use StudioAstra\ImageOptimizerBundle\Tests\ImageFactory;

final class AccessibilityMetadataTest extends TestCase
{
    public static function tearDownAfterClass(): void
    {
        ImageFactory::cleanup();
    }

    public function testParsesIptcLanguageAlternatives(): void
    {
        $metadata = AccessibilityMetadata::fromXmp(ImageFactory::accessibilityXmp());

        self::assertNotNull($metadata);
        self::assertSame('A black cat on a red sofa', $metadata->altText());
        self::assertSame('Un chat noir sur un canapé rouge', $metadata->altText('fr_FR'));
        self::assertSame('Un chat noir sur un canapé rouge', $metadata->altText('fr'));
        self::assertSame('A black cat on a red sofa', $metadata->altText('de'));
        self::assertSame('The cat is sleeping, curled up, next to a blue cushion.', $metadata->extendedDescription('fr'));
    }

    public function testMinimalXmpKeepsOnlyAccessibilityFields(): void
    {
        $xmp = AccessibilityMetadata::fromXmp(ImageFactory::accessibilityXmp())?->toXmp();

        self::assertNotNull($xmp);
        self::assertStringNotContainsString('jane@example.com', $xmp);
        self::assertStringNotContainsString('GPS', $xmp);
        self::assertEquals(AccessibilityMetadata::fromXmp(ImageFactory::accessibilityXmp()), AccessibilityMetadata::fromXmp($xmp));
    }

    public function testAttributeFormAndNoise(): void
    {
        $xmp = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:Iptc4xmpCore="http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/" Iptc4xmpCore:AltTextAccessibility="Logo"/>'
            . '</rdf:RDF></x:xmpmeta>';

        self::assertSame('Logo', AccessibilityMetadata::fromXmp($xmp)?->altText());
        self::assertNull(AccessibilityMetadata::fromXmp('<x:xmpmeta>no accessibility here</x:xmpmeta>'));
        self::assertNull(AccessibilityMetadata::fromXmp('Accessibility <not xml'));
    }

    public function testReadsSvgTitle(): void
    {
        $path = ImageFactory::path('icon.svg');
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><title>Accueil</title><desc>Icône maison</desc><rect width="10" height="10"/></svg>');

        $metadata = (new AccessibilityMetadataReader())->read($path);

        self::assertSame('Accueil', $metadata?->altText());
        self::assertSame('Icône maison', $metadata?->extendedDescription());
    }
}
