# WordPress.org branding

Production directory assets live in `.wordpress-org/`, following Aculect AI
Companion's repository layout. They are separate from the installed plugin's
`assets/` directory and excluded from the production ZIP.

Files: `banner-772x250.png`, `banner-1544x500.png`, `icon-128x128.png`,
`icon-256x256.png`, and `icon.svg`. The SVG is an unchanged copy of the owner's
original Aculect icon, including its border. Banner exports preserve the approved
layout and replace only its generated mark with the original SVG.

The approved raster is `source/approved-banner.png`. Regenerate with Node.js
and the `sharp` package available locally or on `NODE_PATH`:

```sh
node scripts/build-directory-assets.cjs
php scripts/validate-directory-assets.php
```

Generated PNGs are committed; release runners validate them without rebuilding
artwork. Earlier concepts are drafts and are never deployed.

## Release routing

Directory screenshots are `screenshot-1.png` (installed libraries),
`screenshot-2.png` (available libraries), and `screenshot-3.png` (custom uploads).
Their numbered captions live in the `Screenshots` section of `readme.txt`.
These are AI-assisted cleaned versions of owner-supplied screenshots, not raw
browser captures. The unrelated admin chrome and blank space were removed.
Review them against the live UI before publication; future captures should
retain the same numbering. They are deployed with the other directory assets,
not included in the plugin ZIP.

- Prereleases build the plugin ZIP and upload directory images as a separate
  Actions preview artifact. They do not write to WordPress.org.
- Stable releases publish the verified GitHub ZIP, then use the same ZIP as
  `BUILD_DIR` for 10up's WordPress deploy action. `ASSETS_DIR: .wordpress-org`
  maps directory images to SVN `/assets`, beside `/trunk` and `/tags`.
- The `release` environment requires `SVN_USERNAME` and `SVN_PASSWORD`, either
  supplied as environment secrets or inherited from repository secrets. The
  account must have commit access to the `aculect-icon-library` SVN repository.
- No release or SVN deployment is triggered by local asset generation. Publish
  stable tags only when the release has been authorized.
- 10up synchronizes the complete asset directory. Keep any future screenshots
  and directory images in `.wordpress-org` so later releases retain them.

References:
https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
https://github.com/mehul0810/aculect-ai-companion/blob/main/.github/workflows/release.yml
