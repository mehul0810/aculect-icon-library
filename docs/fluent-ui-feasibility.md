# Fluent UI System Icons: issue #24 feasibility

Issue #24 targets the `release/1.2.0` branch and calls for the Fluent UI System
Icons system collection, with Regular and Filled styles and stable identifiers
that preserve each upstream size variant. This note records why the complete
collection cannot yet enter the current production package. It does not add a
Fluent collection or change existing icon behavior.

## Source and compatibility probe

- Official source: `microsoft/fluentui-system-icons`, release `1.1.341`, commit
  `9cf8af0f95a555918a60b8147a2f33a6a1248442`.
- The source repository has an MIT `LICENSE` and a separate `NOTICE`. A future
  import must retain applicable notices in the production package.
- The pinned source contains 19,633 `*_regular.svg` and `*_filled.svg` files
  across sizes 10, 12, 16, 20, 24, 28, 32, and 48. The probe passed 19,615
  files through `CollectionBuild::normalize_svg()` without changing the runtime
  sanitizer. It deferred 18 SVGs whose root `fill="none"` could not be removed
  while proving equivalent rendering.
- The probe deliberately kept size and style in separate identifiers. It did
  not collapse same-named icons to one nominal size or select a subset.

The probe archive contains only the 19,615 normalized SVGs under their intended
plugin paths. With ZIP deflate level 9, it is **16,536,699 bytes**. This is a
lower bound for a production package: it excludes the existing plugin, other
collections, manifests, metadata, license, notice, and exclusion report. The
current release workflow rejects a ZIP of **10,485,760 bytes or more**. The
Fluent SVGs alone exceed that limit by **6,050,939 bytes**. Even the probe's
compressed file data, without ZIP entry overhead, is 11,392,133 bytes.

The probe establishes format feasibility for most source SVGs, not completed
product support. It has not proven native picker usability, registration time,
memory, discovery payload, saved-block rendering, visual fidelity, or a final
package. The 18 deferred SVGs require individual conversion or a documented
exclusion report before import. No runtime sanitizer relaxation is proposed.

## Maintainer decision needed

The complete Regular/Filled, all-size collection conflicts with the existing
WordPress.org package limit. Before implementation, maintainers need to choose
one of these explicit changes to the delivery contract:

1. Approve a bounded size/style subset and amend issue #24 accordingly, with
   an exact inclusion rule and exclusions report. This changes the requested
   coverage and must not happen silently.
2. Approve a different distribution architecture that keeps the complete
   collection available without remote editor/frontend dependencies, while
   preserving installation, saved-content, notice, and performance guarantees.
3. Change the package limit only if the destination's actual acceptance policy
   and release process permit a larger ZIP. The current workflow does not.

Once the delivery scope is confirmed, implement the pinned import, manifest,
notices, tests, performance measurements, and editor/frontend proof on a
development branch targeting `release/1.2.0`.
