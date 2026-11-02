const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const babel = require( '@babel/core' );

function createHarness() {
	const requests = [];
	const heading = {
		attributes: {},
		focused: false,
		setAttribute( name, value ) { this.attributes[ name ] = value; },
		focus() { this.focused = true; },
	};
	const mount = { dataset: {} };
	let component;
	let current;
	const element = {
		useState: ( initial ) => current.useState( initial ),
		useEffect: ( effect, deps ) => current.useEffect( effect, deps ),
		useMemo: ( factory ) => factory(),
		useCallback: ( callback ) => callback,
		useRef: ( initial ) => current.useRef( initial ),
	};
	const apiFetch = ( options ) => new Promise( ( resolve, reject ) => requests.push( { options, resolve, reject } ) );
	const source = fs.readFileSync( path.join( __dirname, '../assets/src/custom-icons-dataviews.js' ), 'utf8' );
	const code = babel.transformSync( source, {
		babelrc: false,
		configFile: false,
		plugins: [
			[ require.resolve( '@babel/plugin-transform-react-jsx' ), { pragma: 'h', pragmaFrag: 'Fragment' } ],
			require.resolve( '@babel/plugin-transform-modules-commonjs' ),
		],
	} ).code;
	const context = {
		AbortController,
		URLSearchParams,
		Fragment: 'fragment',
		h: ( type, props, ...children ) => ( { type, props: props || {}, children } ),
		window: { iconLibraryAdmin: { customPath: '/custom-icons' } },
		document: {
			getElementById: () => mount,
			querySelector: ( selector ) => selector === '.icon-library-custom-heading' ? heading : null,
		},
		wp: { element: { createRoot: () => ( { render: ( tree ) => { component = tree.type; }, unmount() {} } ) } },
		exports: {},
		require: ( name ) => ( {
			'@wordpress/components': { Button: 'button', TextControl: 'text-control' },
			'@wordpress/dataviews/wp': { DataViews: 'dataviews' },
			'@wordpress/element': element,
			'@wordpress/i18n': { __: ( text ) => text },
			'@wordpress/api-fetch': { __esModule: true, default: apiFetch },
		} )[ name ],
	};
	vm.runInNewContext( code, context );

	const slots = [];
	let cursor = 0;
	function render() {
		cursor = 0;
		current = { useState, useRef, useEffect };
		const effects = [];
		function useState( initial ) {
			const index = cursor++;
			if ( ! slots[ index ] ) slots[ index ] = { value: initial };
			return [ slots[ index ].value, ( next ) => { slots[ index ].value = typeof next === 'function' ? next( slots[ index ].value ) : next; } ];
		}
		function useRef( initial ) {
			const index = cursor++;
			if ( ! slots[ index ] ) slots[ index ] = { current: initial };
			return slots[ index ];
		}
		function useEffect( effect, deps ) {
			const index = cursor++;
			const previous = slots[ index ];
			if ( ! previous || deps.some( ( value, i ) => value !== previous.deps[ i ] ) ) {
				effects.push( { index, effect, previous: previous?.cleanup } );
				slots[ index ] = { deps };
			}
		}
		const tree = component();
		for ( const pending of effects ) {
			pending.previous?.();
			slots[ pending.index ].cleanup = pending.effect();
		}
		return tree;
	}
	function find( node, predicate ) {
		if ( ! node || typeof node !== 'object' ) return null;
		if ( Array.isArray( node ) ) {
			for ( const child of node ) { const found = find( child, predicate ); if ( found ) return found; }
			return null;
		}
		if ( predicate( node ) ) return node;
		return find( node.children, predicate );
	}
	const tick = () => new Promise( ( resolve ) => setImmediate( resolve ) );
	return { render, requests, find, heading, tick };
}

test( 'a successful Upload list Retry keeps keyboard focus on the stable heading', async () => {
	const { render, requests, find, heading, tick } = createHarness();
	render();
	requests[ 0 ].reject( Error( 'Service unavailable' ) );
	await tick();
	const failed = render();
	const retry = find( failed, ( node ) => node.type === 'button' && node.children[ 0 ] === 'Retry' );
	assert.ok( retry );
	assert.equal( heading.focused, false );
	retry.props.onClick();
	assert.equal( heading.focused, true );
	assert.equal( heading.attributes.tabindex, '-1' );
	render();
	assert.equal( requests.length, 2 );
	requests[ 1 ].resolve( { items: [ { name: 'custom/check', label: 'Check', iconName: 'custom/check', svg: '<svg></svg>' } ], total: 1 } );
	await tick();
	const loaded = render();
	assert.equal( find( loaded, ( node ) => node.type === 'button' && node.children[ 0 ] === 'Retry' ), null );
	assert.ok( find( loaded, ( node ) => node.type === 'dataviews' ) );
	assert.equal( heading.focused, true );
} );

test( 'deleting the final row on page two fetches page one and preserves the query', async () => {
	const { render, requests, find, tick } = createHarness();
	const dataView = () => find( render(), ( node ) => node.type === 'dataviews' );
	const initial = dataView();
	initial.props.onChangeView( { ...initial.props.view, page: 2, search: 'badge', sort: { field: 'label', direction: 'desc' } } );
	dataView();
	requests[ 1 ].resolve( { items: [ { name: 'last', label: 'Last' } ], total: 21 } );
	await tick();
	const lastPage = dataView();
	// Invoke the same completion callback used by the successful delete modal.
	lastPage.props.actions.find( ( action ) => action.id === 'delete' ).RenderModal( {} ).props.onDone();
	dataView();
	requests[ 2 ].resolve( { items: [], total: 20 } );
	await tick();
	const recovering = dataView();
	assert.equal( recovering.props.view.page, 1 );
	assert.equal( recovering.props.view.search, 'badge' );
	assert.equal( recovering.props.view.sort.direction, 'desc' );
	assert.match( requests[ 3 ].options.path, /page=1&per_page=20&search=badge&orderby=label&order=desc/ );
	requests[ 3 ].resolve( { items: [ { name: 'retained', label: 'Retained' } ], total: 20 } );
	await tick();
	assert.equal( dataView().props.data[ 0 ].name, 'retained' );
} );

test( 'deleting the only icon keeps page one and renders a genuine empty result', async () => {
	const { render, requests, find, tick } = createHarness();
	const dataView = () => find( render(), ( node ) => node.type === 'dataviews' );
	dataView();
	requests[ 0 ].resolve( { items: [ { name: 'only', label: 'Only' } ], total: 1 } );
	await tick();
	dataView().props.actions.find( ( action ) => action.id === 'delete' ).RenderModal( {} ).props.onDone();
	dataView();
	requests[ 1 ].resolve( { items: [], total: 0 } );
	await tick();
	const empty = dataView();
	assert.equal( empty.props.view.page, 1 );
	assert.equal( empty.props.data.length, 0 );
	assert.equal( empty.props.paginationInfo.totalItems, 0 );
	assert.equal( requests.length, 2 );
} );

test( 'concurrent deletions recover to the last valid page using the selected page size', async () => {
	const { render, requests, find, tick } = createHarness();
	const dataView = () => find( render(), ( node ) => node.type === 'dataviews' );
	const initial = dataView();
	initial.props.onChangeView( { ...initial.props.view, page: 5, perPage: 10 } );
	dataView();
	requests[ 1 ].resolve( { items: [], total: 11 } );
	await tick();
	assert.equal( dataView().props.view.page, 2 );
	assert.match( requests[ 2 ].options.path, /page=2&per_page=10/ );
	// More removals can invalidate the recovery request itself.
	requests[ 2 ].resolve( { items: [], total: 0 } );
	await tick();
	assert.equal( dataView().props.view.page, 1 );
	requests[ 3 ].resolve( { items: [], total: 0 } );
	await tick();
	assert.equal( dataView().props.data.length, 0 );
	assert.equal( requests.length, 4 );
} );

test( 'an obsolete empty response cannot reset a newer page selection', async () => {
	const { render, requests, find, tick } = createHarness();
	const dataView = () => find( render(), ( node ) => node.type === 'dataviews' );
	const initial = dataView();
	initial.props.onChangeView( { ...initial.props.view, page: 3 } );
	const oldView = dataView();
	oldView.props.onChangeView( { ...oldView.props.view, page: 2, search: 'new' } );
	dataView();
	assert.equal( requests[ 1 ].options.signal.aborted, true );
	requests[ 2 ].resolve( { items: [ { name: 'new', label: 'New' } ], total: 40 } );
	await tick();
	requests[ 1 ].resolve( { items: [], total: 0 } );
	await tick();
	const loaded = dataView();
	assert.equal( loaded.props.view.page, 2 );
	assert.equal( loaded.props.view.search, 'new' );
	assert.equal( loaded.props.data[ 0 ].name, 'new' );
	assert.equal( requests.length, 3 );
} );
