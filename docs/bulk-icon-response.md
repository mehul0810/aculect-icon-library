# Native bulk Icon response memory

The native All tab requests every enabled icon in one Core JSON response. With
the published packs enabled, Core holds registered SVGs, the prepared response,
its encoded JSON and an output buffer concurrently. The candidate exceeded the
existing 256 MiB admin allowance while emitting that response.

The plugin now releases only its own request-local icon/collection registrations
and caches at the final `rest_pre_echo_response` phase, after Core prepares links.
The callback runs at the highest filter priority. The response array remains unchanged;
SVG strings stay referenced until encoding finishes. This removes duplicate
bookkeeping and permits normal Core JSON output without custom streaming,
pagination, truncation, a changed memory limit or persistent storage changes.
When available, `gc_mem_caches()` also releases unused allocator pages; it does
not collect live objects or execute their destructors.

Cleanup requires an authorized GET of `/wp/v2/icons`. Exact saved-name reads,
collection metadata, other routes, denied requests and other providers retain
their behavior. Subsequent response callbacks still receive the complete data.
Existing ownership-aware cleanup preserves registrations another
provider replaced. Request caches and saved registrations can be rebuilt if a
later hook requires them. All registry changes are request-local.
