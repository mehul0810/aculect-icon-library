# Aculect Icon Library Product Contract

## Purpose And Audience

Aculect Icon Library helps WordPress site administrators and content editors
use curated SVG libraries and custom artwork in the native Icon block.
Administrators choose available libraries and styles; editors select icons
while writing posts and pages. Developers and compatible automation tools can
use the permissioned REST and WordPress Abilities integrations.

This document records the approved **1.2.0 bundled delivery**. It is a product
contract, not a release announcement or evidence that a package was published.
Check the current source, package, and release receipts before readiness claims.

## Scope

- WordPress 7.1+ and PHP 7.4+.
- One plugin ZIP containing ten curated families: Heroicons, Bootstrap Icons,
  Font Awesome Free, Tabler Icons, Radix Icons, Google Material Icons, Iconoir,
  Phosphor Icons, Ionicons, and Rune Icons.
- Supported subsets and style boundaries are listed in [README.md](README.md#bundled-libraries).
  Pinned manifests, licenses, and exclusions under `assets/icons/` determine the
  actual artwork. Upstream catalog totals are not plugin inventory totals.
- Native `core/icon` selection and rendering, library/style management, local
  custom SVG uploads, and permissioned automation actions.
- All required icon artwork is local. No icon-service account, CDN, icon font,
  or runtime library download is required.

## Main Workflows

| Location | Task | Result |
| --- | --- | --- |
| Appearance > Icons > Install Library | Preview and select a bundled family | Adds it to Library, initially disabled; no download |
| Library | Enable or disable a family or its styles | Changes availability for new Icon block selections |
| Install Library > family > Remove from Library | Remove a selected family | Disables and hides it from Library; retains bundled artwork and saved IDs |
| Upload | Upload, search, relabel, or delete custom icons | Manages locally stored custom SVG artwork |
| Block editor > Icon | Choose an enabled icon and save content | Uses the existing WordPress block and its presentation controls |

Fresh sites start with no selected or enabled families. Existing installations
retain their preferences and saved icon names through an ordinary update.
Installing and enabling are separate actions; disabling does not remove the
selection. Do not change these meanings through copy alone.

## Saved Artwork And Data

- Saved blocks store registered icon names. Preserve namespaces, legacy
  Heroicons aliases, and the artwork associated with an existing name.
- Disabled styles and removed bundled selections remain renderable for saved
  blocks while the plugin is active and their files are present.
- Custom icons use supported, validated SVGs up to 64 KB. Display labels may
  change; registered names remain stable. This is separate from Media Library
  SVG uploads.
- An existing active or legacy archived name cannot be reused to replace its
  artwork. Require a new name. The current UI has no archive/restore workflow.
- Deleting a custom icon permanently removes its metadata and SVG, and breaks
  references to it. Explain that consequence before the action.
- Deactivation stops the plugin from providing icons. Uninstall removes
  current-site settings and custom icon storage; it does not promise a
  network-wide or historical-provider cleanup. See [Icon Lifecycle](README.md#icon-lifecycle).
- Metadata writes must preserve concurrent changes and transaction ownership.
  Automated post edits must reject stale content and preserve Core hooks and
  revisions. Maintain capability, nonce, sanitization, and rollback boundaries.

## Explicitly Outside 1.2.0

Remote catalogs, downloadable library packages, background availability updates,
and two-stage bridge/lean migrations are deferred. Research and implementation
files retained in Git do not make them production features. See
[optional-library status](docs/optional-libraries.md) and
[legacy migration status](docs/legacy-migration.md).

The plugin does not provide Font Awesome Pro artwork, every upstream style,
Material Symbols, a standalone AI assistant, unrestricted SVG uploads, or a
page-builder-specific icon widget. A later change to delivery or data policy
needs its own approved scope and validation.

## Documentation And Release Evidence

- `README.md`: user onboarding plus developer integrations and setup.
- `readme.txt`: concise WordPress.org description, FAQ, credits, and changelog.
- `DESIGN.md`: admin interaction and presentation guidance.
- [Release checklist](docs/release-checklist.md): source, package, runtime, and
  publication gates. Local tests do not establish hosted CI or production proof.
- [Contributing](CONTRIBUTING.md): development commands and contribution policy.

Describe benefits concretely: choosing libraries, using the native Icon block,
local artwork, and managing custom SVGs. Search terms are editorial hypotheses,
not measured demand. Do not invent rankings, install gains, performance results,
accessibility conformance, or policy approval. Publication remains a separate
owner-authorized action.
