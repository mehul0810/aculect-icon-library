# Lucide Import

Lucide 1.47.0 is pinned to upstream commit
`3b9ea6d08707edc439f25a4c354cb0d6b8bee973`. All 1,848 outline icons are
included, with upstream category and search tags. The optional collection's
Outline variant is disabled by default. No remote runtime dependencies exist.

The complete upstream LICENSE is redistributed unchanged, including both the
ISC notice for Lucide and the inherited MIT notice for Feather-derived icons.

## Reproduction

```sh
git clone --branch 1.47.0 --depth 1 https://github.com/lucide-icons/lucide.git /tmp/lucide
npm ci --prefix scripts/outline-build --ignore-scripts
node scripts/import-lucide.cjs /tmp/lucide
php scripts/build-catalog-metadata.php
php scripts/validate-manifests.php
node --test tests/lucide-conversion.test.cjs
```

The importer verifies the exact revision, clean source tree, official remote,
contained source files, source count, presentation contract, and generated
manifest before replacing the generated collection. Unknown geometry or
presentation fails the import; it is never silently discarded.

Build-only dependencies are locked separately from the editor bundle:
Google Skia `pathkit-wasm` 1.0.0 (BSD-3-Clause), `svgpath` 2.6.0 (MIT),
and `@xmldom/xmldom` 0.9.12 (MIT). They are excluded from the production
ZIP by the existing package allowlist.

The converter preserves 24-unit viewBoxes, two-unit strokes, round caps and
joins, rounded rectangles, circles/ellipses, and filled circles. Skia expands
strokes into filled paths at build time; the strict PHP validator then checks
every generated asset. Core and plugin sanitizers are unchanged. The existing
experimental Heroicons CSS workaround is not used for Lucide.

Conversion changes geometry representation, so independent rendered comparison,
native-picker selection/save/reload proof, and runtime performance measurements
are required before release approval. Passing manifest checks alone is not
visual or runtime acceptance.
