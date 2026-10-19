# Discovery and Preview Status

Remote library discovery and sample preview are not included in the bundled
1.2.0 production package. The package does not ship the runtime catalog JSON,
network transport, remote discovery endpoints, or download-installation UI.
The Install Library tab previews and selects from the ten bundled families
using local manifests; those previews do not contact a remote catalog.

The source tree may retain earlier implementation and planning material for
remote library workflows. Any such functionality is deferred, is not part of
the 1.2.0 user contract, and must not be described as available in this release.
See [optional-library status](optional-libraries.md).

## Release Boundary

The 1.2.0 ZIP is a single bundled profile. It contains the current bundled
families, each manifest-referenced SVG, all license and exclusion material, and
the Heroicons legacy aliases. Existing sites use the ordinary plugin update
path; there is no bridge package or migration action in this release.

Publication remains fail-closed until separate owner approval. A local build or
passing package checks do not authorize tagging, publishing, or deployment.
