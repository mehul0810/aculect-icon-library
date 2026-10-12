const test = require('node:test');
const assert = require('node:assert/strict');
const { createGate, pagePath } = require('../assets/bounded-picker.js');
const tick = () => new Promise(resolve => setImmediate(resolve));

for (const withAbort of [true, false]) {
	test('latest search wins even when old transport completes late: ' + withAbort, async () => {
		const pending = [];
		const gate = createGate(options => new Promise((resolve, reject) => pending.push({ options, resolve, reject })), withAbort ? AbortController : null);
		const old = gate.run({ path: 'old' }); await tick();
		const current = gate.run({ path: 'new' }); await tick();
		if (withAbort) assert.equal(pending[0].options.signal.aborted, true);
		pending[1].resolve({ name: 'new' });
		assert.deepEqual(await current, { stale: false, data: { name: 'new' } });
		pending[0].resolve({ name: 'old' }); assert.deepEqual(await old, { stale: true });
	});
}
test('closing cancels errors and a superseded scheduled request does not start', async () => {
	const pending = [];
	const gate = createGate(options => new Promise((resolve, reject) => pending.push({ options, resolve, reject })), null);
	const first = gate.run({ path: 'first' }); gate.cancel(); assert.deepEqual(await first, { stale: true }); assert.equal(pending.length, 0);
	const running = gate.run({ path: 'active' }); await tick(); gate.cancel(); pending[0].reject(Error('offline')); assert.deepEqual(await running, { stale: true });
});
test('search and selected identities remain encoded query values', () => {
	const path = pagePath('/icon-library/v1/picker', 'hugeicons-stroke-rounded', 'a&search=other', 0, 48, 'rune-pixelated/arrows-arrow-down-pixelated');
	const url = new URL(path, 'https://example.test'); assert.equal(url.searchParams.get('search'), 'a&search=other'); assert.equal(url.searchParams.get('selected'), 'rune-pixelated/arrows-arrow-down-pixelated'); assert.equal(url.pathname, '/icon-library/v1/picker');
});
