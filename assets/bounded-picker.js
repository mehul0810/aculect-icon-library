(function (root, factory) {
	'use strict';
	var helpers = factory();
	if (typeof module === 'object' && module.exports) { module.exports = helpers; }
	if (!root.wp || !root.iconLibraryPicker) { return; }
	var wp = root.wp, config = root.iconLibraryPicker, h = wp.element.createElement;
	var useState = wp.element.useState, useEffect = wp.element.useEffect, useRef = wp.element.useRef;
	var __ = wp.i18n.__, sprintf = wp.i18n.sprintf;

	function Picker(props) {
		var groups = useState(null), collections = groups[0], setCollections = groups[1];
		var scope = useState(null), collection = scope[0], setCollection = scope[1];
		var input = useState(''), searchInput = input[0], setSearchInput = input[1];
		var changes = useState(0), inputSequence = changes[0], setInputSequence = changes[1];
		var term = useState(''), search = term[0], setSearch = term[1];
		var paging = useState(0), page = paging[0], setPage = paging[1];
		var data = useState(null), result = data[0], setResult = data[1];
		var busy = useState(true), loading = busy[0], setLoading = busy[1];
		var problem = useState(''), error = problem[0], setError = problem[1];
		var retry = useState(0), attempt = retry[0], setAttempt = retry[1];
		var gate = useRef(null);
		var selectedButton = useRef(null);
		var paginationFocus = useRef('');
		var previousButton = useRef(null);
		var nextButton = useRef(null);
		var paginationStatus = useRef(null);
		var retryButton = useRef(null);
		if (!gate.current) { gate.current = helpers.createGate(wp.apiFetch, root.AbortController); }
		useEffect(function () {
			if (collection !== null) { return; }
			var active = true;
			setLoading(true); setError('');
			wp.apiFetch({ path: '/wp/v2/icon-collections' }).then(function (rows) {
				if (!active) { return; }
				setCollections(rows);
				var selected = (props.value || '').split('/')[0];
				setCollection(rows.some(function (row) { return row.slug === selected; }) ? selected : rows[0] ? rows[0].slug : '');
			}).catch(function (failure) { if (active) { setError(failure.message || __('Icons could not be loaded.', 'aculect-icon-library')); setLoading(false); } });
			return function () { active = false; gate.current.cancel(); };
		}, [collection, attempt]);
		useEffect(function () {
			if (!inputSequence) { return; }
			var timer = root.setTimeout(function () { setSearch(searchInput); setPage(0); setAttempt(function (value) { return value + 1; }); }, 250);
			return function () { root.clearTimeout(timer); };
		}, [searchInput, inputSequence]);
		useEffect(function () {
			if (collection === null) { return; }
			setLoading(true); setError('');
			gate.current.run({ path: helpers.pagePath(config.path, collection, search, page, config.perPage, props.value || '') }).then(function (response) {
				if (response.stale) { return; }
				setResult(response.data); setLoading(false);
			}).catch(function (failure) { setError(failure.message || __('Icons could not be loaded.', 'aculect-icon-library')); setLoading(false); });
			return function () { gate.current.cancel(); };
		}, [collection, search, page, attempt]);
		useEffect(function () { if (!loading && selectedButton.current) { selectedButton.current.scrollIntoView({ block: 'nearest' }); } }, [loading, result]);
		useEffect(function () {
			if (loading || !paginationFocus.current) { return; }
			var target = error ? retryButton.current : paginationFocus.current === 'next' ? nextButton.current : previousButton.current;
			if (!target || target.disabled) { target = paginationStatus.current; }
			if (target) { target.focus(); }
			paginationFocus.current = '';
		}, [loading, error, result]);
		var tabs = [{ slug: '', label: __('All') }].concat(collections || []);
		function chooseTab(slug) { if (slug === collection) { return; } gate.current.cancel(); setLoading(true); setCollection(slug); setPage(0); }
		function changePage(value, direction) { paginationFocus.current = direction; gate.current.cancel(); setLoading(true); setPage(value); }
		function changeSearch(value) { gate.current.cancel(); setLoading(true); setSearchInput(value); setInputSequence(function (old) { return old + 1; }); }
		function moveTab(event, index) {
			var delta = ['ArrowDown', 'ArrowRight'].indexOf(event.key) >= 0 ? 1 : ['ArrowUp', 'ArrowLeft'].indexOf(event.key) >= 0 ? -1 : 0;
			var next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : delta ? (index + delta + tabs.length) % tabs.length : null;
			if (next === null) { return; }
			event.preventDefault(); chooseTab(tabs[next].slug);
			var target = event.currentTarget.parentElement.querySelectorAll('[role="tab"]')[next]; if (target) { target.focus(); }
		}
		var items = result ? result.items : [], start = result && result.total ? (result.page - 1) * result.per_page + 1 : 0;
		return h(wp.components.Modal, { title: __('Icon library'), isFullScreen: true, className: 'wp-block-icon__inserter-modal icon-library-bounded-modal', onRequestClose: props.onClose },
			h('div', { className: 'icon-library-bounded-layout' },
				h('aside', { className: 'wp-block-icon__inserter-sidebar' },
					h(wp.components.SearchControl, { label: __('Search'), value: searchInput, onChange: changeSearch, __nextHasNoMarginBottom: true }),
					h('div', { role: 'tablist', 'aria-label': __('Icon collections', 'aculect-icon-library'), 'aria-orientation': 'vertical' }, tabs.map(function (tab, index) {
						return h(wp.components.Button, { key: tab.slug, role: 'tab', id: 'ail-tab-' + (tab.slug || 'all'), 'aria-selected': collection === tab.slug, tabIndex: collection === tab.slug ? 0 : -1, className: 'icon-library-picker-tab', variant: collection === tab.slug ? 'primary' : 'tertiary', onClick: function () { chooseTab(tab.slug); }, onKeyDown: function (event) { moveTab(event, index); } }, tab.label);
					}))),
				h('section', { role: 'tabpanel', 'aria-labelledby': 'ail-tab-' + (collection || 'all'), className: 'icon-library-picker-panel', 'aria-busy': loading },
					error ? h('div', { role: 'alert' }, h('p', null, error), h(wp.components.Button, { ref: retryButton, variant: 'secondary', onClick: function () { setAttempt(function (value) { return value + 1; }); } }, __('Retry', 'aculect-icon-library'))) : null,
					loading ? h('div', { role: 'status', 'aria-label': __('Loading…'), className: 'icon-library-picker-loading' }, h(wp.components.Spinner)) : null,
					!loading && !error && !items.length ? h('p', null, __('No results found.')) : null,
					!loading && !error ? h('div', { className: 'wp-block-icon__inserter-grid-icons-list', 'aria-label': __('Icon library') }, items.map(function (icon) {
						return h(wp.components.Button, { key: icon.name, ref: icon.name === props.value ? selectedButton : undefined, className: 'wp-block-icon__inserter-grid-icons-list-item', variant: icon.name === props.value ? 'primary' : undefined, __next40pxDefaultSize: true, onClick: function () { props.onSelect(icon); } },
							h('span', { className: 'wp-block-icon__inserter-grid-icons-list-item-icon' }, h(wp.element.RawHTML, null, icon.content)), h('span', { className: 'wp-block-icon__inserter-grid-icons-list-item-title' }, icon.label));
					})) : null,
					!loading && !error && result && result.total ? h('div', { className: 'icon-library-picker-pagination' },
						h('span', { ref: paginationStatus, tabIndex: -1, 'aria-live': 'polite' }, sprintf(__('%1$d–%2$d of %3$d icons', 'aculect-icon-library'), start, start + items.length - 1, result.total)),
						h(wp.components.Button, { ref: previousButton, variant: 'secondary', disabled: result.page <= 1, onClick: function () { changePage(result.page - 1, 'previous'); } }, __('Previous', 'aculect-icon-library')),
						h(wp.components.Button, { ref: nextButton, variant: 'secondary', disabled: result.page >= result.total_pages, onClick: function () { changePage(result.page + 1, 'next'); } }, __('Next', 'aculect-icon-library'))) : null)));
	}
	function IconEdit(props) {
		var state = useState(false), open = state[0], setOpen = state[1], wrapper = useRef(null);
		function trigger(event, toolbar) {
			var button = event.target.closest && event.target.closest('button'); if (!button) { return; }
			var label = button.getAttribute('aria-label') || button.textContent.trim();
			if (label !== __('Replace') && label !== __('Choose icon')) { return; }
			if (toolbar ? !button.closest('.block-editor-block-toolbar, .block-editor-block-contextual-toolbar') : !wrapper.current || !wrapper.current.contains(button)) { return; }
			event.preventDefault(); event.stopPropagation(); if (event.stopImmediatePropagation) { event.stopImmediatePropagation(); } setOpen(true);
		}
		useEffect(function () {
			if (!props.isSelected) { return; }
			var handler = function (event) { trigger(event, true); };
			root.document.addEventListener('click', handler, true);
			return function () { root.document.removeEventListener('click', handler, true); };
		}, [props.isSelected]);
		return h(wp.element.Fragment, null,
			h('div', { ref: wrapper, style: { display: 'contents' }, onClickCapture: function (event) { trigger(event, false); } }, h(props.Original, props)),
			open ? h(Picker, { value: props.attributes.icon, onClose: function () { setOpen(false); }, onSelect: function (icon) {
				wp.data.dispatch('core').receiveEntityRecords('root', 'icon', icon);
				props.setAttributes({ icon: icon.name }); setOpen(false);
			} }) : null);
	}
	wp.hooks.addFilter('editor.BlockEdit', 'icon-library/bounded-picker', wp.compose.createHigherOrderComponent(function (Original) {
		return function (props) { return props.name === 'core/icon' ? h(IconEdit, Object.assign({}, props, { Original: Original })) : h(Original, props); };
	}, 'IconLibraryBoundedPicker'));
})(typeof window === 'object' ? window : globalThis, function () {
	'use strict';
	function pagePath(path, collection, search, page, size, selected) {
		var query = new URLSearchParams({ collection: collection, search: search, page: String(page), per_page: String(size), selected: selected });
		return path + '?' + query.toString();
	}
	function createGate(fetch, Abort) {
		var serial = 0, controller = null;
		function cancel() { serial++; if (controller) { controller.abort(); controller = null; } }
		return { cancel: cancel, run: function (options) {
			cancel(); var generation = serial; controller = Abort ? new Abort() : null; var current = controller;
			return Promise.resolve().then(function () { if (generation !== serial) { return null; } return fetch(Object.assign({}, options, current ? { signal: current.signal } : {})); }).then(function (data) {
				return generation === serial ? { data: data, stale: false } : { stale: true };
			}, function (error) { if (generation !== serial) { return { stale: true }; } throw error; });
		} };
	}
	return { pagePath: pagePath, createGate: createGate };
});
