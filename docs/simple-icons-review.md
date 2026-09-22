# Simple Icons Import Review

Related to #28. Reviewed for milestone 1.2.0 against base
`4e98941dcaa505e975c2d8624d24a3b212b5489e`. No assets are imported by this review.

## Pinned Evidence

- Official repository: https://github.com/simple-icons/simple-icons
- Release: `16.32.0`; commit: `45744ae915ba4eeec3a56d69bd8545beb6a89bdc`.
- Annotated tag object: `c108b73695799eac0c6d4ca3f7cecdc4fbaebe82`.
- [License](https://github.com/simple-icons/simple-icons/blob/45744ae915ba4eeec3a56d69bd8545beb6a89bdc/LICENSE.md).
- [Asset disclaimer](https://github.com/simple-icons/simple-icons/blob/45744ae915ba4eeec3a56d69bd8545beb6a89bdc/DISCLAIMER.md).
- [Per-icon metadata](https://github.com/simple-icons/simple-icons/blob/45744ae915ba4eeec3a56d69bd8545beb6a89bdc/data/simple-icons.json).

The repository's CC0 license is not evidence that every individual brand icon
is CC0. Upstream explicitly distinguishes the collection license from asset
licenses and says absent license metadata does not establish unrestricted use.
Trademark rights and brand guidelines remain separate from copyright licensing.

## Inventory

The pinned JSON contains 3,461 icons: 3,238 without an explicit `license.type`,
23 with `custom` licenses, and 200 with named licenses. Named licenses include
CC-BY-NC, CC-BY-ND, CC-BY-NC-ND, CC-BY-NC-SA, AGPL, GPL, MPL, Apache, MIT, BSD,
CC-BY, CC-BY-SA, CC0 and Unlicense variants. Eleven entries expressly say CC0.
These are upstream metadata claims, not independently verified rights grants.

Reproduce the inventory without installing or executing upstream packages:

```sh
git clone --depth 1 --branch 16.32.0 https://github.com/simple-icons/simple-icons.git
git -C simple-icons rev-parse HEAD
node -e 'const fs=require("fs");const a=JSON.parse(fs.readFileSync("simple-icons/data/simple-icons.json"));const counts={};for(const i of a){const key=i.license?.type||"missing";counts[key]=(counts[key]||0)+1;}console.log(a.length,counts);'
```

## Decision And Next Gate

Do not bulk-import this release under a blanket CC0 assertion. Repository policy
prohibits proprietary, restricted, or ambiguously licensed artwork. In particular,
the noncommercial/no-derivatives entries cannot enter the ordinary redistributable
bundle. Keeping source links alone does not resolve missing permissions.

Before implementation, establish an explicit, reviewed per-icon inclusion list:
verify the original rights grant and attribution for each candidate; exclude
restricted/unknown/custom grants until cleared; retain source, guidelines and
license metadata; document that trademark permissions are not granted. A small
curated subset would materially narrow the requested collection and needs owner
scope confirmation before it is presented as Simple Icons support.

No importer, manifest, runtime behavior, licensing policy, or ZIP contents change.
SVG compatibility, registration/discovery performance, native picker lifecycle,
frontend rendering and package-size deltas remain untested because the licensing
gate precedes importing assets. Issue #28 remains open and implementation-blocked.
