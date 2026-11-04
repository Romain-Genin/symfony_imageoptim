# ImageOptimizerBundle

[![CI](https://github.com/StudioAstra/ImageOptimizer/actions/workflows/ci.yml/badge.svg)](https://github.com/StudioAstra/ImageOptimizer/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/romain-genin/symfony-image-optimizer-bundle)](https://packagist.org/packages/romain-genin/symfony-image-optimizer-bundle)
[![PHP](https://img.shields.io/packagist/dependency-v/romain-genin/symfony-image-optimizer-bundle/php)](https://packagist.org/packages/romain-genin/symfony-image-optimizer-bundle)
[![License](https://img.shields.io/packagist/l/romain-genin/symfony-image-optimizer-bundle)](LICENSE)

Bundle Symfony qui **optimise automatiquement toutes les images uploadées, sans perte de qualité**,
puis les **sert dans le format et la taille adaptés à chaque appareil** (AVIF/WebP, `srcset`, Client Hints).

- **Peu importe comment l'image arrive** : formulaire Symfony, API Platform, VichUploader, contrôleur maison,
  champs multiples ou imbriqués… Un listener traite chaque `UploadedFile` de la requête **avant** votre code.
- **Peu importe le format** : JPEG, PNG, GIF (animé ou non), BMP, TIFF, WebP, SVG. Le format est détecté
  sur le contenu réel du fichier, jamais sur le nom ou le MIME envoyé par le client.
- **Zéro perte** : aucune étape ne modifie les pixels. Le JPEG n'est jamais ré-encodé, les conversions
  de format sont en WebP *lossless*, et tout ce qui ne peut pas être converti à l'identique est refusé.
- **Selon l'appareil** : `{{ responsive_image() }}` génère un `<picture>` ; le navigateur choisit
  AVIF > WebP > original et la largeur adaptée à l'écran et à la densité de pixels (DPR).
- **Accessible** : texte alternatif obligatoire (ou image explicitement décorative), textes alternatifs
  IPTC conservés dans les fichiers et réutilisés, version fixe des animations pour « réduire les animations ».

## Installation

```bash
composer require romain-genin/symfony-image-optimizer-bundle
```

Avec Symfony Flex le bundle est enregistré automatiquement. Sinon, dans `config/bundles.php` :

```php
RomainGenin\ImageOptimizerBundle\ImageOptimizerBundle::class => ['all' => true],
```

Pour les variantes responsives, importez les routes (`config/routes/image_optimizer.yaml`) :

```yaml
image_optimizer:
    resource: .
    type: image_optimizer
```

C'est tout : les uploads sont optimisés dès maintenant, sans configuration.

### Outils optionnels (recommandés)

Le bundle fonctionne en pur PHP + GD, mais donne de meilleurs résultats avec ces outils
(détectés automatiquement dans le `PATH`) :

| Outil | Paquet Debian/Ubuntu | Apport (toujours sans perte) |
|---|---|---|
| `jpegtran` | `libjpeg-turbo-progs` | JPEG : tables de Huffman optimales, progressif, rotation EXIF sans perte |
| `oxipng` ou `optipng` | `optipng` | PNG : meilleurs filtres/compression |
| `cwebp` / `gif2webp` | `webp` | PNG/GIF/TIFF → WebP lossless (canal alpha 8 bits, profil ICC, GIF animés) |
| `gifsicle` | `gifsicle` | GIF : `-O3` |
| `avifenc` | `libavif-bin` | Variantes AVIF si GD n'a pas le support AVIF |
| `svgo` | `npm i -g svgo` (v4 recommandée) | SVG : minification |
| `webpmux` | `webp` | Version fixe des WebP animés (préférence « réduire les animations ») |

```bash
apt-get install -y libjpeg-turbo-progs optipng webp gifsicle libavif-bin
```

`pngquant`, `jpegoptim --max` ou tout encodage JPEG avec perte ne sont **volontairement pas utilisés**.

## Ce qui est fait à l'upload

| Format reçu | Traitement | Résultat |
|---|---|---|
| JPEG | `jpegtran` (Huffman optimisé + progressif) + suppression des métadonnées EXIF/GPS/XMP/IPTC/commentaires | JPEG, pixels identiques au bit près, en moyenne 10–30 % plus léger |
| JPEG avec orientation EXIF | rotation physique sans perte (`jpegtran -perfect`) ou conservation du tag d'orientation | l'image ne s'affiche jamais de travers |
| JPEG HDR (gain map iPhone / Ultra HDR Android) | laissé intact pour préserver le HDR | — |
| PNG | suppression des chunks texte + recompression zlib -9 + oxipng/optipng, puis conversion en **WebP lossless** si ≥ 5 % plus léger | WebP (souvent 25–50 % plus léger) ou PNG optimisé |
| GIF | gifsicle `-O3`, puis WebP lossless (animations conservées avec `gif2webp`) | WebP ou GIF optimisé |
| BMP / TIFF | conversion WebP lossless | gain énorme (souvent > 90 %) |
| SVG | svgo si installé | SVG minifié |
| Autre chose (PDF, vidéo…) | ignoré | fichier intact |

Toujours conservés : le profil couleur ICC (photos Display P3), l'orientation, la transparence, et les
textes alternatifs IPTC (voir « Accessibilité »). Les autres métadonnées (GPS, auteur, appareil…) sont supprimées.
Une conversion est refusée si elle ne peut pas être exacte (PNG 16 bits, APNG, chunks gamma, CMYK…).

Quand le format change, le bundle remplace l'`UploadedFile` de la requête par une instance pointant sur
le même fichier temporaire mais avec le bon nom et le bon MIME :

```php
$file->getClientOriginalName(); // "logo.webp" (au lieu de "logo.png")
$file->getClientMimeType();     // "image/webp"
$file->guessExtension();        // "webp"
$file->move($dir, $name);       // fonctionne normalement
```

Si votre code doit absolument conserver le format d'origine : `image_optimizer.upload.convert: false`
(l'optimisation sans perte reste active).

## Affichage adapté à l'appareil

```twig
{{ responsive_image('uploads/photo.jpg', {
    alt: 'Coucher de soleil',
    sizes: '(min-width: 1024px) 50vw, 100vw',
    class: 'hero',
    loading: 'eager',
    fetchpriority: 'high',
}) }}
```

produit :

```html
<picture>
  <source type="image/avif" srcset="/_image/320/uploads/photo.jpg.avif 320w, /_image/640/uploads/photo.jpg.avif 640w, …" sizes="(min-width: 1024px) 50vw, 100vw">
  <source type="image/webp" srcset="/_image/320/uploads/photo.jpg.webp 320w, …" sizes="…">
  <img src="/_image/2560/uploads/photo.jpg.jpg" srcset="…" sizes="…" width="4032" height="3024"
       alt="Coucher de soleil" class="hero" loading="eager" decoding="async" fetchpriority="high">
</picture>
```

- Un smartphone en 390 px de large et DPR 3 télécharge ~1280 px en AVIF ; un écran 4K en charge plus ;
  un vieux navigateur reçoit le JPEG.
- `width`/`height` sont renseignés (pas de décalage de mise en page / CLS), jamais d'agrandissement.
- Les variantes sont générées **à la première demande** et écrites dans `public/_image/…`, exactement là où
  pointe leur URL : les requêtes suivantes sont servies directement par le serveur web (Nginx/Apache),
  sans PHP.
- Les sources sans perte (PNG, GIF, WebP lossless) donnent des variantes WebP/AVIF sans perte.
  Les photos redimensionnées sont encodées en haute qualité (`quality`, réglable). **L'original n'est jamais modifié.**
- SVG : `<img>` simple avec l'original. Images animées : l'original, plus une version fixe (voir ci-dessous).

Autres fonctions Twig :

```twig
{{ image_variant('uploads/photo.jpg', 640, 'webp') }}  {# URL d'une variante précise #}
{{ image_auto('uploads/photo.jpg') }}                  {# URL négociée côté serveur #}
```

`image_auto()` choisit le format via l'en-tête `Accept` et la largeur via les Client Hints
(`Sec-CH-Width`, `Sec-CH-DPR`, `Sec-CH-Viewport-Width`) — utile pour les `background-image` CSS,
Open Graph, e-mails… Le bundle ajoute `Accept-CH` sur les réponses HTML pour que Chrome/Edge envoient
ces informations. Sans Client Hints, `default_width` est utilisée.

## Accessibilité

### Texte alternatif obligatoire (WCAG 1.1.1)

`responsive_image()` exige de choisir explicitement :

```twig
{{ responsive_image('uploads/chat.jpg', {alt: 'Un chat noir sur un canapé rouge'}) }}
{{ responsive_image('uploads/motif.png', {decorative: true}) }}   {# rendu avec alt="" #}
```

Sans `alt` (ou avec un `alt` vide ou ne contenant que des espaces, typiquement `{alt: produit.alt}` jamais rempli),
le bundle cherche le texte alternatif **intégré au fichier** (voir ci-dessous). S'il n'en trouve pas :

- avec `require_alt: true` (par défaut en debug), une `MissingAltTextException` est levée : l'oubli se voit tout de suite
  en développement ;
- sinon (par défaut en production), un avertissement est écrit dans les logs et l'image est rendue avec `alt=""`.

`decorative: true` combiné à un `alt` non vide est refusé, car c'est contradictoire.

### Textes alternatifs intégrés aux images (IPTC)

Lightroom, Photoshop, les banques d'images et certains CMS enregistrent dans le fichier les champs IPTC
**Alt Text (Accessibility)** et **Extended Description (Accessibility)**, éventuellement en plusieurs langues.
Le bundle :

- les **conserve** lors du nettoyage des métadonnées (JPEG, PNG, et après conversion en WebP) en ne gardant
  qu'un XMP minimal : le GPS, l'auteur, etc. sont bien supprimés ;
- les **utilise** comme `alt` par défaut dans `responsive_image()`, dans la langue de la requête (`fr`, `fr_FR`…),
  sinon `x-default` ;
- lit aussi le `<title>` (ou l'`aria-label`) et le `<desc>` des SVG.

Pour pré-remplir un champ « texte alternatif » de formulaire au moment de l'upload :

```php
use RomainGenin\ImageOptimizerBundle\Metadata\AccessibilityMetadataReader;

$metadata = $reader->read($uploadedFile->getPathname());
$product->setImageAlt($metadata?->altText($request->getLocale()));
$product->setImageDescription($metadata?->extendedDescription($request->getLocale()));
```

Pour une description longue (graphique, schéma…), affichée à côté de l'image :

```twig
{% set a11y = image_accessibility('uploads/graphique.png') %}
<figure>
    {{ responsive_image('uploads/graphique.png', {alt: 'Ventes 2026 par trimestre', 'aria-describedby': 'desc-ventes'}) }}
    {% if a11y and a11y.extendedDescription(app.request.locale) %}
        <figcaption id="desc-ventes">{{ a11y.extendedDescription(app.request.locale) }}</figcaption>
    {% endif %}
</figure>
```

### Animations et « réduire les animations » (WCAG 2.2.2 / 2.3.3)

Pour un GIF, WebP ou PNG animé, `responsive_image()` produit :

```html
<picture>
  <source media="(prefers-reduced-motion: reduce)" type="image/webp" srcset="/_image/320/uploads/anim.gif.webp 320w, …">
  <img src="/uploads/anim.gif" width="480" height="270" alt="…">
</picture>
```

Les personnes ayant activé « réduire les animations » dans leur système (troubles vestibulaires, épilepsie,
troubles de l'attention) voient la **première image fixe** ; les autres voient l'animation. Les WebP animés
nécessitent `webpmux` (paquet `webp`). Désactivable avec `responsive.reduced_motion: false`.

Cela ne remplace pas un bouton pause pour les animations de plus de 5 secondes affichées à tout le monde :
pour celles-ci, préférez une vidéo `<video>` avec contrôles.

### Ce qui reste de votre ressort

La pertinence des textes alternatifs, le texte incrusté dans les images et le contraste des visuels relèvent du contenu.

## Utilisation manuelle

Pour une image qui n'arrive pas par une requête HTTP (import, Messenger, URL distante…) :

```php
use RomainGenin\ImageOptimizerBundle\Optimizer\ImageOptimizerInterface;
use RomainGenin\ImageOptimizerBundle\Upload\UploadedFileOptimizer;

public function __construct(
    private ImageOptimizerInterface $optimizer,
    private UploadedFileOptimizer $uploadedFileOptimizer,
) {}

$result = $this->optimizer->optimize('/chemin/image.png');            // en place, ne lève jamais d'exception
$result->isConverted();  // true si le contenu est maintenant du WebP (renommez le fichier)
$result->savedRatio();   // 0.42

$file = $this->uploadedFileOptimizer->optimize($uploadedFile);        // UploadedFile éventuellement renommé
```

Images déjà stockées :

```bash
php bin/console image-optimizer:optimize public/uploads             # sans perte, mêmes noms de fichiers
php bin/console image-optimizer:optimize public/uploads --convert   # + conversions (renomme les fichiers !)
```

## Configuration complète (valeurs par défaut)

```yaml
# config/packages/image_optimizer.yaml
image_optimizer:
    upload:
        enabled: true
        convert: true              # autoriser les changements de format sans perte
        max_file_size: 52428800    # octets, 0 = illimité
        paths: []                  # regex des URLs à traiter (vide = toutes), ex. ['^/admin', '^/api/media']
        exclude_paths: []          # regex des URLs à ignorer
        listener_priority: 7       # après le firewall (8), avant API Platform (4)
    optimization:
        strip_metadata: true       # EXIF/GPS/XMP… (ICC et orientation toujours conservés)
        auto_orient: true
        min_conversion_gain: 0.05  # une conversion doit faire gagner au moins 5 %
        max_pixels: 50000000       # garde-fou mémoire
        keep_accessibility_metadata: true   # conserver les textes alternatifs IPTC
        conversions:
            png: webp              # webp | false
            gif: webp              # webp | png | false
            bmp: webp              # webp | png | false
            tiff: webp             # webp | false
    binaries:                      # null = auto-détection, false = désactivé, ou chemin absolu
        timeout: 60
        jpegtran: ~
        oxipng: ~
        optipng: ~
        gifsicle: ~
        gif2webp: ~
        cwebp: ~
        avifenc: ~
        svgo: ~
        webpmux: ~
    responsive:
        enabled: true
        route_prefix: /_image
        public_dir: '%kernel.project_dir%/public'   # racine web
        source_dir: '%kernel.project_dir%/public'   # base des chemins passés à responsive_image()
        widths: [320, 480, 640, 768, 960, 1280, 1600, 1920, 2560]
        default_width: 1280
        formats: [avif, webp]
        quality: { webp: 90, avif: 80, jpeg: 90 }
        default_sizes: 100vw
        cache_max_age: 2592000
        require_alt: '%kernel.debug%'  # exception si ni alt ni decorative: true (sinon log)
        reduced_motion: true       # version fixe des animations pour prefers-reduced-motion
        client_hints: true
```

Les logs sont émis sur le canal Monolog `image_optimizer`.

## Bon à savoir

- **Sécurité des variantes** : seules les largeurs de `widths` sont acceptées, les chemins sont confinés à
  `source_dir` (pas de `..`), et on ne génère jamais une variante de variante. Ne pointez pas `source_dir`
  vers des fichiers privés : leurs variantes seraient publiques.
- **Cache** : si vous remplacez un fichier source par un autre du même nom, les variantes sont régénérées
  à la prochaine requête passant par PHP ; supprimez `public/_image/` pour forcer le renouvellement côté serveur web.
- Le bundle traite `$request->files`. Le code qui lit directement `$_FILES` n'en bénéficie pas : utilisez alors
  `UploadedFileOptimizer` ou `ImageOptimizerInterface`.
- HEIC/HEIF et AVIF uploadés sont laissés tels quels (déjà très compressés ; toute conversion serait avec perte ou plus lourde).
- SVG : le bundle optimise mais ne **nettoie pas** les scripts éventuellement présents (XSS) ; utilisez un sanitizer dédié si
  vous acceptez des SVG d'utilisateurs non fiables.

## Compatibilité

PHP 8.1 à 8.5, Symfony 6.4, 7.x et 8.x. Extension GD recommandée.
Les changements de chaque version sont listés dans [CHANGELOG.md](CHANGELOG.md).

## Tests

```bash
composer install
vendor/bin/phpunit
```

Les tests vérifient notamment que les pixels décodés sont **strictement identiques** avant/après optimisation,
avec et sans les outils en ligne de commande.

## Licence

MIT
