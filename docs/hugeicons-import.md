# Hugeicons Free Import

The optional Stroke Rounded collection contains 6,064 icons from the official
`@hugeicons/core-free-icons` 4.3.5 package. Three opacity-dependent icons are
explicitly excluded: Arrow Big Right Dash, Hamburger 01, and Right To Left
List Bullet. Their per-element opacity cannot be preserved by the current Core
sanitizer. Pro, deprecated `hugeicons-react`, and restricted `@hugeicons/static`
packages are not used.

The exact tarball MIT license, copyright (c) 2025 Hugeicons, is included
unchanged. Brand marks in upstream assets do not imply trademark endorsement.
The registry's recorded Git revision is
`bf880d758a69ab69edb278f8b529579fac54e5df`. The importer checks the pinned
SHA-512 integrity before extracting the archive. It parses data arrays with
Acorn without executing upstream JavaScript.

## Reproduce

```sh
npm pack @hugeicons/core-free-icons@4.3.5 --ignore-scripts
npm ci --prefix scripts/hugeicons-build --ignore-scripts
node scripts/import-hugeicons.cjs ./hugeicons-core-free-icons-4.3.5.tgz
php scripts/build-catalog-metadata.php
php scripts/validate-manifests.php
node --test tests/hugeicons-conversion.test.cjs
```

Build-only dependencies are pinned in a separate lockfile: Acorn 8.18.0 (MIT),
svgpath 2.6.0 (MIT), and Google Skia pathkit-wasm 1.0.0 (BSD-3-Clause).
The existing production package allowlist excludes these dependencies.

Source rendering defaults were checked against the official React renderer at
the same revision: 24-unit viewBox, root fill none, and per-element stroke/fill
attributes. The converter preserves varying stroke widths, round/square/butt
caps, miter/round/bevel joins, filled shapes, fill rules, and transforms. Skia
expands strokes at build time; no CSS repair or sanitizer changes are used.
Unsupported presentation fails the import unless it is one of the exact three
documented opacity exclusions. Labels/search keywords derive from stable
package export names; category is General, not invented upstream taxonomy.

The variant is disabled by default. No remote calls or whole-collection frontend
loading are added. Before merge/release acceptance, require independent visual
comparison, real Core sanitization, picker save/reload, performance/payload
measurements, and the combined ZIP size gate. Static checks alone do not prove
rendering fidelity or release suitability.

The current individual package build is 18,755,414 bytes (6,064 Hugeicons
included), above the repository's 10 MiB WordPress.org submission gate. This
integration is therefore not release-ready as packaged. Do not merge it into a
release until the distribution-size strategy and the checks above are resolved.
