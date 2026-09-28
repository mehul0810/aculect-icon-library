# Rune Icons

Issue #18 adds only the 215 pixelated drawings from the official
[Nexvyn/runeicons repository](https://github.com/Nexvyn/runeicons), commit
`f649e467d1bc9f272aae3f8daa329d4c924e7340`. Upstream README identifies
`runeicons.com` as its website. Version is recorded as a commit pin, not an
invented published release. This variant is optional and no collection is
installed/enabled by default.

The source LICENSE is Apache-2.0, copyright 2026 Runeicons, and is copied verbatim
into the collection. No upstream NOTICE file exists at this revision. Modified
SVGs carry a modification comment. Apache terms remain applicable to these
separate assets; the plugin source license remains GPL-2.0-or-later. No trademark
rights are granted and no general claim of GPLv2 compatibility is made.

The importer removes only redundant root `fill="none"` after checking every
child is a path with its own explicit fill. Path geometry, per-path colors and
viewboxes are unchanged. It does not recolor black/white artwork to currentColor.
Stable IDs combine upstream category and filename, avoiding cross-category name
collisions. Search labels and terms derive from these filenames/categories.

The pinned upstream manifest reports 217 normal, 213 duotone, 124 fill, 215
pixelated and 135 glass drawings. Normal/fill variants use strokes, duotone uses
multiple tones, and glass uses effects outside the current Core contract. Those
variants are not imported or advertised as supported. Marketing totals must not
be confused with unique names or this collection's supported icon count.

Reproduce without installing upstream dependencies:

```sh
git clone https://github.com/Nexvyn/runeicons.git /tmp/runeicons
git -C /tmp/runeicons checkout f649e467d1bc9f272aae3f8daa329d4c924e7340
php scripts/import-rune-icons.php /tmp/runeicons
php scripts/build-catalog-metadata.php
```

Run the repository checks and package command before handoff. Actual Core native
picker/save/reload/frontend/lifecycle proof must be performed on the reviewed
package; manifest checks alone do not establish browser or runtime success.
