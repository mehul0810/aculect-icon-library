# Ionicons

Optional, disabled by default, and entirely local: no Ionic runtime or remote
requests. Enable the collection in the library manager.

Pinned official source: `ionic-team/ionicons`, tag `v8.0.13`, commit
`a9d1b7e23d7b9dec29f2041897ab14b2cef55064`. The MIT copyright and permission
notice are copied unchanged to `assets/icons/ionicons/LICENSE` in the ZIP.

```sh
git clone --depth 1 --branch v8.0.13 https://github.com/ionic-team/ionicons.git /tmp/ionicons
php scripts/import-path-library.php ionicons /tmp/ionicons
php scripts/build-catalog-metadata.php
```

This compatibility subset contains 313 Filled, 284 Sharp, 14 Outline, and 68
Brands icons. Another 678 SVGs are explicitly excluded with filename and reason
in `exclusions.json`: unsupported shapes, inline styles, strokes, or attributes
must not be silently stripped or converted into different artwork. Most Outline
icons are therefore not available. No sanitizer rules are changed.

Stable IDs use `ionicons/<variant>/<upstream-filename>` and manifest Core names
use `ionicons/<upstream-filename>-<variant>`. Native picker style namespaces use
`ionicons-<variant>`. Search includes the official `src/data.json` tags.

Upstream trademark notice: All brand icons are trademarks of their respective
owners. The use of these trademarks does not indicate endorsement of the
trademark holder by Ionic, nor vice versa. The same applies to this collection.
Brand use remains subject to the respective owner's trademark rules.
