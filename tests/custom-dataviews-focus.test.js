const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const vm = require( 'node:vm' );
const babel = require( '@babel/core' );

test( 'a successful Upload list Retry keeps keyboard focus on the stable heading', async () => {
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
