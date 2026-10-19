# Optional Libraries

Optional downloaded-library installation is not part of the bundled 1.2.0
release. The production package does not include the installer, discovery,
transport, or runtime catalog data, and the release documentation makes no
claim that those controls are available to users.

The **Install Library** tab selects from the ten families already bundled in
the plugin ZIP. Selection adds one family to **Library** without enabling it.
Disabling retains the selection. Removing the selection disables and hides the
family from Library, but does not delete its packaged artwork or saved icon IDs.
No package is downloaded or stored separately for this action.

The source tree contains implementation and research material for a possible
future optional-library workflow. Its catalog, previews, network behavior,
storage, permissions, recovery, and directory-policy questions remain outside
the shipped 1.2.0 contract. Do not use this document as an installation guide
or as evidence that remote installation is included in the release.

## Deferred Acceptance Areas

Before any such workflow is proposed for a later release, its owner must review
the trust and licensing model, consent and privacy disclosures, package and
runtime boundaries, authorization and failure paths, WordPress.org policy, and
the exact production ZIP. Source tests or local package construction alone do
not establish that a feature ships or is approved for distribution.
