/* Rendered navigation and cache lifecycle tests; no server or browser required. */
const { test } = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const path = require( 'node:path' );
const { JSDOM } = require( 'jsdom' );

const tick = () => new Promise( ( resolve ) => setImmediate( resolve ) );
function markup( tab, notice = '' ) {
	return `<html><head><title>${ tab }</title></head><body><div class="icon-library-admin"><h1>Icons</h1>
		<div class="icon-library-status" role="status"></div>${ notice }
		<div class="icon-library-panel icon-library-collection-detail"><h2>${ tab }</h2><div class="icon-library-variant-list">
		<span class="icon-library-variant-state" data-variant-state="regular" data-active-label="Active" data-inactive-label="Inactive">Active</span>
		<form class="icon-library-toggle icon-library-variant-toggle" data-collection="rune" data-variant="regular" data-state="deactivate"><input name="state" value="deactivate"><button type="submit" data-enable-label="Enable" data-disable-label="Disable">Disable</button></form></div>
		<form class="icon-library-toggle" data-collection="rune" data-state="deactivate"><input name="state" value="deactivate"><button type="submit" data-enable-label="Enable" data-disable-label="Disable">Disable</button></form>
		<div class="preview">Rendered icon preview</div></div>
		<section class="icon-library-install-detail"><p class="icon-library-package-intro" data-installed-text="Remove disabled family." data-uninstalled-text="Install adds disabled family.">Install adds disabled family.</p>
		<form class="icon-library-toggle" data-collection="rune" data-state="install"><input name="state" value="install"><button type="submit" data-install-label="Install Library" data-remove-label="Remove from Library">Install Library</button></form></section>
		</div></body></html>`;
}

function harness( supportsAbort = true, initialNotice = '', initialQuery = '' ) {
	const origin = 'https://wp.test';
	const initialUrl = origin + '/wp-admin/themes.php?page=icon-library&tab=library' + initialQuery;
	const dom = new JSDOM( markup( 'library', initialNotice ), { url: initialUrl } );
	const document = dom.window.document;
	const events = {}, requests = [], apiRequests = [], history = [], redirects = [], middleware = [];
	const cacheMaps = [];
	class ObservedMap extends Map {
		constructor() { super(); cacheMaps.push( this ); }
	}
	let now = 0, unmounts = 0;
	const apiFetch = ( options ) => middleware.reduceRight( ( next, fn ) => ( value ) => fn( value, next ),
		( value ) => new Promise( ( resolve, reject ) => apiRequests.push( { options: value, resolve, reject } ) ) )( options );
	apiFetch.use = ( fn ) => middleware.unshift( fn );
	apiFetch.createNonceMiddleware = () => ( options, next ) => next( options );
	const window = {
		wp: { apiFetch },
		iconLibraryAdmin: { nonce: 'test', customPath: '/icon-library/v1/custom-icons', restPath: '/icon-library/v1/collections/', i18n: { updating: 'Updating library...', updated: 'Icon library updated.' } },
		iconLibraryUnmountCustomDataViews: () => { unmounts++; },
		location: { href: initialUrl, origin, assign: ( url ) => redirects.push( url ) },
		history: {
			pushState: ( state, title, url ) => { history.push( url ); window.location.href = url; },
			replaceState: ( state, title, url ) => { window.location.href = url; },
		},
		sessionStorage: dom.window.sessionStorage,
		requestAnimationFrame: ( callback ) => callback(),
		addEventListener: ( type, callback ) => { events[ type ] = callback; },
		AbortController: supportsAbort ? AbortController : undefined,
		fetch: ( url, options ) => new Promise( ( resolve, reject ) => requests.push( { url, options, resolve, reject } ) ),
		DOMParser: dom.window.DOMParser,
	};
	vm.runInNewContext( fs.readFileSync( path.join( __dirname, '../assets/admin.js' ), 'utf8' ), {
		window, document, URL, Map: ObservedMap, Date: { now: () => now },
	} );
	function navigate( tab, query = '' ) {
		const link = document.createElement( 'a' );
		link.className = 'icon-library-tab';
		link.href = `?page=icon-library&tab=${ tab }${ query }`;
		document.body.appendChild( link );
		link.dispatchEvent( new dom.window.MouseEvent( 'click', { bubbles: true, cancelable: true, button: 0, detail: 0 } ) );
		link.remove();
	}
	async function complete( index, tab, notice = '' ) {
		requests[ index ].resolve( { ok: true, text: async () => markup( tab, notice ) } );
		await tick();
	}
	return { document, requests, apiRequests, apiFetch, history, redirects, events, window, navigate, complete, cacheMaps,
		advance: ( ms ) => { now += ms; }, get unmounts() { return unmounts; } };
}

const summary = ( enabled = false ) => ( { slug: 'rune', installed: true, enabled, variants: [ { slug: 'regular', enabled } ] } );

test( 'confirmed toggle updates controls in place without fetching or replacing the preview', async () => {
	const h = harness();
	const preview = h.document.querySelector( '.preview' );
	const form = h.document.querySelector( '.icon-library-toggle' );
	form.dispatchEvent( new h.document.defaultView.Event( 'submit', { bubbles: true, cancelable: true } ) );
	assert.equal( h.apiRequests.length, 1 );
	assert.equal( h.requests.length, 0 );
	h.apiRequests[ 0 ].resolve( summary( false ) );
	await tick();
	assert.equal( h.requests.length, 0 );
	assert.equal( h.document.querySelector( '.preview' ), preview );
	assert.equal( form.querySelector( 'button' ).textContent, 'Enable' );
	assert.equal( form.querySelector( 'input[name="state"]').value, 'activate' );
	assert.equal( h.document.querySelector( '.icon-library-status' ).textContent, 'Icon library updated.' );
} );

test( 'sibling toggles serialize and restore disabled state and keyboard focus', async () => {
	const h = harness();
	const variantForm = h.document.querySelector( '.icon-library-variant-toggle' );
	const collectionForm = h.document.querySelector( '.icon-library-collection-detail > .icon-library-toggle' );
	const installForm = h.document.querySelector( '.icon-library-install-detail .icon-library-toggle' );
	const focusedButton = variantForm.querySelector( 'button' );
	focusedButton.focus();
	variantForm.dispatchEvent( new h.document.defaultView.Event( 'submit', { bubbles: true, cancelable: true } ) );
	assert.equal( h.apiRequests.length, 1 );
	assert.equal( [ focusedButton, collectionForm.querySelector( 'button' ), installForm.querySelector( 'button' ) ].every( button => button.disabled ), true );
	collectionForm.dispatchEvent( new h.document.defaultView.Event( 'submit', { bubbles: true, cancelable: true } ) );
	assert.equal( h.apiRequests.length, 1, 'a sibling submit cannot race the pending summary' );
	h.apiRequests[ 0 ].resolve( summary( false ) );
	await tick();
	assert.equal( [ focusedButton, collectionForm.querySelector( 'button' ), installForm.querySelector( 'button' ) ].every( button => ! button.disabled ), true );
	assert.equal( h.document.activeElement, focusedButton );
	assert.equal( collectionForm.querySelector( 'button' ).textContent, 'Enable' );
} );

test( 'a confirmed stale-view toggle stays serialized until the replacement view is fresh', async () => {
	const h = harness();
	h.document.querySelector( '.icon-library-toggle' ).dispatchEvent( new h.document.defaultView.Event( 'submit', { bubbles: true, cancelable: true } ) );
	h.navigate( 'install' );
	await h.complete( 0, 'install' );
	assert.equal( h.document.querySelector( '.icon-library-toggle button' ).disabled, true );
	h.apiRequests[ 0 ].resolve( summary( false ) );
	await tick();
	assert.equal( h.requests.length, 2, 'the current view is refreshed after the stale mutation succeeds' );
	assert.equal( h.document.querySelector( '.icon-library-toggle button' ).disabled, true );
	await h.complete( 1, 'install fresh' );
	assert.equal( h.document.querySelector( 'h2' ).textContent, 'install fresh' );
	assert.equal( h.document.querySelector( '.icon-library-status' ).textContent, 'Icon library updated.' );
	assert.equal( h.document.querySelector( '.icon-library-toggle button' ).disabled, false );
} );

test( 'mutation completion during pending navigation preserves its destination and refreshes by epoch', async () => {
	const h = harness();
	h.document.querySelector( '.icon-library-toggle' ).dispatchEvent( new h.document.defaultView.Event( 'submit', { bubbles: true, cancelable: true } ) );
	h.navigate( 'install' );
	assert.equal( h.requests.length, 1 );
	h.apiRequests[ 0 ].resolve( summary( false ) );
	await tick();
	assert.equal( h.requests.length, 1, 'pending destination is not replaced with the previously committed URL' );
	assert.match( h.requests[ 0 ].url, /tab=install/ );
	await h.complete( 0, 'install stale' );
	assert.equal( h.requests.length, 2, 'cache epoch forces the pending destination to refetch after mutation' );
	assert.equal( h.document.querySelector( 'h2' ).textContent, 'library' );
	await h.complete( 1, 'install fresh' );
	assert.equal( h.document.querySelector( 'h2' ).textContent, 'install fresh' );
	assert.equal( h.document.querySelector( '.icon-library-status' ).textContent, 'Icon library updated.' );
	assert.equal( h.document.querySelector( '.icon-library-toggle button' ).disabled, false );
} );

test( 'confirmed install and removal update the detail action and copy without replacing preview nodes', async () => {
	const h = harness();
	const preview = h.document.querySelector( '.preview' );
	const form = h.document.querySelector( '.icon-library-install-detail .icon-library-toggle' );
	form.dispatchEvent( new h.document.defaultView.Event( 'submit', { bubbles: true, cancelable: true } ) );
	h.apiRequests[ 0 ].resolve( { slug: 'rune', installed: true, enabled: false, variants: [ { slug: 'regular', enabled: false } ] } );
	await tick();
	assert.equal( form.dataset.state, 'remove' );
	assert.equal( form.querySelector( 'button' ).textContent, 'Remove from Library' );
	assert.equal( h.document.querySelector( '.icon-library-package-intro' ).textContent, 'Remove disabled family.' );
	assert.equal( h.requests.length, 0 );
	assert.equal( h.document.querySelector( '.preview' ), preview );
} );

test( 'incomplete mutation summary uses full-page fallback rather than claiming success', async () => {
	const h = harness();
	h.document.querySelector( '.icon-library-toggle' ).dispatchEvent( new h.document.defaultView.Event( 'submit', { bubbles: true, cancelable: true } ) );
	h.apiRequests[ 0 ].resolve( { slug: 'rune', installed: true } );
	await tick();
	assert.equal( h.requests.length, 1 );
	assert.match( h.requests[ 0 ].url, /icon-library-updated=1/ );
	assert.equal( h.document.querySelector( '.icon-library-status' ).textContent, 'Updating library...' );
} );

test( 'a mutation response cannot update a view after navigation has moved on', async () => {
	const h = harness();
	h.document.querySelector( '.icon-library-toggle' ).dispatchEvent( new h.document.defaultView.Event( 'submit', { bubbles: true, cancelable: true } ) );
	h.navigate( 'install' );
	await h.complete( 0, 'install' );
	h.apiRequests[ 0 ].resolve( summary( false ) );
	await tick();
	assert.equal( h.requests.length, 2, 'confirmed mutation refreshes the view without applying to stale DOM' );
	await h.complete( 1, 'install fresh' );
	assert.equal( h.document.querySelector( 'h2' ).textContent, 'install fresh' );
	assert.equal( h.document.querySelector( '.icon-library-status' ).textContent, 'Icon library updated.' );
} );

for ( const supportsAbort of [ true, false ] ) {
	test( `latest navigation wins, including late cache writes (AbortController: ${ supportsAbort })`, async () => {
		const h = harness( supportsAbort );
		h.navigate( 'install' ); h.navigate( 'custom' );
		if ( supportsAbort ) assert.equal( h.requests[ 0 ].options.signal.aborted, true );
		await h.complete( 1, 'custom' ); await h.complete( 0, 'install' );
		assert.equal( h.document.querySelector( 'h2' ).textContent, 'custom' );
		assert.equal( h.history.length, 1 );
		h.navigate( 'install' ); assert.equal( h.requests.length, 3, 'superseded response was not cached' );
	} );
}

test( 'cached Back supersedes an in-flight link and restores focus without adding history', async () => {
	const h = harness(); h.navigate( 'install' ); h.events.popstate();
	await tick(); await h.complete( 0, 'install' );
	assert.equal( h.document.querySelector( 'h2' ).textContent, 'library' );
	assert.equal( h.history.length, 0 );
	assert.equal( h.document.activeElement.textContent, 'library' );
	assert.equal( h.unmounts, 1 );
} );

test( 'repeated navigation reuses the view, expires after 30 seconds and clears on blur', async () => {
	const h = harness(); h.navigate( 'install' ); await h.complete( 0, 'install' );
	h.navigate( 'library' ); await tick(); h.navigate( 'install' ); await tick();
	assert.equal( h.requests.length, 1 ); assert.equal( h.history.length, 3 );
	assert.equal( h.document.activeElement.textContent, 'install' );
	h.advance( 30000 ); h.navigate( 'install' ); await h.complete( 1, 'install' );
	h.events.blur(); h.navigate( 'install' ); assert.equal( h.requests.length, 3 );
} );

test( 'view cache is bounded to six entries', async () => {
	const h = harness();
	for ( let i = 0; i < 7; i++ ) {
		h.navigate( 'install', '&collection=' + i ); await h.complete( i, 'collection ' + i );
	}
	h.navigate( 'install', '&collection=0' ); assert.equal( h.requests.length, 8 );
} );

test( 'success appears once and cannot replay through a cached view or history URL', async () => {
	const h = harness(); h.navigate( 'install', '&icon-library-updated=1' );
	await h.complete( 0, 'install', '<div class="notice icon-library-notice notice-success" role="status">Library settings updated.</div>' );
	assert.equal( h.document.querySelectorAll( '.notice-success' ).length, 1 );
	assert.equal( h.document.querySelector( '.icon-library-status' ).textContent, '' );
	assert.equal( new URL( h.window.location.href ).searchParams.has( 'icon-library-updated' ), false );
	h.navigate( 'library' ); await tick(); h.navigate( 'install' ); await tick();
	assert.equal( h.document.querySelectorAll( '.notice-success' ).length, 0 );
	assert.equal( h.requests.length, 1 );
} );

test( 'initial success consumes its flag but initial upload failure stays visible and uncached', async () => {
	const success = harness( true, '<div class="notice icon-library-notice notice-success">Saved</div>', '&icon-library-updated=1' );
	assert.equal( new URL( success.window.location.href ).searchParams.has( 'icon-library-updated' ), false );
	assert.equal( success.document.querySelector( '.notice-success' ).textContent, 'Saved' );
	const failure = harness( true, '<div class="notice notice-error" role="alert">Check uploads before retrying.</div>', '&icon-library-upload-error=icon_library_custom_cleanup_failed' );
	assert.match( failure.window.location.href, /icon-library-upload-error/ );
	assert.match( failure.document.querySelector( '[role="alert"]' ).textContent, /Check uploads/ );
	failure.navigate( 'library' ); assert.equal( failure.requests.length, 1 );
} );

test( 'custom results are cached but attempted and completed mutations invalidate all views', async () => {
	const h = harness(), query = { path: '/icon-library/v1/custom-icons?page=1' };
	const first = h.apiFetch( query ); h.apiRequests[ 0 ].resolve( { items: [], total: 0 } ); await first;
	await h.apiFetch( query ); assert.equal( h.apiRequests.length, 1 );
	const mutation = h.apiFetch( { path: '/icon-library/v1/custom-icons/icon', method: 'DELETE' } );
	const during = h.apiFetch( query ); h.apiRequests[ 2 ].resolve( { items: [], total: 0 } ); await during;
	h.apiRequests[ 1 ].resolve( {} ); await mutation;
	const fresh = h.apiFetch( query ); assert.equal( h.apiRequests.length, 4 );
	h.apiRequests[ 3 ].resolve( { items: [], total: 0 } ); await fresh;
	h.navigate( 'library' ); assert.equal( h.requests.length, 1, 'initial HTML was invalidated' );
} );

test( 'late list responses cannot refill caches across a mutation; failed mutations also invalidate', async () => {
	const h = harness(), query = { path: '/icon-library/v1/custom-icons?page=1' };
	const old = h.apiFetch( query );
	const mutation = h.apiFetch( { path: '/icon-library/v1/collections/heroicons/activate', method: 'POST' } );
	h.apiRequests[ 1 ].reject( Error( 'Unconfirmed' ) ); await assert.rejects( mutation );
	h.apiRequests[ 0 ].resolve( { items: [], total: 0 } ); await old;
	const fresh = h.apiFetch( query ); assert.equal( h.apiRequests.length, 3 );
	h.apiRequests[ 2 ].resolve( { items: [], total: 0 } ); await fresh;
} );

test( 'list errors are never cached and original cancellation signals reach the network', async () => {
	const h = harness(), controller = new AbortController();
	const query = { path: '/icon-library/v1/custom-icons?page=1', signal: controller.signal };
	const failed = h.apiFetch( query ); assert.equal( h.apiRequests[ 0 ].options.signal, controller.signal );
	h.apiRequests[ 0 ].reject( Error( 'Forbidden' ) ); await assert.rejects( failed );
	const retry = h.apiFetch( query ); assert.equal( h.apiRequests.length, 2 );
	h.apiRequests[ 1 ].resolve( { items: [], total: 0 } ); await retry;
} );

test( 'a view fetched across a mutation is refreshed before display', async () => {
	const h = harness(); h.navigate( 'install' );
	const mutation = h.apiFetch( { path: '/icon-library/v1/custom-icons/icon', method: 'PATCH' } );
	h.apiRequests[ 0 ].resolve( {} ); await mutation;
	await h.complete( 0, 'stale' ); assert.equal( h.requests.length, 2 );
	assert.equal( h.document.querySelector( 'h2' ).textContent, 'library' );
	await h.complete( 1, 'fresh' ); assert.equal( h.document.querySelector( 'h2' ).textContent, 'fresh' );
} );

test( 'invalid navigation responses retain the full-page fallback', async () => {
	const h = harness(); h.navigate( 'custom' );
	h.requests[ 0 ].resolve( { ok: false } ); await tick();
	assert.equal( h.redirects.length, 1 ); assert.match( h.redirects[ 0 ], /tab=custom/ );
} );

test( 'oversized views and custom results are not retained', async () => {
	const h = harness(), large = 'x'.repeat( 262145 );
	h.navigate( 'install' ); await h.complete( 0, large );
	h.navigate( 'install' ); assert.equal( h.requests.length, 2 );
	const query = { path: '/icon-library/v1/custom-icons?page=1' };
	const first = h.apiFetch( query ); h.apiRequests[ 0 ].resolve( { items: [ large ], total: 1 } ); await first;
	const second = h.apiFetch( query ); assert.equal( h.apiRequests.length, 2 );
	h.apiRequests[ 1 ].resolve( { items: [], total: 0 } ); await second;
} );

test( 'custom result expiration and leaving the page require fresh reads', async () => {
	const h = harness(), query = { path: '/icon-library/v1/custom-icons?page=1' };
	const first = h.apiFetch( query ); h.apiRequests[ 0 ].resolve( { items: [], total: 0 } ); await first;
	h.advance( 30000 );
	const expired = h.apiFetch( query ); assert.equal( h.apiRequests.length, 2 );
	h.apiRequests[ 1 ].resolve( { items: [], total: 0 } ); await expired;
	h.events.pagehide();
	const fresh = h.apiFetch( query ); assert.equal( h.apiRequests.length, 3 );
	h.apiRequests[ 2 ].resolve( { items: [], total: 0 } ); await fresh;
} );

test( 'a mutation clears only plugin success notices and retains failure and unrelated notices', async () => {
	const h = harness( true, '<div class="notice icon-library-notice notice-success">Saved</div><div class="notice notice-success">Other plugin</div><div class="notice icon-library-notice notice-error">Upload failed</div>' );
	const mutation = h.apiFetch( { path: '/icon-library/v1/custom-icons/icon', method: 'DELETE' } );
	assert.equal( h.document.querySelector( '.icon-library-notice.notice-success' ), null );
	assert.equal( h.document.querySelector( '.notice-success' ).textContent, 'Other plugin' );
	assert.equal( h.document.querySelector( '.notice-error' ).textContent, 'Upload failed' );
	h.apiRequests[ 0 ].reject( Error( 'Failed' ) ); await assert.rejects( mutation );
} );

test( 'first Upload visit uses its own document and does not prefetch list data', async () => {
	const h = harness();
	h.window.iconLibraryAdmin.dataViewsEnqueued = '0';
	h.navigate( 'install' );
	assert.equal( h.document.querySelectorAll( 'script' ).length, 0 );
	await h.complete( 0, 'install' );
	h.navigate( 'custom' );
	assert.equal( h.requests.length, 1 );
	assert.equal( h.redirects.length, 1 );
	assert.equal( new URL( h.redirects[0] ).searchParams.get( 'tab' ), 'custom' );
	assert.equal( h.document.querySelectorAll( 'script' ).length, 0 );
	assert.equal( h.apiRequests.length, 0 );
} );

test( 'an already loaded Upload module mounts only on its own tab', async () => {
	const h = harness();
	let mounts = 0;
	h.window.iconLibraryAdmin.dataViewsEnqueued = '1';
	h.window.iconLibraryMountCustomDataViews = () => { mounts++; };
	h.navigate( 'install' ); await h.complete( 0, 'install' );
	assert.equal( mounts, 0 );
	h.navigate( 'custom' ); await h.complete( 1, 'custom', '<div id="icon-library-custom-dataviews"></div>' );
	assert.equal( mounts, 1 );
	assert.equal( h.apiRequests.length, 0 );
} );

test( '100 distinct views and list queries retain bounded plain-data snapshots', async () => {
	const h = harness();
	for ( let i = 0; i < 100; i++ ) {
		h.navigate( 'install', '&collection=' + i ); await h.complete( i, 'collection ' + i );
		const request = h.apiFetch( { path: '/icon-library/v1/custom-icons?page=' + i } );
		h.apiRequests[ i ].resolve( { items: [ { name: 'icon-' + i } ], total: 500 } ); await request;
		for ( const cache of h.cacheMaps ) {
			assert.ok( cache.size <= 6 );
			for ( const entry of cache.values() ) {
				assert.ok( JSON.stringify( entry.value ).length <= 262144 );
				assert.ok( typeof entry.value.html === 'string' || Array.isArray( entry.value.items ) );
				assert.equal( entry.value.nodeType, undefined );
			}
		}
	}
	assert.deepEqual( h.cacheMaps.map( ( cache ) => cache.size ), [ 6, 6 ] );
	h.navigate( 'install', '&collection=99' ); await tick();
	await h.apiFetch( { path: '/icon-library/v1/custom-icons?page=99' } );
	assert.equal( h.requests.length, 100 ); assert.equal( h.apiRequests.length, 100 );
	h.events.blur(); assert.deepEqual( h.cacheMaps.map( ( cache ) => cache.size ), [ 0, 0 ] );
} );
