# GitHub discovery and previews (local 1.2.0 candidate, issue #64)

Fresh activation creates an empty collection state and an empty legacy marker.
It reads no bundled manifests/SVG geometry for collection discovery and makes no
external requests. WordPress's own collection remains owned by Core. A user's
custom icons remain local and keep their existing behavior.

Appearance > Icons > Install Library provides **Refresh from GitHub**, then
**Preview** and **Install**. Each is an explicit nonce-protected administrator
action. Refresh contacts only the existing repository's `main/data/catalog.json`
on raw.githubusercontent.com. Page loads, the editor and the frontend do not
refresh it. Preview fetches a separate sample JSON, never a ZIP or installation
job. Install keeps the existing transactional, exact-version installer. A new
installed collection starts disabled and can be enabled in Library. Disable
hides picker discovery while preserving exact saved-name rendering offline.

The public repository now lists all 15 issue-backed families, with 35 prepared
packs containing 38,720 icons across 13 families. Simple Icons and Keyline remain
gated. Real catalog and all 35 previews were verified through WordPress; full
release assets are published and independently HTTP-verified against the reviewed
CI bytes. Only those 35 entries are installable. Local pins retain two earlier immutable candidate
descriptors for existing installed content. Fluent uses eight size collections,
each with Regular/Filled styles, to bound state and metadata. Explicit installs
use WordPress's normal per-request admin memory allowance; no persistent memory
configuration changes. Neither hashes nor these checks establish publisher
signatures or WordPress.org directory approval.

## Trust and bounds

`data/library-catalog.json` is the plugin's reviewed trust allowlist. GitHub's
index controls discovery only: library/style/version, ZIP digest, manifest
digest, ZIP length, preview digest, preview length and optional immutable preview
revision must match those local
pins. Remote URLs, executable modules, extra publisher keys and changed hashes
cannot create install authority. Cached entries are matched again on every read.
New libraries/versions currently require new reviewed plugin pins. Fully dynamic
additions without plugin updates would require a separate reviewed signature/key
and rotation/revocation design; this candidate does not trust a remotely supplied
key or self-asserted publisher.

TLS verifies the fixed GitHub/raw/CDN server endpoint. Repository ownership and
the locally shipped reviewed pins define the publisher trust assumption. Hashes
prove byte integrity relative to those pins, not that a package author is who
they claim to be. A compromised account can withdraw index entries or disrupt
availability, but cannot replace an approved package or sample with new bytes.

The index is limited to 1 MiB, 100 entries, JSON depth 16, 15-second requests,
TLS verification and no redirects. Previews are limited to 256 KiB, 12 samples,
200-byte labels and 64 KiB per SVG. Sample requests permit at most three HTTPS
redirects to the exact GitHub release-assets host, with no credentials, custom
port or fragment. Every SVG passes the existing strict custom-SVG sanitizer;
output passes the existing SVG allowlist again. All entries are plain JSON and
static SVG. No remote PHP, JavaScript, CSS or HTML executes.

Last successful catalog metadata and validated preview bytes are non-autoloaded,
per-site options. The UI shows the catalog refresh time. Failed transport,
malformed/oversized JSON, changed preview hashes or unsafe SVG leave prior cache
and installed state intact. A valid empty remote index withdraws available
choices; installed collections/jobs remain locally manageable. Cached samples
work offline while their release remains in the saved index. Corrupt cached
preview bytes fail closed; explicit Preview can retry their small download.

## Upgrade compatibility

On an existing installation, the first plugin boot records the already-present
bundled collection slugs in `icon_library_legacy_collections` without changing
enabled collections, variants, custom icons, jobs, posts or assets. It also works
when activation does not run during an update and when the site skips versions.
Legacy disabled collections remain available for saved content and for manual
reenabling. Existing runtime packages retain their immutable storage and saved
names. Fresh activation sets an empty marker and hides the bundled collections.

The ten legacy collection asset trees remain in the ZIP. Removing them now could
break disabled/seldom-used saved content or a skipped-version upgrade. Therefore
this candidate reduces fresh runtime discovery/loading, **not plugin ZIP size**.
Asset removal needs a separately proven migration that preserves all existing
icon IDs and offline artwork, including older Heroicons paths. Rollback is to
restore the previous plugin; no content or package deletion migration occurs.

## GitHub data layout recommendation

Reuse `mehul0810/aculect-icon-libraries`, its existing `data/catalog.json`, and
immutable per-style release tags:

```
data/catalog.json                      # published reviewed descriptor index
data/previews/<library>-<style>-<version>.preview.json # bounded licensed samples
releases/download/lucide-outline-1.0.0/
  lucide-outline-1.0.0.zip             # complete validated data pack
  lucide-outline-1.0.0.descriptor.json  # provenance/integrity record
  lucide-outline-1.0.0.preview.json     # <=12 samples, separate pinned digest
```

The ZIP retains `manifest.json`, `icons/*.svg`, `licenses/*`; licenses, upstream
revision and conversion revision remain pinned. Build samples reproducibly from
that validated archive with companion tooling `tools/build_preview.py`; each
separate sample JSON embeds the original license/copyright notices. Publish
entries as installable only after every full-package artifact exists and independent HTTP
hash verification passes. Never replace versioned bytes or move published tags.
Retain old releases for existing jobs and saved artwork. Index changes may remove
discovery but must never purge a site's existing data.

Whole-style packs reuse the tested installer and are the current recommendation.
Per-icon downloading would need many more integrity, ownership and offline
recovery transactions. The bounded sample format supplies preview without
requiring a full-pack fetch. Current previews use immutable raw GitHub repository
URLs derived from locally pinned revisions; their license text is escaped and
shown in an accessible attribution disclosure. Unpublished packages remain
previewable but cannot start installation jobs. No new repository/catalog/service
is necessary.

## Distribution gate

The official WordPress directory guidelines checked 2026-10-06 were last updated
2026-03-11. Guideline 7 calls for authorized consent and identifies offloaded
assets unrelated to a service as prohibited; guideline 8 limits external code
and non-service remote lists. Keeping downloads data-only and opt-in is useful
engineering but does not establish acceptance of GitHub-hosted icon offloading.
Treat directory acceptance as unresolved; no Plugin Team outreach was performed.

Source: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
