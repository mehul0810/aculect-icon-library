# Aculect Icon Library Release Checklist

## Automated Gate

- Run `composer install` from the committed lock file.
- Run `composer check` on PHP 7.4 and the current supported PHP version.
- Run `ICON_LIBRARY_WP_LOAD=/path/to/wp-load.php composer smoke` on WordPress 7.1.
- Run `composer package` twice and confirm identical SHA-256 hashes.
- Run Plugin Check against the extracted production ZIP.
- Confirm the package contains manifest-referenced SVG files plus the explicitly
  documented Heroicons legacy size aliases.

## WordPress.org Submission

- Run the official WordPress.org Readme Validator against `readme.txt`.
- Confirm the ZIP is below 10 MB, extracts to a single `aculect-icon-library/`
  directory, and contains no tests, development dependencies, root-level build
  artifacts, or repository metadata. The runtime assets under `assets/build/`
  must remain included.
- Confirm all bundled third-party license files and source links are present.
- Confirm Tabler ships exactly 1,019 icons with 35 recorded brand exclusions,
  and Radix ships exactly 299 icons with 14 trademark and five compatibility
  exclusions. Re-run both pinned importers and verify byte-identical output.
- Before the first directory release, submit the production ZIP for WordPress.org
  review and configure the approved SVN credentials in the protected `release`
  environment. Stable tag workflows publish the verified ZIP and top-level
  `.wordpress-org/` assets to the assigned SVN repository.

## Editor and Frontend

- Open Appearance > Icons at desktop and mobile widths.
- Activate and deactivate Heroicons with keyboard controls and verify status messages.
- Enable the experimental Heroicons Outline variant and verify Core's sanitized output retains the root marker and renders through the scoped stylesheet.
- Search and filter the icon browser.
- Open Font Awesome Free and verify the Solid, Regular, and Brands variants,
  the official category labels/counts, and category-plus-search filtering.
- Install Tabler Icons and Radix Icons separately. Verify their categories,
  search results, default-off state, disable/re-enable flow, and uninstall/reinstall
  persistence before testing them together.
- Select one unconverted and one converted-geometry Radix icon in the native
  Icon block, then save, reload, and compare editor and frontend rendering.
- Confirm collection-scoped Core icon requests remain responsive with all
  bundled libraries enabled; repeat a cold and warm request for a large style.
- On WordPress 7.1+, list the registered `icon-library/*` Abilities and run the
  read-only catalog abilities with an editor account.
- With a disposable post, list icon blocks, insert, replace, and remove one
  block through the Abilities API; verify `edit_post` enforcement and stale-post
  rejection with `expected_modified_gmt`.
- Insert a bundled icon and a custom icon in the native Icon block.
- Change supported block styles, save, reload, and inspect the frontend.
- Disable Heroicons and verify existing content still renders while new discovery is hidden.
- Delete a disposable custom icon only after confirming the dependency warning.

## Release Execution

- Complete independent exact-head engineering review and separate package and behavior proof.
- Merge the release branch only after all required checks pass and the approved mainline-first path is satisfied.
- Create the matching version tag and GitHub/WordPress.org release only after the release packet is complete.
