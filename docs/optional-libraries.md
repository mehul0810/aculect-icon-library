# Optional Library Runtime Contract

This describes the local installer architecture and its acceptance checks. It
is not approval to publish packages, populate the production catalog, or release
the plugin. The shipped catalog is empty.

## Ownership And Trust

The separate `aculect-icon-libraries` repository owns the data-only package
format, immutable upstream/converter provenance, license notices and independent
library/style release versions. The plugin owns a reviewed local catalog at
`data/library-catalog.json`. Each entry pins the ZIP size, ZIP SHA-256, manifest
SHA-256 and exact version. URLs are derived from the canonical GitHub release
location; browser input cannot choose a URL, digest or storage path.

`TrustedLibraryCatalog` validates release descriptors and version precedence.
`LibraryPackageValidator` checks bounded ZIP members, their digests, provenance,
license data and path-only SVG compatibility before activation. Test-fixture
provenance is accepted only through explicit server-side test injection.

`LibraryInstaller` coordinates explicit queue, claim, validation, immutable file
publication and activation. `LibraryJobStore` owns the current site's durable,
non-autoloaded state row and byte-exact compare-and-swap writes.
`InstalledLibraryRepository` owns local immutable files and adapts installed
manifests to the existing collection-provider boundary. `LibraryAdminController`
owns REST and non-JavaScript authorization; `AdminPage` owns presentation only.

## State And Compatibility

- Queueing pins the complete trusted descriptor. Retrying or resuming an
  existing job does not reinterpret its version using a changed catalog.
- An attempt claims a generation and expiring lease. Progress, failure and
  activation require that same unexpired lease. The active pointer and success
  status are written together. Failed attempts retain the previously active
  version; a superseded worker cannot activate over its replacement.
- Files are immutable and are activated only after validation. Existing saved
  identities cannot be reassigned. Removed icons retain their prior file paths
  and are excluded from discovery while exact saved names remain resolvable.
- Installation and collection/style enablement are separate actions. Disabling
  discovery does not delete local files or saved identities. Deactivating the
  plugin is outside this saved-icon guarantee.
- Recovery entries absent from the current catalog offer only the existing
  job's Retry/Resume action, not a new Install/Update action.
- State, storage paths and path grants are site-scoped. Site switches invalidate
  provider caches and remove this registrar's own Core entries before lazy
  registration for the current site. External Core registrations are retained.

## Runtime Acceptance

Run the commands in `AGENTS.md`, then install the exact production ZIP in a
disposable WordPress instance. Record its SHA-256 and bytes. Source-checkout or
mocked tests do not replace packaged runtime evidence.

Test the ordinary admin REST request without preloading administrative PHP
helpers. The installer must load the Core file helper when needed; a unit
bootstrap's `wp_tempnam` stub can otherwise hide a real REST failure. Exercise
the non-JavaScript form independently as it has a different bootstrap path.

Use explicit test-only catalog/transport injection outside the packaged plugin
for local fixtures. Keep the production catalog empty, block outbound HTTP and
mail, and use only synthetic users/content. Cover:

- First install, separate enablement, Core picker insertion, save/reopen and
  frontend rendering from local files.
- Update with a removed icon, a second style, disabled discovery and retained
  saved rendering, including same-request Core list and exact-icon reads.
- Corrupt/truncated downloads, timeouts, explicit retry, interruption/resume,
  duplicate submissions, downgrade rejection and immutable identity conflicts.
- Invalid nonce, unauthorized roles, file-modification policy and multisite
  super-admin authority. Read-only requests must not start jobs or downloads.
- Stale worker activation, first-CAS retry, site A/B isolation and restoring a
  previously selected site within the same request.
- Desktop/mobile layout, keyboard operation and truthful failure/progress state.

Report each case as passed, failed or unverified. A local injected download does
not prove a live GitHub redirect/download, source-owner approval, WordPress.org
distribution acceptance, or compatibility with an untested PHP/WordPress version.
