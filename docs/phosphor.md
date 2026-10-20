# Phosphor Icons

Optional and disabled by default. Enable the collection in the library manager.
Regular contains 1,248 icons; Fill contains 1,239 icons. Other weights and
Duotone are outside this import's scope. No remote runtime assets are used.

The official `phosphor-icons/core` source is pinned to tag `v2.0.8`, commit
`d42782b2abe747d904b971ccab48b182a1455f86`. The upstream package.json at that
tag says `2.0.7`; the manifest records that package version and the exclusion
report records both facts. Its MIT copyright and permission notice are copied
unchanged to `assets/icons/phosphor/LICENSE` and included in production ZIPs.

```sh
git clone --depth 1 --branch v2.0.8 https://github.com/phosphor-icons/core.git /tmp/phosphor-core
php scripts/import-path-library.php phosphor /tmp/phosphor-core
php scripts/build-catalog-metadata.php
```

All shipped assets use the existing strict path/polygon SVG validation. Nine
Fill assets contain unsupported circles or rectangles and are excluded with
exact names and reasons in `exclusions.json`, rather than changing their artwork
or weakening sanitization. Upstream brand marks remain upstream artwork; MIT
permission is not a grant of trademark rights.

IDs follow `phosphor/<variant>/<upstream-filename>`; Core names follow
`phosphor/<upstream-filename>-<variant>` in the manifest and
`phosphor-<variant>/<upstream-filename>-<variant>` in native style tabs.
Search terms are derived from the
upstream filenames. Catalog listings use compact metadata, while icon geometry
is registered only when the selected collection is requested.
