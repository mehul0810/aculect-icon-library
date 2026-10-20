# Legacy Asset Migration

## 1.2.0 Release Behavior

The planned 1.2.0 production package is a single bundled profile. It includes
the existing ten supported icon families, their required license and exclusion
files, and the Heroicons aliases used by previously saved blocks. Existing
sites do not need a bridge package or a separate preservation stage to retain
these files during a normal plugin update.

No runtime migration or asset-copy workflow is part of the 1.2.0 package.
Changes exploring explicit preservation are deferred and are not instructions
for users of this release.

## Deferred Work

Any future removal of bundled assets would need its own compatibility design,
tested package and runtime proof, and explicit release approval. This document
does not define that future workflow. Do not install a bridge or run an asset
preservation command for the bundled 1.2.0 release.
