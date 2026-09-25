# Keyline Icons Compatibility Review

Related to #42; milestone 1.2.0; plugin base
`4e98941dcaa505e975c2d8624d24a3b212b5489e`. No artwork is imported.

## Provenance And License

The [official site](https://keylineicons.com/) identifies
https://github.com/keyline-icons/keyline-icons as its source. Reviewed commit
`0a4385b467690653498a67917e554d1de6a6bfd1`; root package version `0.0.1` is a
private website package, not a claim about a published icon release.

The [pinned MIT license](https://github.com/keyline-icons/keyline-icons/blob/0a4385b467690653498a67917e554d1de6a6bfd1/LICENSE)
covers icons and code according to the README. A future import must preserve
the copyright notice and permission text. The Keyline Icons name is not granted
by that license. No proprietary assets or packages were installed.

## Actual Inventory

There are 1,000 names and 8,000 SVG files: four styles in rounded and sharp
corners. Files byte-identical to the corresponding stroke drawing are fallbacks,
not distinct drawings:

| Style | Rounded stroke fallbacks | Sharp stroke fallbacks | Opacity-bearing files per corner |
| --- | ---: | ---: | ---: |
| Stroke | 1,000 (reference) | 1,000 (reference) | 0 |
| Two-tone | 66 | 68 | 931 |
| Duotone | 107 | 109 | 890 |
| Fill | 223 | 223 | 0 |

Byte comparison detects exact fallbacks only, not all visually equivalent paths.
No complete trademark audit has been performed; this is not clearance of every
drawing. Category/search metadata requires its own pinned mapping at import time.

## Core Fidelity Gate

Passing all 8,000 SVG files through the existing
`CollectionBuild::normalize_svg()` rejects every file, initially at the root
`fill="none"`. Simply dropping that attribute is not a valid repair: inherited
stroke width, caps and joins define the visible geometry. The existing Core
allowlist does not support these stroke semantics or multitone opacity.

For example, [fill/bell.svg](https://github.com/keyline-icons/keyline-icons/blob/0a4385b467690653498a67917e554d1de6a6bfd1/icons/fill/bell.svg)
has a filled body but a stroked clapper. `fill/check.svg` is precisely the stroke
fallback. Duotone bell includes `fill-opacity="0.4"`. Calling these filled or
duotone while stripping strokes/opacity would silently change the artwork.

## Reproduction

```sh
git clone --depth 1 https://github.com/keyline-icons/keyline-icons.git
git -C keyline-icons rev-parse HEAD
```

Check out the exact commit above if upstream has advanced. From this plugin:

```php
require 'scripts/lib/CollectionBuild.php';
$counts = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('/path/to/keyline-icons/icons', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ('svg' !== $file->getExtension()) {
        continue;
    }
    try {
        IconLibrary\Build\CollectionBuild::normalize_svg(file_get_contents($file));
    } catch (RuntimeException $error) {
        $message = $error->getMessage();
        $counts[$message] = ($counts[$message] ?? 0) + 1;
    }
}
print_r($counts);
```

## Decision And Follow-Up

Defer support rather than weaken sanitization or silently reduce visual fidelity.
A future bounded proposal can use a proven build-time stroke-to-path converter,
with pinned tooling and pixel comparisons at representative sizes, to evaluate
monochrome variants. Multitone still requires a faithful Core-compatible solution
or explicit scope exclusion. Count fallback duplicates honestly and get approval
for material scope/fidelity changes before presenting a reduced set as support.

No production files change, so asset size impact is zero. There is no implemented
collection to benchmark or validate in the picker. Registration, REST payload,
memory, browser long tasks, responsive/keyboard behavior, save/reload/frontend,
disable/re-enable and uninstall/reinstall proof remain required before release.
Issue #42 remains open; this review does not claim implementation completion.
