# Aculect Icon Library

Easily add support for popular icon libraries and custom SVG icons to the native WordPress Icon block

Aculect Icon Library adds Heroicons, Bootstrap Icons, Font Awesome Free, and
custom SVG icons to WordPress's existing `core/icon` block. Manage libraries
from **Appearance > Icons**, enable the styles you need, and choose icons in
the block editor. It does not add a competing block or use icon fonts.

## Supported Icon Libraries

| Library | Variants |
| --- | --- |
| Heroicons | Outline, Solid |
| Bootstrap Icons | Default, Filled |
| Font Awesome Free | Solid, Regular, Brands |

No libraries are installed by default. Preview and search the included libraries
before installing them, filter by variant and category where available, and
enable only the styles you need. Font Awesome Pro styles are not bundled.
Included SVGs and custom uploads are stored locally; browsing and using them
requires no external service connection.

## Requirements And Quick Start

Requires **WordPress 7.1+** and **PHP 7.4+**.

1. Install the plugin ZIP through **Plugins > Add New Plugin > Upload Plugin**
   and activate Aculect Icon Library.
2. Open **Appearance > Icons > Install Library** and install a library.
3. Open its detail screen and enable the variants you need.
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
- `icon-library/list-icon-blocks`: list editable post `core/icon` blocks with stable block-tree paths and a `modified_gmt` token for stale-write protection.
- `icon-library/insert-icon-block`: insert a `core/icon` block at the root or inside a container.
- `icon-library/replace-icon-block`: assign a different icon and selected accessible presentation attributes to an existing block.
- `icon-library/remove-icon-block`: remove one `core/icon` block by path.

Read abilities require editor-style icon access. Post discovery and all content
mutations require the caller to have `edit_post` capability for the target
post. Mutation inputs accept an optional `expected_modified_gmt` value (returned
by `list-icon-blocks`) to detect changes completed before an operation starts.
This timestamp check is not an atomic write guard: concurrent editor or agent
writes can still race. Do not run concurrent mutations against the same post.
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

WordPress stores an Icon block's registered name rather than a copy of its SVG.
Deactivating or uninstalling the provider plugin therefore makes those icons
unavailable. This Core limitation is tracked upstream in
https://github.com/WordPress/gutenberg/issues/80668.

## Custom Icons

Administrators can add SVG files up to 64 KB through **Appearance > Icons > Upload**.
The plugin validates the file against the WordPress 7.1 icon geometry contract
before storing the sanitized SVG locally under the uploads directory. This does
not enable SVG uploads in the Media Library and makes no remote requests.

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

## Importing bundled libraries

```bash
git clone --depth=1 https://github.com/tailwindlabs/heroicons.git /tmp/heroicons
php scripts/import-heroicons.php /tmp/heroicons
php scripts/import-path-library.php bootstrap-icons /path/to/bootstrap-icons
php scripts/import-path-library.php font-awesome /path/to/font-awesome
php scripts/validate-manifests.php
```

## Development

Install the development tools with `composer install`. Tests use WordPress's
real block parser and serializer. Set `WP_CORE_DIR` to a WordPress checkout when
the plugin is not inside a site's `wp-content/plugins` directory. Node.js 20+
is required for the dependency-free admin navigation tests.

```bash
composer check
node --test tests/*.test.js
composer package
```

After importing or changing bundled manifests, run `composer catalog` to
regenerate compact `metadata.json` files. `composer check` rejects stale metadata.
Runtime manifest filters bypass these summaries to preserve filtered catalogs.
Provider callbacks are cached within a request; providers that change during
that request must call `CollectionRegistry::clear_request_caches()`. Stored
library/variant/custom-icon option changes invalidate derived registry caches.

See `docs/remediation-status.md` for the review fixes, measured scope, and
remaining concurrency and browser-proof work.

`composer package` creates a versioned release ZIP in `build/`, using the plugin
version in its filename, from an explicit production allowlist. SVG files referenced by validated library manifests
remain available through the documented Heroicons legacy size aliases.
The root `.distignore` mirrors the development paths excluded by compatible
WordPress distribution tooling; the built-in packager keeps its stricter
allowlist so an unexpected repository file cannot enter a release.

GitHub Actions runs the same checks and production packaging for semver release
and pre-release tags, then uploads the ZIP as a workflow artifact and GitHub
release asset. Prereleases also upload directory images as a separate preview
artifact and do not write to WordPress.org. Stable releases are configured to
deploy the verified ZIP through 10up's WordPress deploy action, with
`.wordpress-org/` images sent to SVN `/assets`, separate from plugin files.
Deployment requires the release environment's SVN credentials and repository
access. See [WordPress.org branding](docs/branding/README.md) for asset sizes,
validation, and release routing. Workflow configuration alone does not confirm
a successful directory deployment.

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
and restores the known stroke presentation with a scoped stylesheet in the
editor and on frontend requests containing the icon. This keeps Core's
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
other plugins. Aculect Icon Library only filters discovery results through
`rest_request_after_callbacks`; it does not replace the picker UI or widen
WordPress's global SVG sanitizer.

## Documentation And Licenses

- [WordPress.org readme and FAQ](readme.txt)
- [Contributor and development workflow](CONTRIBUTING.md)
- [Release downloads and notes](https://github.com/mehul0810/aculect-icon-library/releases)
- [Bug reports](https://github.com/mehul0810/aculect-icon-library/issues)

Aculect Icon Library is licensed under GPLv2 or later. Bundled icon libraries
retain their upstream licenses: [Heroicons (MIT)](https://github.com/tailwindlabs/heroicons),
[Bootstrap Icons (MIT)](https://github.com/twbs/icons), and
[Font Awesome Free](https://fontawesome.com/license/free).
