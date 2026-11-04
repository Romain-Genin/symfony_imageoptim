# Changelog

Toutes les évolutions notables de ce bundle sont documentées ici.
Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le projet respecte le
[versionnage sémantique](https://semver.org/lang/fr/).

## [Unreleased]

## [2.0.0] - 2026-11-04

### Modifié (rupture)

- Namespace PHP renommé `StudioAstra\\ImageOptimizerBundle` → `RomainGenin\\ImageOptimizerBundle` : mettez à jour `config/bundles.php`
  et vos `use`.
- Toutes les références au paquet utilisent son nom Composer `romain-genin/symfony-image-optimizer-bundle` (chemins `vendor/…` compris).

## [1.0.0] - 2026-11-04

### Ajouté

- Optimisation automatique et **sans perte** de toutes les images uploadées (`$request->files`, formulaires,
  API Platform, VichUploader, champs imbriqués), avant le contrôleur.
- Détection du format sur le contenu réel : JPEG, PNG, GIF, WebP, BMP, TIFF, SVG, AVIF, HEIC.
- JPEG : `jpegtran` (Huffman optimal, progressif, rotation EXIF sans perte), suppression des métadonnées en
  pur PHP ; profil ICC, orientation et gain maps HDR préservés.
- PNG : suppression des métadonnées + recompression zlib -9 en pur PHP, puis `oxipng` / `optipng`.
- GIF : `gifsicle -O3` ; SVG : `svgo`.
- Conversions sans perte PNG / GIF / BMP / TIFF → WebP lossless, conservées seulement si elles sont nettement plus
  légères ; l'`UploadedFile` reçoit la nouvelle extension et le nouveau type MIME.
- Diffusion adaptée à l'appareil : fonction Twig `responsive_image()` (`<picture>`, AVIF/WebP, `srcset`/`sizes`),
  variantes générées à la demande et servies ensuite en statique, URL `image_auto()` négociée via `Accept` et
  Client Hints.
- Accessibilité : texte alternatif obligatoire ou image explicitement décorative (`require_alt`), conservation et
  réutilisation des textes alternatifs IPTC (« Alt Text / Extended Description (Accessibility) »), lecture du
  `<title>`/`<desc>` des SVG, version fixe des images animées pour `prefers-reduced-motion`.
- Service `AccessibilityMetadataReader`, fonction Twig `image_accessibility()`.
- Commande `image-optimizer:optimize` pour les images déjà stockées.
- Compatible PHP 8.1+ et Symfony 6.4, 7.x, 8.x.

[Unreleased]: https://github.com/StudioAstra/ImageOptimizer/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/StudioAstra/ImageOptimizer/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/StudioAstra/ImageOptimizer/releases/tag/v1.0.0
