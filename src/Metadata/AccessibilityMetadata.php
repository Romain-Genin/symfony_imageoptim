<?php

declare(strict_types=1);

namespace RomainGenin\ImageOptimizerBundle\Metadata;

/**
 * IPTC Photo Metadata accessibility fields (2021.1+), stored in XMP:
 *  - Iptc4xmpCore:AltTextAccessibility  -> short text alternative ("alt")
 *  - Iptc4xmpCore:ExtDescrAccessibility -> extended description (charts, complex images)
 *
 * Both are language alternatives: ['x-default' => '...', 'fr' => '...'].
 */
final class AccessibilityMetadata
{
    public const IPTC_NS = 'http://iptc.org/std/Iptc4xmpCore/1.0/xmlns/';
    private const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /**
     * @param array<string, string> $altText             language => text
     * @param array<string, string> $extendedDescription language => text
     */
    public function __construct(
        public readonly array $altText = [],
        public readonly array $extendedDescription = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->altText && [] === $this->extendedDescription;
    }

    public function altText(?string $locale = null): ?string
    {
        return self::pick($this->altText, $locale);
    }

    public function extendedDescription(?string $locale = null): ?string
    {
        return self::pick($this->extendedDescription, $locale);
    }

    public static function fromXmp(?string $xmp): ?self
    {
        if (null === $xmp || !str_contains($xmp, 'Accessibility')) {
            return null;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML(trim($xmp), \LIBXML_NONET | \LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $metadata = new self(
            self::readLangAlt($document, 'AltTextAccessibility'),
            self::readLangAlt($document, 'ExtDescrAccessibility'),
        );

        return $metadata->isEmpty() ? null : $metadata;
    }

    /**
     * Minimal XMP packet containing only the accessibility fields (no personal data).
     */
    public function toXmp(): string
    {
        $fields = '';
        foreach (['AltTextAccessibility' => $this->altText, 'ExtDescrAccessibility' => $this->extendedDescription] as $name => $values) {
            if ([] === $values) {
                continue;
            }
            $items = '';
            foreach ($values as $lang => $text) {
                $items .= sprintf('<rdf:li xml:lang="%s">%s</rdf:li>', self::escape((string) $lang), self::escape($text));
            }
            $fields .= sprintf('<Iptc4xmpCore:%1$s><rdf:Alt>%2$s</rdf:Alt></Iptc4xmpCore:%1$s>', $name, $items);
        }

        return '<?xpacket begin="' . "\u{FEFF}" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="' . self::RDF_NS . '">'
            . '<rdf:Description rdf:about="" xmlns:Iptc4xmpCore="' . self::IPTC_NS . '">' . $fields . '</rdf:Description>'
            . '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';
    }

    /**
     * @return array<string, string>
     */
    private static function readLangAlt(\DOMDocument $document, string $property): array
    {
        $values = [];

        foreach ($document->getElementsByTagNameNS(self::IPTC_NS, $property) as $element) {
            foreach ($element->getElementsByTagNameNS(self::RDF_NS, 'li') as $li) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $li->textContent));
                if ('' !== $text) {
                    $lang = $li->getAttributeNS(self::XML_NS, 'lang') ?: 'x-default';
                    $values[$lang] ??= $text;
                }
            }
            // Simple (non lang-alt) value written by some tools.
            if ([] === $values && '' !== ($text = trim($element->textContent))) {
                $values['x-default'] = $text;
            }
        }

        // Attribute form: <rdf:Description Iptc4xmpCore:AltTextAccessibility="...">
        if ([] === $values) {
            foreach ($document->getElementsByTagNameNS(self::RDF_NS, 'Description') as $description) {
                $text = trim($description->getAttributeNS(self::IPTC_NS, $property));
                if ('' !== $text) {
                    $values['x-default'] = $text;
                    break;
                }
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     */
    private static function pick(array $values, ?string $locale): ?string
    {
        if ([] === $values) {
            return null;
        }

        if (null !== $locale && '' !== $locale) {
            $wanted = strtolower(str_replace('_', '-', $locale));
            $language = explode('-', $wanted)[0];
            foreach ([$wanted, $language] as $candidate) {
                foreach ($values as $lang => $text) {
                    if (strtolower((string) $lang) === $candidate) {
                        return $text;
                    }
                }
            }
            foreach ($values as $lang => $text) {
                if (str_starts_with(strtolower((string) $lang), $language . '-')) {
                    return $text;
                }
            }
        }

        return $values['x-default'] ?? reset($values);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
