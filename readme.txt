=== Aculect Icon Library ===
Contributors: mehul0810, alkesh7
Tags: block, icon, icon-block, SVG, SVG block
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use custom SVG icons alongside popular icon libraries like Heroicons, Bootstrap Icons, and Font Awesome Free in the native WordPress Icon block.

== Description ==

Pick the icon that fits your design. Use an envelope from Bootstrap Icons on your contact page or upload your own brand mark. Enabled library icons and uploaded SVGs share the same picker. Choose an icon and adjust its size right in the WordPress Icon block. No icon font or CDN needed.

Select **Live Preview** on WordPress.org to try the library before installing it on your site.

= Choose icons from bundled libraries =

In **Appearance → Icons**, preview a library before adding it. Enable just the styles you want editors to see, such as Heroicons Outline for navigation or Font Awesome Free Brands for social links. The SVG files are included with the plugin and served from your site. You don't need an icon service account, an API key, or a CDN.

Available libraries and styles:

* **Heroicons:** Outline and Solid.
* **Bootstrap Icons:** Default and Filled.
* **Font Awesome Free:** Solid, Regular, and Brands. Pro icons are not included.
* **Tabler Icons:** Filled, excluding the Brands category and selected trademark or character icons.
* **Radix Icons:** Default, excluding logos.
* **Google Material Icons:** Selected Filled icons from the 24px set.
* **Iconoir:** Selected Solid icons with filled paths.
* **Phosphor Icons:** Regular and Fill.
* **Ionicons:** Selected Filled, Sharp, Outline, and Brands icons.
* **Rune Icons:** Pixelated.

= Use your own SVG icons =

As an administrator, open **Appearance → Icons → Upload** and choose an SVG up to 64 KB. Give it a display label your editors will recognize, check the icon name, and select **Upload icon**. Editors can then find it by label in the Icon block picker. You can rename the label later without changing the icon used in existing posts.

= Use icon tools through MCP =

Aculect Icon Library registers actions with the WordPress Abilities API. It does not include a Model Context Protocol (MCP) server or connector. To use these actions from an AI assistant, choose any MCP connector that exposes the plugin's WordPress abilities. For example, you can ask your assistant to find an enabled icon and replace the icon in a draft post.

We recommend [Aculect AI Companion](https://wordpress.org/plugins/aculect-ai-companion/), the MCP connector we build alongside Icon Library. It makes the icon abilities available to your connected assistant and lets you control what the assistant can do. You don't need a connector to use icons in the block editor.

= From Mehul Gohil =

* [PreviewShare](https://wordpress.org/plugins/previewshare/): Share a draft with a reviewer without publishing it or creating a WordPress account for them.
* [CleanLinks](https://wordpress.org/plugins/cleanlinks/): Create short links on your own domain and track clicks.
* [OneCaptcha](https://onecaptchawp.com/): Protect WordPress forms from spam with your choice of CAPTCHA provider.
* [ThemeRouter](https://themerouter.com/): Try a new theme on selected pages while the rest of your site keeps its current theme.
* [WP Distraction Free View](https://wordpress.org/plugins/wp-distraction-free-view/): Give visitors a reader mode without sidebars or navigation.
* [Aculect AI Companion](https://wordpress.org/plugins/aculect-ai-companion/): Connect an AI assistant to WordPress through MCP and choose which actions it can use.
* [Perform](https://wordpress.org/plugins/perform/): Manage page caching and choose where scripts and styles load.

== Installation ==

1. In **Plugins → Add New Plugin**, search for **Aculect Icon Library**, then install and activate it. For a manual installation, upload the plugin folder to `wp-content/plugins/aculect-icon-library` and activate it.
2. Open **Appearance → Icons → Install Library**. Choose a library and select **Install Library**. This selects files already included with the plugin; nothing is downloaded.
3. Switch to **Library**, open the library you selected, and choose **Enable** for the styles you want in the picker.
4. Edit a post or page, insert the WordPress **Icon** block, and choose an icon. Use the block's own controls to adjust its size and styling.

== Frequently Asked Questions ==

= Does this work with my page builder? =

Aculect Icon Library extends the native Icon block in WordPress 7.1 or later. It does not add a separate block or change a page builder's icon widget. Your site also needs PHP 7.4 or later.

= Why isn't the library I installed showing up in the Icon block? =

Installing a library and enabling it are separate steps. New libraries start disabled. Go to **Appearance → Icons → Library**, open the library, and enable at least one style. Its icons will then appear in the block picker.

= Can I use my own SVGs without enabling a library? =

Yes. Upload a supported SVG in **Appearance → Icons → Upload**. Custom icons work on their own or alongside library icons. Uploads are validated and stored separately; this does not enable SVG uploads in the WordPress Media Library.

= Why was my SVG rejected? =

Check that the file is an SVG no larger than 64 KB. Not every SVG feature is supported. For complex artwork, export a version with flattened paths and explicit fills. New icon names can be up to 100 characters and display labels up to 200.

= Can I search uploaded icons using a non-English label? =

Yes. When you search the uploaded icons list, matching ignores case for international labels if your server has the PHP `mbstring` extension. Without it, case-insensitive matching is limited to ASCII. The extension is not required to upload or use icons.

= Will existing posts keep their icons if I disable a style or remove a library? =

Yes, for bundled icons. Disabling a style or removing a library from your selection hides those icons from new selections. Saved Icon blocks still show their artwork as long as the plugin is active and the bundled SVG files are available.

= What happens if I delete an uploaded icon? =

Replace the icon in any posts that use it **before** deleting it. Deletion permanently removes the custom SVG and its metadata, so existing blocks can no longer display it. To change only the text editors see in the picker, rename its display label instead.

= What happens to my icons when I update, deactivate, or uninstall? =

A normal update keeps your library selections, settings, and saved icon names. Deactivation leaves post content in place, but the Icon block saves an icon name, not a copy of the SVG. It cannot display plugin-provided artwork while the plugin is inactive. Uninstalling also deletes plugin settings and uploaded custom icons. Back up your custom SVGs before uninstalling.

= What can a connected AI assistant do with icons? =

When your MCP connector allows it, an assistant can search enabled icons, look up an icon by name, and list, insert, replace, or remove native Icon blocks. Searching the catalog requires permission to edit posts. To list or change the Icon blocks in a particular post, the connected user must be able to edit that post. The abilities work with icon names, not raw SVG or arbitrary block markup.

== Screenshots ==

1. View bundled icon libraries and their active variants from Appearance > Icons.
2. Search and filter bundled icons by library, variant, and category.
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
* Tabler Icons: https://github.com/tabler/tabler-icons (MIT license).
* Radix Icons: https://github.com/radix-ui/icons (MIT license).
* Rune Icons, pixelated variant only: https://github.com/Nexvyn/runeicons (Apache-2.0; bundled license and SVG modification notices).
* Google Material Icons, compatible filled 24px subset: https://github.com/google/material-design-icons (Apache-2.0; bundled license and modification notices).
* Iconoir, compatible Solid subset: https://github.com/iconoir-icons/iconoir (MIT license; bundled license and exclusions).
* Phosphor Icons, Regular and Fill: https://github.com/phosphor-icons/core (MIT license; bundled license and exclusions).
* Ionicons, compatible style subset: https://github.com/ionic-team/ionicons (MIT license; bundled license and exclusions; brand marks remain their owners' trademarks).

== Changelog ==

= 1.2.3 =

* Preview a custom SVG before uploading it, edit its display label and icon name together, and manage saved icons from their row menus.
* Load uploaded-icon previews without rereading the whole list for each row, and sort by the displayed icon name.
* Show when stored artwork is unavailable and report storage failures separately from missing icons.
* Match the digit 0 when searching the bundled catalog.
* Recover the Icon block picker after an initial loading failure so collections can be retried instead of remaining unavailable.
* Support case-insensitive custom-icon searches for international labels when PHP mbstring is available, with an ASCII fallback otherwise.
* Clarify setup steps, supported library styles, and custom-icon behavior in the plugin documentation.

= 1.2.2 =

* Load available collections and the first page of icons together when opening the Icon block picker. Make browsing and searching bundled icons faster.
* Install or remove bundled libraries, and enable or disable their styles, without reloading the admin view or rebuilding the current preview.
* Load custom SVG management scripts only on the Upload tab.
* Skip unnecessary processing on pages without Icon blocks and reduce the work needed to resolve saved icons.
* Keep library listings and icon queries consistent when integrations filter available collections.

= 1.2.1 =

* Improve repeated switches between Library, Install Library, and Upload, and prevent duplicate success notifications.
* Keep saved outline icons styled when pages use cached or template-rendered content.
* Remove unpublished SVG files after a definite upload metadata failure while preserving artwork when the save outcome is uncertain.
* Restore the uploaded-icon list after deleting the last icon on a page.
* Show actionable upload failure guidance when JavaScript is unavailable.

= 1.2.0 =

* Added support for [Phosphor Icons](https://phosphoricons.com/) (Regular and Fill).
* Added support for [Ionicons](https://ionic.io/ionicons) (compatible Filled, Sharp, Outline, and Brands subset).
* Added support for [Iconoir](https://iconoir.com/) (compatible Solid subset).
* Added support for [Rune Icons](https://github.com/Nexvyn/runeicons) (Pixelated).
* Added support for [Google Material Icons](https://github.com/google/material-design-icons) (compatible Filled 24px subset).
* Add Install Library to choose from ten bundled icon families, then enable their styles in Library. Removing a library preserves saved icons.
* Reduce icon picker loading and load custom icon previews one page at a time.
* Protect library settings and custom icons during concurrent changes, and prevent uploads from replacing archived artwork.
* Prevent automated post edits from overwriting newer changes.
* Improve keyboard focus during icon picker pagination and upload retries, and close upload dialogs when switching tabs.
* Show clearer upload and listing errors and retain existing results when a refresh fails.

= 1.1.0 =

* Added support for [Tabler Icons](https://tabler.io/icons).
* Added support for [Radix Icons](https://www.radix-ui.com/icons).
* Preserve upstream categories, searchable metadata, licenses, and source revisions for both libraries.
* Add deterministic importers and exclusion reports without weakening the WordPress Icon API SVG contract.

= 1.0.1 =

* Add a WordPress.org Playground preview Blueprint that installs the latest stable package and creates an Icon block demo.
* Validate the preview package source and fail clearly when the demo page cannot be created.
* Extend PHPCS coverage so `shell_exec()` usage must remain explicitly reviewed. Props Alkesh Miyani (`@alkesh7`).
* Document the reasoning behind security, filesystem, permissions, and concurrency safeguards. Props Alkesh Miyani (`@alkesh7`).
* Strengthen release-package validation and release documentation for the 1.0.1 candidate.

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
