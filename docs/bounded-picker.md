# Bounded Icon selection

The Icon block keeps Core's original edit, attributes, formatting controls and
frontend renderer. A supported `editor.BlockEdit` extension routes the existing
Replace/Choose icon actions to a WordPress-components modal with the same visible
collections, search, selected-icon highlight, keyboard tabs and close behavior.
The grid holds at most 48 icons and exposes Previous/Next controls. Opening a
collection locates the existing selection's page; search examines the whole
enabled catalog, including icons beyond the first page.

`GET /icon-library/v1/picker` is a separate paginated editor adapter. Existing
Core and plugin REST endpoints, names, schemas and complete response contracts
are unchanged. The adapter uses the same manifests, providers, activation flags
and namespace mapping as Core discovery. It counts searchable metadata while
retaining only a bounded page, then registers/reads SVGs for that page. It creates
no second catalog/index, install, persistent cache or migration. Registered Core
and external providers remain available. Disabled/retained variants stay absent
from new selection; exact saved-name reads retain their existing behavior.

Read permission matches Core (edit-post capabilities, including REST post
types). Cookie/nonce checks stay in WordPress REST handling. Page size is at most
48, query length 200, collection length 100 and selected-name length 256. SVG
content passes normal Core registration/sanitization; response data includes no
filesystem paths. Missing artwork returns an explicit retryable error. No remote
transport, network-policy exception, memory-limit increase or executable download
is introduced.

Search changes cancel prior requests, hide stale choices and suppress late
results/errors even without AbortController. Closing cancels outstanding work.
Selecting an icon updates only its existing Core `icon` attribute and receives
the real record into Core data. Artwork is never a placeholder or proxy URL.
Assets use content-hashed script versions so a candidate update cannot keep an
older cached picker script. The extension is tested against WordPress 7.1.2's
Replace/Choose icon controls; future Core UI changes should be rechecked.

The old full Core bulk endpoint still deliberately returns its full SVG
contract for integrations. The bounded editor does not call that endpoint.
External providers that eagerly register/load their own large assets remain
responsible for their bootstrap cost; the adapter does not rewrite their data.
