# Google Material Icons

Issue #27 adds the strictly Core-compatible subset of the filled 24px SVGs from
[Google's official repository](https://github.com/google/material-design-icons),
commit `27e9ef1dbeedc13d682fece4a58e1eda4cb0961a`. The
[official Material Icons guide](https://developers.google.com/fonts/docs/material_icons)
identifies this source and Apache-2.0 license. This is Material Icons, not Material
Symbols, fonts or variable-font support. Version is a commit pin, not a release tag.

## Scope

Of 2,170 filled 24px source drawings, 1,038 pass the existing strict build contract
without geometry conversion. The other 1,132 are recorded by source path and
first validation error in `assets/icons/material-icons/exclusions.json`. Those
first errors include 1,083 `enable-background` attributes, 33 circle elements,
four titles, four groups, five opacity attributes and one each baseprofile,
style and path class. Removing the first rejected attribute alone is not proof
of compatibility: many affected drawings also contain groups or rectangles.

No sanitizer allowlist is changed and unsupported drawing semantics are not
silently discarded. Outlined, rounded, sharp and two-tone styles are not bundled.
The manifest explicitly describes the limited filled subset; it does not claim
to contain the entire upstream library. Additional geometry conversion belongs
in separately reviewed work with visual-fidelity evidence.

The optional collection is disabled until installed like existing collections.
Stable IDs retain upstream names with underscores changed to hyphens; importer
rejects collisions. Search terms also retain original underscored names, and
categories follow upstream directory names. No runtime remote calls are added.

## License And Reproduction

The pinned Apache-2.0 LICENSE is copied verbatim. No separate NOTICE exists in
the pinned root or icon source tree. Modified SVG files identify XML formatting
normalization in a comment; path geometry and presentation remain unchanged.
Apache terms apply to the separate assets; plugin source remains GPL-2.0-or-later.
No trademark rights or general GPLv2-combination compatibility are claimed.

```sh
git clone --filter=blob:none --sparse https://github.com/google/material-design-icons.git /tmp/material-design-icons
git -C /tmp/material-design-icons checkout 27e9ef1dbeedc13d682fece4a58e1eda4cb0961a
git -C /tmp/material-design-icons sparse-checkout set src
php scripts/import-material-icons.php /tmp/material-design-icons
php scripts/build-catalog-metadata.php
```

Imports pin the official remote, clean checkout, revision, source count and exact
inclusion/exclusion counts. Output SVG checksums protect the committed manifest.
No upstream dependencies or build scripts need to execute.

Core-native picker, save/reload/frontend, collection lifecycle, accessibility and
controlled registration/REST/browser performance proof must be recorded on the
reviewed package. Static manifest checks do not establish those runtime outcomes.
