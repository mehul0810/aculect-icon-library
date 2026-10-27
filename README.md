# Aculect Icon Library

SVG icons for the native WordPress Icon block.

Choose from ten bundled icon libraries, preview their artwork, and enable the
styles you need in the block editor. Add your own SVG icons alongside Heroicons,
Bootstrap Icons, Font Awesome Free, Tabler, Radix, and the other included
collections, using the same WordPress editing workflow.

- **Use the native Icon block.** Pick icons and adjust their size and styling
  with WordPress's existing block controls.
- **Choose your libraries.** Preview bundled families before adding them to
  Library, then enable individual styles.
- **Bring your own SVGs.** Upload supported artwork, search your icons, and edit
  display labels without changing the names used by saved blocks.
- **Keep assets on your site.** Bundled libraries and custom uploads use local
  SVG files. No icon-service account, icon font, or CDN is required.
- **Connect compatible tools.** WordPress Abilities let authorized automation
  clients discover icons and edit Icon blocks.

This branch prepares **1.2.1**. For published packages, see
[GitHub Releases](https://github.com/mehul0810/aculect-icon-library/releases).

[Get started](#requirements-and-quick-start) | [Included libraries](#bundled-libraries) |
[Custom SVGs](#custom-icons) | [Developer setup](#development)

## Bundled Libraries

The package includes the following curated styles. These are the plugin's
supported subsets, rather than the full catalogs advertised by each upstream
project.

| Icon library | Included styles |
| --- | --- |
| [Heroicons](https://heroicons.com/) | Outline (opt-in) and Solid |
| [Bootstrap Icons](https://icons.getbootstrap.com/) | Default and Filled |
| [Font Awesome Free](https://fontawesome.com/) | Solid, Regular, and Brands |
| [Tabler Icons](https://tabler.io/icons) | Filled |
| [Radix Icons](https://www.radix-ui.com/icons) | Default, non-logo subset |
| [Google Material Icons](https://github.com/google/material-design-icons) | Compatible Filled 24px subset |
| [Iconoir](https://iconoir.com/) | Compatible Solid subset |
| [Phosphor Icons](https://phosphoricons.com/) | Regular and Fill |
| [Ionicons](https://ionic.io/ionicons) | Compatible Filled, Sharp, Outline, and Brands subset |
| [Rune Icons](https://github.com/Nexvyn/runeicons) | Pixelated |

Fresh installations start with no libraries selected or enabled. Installing a
library adds it to **Library**; enable it or its variants there to make its
icons available for new blocks. Existing sites retain their saved icon names
and library preferences through an ordinary plugin update.

Iconoir includes a compatible filled-path Solid subset. Regular and mixed-stroke
icons are explicitly deferred, not silently simplified. See
[Iconoir compatibility scope](docs/iconoir-import.md) for source, license,
reproduction and the complete exclusion report.
See [Phosphor scope and provenance](docs/phosphor.md) and
[Ionicons compatibility and exclusions](docs/ionicons.md).
Rune's other styles are not bundled. See [source, license and import details](docs/rune-icons.md).
See [Material Icons scope, exclusions and provenance](docs/material-icons.md).

## Requirements And Quick Start

Requires **WordPress 7.1+** and **PHP 7.4+**.

1. Install the plugin ZIP through **Plugins > Add New Plugin > Upload Plugin**
   and activate Aculect Icon Library.
2. Open **Appearance > Icons > Install Library**, preview a bundled family,
   and select **Install Library**. Its files are already included in the plugin;
   this action does not download anything.
3. Switch to **Library**, open the selected family, and enable the styles you
   want to use.
4. Edit a post or page, insert the native **Icon** block, and select an icon.
5. Adjust the block's sizing and styling, then save your content.

To use your own SVG, open **Appearance > Icons > Upload**. Upload a supported
file up to 64 KB, give it a name and label, and select it in the Icon block.
This does not enable SVG uploads in the Media Library. See [Custom Icons](#custom-icons)
for supported exports and [Icon Lifecycle](#icon-lifecycle) before deleting icons.

Use the installable ZIP attached to a [GitHub release](https://github.com/mehul0810/aculect-icon-library/releases),
not GitHub's automatically generated source archive. For a source checkout,
follow [Development](#development) to build a production package.

## Upgrading From The Original Beta

Previously named Icon Library. The plugin slug and translation domain are
`aculect-icon-library`; the entry point is `aculect-icon-library.php`.
Existing `IconLibrary` PHP classes, `ICON_LIBRARY_*` constants,
`icon_library_*` settings and hooks, `icon-library` REST/Abilities identifiers,
admin URLs, and custom-icon storage paths remain stable for compatibility.
When replacing the earlier beta, deactivate it before installing the renamed
plugin, then activate Aculect Icon Library. Do not uninstall the old beta:
its uninstall routine removes shared custom-icon data.

## Core Icon API Integration

WordPress 7.1 exposes public functions for registering icon collections and
icons. `IconLibrary\CoreIconRegistrar` maps enabled plugin manifests to
`wp_register_icon_collection()` and `wp_register_icon()`. Icon SVG files are
passed by absolute `file_path`, allowing Core to load and sanitize their
contents lazily when the REST API or renderer requests them.

## Extension Hooks

Collection providers can register a validated external catalog with the
`icon_library_collection_providers` filter. A provider supplies a slug and a
manifest callback/value, and may supply an SVG path or content callback. Slugs
must be lowercase hyphenated identifiers; SVG paths must resolve to readable
`.svg` files and all content is still passed through Core's sanitizer.

The following filters are available for integrations:

- `icon_library_collections`: collection summaries shown in the admin and REST catalog.
- `icon_library_enabled_collections`: enabled collection slugs.
- `icon_library_enabled_variants`: enabled variants for one collection.
- `icon_library_icon_manifest`: a loaded manifest, its slug, and source path.
- `icon_library_svg_markup`: final SVG markup, re-escaped through the plugin allowlist.
- `icon_library_abilities`: ability definitions before registration, for integrations that need to hide or extend an AI-facing action.

## Manifest Shape

Bundled libraries live under `assets/icons/{collection}/manifest.json`.
Each icon has an internal library ID, a Core-compatible ID, and a checksum:

```json
{
  "id": "heroicons/solid/academic-cap",
  "coreIconName": "heroicons/academic-cap-solid",
  "label": "Academic Cap",
  "variant": "solid",
  "categories": ["general"],
  "keywords": ["academic", "cap"],
  "path": "solid/academic-cap.svg",
  "sha256": "..."
}
```

Libraries may also publish a labeled category index and per-variant counts.
Font Awesome Free imports the official category taxonomy from its upstream
metadata, so the admin browser can show the same labels instead of guessing
from slugs. The current source taxonomy contains 68 categories; the browser
also exposes a `Brands` grouping so every brand style entry remains discoverable:

```json
{
  "variants": [
    { "slug": "solid", "label": "Solid", "iconCount": 2001 },
    { "slug": "regular", "label": "Regular", "iconCount": 273 },
    { "slug": "brands", "label": "Brands", "iconCount": 609 }
  ],
  "categories": [
    { "slug": "accessibility", "label": "Accessibility", "iconCount": 24 }
  ]
}
```

The core ID intentionally uses one namespace separator because the current
`wp/v2/icons/{name}` route only accepts `namespace/icon-name`.

## REST Endpoints

- `GET /wp-json/icon-library/v1/collections`
- `POST /wp-json/icon-library/v1/collections/{slug}/activate`
- `POST /wp-json/icon-library/v1/collections/{slug}/deactivate`
- `POST /wp-json/icon-library/v1/collections/{slug}/install` (select a bundled library, initially disabled)
- `POST /wp-json/icon-library/v1/collections/{slug}/remove` (remove the selection, retaining saved artwork)
- `POST /wp-json/icon-library/v1/collections/{slug}/variants/{variant}/activate`
- `POST /wp-json/icon-library/v1/collections/{slug}/variants/{variant}/deactivate`
- `GET /wp-json/icon-library/v1/icons` (paginated catalog with variant facets)

Library mutations require `manage_options`. Read endpoints require the same
editor-style access as the Core icon endpoint. Icon discovery and rendering use
the native WordPress `wp/v2/icons` endpoints.

## Abilities API

The plugin provides integration actions, not a standalone AI assistant.
Compatible agents can discover enabled icons and edit native Icon blocks while
respecting WordPress permissions.

On WordPress 7.1 and newer, Aculect Icon Library registers public WordPress Abilities
for AI agents and other automation clients. The abilities are discoverable
through the core Abilities API and can also be used with `wp ability list` and
`wp ability run`:

- `icon-library/search-icons`: search enabled libraries and return safe icon metadata.
- `icon-library/get-icon`: validate one enabled icon name and return its metadata.
- `icon-library/list-icon-blocks`: list editable post `core/icon` blocks with stable block-tree paths, `modified_gmt`, and a SHA-256 `content_hash` token.
- `icon-library/insert-icon-block`: insert a `core/icon` block at the root or inside a container.
- `icon-library/replace-icon-block`: assign a different icon and selected accessible presentation attributes to an existing block.
- `icon-library/remove-icon-block`: remove one `core/icon` block by path.

Read abilities require editor-style icon access. Post discovery and all content
mutations require the caller to have `edit_post` capability for the target
post. Send the returned `content_hash` as `expected_content_hash` with a mutation
to detect stale client content, including changes within the same second.
The optional `expected_modified_gmt` remains supported for older clients, but
its seconds-resolution timestamp cannot detect every stale client snapshot.
Every mutation also compares the original content and timestamp under a database
row lock before calling WordPress's post-update API. Conflicts return HTTP 409;
refresh the block list and recompute the edit instead of blindly retrying it.
These guarded writes require transactional storage and refuse to run inside a
caller's active transaction. Unsupported database contexts fail without saving.
Nested edits preserve WordPress's child placeholders. Insertion into an empty
container is supported only when its HTML insertion point is unambiguous;
unsupported containers are rejected without saving changes.
Only registered icon names and a small allowlist of presentation attributes are
accepted; raw SVG, filesystem paths, arbitrary block markup, and post content
are never accepted or returned.

## Icon Lifecycle

Disabling a library hides it from Core collection and icon-list discovery,
so it cannot be selected for new blocks. The plugin continues registering its
icons, allowing existing saved blocks and individual icon requests to render.
The same rule applies when an individual variant is disabled: its style
collection is hidden from new selections, while existing registered names
continue to resolve.

**Remove from Library** also hides the family from new selections, without
deleting its bundled SVGs. Saved icons continue to render while the plugin is
active and the files remain available. Installing it again restores the family
to Library; enabling styles is a separate action.

WordPress stores an Icon block's registered name rather than a copy of its SVG.
Deactivating or uninstalling the provider plugin therefore makes those icons
unavailable. This Core limitation is tracked upstream in
https://github.com/WordPress/gutenberg/issues/80668.

## Custom Icons

Administrators can add SVG files up to 64 KB through **Appearance > Icons > Upload**.
The plugin validates the file against the WordPress 7.1 icon geometry contract
before storing the sanitized SVG locally under the uploads directory. This does
not enable SVG uploads in the Media Library and makes no remote requests.

If saving metadata definitely fails, the plugin removes only that attempt's
unpublished SVG. An uncertain commit retains the artwork and asks you to refresh
the icon list. If cleanup also fails, the error reports retained bytes and asks
the administrator to check uploads before retrying.

Imports normalize unused `id` attributes, root export metadata, empty titles,
comments, and plain groups. Simple single-class CSS rules for hexadecimal fills,
`none`, `currentColor`, and fill rules are converted into path/polygon attributes
before validation. Colors and geometry are preserved; stylesheets and geometry
classes are not stored. Transformed or styled groups, complex CSS, inline styles,
references, scripts, and unsupported geometry are rejected, not silently removed.
Export these features as flattened paths with explicit fills before uploading.

Custom icon names are stable after creation so existing blocks keep their
registered name; their display labels can be changed. Deleting a custom icon
permanently removes its metadata and SVG file, so existing blocks referencing
it can no longer resolve the icon. Replace those icons in content before
deleting them. There is no user-facing archive or restore workflow. Plugin uninstall
removes custom icon metadata and the plugin-owned SVG files, which prevents
retained post content from resolving those icons.

Names present in legacy archived custom-icon metadata are also reserved.
Uploading different artwork under an archived name is rejected; use a new name
so saved blocks cannot silently change appearance.

Uninstall cleanup is scoped to the current site's custom-icon storage and
settings. It does not sweep every site in a multisite network or erase retained
downloaded-provider artwork from earlier experimental versions. Back up and
review those stores separately; removing the plugin can stop saved icons from
rendering even when their artwork is retained. No network-wide or provider-data
deletion is performed implicitly.

## Importing bundled libraries

```bash
git clone --depth=1 https://github.com/tailwindlabs/heroicons.git /tmp/heroicons
php scripts/import-heroicons.php /tmp/heroicons
php scripts/import-path-library.php bootstrap-icons /path/to/bootstrap-icons
php scripts/import-path-library.php font-awesome /path/to/font-awesome
git clone --depth=1 --branch v3.47.0 https://github.com/tabler/tabler-icons.git /tmp/tabler-icons
php scripts/import-tabler-icons.php /tmp/tabler-icons
git clone --depth=1 --branch '@radix-ui/react-icons@1.3.2' https://github.com/radix-ui/icons.git /tmp/radix-icons
php scripts/import-radix-icons.php /tmp/radix-icons
php scripts/build-catalog-metadata.php
php scripts/validate-manifests.php
```

The Tabler importer includes 1,012 Filled icons from version 3.47.0 and records
35 upstream Brand-category exclusions plus seven selected trademark or character
reference exclusions. The Radix importer includes 299 non-logo icons
from version 1.3.2 and records 14 logo/trademark exclusions plus five icons whose
opacity semantics are not preserved by the WordPress 7.1 Icon API. Both importers
require a clean checkout at the pinned revision, preserve the upstream license,
and fail if the approved source or exclusion counts change.

## Development

Install the development tools with `composer install`. Tests use WordPress's
real block parser and serializer. Set `WP_CORE_DIR` to a WordPress 7.1+ checkout
when the plugin is not inside a site's `wp-content/plugins` directory.
Build tooling requires Node.js 20.19+ and npm 10.2.3+.

```bash
composer install
npm ci
composer check
npm run test:js
npm run build
composer package
```

Deterministic packaging needs a build PHP whose Zip extension supports
`ZipArchive::setMtimeName()`. See [Contributing](CONTRIBUTING.md#development-setup)
for the separate builder used with older PHP 7.4 Zip extensions.

After importing or changing bundled manifests, run `composer catalog` to
regenerate compact `metadata.json` files. `composer check` rejects stale metadata.
Runtime manifest filters bypass these summaries to preserve filtered catalogs.
Provider callbacks are cached within a request; providers that change during
that request must call `CollectionRegistry::clear_request_caches()`. Stored
library/variant/custom-icon option changes invalidate derived registry caches.

See [quality fixes](docs/quality-fixes.md) for the scoped remediation record and
the [release checklist](docs/release-checklist.md) for required current-candidate
proof. Historical review notes do not establish present release readiness.

`composer package` creates one versioned bundled ZIP in `build/` from an explicit
production allowlist. It includes all ten supported families, manifest assets,
license and exclusion material, and Heroicons aliases. The archive includes
deterministic `data/package-profile.json` metadata. `--bridge` is unsupported.
Packaging does not publish or deploy the artifact.
The root `.distignore` mirrors the development paths excluded by compatible
WordPress distribution tooling; the built-in packager keeps its stricter
allowlist so an unexpected repository file cannot enter a release.

GitHub Actions validates release and pre-release tags. Both public release
workflows remain fail-closed before artifact upload or GitHub publication until
separate owner release approval; the stable workflow also gates WordPress.org
deployment. Do not change either gate as part of package preparation. See
[WordPress.org branding](docs/branding/README.md) for asset sizes, validation
and release routing; workflow configuration alone does not confirm a successful
directory deployment.

Library authors should follow `schemas/collection-manifest.schema.json`, use
stable namespaced IDs, include source revision and license metadata, and run
the manifest validator before distributing a library.

Font Awesome Free is imported from the upstream `metadata/icons.json` and
`metadata/categories.yml` files. The package exposes the three Free styles
documented by Font Awesome: `Solid`, `Regular`, and `Brands`. Legacy alias SVG
files remain available under their existing Core names, but share the
canonical icon's category and search metadata. Pro-only styles are not bundled.

Heroicons uses `Outline` and `Solid` as its style taxonomy. The Core Icon block
controls the rendered width, so the upstream 20px Mini and 16px Micro files are
not exposed as selectable variants. Outline is bundled and disabled by default.
WordPress 7.1 currently strips the stroke
attributes required by Heroicons Outline. When an incompatible variant is
rendered, Aculect Icon Library adds a fixed root marker before Core sanitizes the markup
and restores the known stroke presentation with a marker-scoped stylesheet in
the editor and early on every frontend request. The existing 509-byte critical
CSS is emitted once inline, adding no stylesheet request and covering cached
and template-driven icons that bypass discovery. Its enqueue performs no
catalog lookup. Solid, custom, and unmarked SVGs keep their
own presentation. This keeps Core's
sanitizer intact while the workaround is validated.

Available styles are registered as separate Core collections, such as
`heroicons-solid` and `heroicons-outline`, so the native picker collection
filter can separate them.
The original library namespaces are registered for enabled variants and lazily
restored when an existing saved block references a disabled or legacy name.
They remain hidden from discovery to avoid duplicate results. Legacy Heroicons
20px, 16px, and 24px Solid names continue to resolve when their source files are
present. Installing or uninstalling a library controls discovery of all its
styles; individual styles can also be enabled or disabled from the library
detail screen. Core prepares its own REST responses, including fields added by
other plugins. Aculect Icon Library filters discovery through
`rest_request_after_callbacks` and enhances the Icon block's chooser with
bounded search and pagination. It retains the native block and does not widen
WordPress's global SVG sanitizer.

## Documentation And Licenses

- [WordPress.org readme and FAQ](readme.txt)
- [Product scope and saved-icon behavior](PRODUCT.md)
- [Admin design and interaction guidance](DESIGN.md)
- [Contributor and development workflow](CONTRIBUTING.md)
- [Release checks](docs/release-checklist.md)
- [Release downloads and notes](https://github.com/mehul0810/aculect-icon-library/releases)
- [Bug reports](https://github.com/mehul0810/aculect-icon-library/issues)

Report security concerns through
[private security advisories](https://github.com/mehul0810/aculect-icon-library/security/advisories/new).
Keep sensitive details out of public issues.

Aculect Icon Library is licensed under GPLv2 or later. Bundled icon libraries
retain their upstream licenses: [Heroicons (MIT)](https://github.com/tailwindlabs/heroicons),
[Bootstrap Icons (MIT)](https://github.com/twbs/icons),
[Font Awesome Free](https://fontawesome.com/license/free),
[Tabler Icons (MIT)](https://github.com/tabler/tabler-icons),
[Radix Icons (MIT)](https://github.com/radix-ui/icons),
[Rune Icons (Apache-2.0)](https://github.com/Nexvyn/runeicons),
[Google Material Icons (Apache-2.0)](https://github.com/google/material-design-icons),
[Iconoir (MIT)](https://github.com/iconoir-icons/iconoir),
[Phosphor Icons (MIT)](https://github.com/phosphor-icons/core), and
[Ionicons (MIT)](https://github.com/ionic-team/ionicons).
