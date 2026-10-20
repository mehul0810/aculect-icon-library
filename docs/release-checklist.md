# Aculect Icon Library Release Checklist

## Automated Gate

- Run `composer install` from the committed lock file.
- Run `composer check` on PHP 7.4 and the current supported PHP version. If
  PHP 7.4's Zip extension lacks `ZipArchive::setMtimeName()`, set
  `ICON_LIBRARY_BUILD_PHP` to an executable build PHP with that method for the
  package-test subprocess; all other assertions must still run under PHP 7.4.
- Run `ICON_LIBRARY_WP_LOAD=/path/to/wp-load.php composer smoke` on WordPress 7.1.
- Run `composer package` twice with a build PHP whose Zip extension provides
  `ZipArchive::setMtimeName()`, and confirm identical SHA-256 hashes.
- Confirm the package is one `bundled` profile and that `--bridge` fails with
  usage error rather than creating another artifact.
- Inspect the ZIP: it must include the ten bundled families, each manifest-
  referenced SVG, every applicable license and exclusion file, and Heroicons
  legacy aliases. Verify the current manifest-derived SVG count rather than
  relying on a stale hard-coded count.
- Confirm deterministic `data/package-profile.json` metadata identifies the
  `bundled` profile.
- Confirm runtime migration/install/discovery/transport code, `assets/library-installer.js`,
  `data/library-catalog.json`, and development/repository files are absent.
- Confirm `src/InstalledLibraryRepository.php` and `src/LibraryJobStore.php`
  are present only for the read-only saved-runtime provider closure and that
  packaged wiring cannot reach write/install methods.
- Run Plugin Check against the extracted production ZIP.
- Confirm the ZIP is below 10,000,000 bytes and contains all package assets.

## WordPress.org Package

- Validate `readme.txt` with the official WordPress.org Readme Validator.
- Check that README/readme usage and style claims match `PRODUCT.md`, current
  manifests, and the packaged UI; keep the short description within 150
  characters and use at most five relevant tags. Preserve contributor credits.
- List each new library in its own linked "Added support for" changelog entry,
  retaining subset qualifiers. Exclude deferred download/migration plans.
- Confirm the built ZIP's readme matches the reviewed source. Any readme edit
  changes the package identity even if all runtime files are unchanged.
- Confirm the ZIP extracts to one `aculect-icon-library/` directory and
  excludes tests, development dependencies, build outputs, and repository
  metadata while retaining runtime assets under `assets/build/`.
- Confirm every bundled family has its upstream license and source attribution.
- Confirm Tabler ships exactly 1,012 icons with 35 Brand-category and seven
  selected trademark or character exclusions, and Radix ships exactly 299
  icons with 14 trademark and five compatibility exclusions.
- WordPress.org submission, credentials configuration, and deployment remain
  owner-authorized release actions. Packaging is local and does not contact
  WordPress.org.

## Runtime

- Verify the standard plugin update preserves bundled manifests, licenses,
  Heroicons aliases, enabled-library preferences, and saved icon resolution.
- Verify no runtime installer, discovery/preview network request, or legacy
  migration workflow is presented or callable from the packaged plugin.
- Verify the bundled Icon block integration, custom SVG validation, and
  existing read-only saved-runtime provider behavior in a disposable site.
- Exercise keyboard navigation, labels, focus, contrast, and responsive admin
  layout as applicable; macOS VoiceOver checks are excluded by owner direction
  and must not be reported as passed.

## Release Execution

- Complete independent exact-head engineering review and separate package and
  behavior proof.
- Keep stable and pre-release workflow gates fail-closed until separate owner
  release approval; do not remove publication checks or widen permissions.
- Merge, tag, publish, or deploy only with the applicable explicit owner
  authorization and after the release packet is complete.
