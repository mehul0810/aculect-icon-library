# Iconoir Compatibility Scope

The optional Iconoir collection contains **210 compatible Solid icons** from
Iconoir 7.12.1, commit `d7dfa4d0341df0670bfed9fc24221c9d7ef2112e`.
The Solid variant is disabled by default. This is deliberately not a claim of
complete Iconoir support.

All 1,383 Regular icons and 78 mixed-geometry Solid icons are deferred. The
complete per-icon report is `assets/icons/iconoir/exclusions.json`. Strokes,
dashes, clipping, transformed rectangles, and mixed filled/stroked elements
are never stripped to force acceptance. Adding those icons requires faithful
build-time conversion with independent rendered proof.

Included icons contain only explicitly `currentColor`-filled paths/polygons.
The importer removes unused root `fill="none"` and `stroke-width` hints only
after checking all geometry has explicit fill; the strict existing validator
still rejects every unsupported attribute, including child stroke attributes.
Existing sanitizer and runtime registration code are unchanged. Labels and
keywords derive from stable upstream filenames; category is explicitly General
rather than an invented upstream taxonomy.

The exact upstream MIT license, copyright (c) 2021 Luca Burgio, is preserved.
Some upstream icons depict third-party brands; MIT copyright permission does
not grant trademark endorsement or imply affiliation.

## Reproduce

```sh
git clone --depth 1 --branch v7.12.1 https://github.com/iconoir-icons/iconoir.git /tmp/iconoir
php scripts/import-iconoir.php /tmp/iconoir
php scripts/build-catalog-metadata.php
php scripts/validate-manifests.php
```

The source revision, clean worktree, official remote, contained files, exact
source counts, imported count and exclusions are verified before finalization.
No extra runtime/build dependencies are added. Reimport replaces only the
generated Iconoir collection after the candidate manifest validates.

Before merge/release acceptance, independently verify representative rendering,
real Core sanitization, native picker selection/save/reload, and runtime
registration/memory/discovery payload costs. Static manifest checks alone do
not establish visual or runtime acceptance.
