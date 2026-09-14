=== Aculect Icon Library ===
Contributors: mehul0810
Tags: icons, icon library, svg icons, icon block, custom icons
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Easily add support for popular icon libraries and custom SVG icons to the native WordPress Icon block

== Description ==

Aculect Icon Library is a WordPress plugin that adds Heroicons, Bootstrap Icons,
Font Awesome Free, and custom SVG icons to the native WordPress Icon block.
Manage libraries from Appearance > Icons, enable the styles you need, and
select icons in the WordPress block editor.

Manage everything from Appearance > Icons. No icon libraries are installed by
default: open Install Library to browse the included libraries and install
the ones you want to use.

= Heroicons, Bootstrap Icons, and Font Awesome Free =

* Heroicons: Outline and Solid.
* Bootstrap Icons: Default and Filled.
* Font Awesome Free: Solid, Regular, and Brands.

Preview icons before installing a library. Search and filter by library,
variant, and category where available, and load more results as you browse.
Enable or disable individual variants and uninstall libraries you no longer
need.

= Upload and manage custom SVG icons =

Upload custom SVG files through the Upload tab, give them recognizable labels,
and use them alongside library icons in the native Icon block. Rename labels
or delete custom icons from the uploaded icons list.

SVG uploads are validated before storage and are limited to supported SVG
elements and attributes. Custom icons are managed by the plugin and do not
enable SVG uploads in the WordPress Media Library.

= Use icons in the WordPress block editor =

Enabled icons appear in the existing Icon block picker, where you can use the
block's own sizing and styling controls. Aculect Icon Library extends this
workflow without adding a separate icon block or using icon fonts.

Included libraries and uploaded icons are stored locally. No external service
connection is required to browse or use them.

= Icon automation with the WordPress Abilities API =

The WordPress Abilities API integration lets compatible AI agents and tools
search enabled icons and insert, replace, or remove Icon blocks. Actions respect
WordPress permissions, including permission to edit the target post.

Requires WordPress 7.1 or later and PHP 7.4 or later.

== Installation ==

1. Upload the plugin to the `wp-content/plugins/aculect-icon-library` directory.
2. Activate Aculect Icon Library in WordPress.
3. Open Appearance > Icons > Install Library to choose a library.
4. Install the library and enable the variants you want to use.
5. Open a post or page in the block editor, insert the Icon block, and select
   an icon from the enabled library. Adjust its sizing and styling, then save.

== Frequently Asked Questions ==

= Which icon libraries are included? =

Heroicons includes Outline and Solid. Bootstrap Icons includes Default and
Filled. Font Awesome Free includes Solid, Regular, and Brands; Pro-only styles
are not included. No libraries are installed by default.

= How do I add an icon to a WordPress post or page? =

Install a library from Appearance > Icons > Install Library, then insert the
native Icon block in the block editor. Choose an icon from an enabled library
and use the block's sizing and styling controls.

= Does this add a custom icon block? =

No. Aculect Icon Library integrates with the native WordPress `core/icon` block.

= Can administrators add custom SVG icons? =

Yes. Administrators can add SVG files through Appearance > Icons > Upload.
SVG files must be no larger than 64 KB and use supported SVG geometry.
Unsupported features can cause an upload to be rejected; arbitrary SVG files
are not supported. Use flattened paths with explicit fills for complex exports.

= Does this enable SVG uploads in the Media Library? =

No. Custom SVG icons are validated and stored separately by Aculect Icon Library.
The plugin does not enable general SVG uploads in the WordPress Media Library.

= Are icons hosted locally? =

Yes. Included library SVGs and uploaded custom icons are stored on your site.
No external service connection is required to browse or use them.

= Can I enable or disable individual icon styles? =

Yes. Open a library's detail screen to enable or disable its variants, such as
Solid or Outline. Disabled variants stop appearing for new selections.

= What happens when I disable or uninstall a bundled library? =

Its icons stop appearing for new selections. Existing saved Icon blocks can
still resolve those icons while Aculect Icon Library remains active and its
bundled SVG files remain available. This also applies to disabled variants.

= What happens when I delete a custom icon? =

Deleting a custom icon permanently removes its stored SVG and metadata.
Existing blocks that reference it can no longer resolve that icon. Replace
those icons in your content before deleting them. Renaming a display label
does not change the icon's registered name.

= What happens when I deactivate or uninstall the plugin? =

The Icon block stores the registered icon name rather than an SVG copy. Existing
post content remains, but plugin-provided icons cannot resolve while the plugin
is inactive. Uninstalling also removes plugin settings, custom icon files, and
custom icon metadata. Back up custom icons before uninstalling.

= Can AI agents manage icons? =

Compatible agents and tools can use the WordPress Abilities API to search enabled
icons and list, insert, replace, or remove native Icon blocks. The plugin does
not include an AI assistant. Read actions require editor-style access; post
actions require permission to edit the target post. Ability inputs do not accept
raw SVG, filesystem paths, arbitrary block markup, or post content.

= What are the requirements? =

Aculect Icon Library requires WordPress 7.1 or later and PHP 7.4 or later.
It integrates with the native WordPress Icon block, not a page-builder-specific
icon widget.

== Screenshots ==

1. View installed icon libraries and their active variants from Appearance > Icons.
2. Browse Heroicons, Bootstrap Icons, and Font Awesome Free in the Install Library tab.
3. Upload custom SVG icons, search uploaded icons, rename labels, and delete icons.

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
