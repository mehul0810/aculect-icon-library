( function ( root ) {
	'use strict';

	function jobPath( base, library, jobId ) {
		var path = base + encodeURIComponent( library ) + '/jobs';
		return jobId ? path + '/' + encodeURIComponent( jobId ) + '/run' : path;
	}

	function isFinished( job ) {
		return !! job && ( 'succeeded' === job.status || 'failed' === job.status );
	}

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = { jobPath: jobPath, isFinished: isFinished };
	}

	if ( ! root.document || ! root.wp || ! root.wp.apiFetch || ! root.iconLibraryInstaller ) {
		return;
	}

	var apiFetch = root.wp.apiFetch;
	var config = root.iconLibraryInstaller;
	var active = false;

	function request( path, options ) {
		return apiFetch( Object.assign( { path: path }, options || {} ) );
	}

	function showStatus( group, message ) {
		var status = group.querySelector( '.icon-library-package-job-status' );
		if ( ! status ) {
			status = root.document.createElement( 'p' );
			status.className = 'icon-library-package-job-status';
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
			group.insertBefore( status, group.firstChild.nextSibling );
		}
		status.textContent = message;
	}

	root.document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '.icon-library-package-form' );
		var group;
		var button;
		var library;
		var style;
		var version;
		var jobId;
		var timer;
		var nonceHeaders;

		if ( ! form || ! form.isConnected ) {
			return;
		}
		event.preventDefault();
		if ( active ) {
			return;
		}
		group = form.closest( '.icon-library-package-group' );
		button = form.querySelector( 'button[type="submit"]' );
		library = form.elements.library.value;
		style = form.elements.style.value;
		version = form.elements.version.value;
		jobId = form.elements.job_id ? form.elements.job_id.value : '';
		if ( ! group || ! button || ! library || ! style || ! version ) {
			return;
		}

		active = true;
		button.disabled = true;
		form.setAttribute( 'aria-busy', 'true' );
		showStatus( group, config.i18n.working );
		nonceHeaders = { 'X-WP-Nonce': config.nonce };

		function poll() {
			request( jobPath( config.restPath, library ) ).then( function ( state ) {
				var job = state && state.job;
				if ( ! job || ! group.isConnected || isFinished( job ) ) {
					return;
				}
				showStatus( group, style + ' ' + version + ': ' + job.status + ( Number.isFinite( Number( job.progress ) ) ? ' (' + Number( job.progress ) + '%)' : '' ) );
			} ).catch( function () {
				// The install request remains authoritative; a progress read can fail independently.
			} );
		}

		var queued = jobId ? Promise.resolve( { job_id: jobId } ) : request( jobPath( config.restPath, library ), {
			method: 'POST',
			data: { style: style, version: version },
			headers: nonceHeaders
		} );

		queued.then( function ( job ) {
			if ( job && 'succeeded' === job.status ) {
				return job;
			}
			if ( ! job || ! job.job_id ) {
				throw new Error( 'Missing job identifier.' );
			}
			timer = root.setInterval( poll, 2000 );
			return request( jobPath( config.restPath, library, job.job_id ), { method: 'POST', headers: nonceHeaders } );
		} ).then( function ( job ) {
			if ( isFinished( job ) ) {
				showStatus( group, 'succeeded' === job.status ? config.i18n.complete : config.i18n.failed );
			} else {
				showStatus( group, config.i18n.reload );
			}
			root.location.reload();
		} ).catch( function () {
			showStatus( group, config.i18n.unknown );
			button.disabled = false;
			form.removeAttribute( 'aria-busy' );
		} ).finally( function () {
			root.clearInterval( timer );
			active = false;
		} );
	} );
} )( typeof window !== 'undefined' ? window : globalThis );
