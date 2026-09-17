## Related issue

Related to #

## Base and strategy

- Target branch: `release/<version>` or maintainer-directed `develop`
- Exact base commit:
- Branch name:
- Maintainer-confirmed milestone:

Development pull requests must not target `main` or `master`.

## Scope

### Included

-

### Non-goals

-

## Changed files

-

## Validation

List the exact commands run and their results. Delete commands that do not apply.

- [ ] `composer check`
- [ ] `npm run test:js`
- [ ] `npm run build`
- [ ] `composer package`
- [ ] `ICON_LIBRARY_WP_LOAD=/path/to/wp-load.php composer smoke`
- [ ] Production ZIP inspected when packaging or distribution changed

Proof gaps or checks not run:

## UI and behavior proof

- Before/after screenshots for desktop and mobile, if applicable:
- Keyboard and accessibility checks, if applicable:
- Editor save/reload and frontend-render proof, if applicable:

## Third-party assets

- Upstream source and exact version/revision:
- License, redistribution terms, and required notices:
- Import/reproducibility and SVG-safety evidence:

Use `Not applicable` when no third-party assets are changed.

## Risk and rollback

- Main risks:
- Compatibility impact:
- Rollback approach:

## Changelog and release impact

- User-facing changelog entry:
- Intended release/milestone:
- Version or migration impact:

## AI assistance

- [ ] No material AI assistance was used.
- [ ] AI assistance was used and the contributor reviewed all generated code,
      documentation, tests, licenses, and claims.

Describe material AI assistance, tools, and human review:

## Contributor checklist

- [ ] I searched existing issues and pull requests before starting.
- [ ] The issue scope, milestone, and target branch were confirmed by a maintainer.
- [ ] This pull request contains one focused issue and no unrelated cleanup.
- [ ] I did not include secrets, private data, generated packages, or dependencies.
- [ ] I did not weaken SVG sanitization or omit required license/provenance evidence.
- [ ] I documented all known limitations and proof gaps.
