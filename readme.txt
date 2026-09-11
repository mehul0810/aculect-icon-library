=== Aculect Icon Library ===
Contributors: mehul0810
Tags: icons, blocks, svg, editor
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Easily add support for popular icon libraries and custom SVG icons to the native WordPress Icon block

== Description ==

Aculect Icon Library brings popular icon libraries and your own custom SVG
icons into the native WordPress Icon block. Choose the libraries and styles
your site needs, then select their icons directly in the block editor.

Manage everything from Appearance > Icons. No icon libraries are installed by
default: open Install Library to browse the included libraries and install
the ones you want to use.

= Popular icon libraries =

* Heroicons: Outline and Solid.
* Bootstrap Icons: Default and Filled.
* Font Awesome Free: Solid, Regular, and Brands.

Preview icons before installing a library. Search and filter by library,
variant, and category where available, and load more results as you browse.
Enable or disable individual variants and uninstall libraries you no longer
need.

= Your own custom icons =

Upload custom SVG files through the Upload tab, give them recognizable labels,
and use them alongside library icons in the native Icon block. Rename labels
or delete custom icons from the uploaded icons list.

SVG uploads are validated before storage and are limited to supported SVG
elements and attributes. Custom icons are managed by the plugin and do not
enable SVG uploads in the WordPress Media Library.

= Built around the native Icon block =

Enabled icons appear in the existing Icon block picker, where you can use the
block's own sizing and styling controls. Aculect Icon Library extends this
workflow without adding a separate icon block or using icon fonts.

Included libraries and uploaded icons are stored locally. No external service
connection is required to browse or use them.

= AI and automation =

The WordPress Abilities API integration lets compatible AI agents and tools
search enabled icons and insert, replace, or remove Icon blocks. Actions respect
WordPress permissions, including permission to edit the target post.

Requires WordPress 7.1 or later.

== Installation ==

1. Upload the plugin to the `wp-content/plugins/aculect-icon-library` directory.
2. Activate Aculect Icon Library in WordPress.
3. Open Appearance > Icons > Install Library to choose a library.

== Frequently Asked Questions ==

= Does this add a custom icon block? =

No. Aculect Icon Library integrates with the native WordPress `core/icon` block.

= Can administrators add custom SVG icons? =

Yes. Administrators can add SVG files through Appearance > Icons > Upload.
Files are limited to supported SVG geometry, are
validated before storage, and never enter the Media Library.

= What happens when a custom icon is removed or the plugin is uninstalled? =

The Icon block stores the registered icon name rather than an SVG copy. Existing
blocks that reference a removed custom icon continue to render, while the icon
is hidden from new selections. Uninstall removes plugin-owned custom icon files
and metadata, so icons still require the plugin to remain active.

On WordPress 7.1 and newer, Aculect Icon Library also exposes public Abilities API
actions for AI agents and automation: searching enabled icons, validating icon
metadata, listing `core/icon` blocks by stable paths, inserting and replacing
icons, and removing an icon block. Read actions use editor-style access; post
actions require permission to edit the target post. Inputs never accept raw SVG,
filesystem paths, arbitrary block markup, or post content.

== Source code ==

Source and build tools: https://github.com/mehul0810/aculect-icon-library

The editable DataViews source is assets/src/custom-icons-dataviews.js.
To rebuild assets/build/custom-icons-dataviews.js, run npm ci followed by
npm run build from the repository root. Dependencies and their exact versions
are recorded in package.json and package-lock.json.

== Third-party licenses ==

Bundled libraries remain under their upstream terms:

* Heroicons: https://github.com/tailwindlabs/heroicons (MIT license).
* Bootstrap Icons: https://github.com/twbs/icons (MIT license).
* Font Awesome Free: https://fontawesome.com/license/free (Font Awesome Free license).

== Changelog ==

= 1.0.0 =

* Integrate collections with the WordPress 7.1 public Icon API.
* Bundle 648 Heroicons Outline and Solid icons, 2,073 Bootstrap Icons, and 2,883 Font Awesome Free style entries with the upstream 68-category taxonomy plus a Brands grouping, separately categorized variants, and deterministic manifests. Outline is opt-in while its WordPress 7.1 Core sanitization workaround is validated.
* Expose available styles separately in the native Icon picker collection filter.
* Add per-variant enable and disable controls with capability-checked REST routes.
* Add Appearance > Icons library management and searchable previews.
* Add strict local custom SVG icon management.
* Preserve saved icons when bundled libraries are disabled.
* Add reproducible validation and release packaging.
* Add permissioned WordPress Abilities API actions for icon discovery and core/icon block editing.
