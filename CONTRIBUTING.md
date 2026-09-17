# Contributing to Aculect Icon Library

Thank you for helping improve Aculect Icon Library. Contributions may be
written by people or with AI assistance, but the same evidence, review, and
safety requirements apply to every change.

## Before You Start

1. Search [open and closed issues](https://github.com/mehul0810/aculect-icon-library/issues)
   and pull requests for existing work.
2. Open or join an issue describing the problem, expected outcome, and scope.
   For coordinated security fixes, use the private advisory as the tracking
   reference instead. Do not post exploit details, credentials, private data,
   or unsafe SVG samples publicly.
3. Wait for a maintainer to confirm the scope, milestone, and target branch
   before implementation. An accepted issue or private advisory is not
   permission to expand its scope.

For a suspected vulnerability, use a private
[GitHub Security Advisory](https://github.com/mehul0810/aculect-icon-library/security/advisories/new).
Do not open a public issue or include sensitive vulnerability details in a pull
request.

## Branch Strategy

- Do not target development pull requests at `main` or `master`. `main` is the
  stable branch and receives reviewed release pull requests only.
- Target the `release/<version>` branch assigned by a maintainer for the issue's
  milestone.
- Use `develop` only when a maintainer explicitly directs unmilestoned work
  there. Do not assume that `develop` exists or is the correct base.
- Use one issue or private security advisory, one focused branch, and one pull
  request. Name branches by purpose, for example `fix/42-svg-validation` or
  `docs/33-contributor-guidance`.
- Record the exact base branch and commit before editing. Rebase or merge an
  updated base only when needed, then rerun affected validation.

## Development Setup

Requirements are WordPress 7.1+, PHP 7.4+, Composer, and Node.js 20+.
The PHPUnit bootstrap loads WordPress's real block parser. In a standalone
clone outside `wp-content/plugins`, point `WP_CORE_DIR` to a WordPress 7.1+
checkout whose root contains `wp-includes/blocks.php`.

```bash
composer install
npm ci
WP_CORE_DIR=/path/to/wordpress composer check
npm run test:js
npm run build
composer package
```

Use `npm run start` while developing the admin JavaScript. Run the smallest
relevant checks during implementation and the full applicable checks before
opening a pull request. Runtime smoke testing requires a WordPress installation:

```bash
ICON_LIBRARY_WP_LOAD=/path/to/wp-load.php composer smoke
```

The production ZIP is written under `build/`. Do not commit generated packages,
dependencies, credentials, local configuration, or unrelated working-tree
changes.

## Product Safety

- Treat custom SVG input as hostile. Preserve the established sanitizer and
  normalizer contract; do not weaken it merely to accept another export.
- Never allow scripts, event handlers, external references, unsafe URLs,
  `foreignObject`, arbitrary inline styles, or unvalidated geometry.
- Changes to SVG acceptance need focused malicious-input tests and evidence that
  existing valid icons still work.
- A proposed icon library must have verifiable source provenance, an exact
  upstream version or revision, redistribution-compatible licensing, required
  notices, and a documented import path. Evaluate bundle size, maintenance,
  taxonomy, SVG safety, and accessibility before bundling assets.
- Do not add Pro, restricted, ambiguously licensed, or scraped artwork.
- Do not add remote calls during editor or frontend loading.

## Pull Requests

Complete the repository pull request template. A reviewable pull request must:

- link its issue or identify the private security tracking reference without
  exposing sensitive details, and target the maintainer-assigned branch;
- state scope, non-goals, changed files, risks, and rollback approach;
- list every validation command actually run and any proof gaps;
- include before/after screenshots for UI changes at relevant desktop and mobile
  widths;
- document license and source evidence for third-party assets;
- note user-facing changelog and release impact, including `Not applicable` when
  justified; and
- remain focused: unrelated cleanup belongs in another issue.

External contributors and unapproved or contributor-controlled automation must
not merge, tag, release, deploy to WordPress.org, or change repository settings.
Owner-authorized maintainers and protected release workflows may perform release
integration after independent review and package/runtime validation.

## AI-Assisted Contributions

Disclose material AI assistance in the pull request. The contributor remains
responsible for every line and claim. Before submitting:

- inspect the repository and current issue or private tracking context instead
  of relying on generated assumptions;
- preserve pre-existing local changes and use an isolated branch or worktree;
- verify commands, paths, API names, compatibility, and product claims against
  the repository;
- review generated dependencies and assets for licensing and provenance;
- never expose secrets or private data to prompts, logs, commits, or issues;
- report incomplete tests, unavailable environments, and uncertain behavior
  plainly; and
- do not let an agent merge, tag, release, deploy, weaken security controls, or
  broaden scope without maintainer direction.

Repository-specific agent instructions are in [AGENTS.md](AGENTS.md).
