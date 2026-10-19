const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );
const { jobPath, isFinished } = require( '../assets/library-installer.js' );

test( 'installer paths contain only route identifiers', () => {
	assert.equal( jobPath( '/icon-library/v1/library-packages/', 'sample-icons' ), '/icon-library/v1/library-packages/sample-icons/jobs' );
	assert.equal( jobPath( '/icon-library/v1/library-packages/', 'sample-icons', 'job-123' ), '/icon-library/v1/library-packages/sample-icons/jobs/job-123/run' );
	assert.equal( jobPath( '/base/', 'a/b' ), '/base/a%2Fb/jobs' );
} );

test( 'only terminal jobs stop progress polling', () => {
	assert.equal( isFinished( null ), false );
	assert.equal( isFinished( { status: 'queued' } ), false );
	assert.equal( isFinished( { status: 'running' } ), false );
	assert.equal( isFinished( { status: 'succeeded' } ), true );
	assert.equal( isFinished( { status: 'failed' } ), true );
} );

test( 'page load is read-only and a button press sends explicit nonce mutations', async () => {
	const calls = [];
	const handlers = {};
	const status = { textContent: '' };
	const button = { disabled: false };
	const group = { isConnected: true, querySelector: () => status };
	const form = {
		isConnected: true,
		closest: selector => selector === '.icon-library-package-group' ? group : null,
		querySelector: () => button,
		elements: {
			library: { value: 'sample-icons' },
			style: { value: 'outline' },
			version: { value: '1.2.3' }
		},
		setAttribute() {},
		removeAttribute() {}
	};
	const document = { addEventListener: ( name, handler ) => { handlers[ name ] = handler; } };
	const window = {
		document,
		wp: { apiFetch: request => {
			calls.push( request );
			return Promise.resolve( calls.length === 1 ? { job_id: 'job-123', status: 'queued' } : { status: 'succeeded' } );
		} },
		iconLibraryInstaller: { restPath: '/icon-library/v1/library-packages/', nonce: 'nonce-123', i18n: { working: 'working', complete: 'complete' } },
		setInterval: () => 1,
		clearInterval() {},
		location: { reload() {} }
	};
	vm.runInNewContext( fs.readFileSync( require.resolve( '../assets/library-installer.js' ), 'utf8' ), { window } );
	assert.equal( calls.length, 0 );
	let prevented = false;
	const event = { target: { closest: () => form }, preventDefault() { prevented = true; } };
	handlers.submit( event );
	await new Promise( resolve => setImmediate( resolve ) );
	assert.equal( prevented, true );
	assert.equal( calls.length, 2 );
	assert.equal( calls[ 0 ].method, 'POST' );
	assert.equal( calls[ 0 ].headers[ 'X-WP-Nonce' ], 'nonce-123' );
	assert.equal( calls[ 0 ].data.style, 'outline' );
	assert.equal( calls[ 0 ].data.version, '1.2.3' );
	assert.equal( calls[ 1 ].path, '/icon-library/v1/library-packages/sample-icons/jobs/job-123/run' );
	assert.equal( calls[ 1 ].headers[ 'X-WP-Nonce' ], 'nonce-123' );
} );

test( 'already-installed response succeeds without attempting to run an empty job ID', async () => {
	const calls = [];
	const handlers = {};
	const status = { textContent: '' };
	let reloads = 0;
	const group = { isConnected: true, querySelector: () => status };
	const form = {
		isConnected: true,
		closest: selector => selector === '.icon-library-package-group' ? group : null,
		querySelector: () => ( { disabled: false } ),
		elements: {
			library: { value: 'sample-icons' },
			style: { value: 'outline' },
			version: { value: '1.2.3' }
		},
		setAttribute() {},
		removeAttribute() {}
	};
	const window = {
		document: { addEventListener: ( name, handler ) => { handlers[ name ] = handler; } },
		wp: { apiFetch: request => {
			calls.push( request );
			return Promise.resolve( { status: 'succeeded', job_id: '' } );
		} },
		iconLibraryInstaller: { restPath: '/icon-library/v1/library-packages/', nonce: 'nonce-123', i18n: { working: 'working', complete: 'complete', unknown: 'unknown' } },
		setInterval: () => { throw new Error( 'Polling must not begin.' ); },
		clearInterval() {},
		location: { reload() { reloads++; } }
	};
	vm.runInNewContext( fs.readFileSync( require.resolve( '../assets/library-installer.js' ), 'utf8' ), { window } );
	handlers.submit( { target: { closest: () => form }, preventDefault() {} } );
	await new Promise( resolve => setImmediate( resolve ) );
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ].method, 'POST' );
	assert.equal( calls[ 0 ].headers[ 'X-WP-Nonce' ], 'nonce-123' );
	assert.equal( status.textContent, 'complete' );
	assert.equal( reloads, 1 );
} );

for ( const scenario of [ 'failed', 'succeeded', 'unreadable', 'superseded' ] ) {
	test( `an interrupted run recovers durable status without retrying: ${ scenario }`, async () => {
		const calls = [], handlers = {}, status = { textContent: '' }, button = { disabled: false };
		let reloads = 0;
		const group = { isConnected: true, querySelector: () => status };
		const form = {
			isConnected: true, closest: () => group, querySelector: () => button,
			elements: { library: { value: 'sample-icons' }, style: { value: 'outline' }, version: { value: '1.2.3' } },
			setAttribute() {}, removeAttribute() {}
		};
		const window = {
			document: { addEventListener: ( name, handler ) => { handlers[ name ] = handler; } },
			wp: { apiFetch: request => {
				calls.push( request );
				if ( calls.length === 1 ) return Promise.resolve( { job_id: 'job-123', status: 'queued' } );
				if ( calls.length === 2 || scenario === 'unreadable' ) return Promise.reject( new Error( 'Interrupted transport' ) );
				return Promise.resolve( { job: { job_id: scenario === 'superseded' ? 'job-new' : 'job-123', style_id: 'outline', release_version: '1.2.3', status: scenario === 'succeeded' ? 'succeeded' : 'failed', error: 'Outbound downloads are blocked.' } } );
			} },
			iconLibraryInstaller: { restPath: '/icon-library/v1/library-packages/', nonce: 'nonce-123', i18n: { working: 'working', complete: 'complete', failed: 'failed', unknown: 'unknown' } },
			setInterval: () => 1, clearInterval() {}, location: { reload() { reloads++; } }
		};
		vm.runInNewContext( fs.readFileSync( require.resolve( '../assets/library-installer.js' ), 'utf8' ), { window } );
		handlers.submit( { target: { closest: () => form }, preventDefault() {} } );
		await new Promise( resolve => setImmediate( resolve ) );
		assert.equal( calls.length, 3 );
		assert.equal( calls.filter( request => request.method === 'POST' ).length, 2 );
		assert.equal( calls[ 2 ].path, '/icon-library/v1/library-packages/sample-icons/jobs' );
		assert.equal( calls[ 2 ].method, undefined );
		assert.equal( button.disabled, false );
		assert.equal( status.textContent, scenario === 'failed' ? 'Outbound downloads are blocked.' : scenario === 'succeeded' ? 'complete' : 'unknown' );
		assert.equal( reloads, scenario === 'succeeded' ? 1 : 0 );
	} );
}
