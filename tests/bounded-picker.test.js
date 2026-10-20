const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { createGate, pagePath } = require('../assets/bounded-picker.js');
const tick = () => new Promise(resolve => setImmediate(resolve));

test('collection request recovers after Retry and then loads its first icon page', async () => {
	const requests = [];
	const listeners = {};
	const focused = [];
	let wrapped;
	let current;
	const wp = {
		apiFetch: options => new Promise((resolve, reject) => requests.push({ options, resolve, reject })),
		element: {
			createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
			useState: initial => current.useState(initial),
			useRef: initial => current.useRef(initial),
			useEffect: (effect, deps) => current.useEffect(effect, deps),
			Fragment: 'fragment', RawHTML: 'raw-html'
		},
		components: { Modal: 'modal', Button: 'button', SearchControl: 'search', Spinner: 'spinner' },
		i18n: { __: text => text, sprintf: (text, ...values) => text.replace(/%\d+\$d/g, () => values.shift()) },
		hooks: { addFilter: (name, namespace, component) => { wrapped = component; } },
		compose: { createHigherOrderComponent: component => component },
		data: { dispatch: () => ({ receiveEntityRecords() {} }) }
	};
	const root = { wp, iconLibraryPicker: { path: '/picker', perPage: 48 }, AbortController, setTimeout, clearTimeout,
		document: { addEventListener: (name, callback) => { listeners[name] = callback; }, removeEventListener() {} } };
	vm.runInNewContext(fs.readFileSync(require.resolve('../assets/bounded-picker.js'), 'utf8'), { window: root, URLSearchParams, Promise });
	function runner(component) {
		const slots = [], effects = [];
		let cursor = 0;
		return {
			render(props) {
				cursor = 0; current = this;
				const tree = component(props);
				(function attachRefs(node) {
					if (!node || typeof node !== 'object') return;
					if (Array.isArray(node)) { node.forEach(attachRefs); return; }
					if (node.props && node.props.ref) {
						node.props.ref.current = { disabled: !!node.props.disabled, focus() { focused.push(node.children[0]); }, scrollIntoView() {} };
					}
					(node.children || []).forEach(attachRefs);
				})(tree);
				for (const pending of effects.splice(0)) { if (pending.previous) pending.previous(); slots[pending.index].cleanup = pending.effect(); }
				return tree;
			},
			useState(initial) {
				const index = cursor++;
				if (!slots[index]) slots[index] = { value: initial };
				return [slots[index].value, update => { slots[index].value = typeof update === 'function' ? update(slots[index].value) : update; }];
			},
			useRef(initial) {
				const index = cursor++;
				if (!slots[index]) slots[index] = { value: { current: initial } };
				return slots[index].value;
			},
			useEffect(effect, deps) {
				const index = cursor++;
				const previous = slots[index];
				if (!previous || deps.some((value, i) => value !== previous.deps[i])) {
					effects.push({ index, effect, previous: previous && previous.cleanup });
					slots[index] = { deps };
				}
			},
			unmount() { for (const slot of slots) { if (slot && slot.cleanup) slot.cleanup(); } }
		};
	}
	function find(node, predicate) {
		if (!node || typeof node !== 'object') return null;
		if (Array.isArray(node)) { for (const child of node) { const found = find(child, predicate); if (found) return found; } return null; }
		if (predicate(node)) return node;
		for (const child of node.children || []) { const found = find(child, predicate); if (found) return found; }
		return null;
	}
	const IconEdit = wrapped(() => null)({ name: 'core/icon' }).type;
	const edit = runner(IconEdit);
	const props = { isSelected: true, attributes: { icon: '' }, Original: () => null, setAttributes() {} };
	edit.render(props);
	listeners.click({ target: { closest: () => ({ getAttribute: () => 'Choose icon', closest: () => ({}) }) }, preventDefault() {}, stopPropagation() {} });
	const Picker = find(edit.render(props), node => node.type && node.type.name === 'Picker').type;
	const picker = runner(Picker);
	const pickerProps = { value: '', onClose() {}, onSelect() {} };
	let tree = picker.render(pickerProps);
	assert.equal(requests.length, 1);
	assert.equal(requests[0].options.path, '/wp/v2/icon-collections');
	requests[0].reject(Error('offline'));
	await tick();
	// Render the failed state and activate the actual Retry button.
	const retry = find(picker.render(pickerProps), node => node.type === 'button' && node.children[0] === 'Retry');
	assert.ok(retry);
	assert.equal(find(tree, node => node.props.role === 'alert'), null);
	retry.props.onClick();
	tree = picker.render(pickerProps);
	tree = picker.render(pickerProps);
	assert.equal(requests.length, 2);
	assert.equal(requests[1].options.path, '/wp/v2/icon-collections');
	assert.equal(find(tree, node => node.props.role === 'alert'), null);
	requests[1].resolve([{ slug: 'external', label: 'External' }]);
	await tick();
	picker.render(pickerProps);
	await tick();
	assert.equal(requests.length, 3);
	assert.match(requests[2].options.path, /^\/picker\?collection=external&/);
	requests[2].resolve({ items: [{ name: 'external/one', label: 'One', content: '<svg />' }], total: 3, page: 1, per_page: 1, total_pages: 3 });
	await tick();
	tree = picker.render(pickerProps);
	assert.ok(find(tree, node => node.type === 'button' && node.children[1] && node.children[1].children[0] === 'One'));
	find(tree, node => node.type === 'button' && node.children[0] === 'Next').props.onClick();
	picker.render(pickerProps);
	await tick();
	assert.equal(requests.length, 4);
	requests[3].resolve({ items: [{ name: 'external/two', label: 'Two', content: '<svg />' }], total: 3, page: 2, per_page: 1, total_pages: 3 });
	await tick();
	picker.render(pickerProps);
	assert.equal(focused.at(-1), 'Next');
	picker.unmount(); edit.unmount();
});

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
