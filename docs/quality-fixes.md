# Bundled 1.2.0 Quality Fixes

Scope: the October 19 review of bundled-library selection, custom icons,
automated block editing, and admin/editor behavior. These local changes do not
authorize publication. Runtime downloads and lean migration are still deferred.

## Integrity Boundaries

- Library selection and custom metadata use one database-authoritative mutation
  owner. Cache contents are not a source of truth while writing. Lock ownership
  and related option changes are committed together, or rolled back together.
- Guarded mutations require supported transactional storage. An existing caller
  transaction must not be committed, replaced, or rolled back by the plugin.
- Automated post editing compares the exact original content and modified time
  while holding a database lock, then uses the WordPress update API. Clients
  should additionally send `expected_content_hash` from the block-list response
  to detect stale requests, including changes sharing a timestamp.
- Existing archived custom names cannot be reused for different artwork.
  Permanent custom deletion remains an explicit destructive operation.

## UI And Resource Boundaries

The Upload view owns its React lifetime, including request cancellation and
modal teardown during navigation. List errors must remain visible and retryable
without pretending stored icons disappeared. Server-rendered content remains
available when enhancement scripts fail. Screen-reader checks are excluded by
owner direction, not passed.

Picker discovery loads only relevant family metadata. Custom SVG hydration
follows the returned page size, not inventory size. Styled-icon variant lookup
is reused within a request and cleared when the current site changes. These
are bounded-work guarantees, not production latency or capacity claims.

Deferred download and preservation UI/helpers are kept outside shipped runtime
ownership. Current-site uninstall behavior is unchanged; network-wide cleanup
and deletion of prior downloaded-provider stores require a separately defined
retention contract. No new destructive cleanup is included.

## Verification Status

The ten scoped findings and the additional integrity defects discovered during
independent review are corrected. The full PHP suite passes on PHP 8.1 and 8.5
(272 tests, 53,967 assertions each), including the real SQLite translator checks.
Syntax checks cover 89 PHP files; all ten manifests, generated catalog metadata,
46 coding-standard targets, and 18 JavaScript tests pass. The frontend build
retains its existing bundle-size warnings.

Independent source review found no remaining actionable defect in the changed
scope. Packaged WordPress 7.1.2 save/reload/render smoke passed. Native Chrome
verified picker pagination focus and a saved icon reopening; admin browser checks
verified modal teardown on Back/Forward, visible list errors, successful Retry,
same-query data retention, new-query stale-data removal, script-failure fallback,
and ordinary operational-notice visibility. The 390px Upload layout has no
page-wide overflow; its wide inventory table scrolls within its own region.
These are bounded checks, not complete accessibility-conformance evidence.

The evidence checkpoint identifies the exact dirty-source overlay and ZIP;
HEAD alone does not identify this uncommitted work. PHP 7.4 execution, actual
MySQL/InnoDB or cross-host persistent-cache contention, production capacity and
the full multisite lifecycle remain unverified. SQLite and deterministic unit
tests do not prove those environments. Current-site uninstall retention remains
unchanged, and historical dependency audit findings were not re-audited here.
