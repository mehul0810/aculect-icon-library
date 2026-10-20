# Aculect Icon Library Design Guidance

## Direction

Build a quiet, task-focused WordPress admin tool for choosing and maintaining
icons. Prioritize recognizable controls, scannable library rows, accurate state,
and inspectable artwork. Keep the product's identity visible without turning
administration into a marketing page.

This is implementation guidance, not a claim that every accessibility or
responsive check has passed. The product behavior lives in [PRODUCT.md](PRODUCT.md).

## Navigation And Actions

Keep the existing **Appearance > Icons** destination and three named tabs:

| Tab | Primary task | Main actions |
| --- | --- | --- |
| Library | Manage selected families and styles | Open family, enable or disable |
| Install Library | Inspect bundled artwork and choose a family | Preview, Install Library, Remove from Library |
| Upload | Manage custom SVGs | Choose file, name and label, upload, relabel, delete |

The library browser and previews must remain useful before selection. Installing
adds a bundled family in the disabled state; enabling is a separate action in
Library. Explain this once at the decision point. Do not introduce download,
remote-catalog, migration, or progress-job UI for the bundled workflow.

Use **Remove from Library** for a non-destructive bundled selection change.
Use **Delete** for permanent custom-icon removal, with the saved-content
consequence stated before confirmation. Keep these labels and behaviors distinct.

## Layout And Visual Language

- Extend `assets/admin.css` and the existing WordPress admin controls. Use the
  admin theme color variables for accents and visible focus states.
- Prefer aligned rows for library/status comparisons and an unframed grid for
  artwork previews. Use compact headings and restrained borders; avoid nested
  cards, oversized introductions, decorative backgrounds, and extra sections
  that do not support an admin task.
- Keep icon previews large and clear enough to inspect their actual geometry.
  Preserve multicolor or fixed-color artwork where required; do not assume
  every icon follows `currentColor` or supports outline rendering.
- Use existing Dashicons in plugin admin controls and WordPress components in
  DataViews. Do not add an icon dependency for controls already covered.
- Preserve readable labels and stable control dimensions when loading text,
  error messages, long names, or counts change. Wrap content rather than clip
  action text or overlap adjacent controls.
- Keep library detail filters and return navigation in predictable positions.
  Mobile layouts must keep search, tabs, and consequential actions reachable.

## Interaction States

- Empty Library leads to **Install Library**; an empty search is distinct from
  a failed request. Do not present an unavailable response as an empty catalog.
- Confirm success from the completed request. Show actionable errors and offer
  Retry where supported; keep existing same-query results if a refresh fails.
- Navigation must ignore stale responses and tear down abandoned requests,
  dialogs, and document listeners. Do not leave an upload dialog on another tab.
- Preserve keyboard focus through pagination and retry recovery. Dialogs need
  an accessible name, contained focus, Escape handling, and a sensible return
  destination. Provide visible labels and names for icon-only controls.
- Retain ordinary URLs and server-rendered forms where available. When
  enhancement scripts fail, keep notices and the upload form available and
  identify which enhanced listing controls cannot operate.
- Keep previews and discovery paginated. Avoid loading every custom SVG or
  inserting an entire catalog into the DOM to support one page of results.

## Copy And Accessibility

Use short, destination-accurate labels. Explain material consequences and
recovery steps; omit implementation jargon and feature-tour text from routine
admin screens. Do not imply that disabling or removing a bundled selection
deletes saved artwork.

Use semantic links for navigation, buttons for commands, and labeled state
controls for enable/disable. Maintain active-tab semantics, useful status
announcements, visible focus, sufficient contrast, and non-color state cues.
Test keyboard interaction, long content, narrow viewports, and error states
when their behavior changes. Screen-reader checks are excluded by owner
direction; do not substitute another screen reader or claim those checks passed.

## Implementation And Review

Admin markup and actions are owned by `src/AdminPage.php` and
`src/AdminActions.php`; navigation/styles by `assets/admin.js` and
`assets/admin.css`. Uploaded-icon DataViews are authored in
`assets/src/custom-icons-dataviews.js`, then rebuilt into `assets/build/`.
Bounded native picker behavior uses `assets/bounded-picker.js` and its stylesheet.

For UI changes, review the actual desktop and mobile render, keyboard/focus,
loading, empty, error, and success states. Capture evidence against the tested
revision and package. Directory image locations and caption numbering are
documented in [branding guidance](docs/branding/README.md); existing cleaned
screenshots do not replace validation of a changed UI.
