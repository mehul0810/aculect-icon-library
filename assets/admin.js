( function ( apiFetch, config ) {
	'use strict';
	var pendingFocusKey = 'iconLibraryPendingFocus';
	var navigationId = 0;
	var navigationController = null;
	var navigationInFlight = false;
	var viewCache = new Map();
	var listCache = new Map();
	var cacheEpoch = 0;
	var pendingMutations = 0;
	var activeToggle = null;
	var cacheLifetime = 30000;
	var cacheLimit = 6;

	function invalidateViews() {
		viewCache.clear();
		listCache.clear();
		cacheEpoch++;
	}

	function clearSuccessNotices( container ) {
		container.querySelectorAll( '.icon-library-notice.notice-success' ).forEach( function ( notice ) { notice.remove(); } );
	}

	function beginMutation() {
		invalidateViews();
		pendingMutations++;
		var admin = document.querySelector( '.icon-library-admin' );
		if ( admin ) {
			clearSuccessNotices( admin );
		}
	}

	function finishMutation() {
		pendingMutations--;
		invalidateViews();
	}

	function recall( cache, key ) {
		var entry = cache.get( key );
		if ( pendingMutations || ! entry || Date.now() - entry.time >= cacheLifetime ) {
			cache.delete( key );
			return null;
		}
		return entry.value;
	}

	function remember( cache, key, value, epoch ) {
		// Bound both the number and size of entries; never retain whole SVG catalogs.
		if ( pendingMutations || epoch !== cacheEpoch || JSON.stringify( value ).length > 262144 ) {
			return;
		}
		cache.delete( key );
		if ( cache.size >= cacheLimit ) {
			cache.delete( cache.keys().next().value );
		}
		cache.set( key, { value: value, time: Date.now() } );
	}

	function viewUrl( url ) {
		var clean = new URL( url, window.location.href );
		if ( '1' === clean.searchParams.get( 'icon-library-updated' ) ) {
			clean.searchParams.delete( 'icon-library-updated' );
		}
		clean.searchParams.sort();
		return clean.toString();
	}

	function rememberView( url, admin, title, epoch ) {
		// Errors must remain live responses. Success is displayed once, never replayed.
		if ( admin.querySelector( '.notice-error' ) ) {
			return;
		}
		var snapshot = admin.cloneNode( true );
		clearSuccessNotices( snapshot );
		var status = snapshot.querySelector( '.icon-library-status' );
		if ( status ) {
			status.textContent = '';
		}
		remember( viewCache, viewUrl( url ), { html: snapshot.innerHTML, title: title }, epoch );
	}

	// Cache only within this open screen, and refresh after work in another window/tab.
	window.addEventListener( 'blur', invalidateViews );
	window.addEventListener( 'pagehide', invalidateViews );
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden ) {
			invalidateViews();
		}
	} );

	window.requestAnimationFrame( function () {
		var admin = document.querySelector( '.icon-library-admin' );

		if ( admin ) {
			admin.classList.add( 'has-js' );
			admin.classList.remove( 'is-loading' );
			focusPendingTarget( admin );
			rememberView( window.location.href, admin, document.title, cacheEpoch );
			if ( '1' === new URL( window.location.href ).searchParams.get( 'icon-library-updated' ) ) {
				window.history.replaceState( window.history.state, '', viewUrl( window.location.href ) );
			}
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var link;
		var url;

		if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		link = event.target.closest( '.icon-library-tab, .icon-library-collection-row, .icon-library-back, .icon-library-empty-state a, .icon-library-custom-pagination a' );

		if ( ! link || link.target || link.hasAttribute( 'download' ) ) {
			return;
		}

		url = new URL( link.href, window.location.href );

		if ( url.origin !== window.location.origin || 'icon-library' !== url.searchParams.get( 'page' ) ) {
			return;
		}

		event.preventDefault();
		navigateTo( url.toString(), true, 0 === event.detail );
	} );

	window.addEventListener( 'popstate', function () {
		navigateTo( window.location.href, false, false );
	} );

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.icon-library-load-more-button' );
		var wrapper;
		var grid;
		var status;
			var strings;
			var requestUrl;
			var requestedNavigation = navigationId;

		if ( ! button || button.disabled ) {
			return;
		}

		wrapper = button.closest( '.icon-library-load-more' );
		grid    = document.getElementById( button.dataset.grid );
		status  = wrapper ? wrapper.querySelector( '.icon-library-load-more-status' ) : null;
		strings = config && config.i18n ? config.i18n : {};

		if ( ! wrapper || ! grid || ! button.dataset.url ) {
			return;
		}

			if ( ! window.fetch || ! window.DOMParser || ! config || ! config.previewUrl ) {
			window.location.assign( button.dataset.url );
			return;
		}

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		wrapper.classList.add( 'is-loading' );
		if ( status ) {
			status.textContent = strings.loadingMore || 'Loading more icons...';
		}

			requestUrl = new URL( config.previewUrl, window.location.href );
			new URL( button.dataset.url, window.location.href ).searchParams.forEach( function ( value, key ) {
				if ( [ 'collection', 'variant', 'category', 'search', 'icon-page' ].indexOf( key ) !== -1 ) {
					requestUrl.searchParams.set( key, value );
				}
			} );
			requestUrl.searchParams.set( 'action', 'icon_library_preview_page' );
			requestUrl.searchParams.set( '_ajax_nonce', config.previewNonce );
			window.fetch( requestUrl.toString(), {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Load more request failed.' );
			}

				return response.json();
			} ).then( function ( response ) {
				if ( requestedNavigation !== navigationId || ! grid.isConnected ) {
					return;
				}
				if ( ! response.success || ! response.data || typeof response.data.html !== 'string' ) {
					throw new Error( 'Load more response was incomplete.' );
				}
				var data = response.data;
				var page = new window.DOMParser().parseFromString( data.html, 'text/html' );
			var nextGrid = page.getElementById( button.dataset.grid );
			var added;
			var loaded;
			var total;
			var template;

			if ( ! nextGrid ) {
				throw new Error( 'Load more response was incomplete.' );
			}

			added = nextGrid.children.length;
			while ( nextGrid.firstElementChild ) {
				grid.appendChild( nextGrid.firstElementChild );
			}

			loaded = grid.children.length;
				total  = data.total;
			wrapper.dataset.loaded = loaded;

				if ( data.has_more && added ) {
					var nextUrl = new URL( button.dataset.url, window.location.href );
					nextUrl.searchParams.set( 'icon-page', data.page + 1 );
					button.dataset.url         = nextUrl.toString();
					button.dataset.page        = data.page;
				button.disabled             = false;
				button.removeAttribute( 'aria-busy' );
			} else {
				button.remove();
			}

			if ( status ) {
				template = strings.loadMoreStatus || 'Loaded %1$s of %2$s icons.';
				status.textContent = template.replace( '%1$s', loaded.toLocaleString() ).replace( '%2$s', total.toLocaleString() );
			}
			wrapper.classList.remove( 'is-loading' );
		} ).catch( function () {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			wrapper.classList.remove( 'is-loading' );
			if ( status ) {
				status.textContent = strings.loadMoreError || strings.error || 'More icons could not be loaded. Try again.';
			}
		} );
	} );

	if ( ! apiFetch || ! config ) {
		return;
	}

	apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	function cacheIconRequests( options, next ) {
		var path = options.path || '';
		var custom = path === config.customPath || 0 === path.indexOf( config.customPath + '?' ) || 0 === path.indexOf( config.customPath + '/' );
		var collection = 0 === path.indexOf( config.restPath );
		var mutation = ( custom || collection ) && [ 'GET', 'HEAD' ].indexOf( ( options.method || 'GET' ).toUpperCase() ) === -1;
		if ( mutation ) {
			beginMutation();
			return next( options ).then( function ( result ) {
				finishMutation();
				return result;
			}, function ( error ) {
				finishMutation();
				throw error;
			} );
		}
		if ( ! custom || false === options.parse || 'GET' !== ( options.method || 'GET' ).toUpperCase() ) {
			return next( options );
		}
		var cached = recall( listCache, path );
		if ( cached ) {
			return Promise.resolve( cached );
		}
		var epoch = cacheEpoch;
		return next( options ).then( function ( result ) {
			remember( listCache, path, result, epoch );
			return result;
		}, function ( error ) {
			invalidateViews();
			throw error;
		} );
	}
	apiFetch.use( cacheIconRequests );
	ensureCustomDataViews();

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '.icon-library-toggle' );
		var uploadForm = event.target.closest( '.icon-library-custom-upload' );

		if ( uploadForm ) {
			event.preventDefault();
			uploadCustomIcon( uploadForm );
			return;
		}

		if ( ! form ) {
			return;
		}

		event.preventDefault();
		if ( activeToggle ) {
			return;
		}

		var status = document.querySelector( '.icon-library-status' );
		var state = form.dataset.state;
		var collection = form.dataset.collection;
		var variant = form.dataset.variant;

		activeToggle = {
			form: form,
			admin: form.closest( '.icon-library-admin' ),
			focusTarget: form.contains( document.activeElement ) ? document.activeElement : null,
			controls: [],
		};
		lockToggleControls( activeToggle.admin, activeToggle );
		form.setAttribute( 'aria-busy', 'true' );
		status.textContent = config.i18n.updating;

		var requestedNavigation = navigationId;
		apiFetch( {
			path: config.restPath + encodeURIComponent( collection ) + ( variant ? '/variants/' + encodeURIComponent( variant ) : '' ) + '/' + state,
			method: 'POST',
		} ).then( function ( collectionSummary ) {
			if ( requestedNavigation !== navigationId ) {
				activeToggle.refreshAfterNavigation = true;
				if ( ! navigationInFlight ) {
					navigateTo( window.location.href, false, '' );
				}
				return;
			}
			if ( ! form.isConnected ) {
				finishToggle( activeToggle );
				return;
			}
			if ( ! applyCollectionSummary( form, collectionSummary ) ) {
				finishToggle( activeToggle );
				reloadWithSuccess( '.icon-library-panel h2' );
				return;
			}
			form.removeAttribute( 'aria-busy' );
			status.textContent = config.i18n.updated;
			finishToggle( activeToggle );
		} ).catch( function ( error ) {
			if ( requestedNavigation !== navigationId || ! form.isConnected ) {
				finishToggle( activeToggle );
				return;
			}
			form.removeAttribute( 'aria-busy' );
			status.textContent = error && error.message ? error.message : config.i18n.error;
			finishToggle( activeToggle );
		} );
	} );

	function lockToggleControls( admin, mutation ) {
		if ( ! admin ) {
			return;
		}
		admin.querySelectorAll( '.icon-library-toggle button[type="submit"]' ).forEach( function ( button ) {
			if ( ! mutation.controls.some( function ( control ) { return control.button === button; } ) ) {
				mutation.controls.push( { button: button, disabled: button.disabled } );
			}
			button.disabled = true;
		} );
	}

	function finishToggle( mutation ) {
		if ( ! mutation || activeToggle !== mutation ) {
			return;
		}
		activeToggle = null;
		mutation.controls.forEach( function ( control ) {
			if ( control.button.isConnected ) {
				control.button.disabled = control.disabled;
			}
		} );
		if ( mutation.form.isConnected ) {
			mutation.form.removeAttribute( 'aria-busy' );
		}
		if ( mutation.focusTarget && mutation.focusTarget.isConnected && ! mutation.focusTarget.disabled && ! mutation.focusTarget.closest( '[hidden]' ) ) {
			mutation.focusTarget.focus( { preventScroll: true } );
		}
	}

	function applyCollectionSummary( form, summary ) {
		var admin = form.closest( '.icon-library-admin' );
		var collection;
		var variants;
		if ( ! admin || ! summary || summary.slug !== form.dataset.collection || typeof summary.installed !== 'boolean' || typeof summary.enabled !== 'boolean' || ! Array.isArray( summary.variants ) ) {
			return false;
		}
		variants = new Map();
		for ( var i = 0; i < summary.variants.length; i++ ) {
			var variant = summary.variants[ i ];
			if ( ! variant || 'string' !== typeof variant.slug || 'boolean' !== typeof variant.enabled || variants.has( variant.slug ) ) {
				return false;
			}
			variants.set( variant.slug, variant.enabled );
		}
		collection = form.closest( '.icon-library-collection-detail, .icon-library-install-detail' );
		if ( ! collection ) {
			return false;
		}
		var incomplete = false;
		collection.querySelectorAll( '.icon-library-toggle' ).forEach( function ( toggle ) {
			var submit = toggle.querySelector( 'button[type="submit"]' );
			if ( ! toggle.querySelector( 'input[name="state"]' ) || ! submit || ( toggle.dataset.variant && ( ! variants.has( toggle.dataset.variant ) || ! submit.dataset.enableLabel || ! submit.dataset.disableLabel ) ) || ( 'install' === toggle.dataset.state || 'remove' === toggle.dataset.state ) && ( ! submit.dataset.installLabel || ! submit.dataset.removeLabel ) ) {
				incomplete = true;
			}
		} );
		collection.querySelectorAll( '[data-variant-state]' ).forEach( function ( state ) {
			if ( ! variants.has( state.dataset.variantState ) || ! state.dataset.activeLabel || ! state.dataset.inactiveLabel ) {
				incomplete = true;
			}
		} );
		var intro = collection.querySelector( '.icon-library-package-intro' );
		if ( intro && ( ! intro.dataset.installedText || ! intro.dataset.uninstalledText ) ) {
			incomplete = true;
		}
		if ( incomplete || ! collection.querySelector( '.icon-library-toggle button[type="submit"]' ) ) {
			return false;
		}
		collection.querySelectorAll( '.icon-library-toggle' ).forEach( function ( toggle ) {
			var submit = toggle.querySelector( 'button[type="submit"]' );
			var state = toggle.dataset.state;
			var variantSlug = toggle.dataset.variant;
			if ( ! submit ) {
				return;
			}
			if ( 'install' === state || 'remove' === state ) {
				toggle.dataset.state = summary.installed ? 'remove' : 'install';
				toggle.querySelector( 'input[name="state"]' ).value = toggle.dataset.state;
				submit.textContent = summary.installed ? submit.dataset.removeLabel : submit.dataset.installLabel;
			} else if ( variantSlug ) {
				var enabled = variants.get( variantSlug );
				if ( 'boolean' !== typeof enabled ) {
					return;
				}
				toggle.hidden = ! summary.enabled;
				toggle.dataset.state = enabled ? 'deactivate' : 'activate';
				toggle.querySelector( 'input[name="state"]' ).value = toggle.dataset.state;
				submit.textContent = enabled ? submit.dataset.disableLabel : submit.dataset.enableLabel;
			} else {
				toggle.dataset.state = summary.enabled ? 'deactivate' : 'activate';
				toggle.querySelector( 'input[name="state"]' ).value = toggle.dataset.state;
				submit.textContent = summary.enabled ? submit.dataset.disableLabel : submit.dataset.enableLabel;
			}
		} );
		collection.querySelectorAll( '[data-variant-state]' ).forEach( function ( state ) {
			var enabled = variants.get( state.dataset.variantState );
			if ( 'boolean' === typeof enabled ) {
				state.textContent = summary.enabled && enabled ? state.dataset.activeLabel : state.dataset.inactiveLabel;
			}
		} );
		if ( intro ) {
			intro.textContent = summary.installed ? intro.dataset.installedText : intro.dataset.uninstalledText;
		}
		invalidateViews();
		return true;
	}

	document.addEventListener( 'change', function ( event ) {
		var input = event.target.closest( '.icon-library-upload-area input[type="file"]' );
		if ( input ) {
			updateSelection( input );
		}
	} );

	function updateSelection( input ) {
		var form = input.closest( 'form' );
		var area = input.closest( '.icon-library-upload-area' );
		var file = input.files[ 0 ];
		var status = form.querySelector( '.icon-library-upload-status' );
		var preview = area.querySelector( 'canvas' );
		var error = file && ( input.files.length !== 1 || ! /\.svg$/i.test( file.name ) ) ? config.i18n.chooseSvg : '';
		if ( file && file.size > 65536 ) {
			error = config.i18n.fileTooLarge;
		}
		input.setCustomValidity( error );
		status.textContent = error;
		area.classList.toggle( 'has-file', !! file );
		area.classList.remove( 'has-preview' );
		preview.hidden = true;
		area.querySelector( '.icon-library-upload-file-name' ).textContent = file ? file.name : '';
		area.querySelector( '.icon-library-upload-file-size' ).textContent = file && ! error ? config.i18n.svgSize.replace( '%s', Math.ceil( file.size / 1024 ) ) : '';

		var fileName = file && ! error ? file.name.replace( /\.svg$/i, '' ) : '';
		suggestValue( form.elements.name, fileName.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /(^-|-$)/g, '' ) );
		suggestValue( form.elements.label, fileName.replace( /[-_]+/g, ' ' ).replace( /\b\w/g, function ( character ) {
			return character.toUpperCase();
		} ) );
		if ( ! file || error ) {
			return;
		}

		// Decode only as an image. The admin DOM gets pixels, never live SVG markup.
		var reader = new FileReader();
		var image = new Image();
		function current() {
			return input.isConnected && input.files[ 0 ] === file;
		}
		reader.onerror = image.onerror = function () {
			if ( current() && ! form.hasAttribute( 'aria-busy' ) ) {
				status.textContent = config.i18n.previewFailed;
			}
		};
		reader.onload = function () {
			if ( current() ) {
				image.src = reader.result;
			}
		};
		image.onload = function () {
			if ( ! current() ) {
				return;
			}
			var context = preview.getContext( '2d' );
			if ( ! context || ! image.naturalWidth || ! image.naturalHeight ) {
				image.onerror();
				return;
			}
			var scale = Math.min( preview.width / image.naturalWidth, preview.height / image.naturalHeight );
			var w = image.naturalWidth * scale;
			var h = image.naturalHeight * scale;
			context.clearRect( 0, 0, preview.width, preview.height );
			context.drawImage( image, ( preview.width - w ) / 2, ( preview.height - h ) / 2, w, h );
			preview.hidden = false;
			area.classList.add( 'has-preview' );
		};
		reader.readAsDataURL( file.slice( 0, file.size, 'image/svg+xml' ) );
	}

	function suggestValue( field, value ) {
		if ( ! field.value || field.value === field.dataset.suggestion ) {
			field.value = value;
		}
		field.dataset.suggestion = value;
	}

	document.addEventListener( 'dragover', function ( event ) {
		var area = event.target.closest( '.icon-library-upload-area' );
		if ( ! area ) {
			return;
		}
		event.preventDefault();
		area.classList.add( 'is-dragging' );
	} );

	document.addEventListener( 'dragleave', function ( event ) {
		var area = event.target.closest( '.icon-library-upload-area' );
		if ( area && ! area.contains( event.relatedTarget ) ) {
			area.classList.remove( 'is-dragging' );
		}
	} );

	document.addEventListener( 'drop', function ( event ) {
		var area = event.target.closest( '.icon-library-upload-area' );
		var input;
		if ( ! area ) {
			return;
		}
		event.preventDefault();
		area.classList.remove( 'is-dragging' );
		input = area.querySelector( 'input[type="file"]' );
		if ( ! input || input.matches( ':disabled' ) || ! event.dataTransfer || ! event.dataTransfer.files.length ) {
			return;
		}
		try {
			input.files = event.dataTransfer.files;
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} catch ( error ) {
			input.closest( 'form' ).querySelector( '.icon-library-upload-status' ).textContent = config.i18n.chooseSvg;
		}
	} );

	function uploadCustomIcon( form ) {
		var fileInput = form.querySelector( 'input[name="svg"]' );
		var file = fileInput.files[ 0 ];
		var fields = form.querySelector( 'fieldset' );
		var status = form.querySelector( '.icon-library-upload-status' );

		if ( ! file || fields.disabled || ! form.reportValidity() ) {
			return;
		}
		if ( 65536 < file.size ) {
			status.textContent = config.i18n.fileTooLarge;
			fileInput.focus();
			return;
		}

		form.setAttribute( 'aria-busy', 'true' );
		fields.disabled = true;
		status.textContent = config.i18n.uploading;

		readFile( file ).then( function ( svg ) {
			return apiFetch( {
				path: config.customPath,
				method: 'POST',
				data: {
					name: form.querySelector( 'input[name="name"]' ).value,
					label: form.querySelector( 'input[name="label"]' ).value,
					svg: svg,
				},
			} );
		} ).then( function () {
			// Refresh whichever listing is mounted now, even after an away/back navigation.
			document.dispatchEvent( new Event( 'icon-library:custom-icon-created' ) );
			if ( form.isConnected ) {
				fields.disabled = false;
				form.removeAttribute( 'aria-busy' );
				form.reset();
				updateSelection( fileInput );
				status.textContent = config.i18n.uploaded;
				if ( ! navigationInFlight ) {
					var heading = document.querySelector( '.icon-library-custom-heading' );
					heading.setAttribute( 'tabindex', '-1' );
					heading.focus();
				}
			}
		} ).catch( function ( error ) {
			form.removeAttribute( 'aria-busy' );
			fields.disabled = false;
			status.textContent = error && error.message ? error.message : config.i18n.uploadFailed;
			if ( form.isConnected && ! navigationInFlight ) {
				form.querySelector( '[type="submit"]' ).focus();
			}
		} );
	}

	function readFile( file ) {
		if ( file && 'function' === typeof file.text ) {
			return file.text();
		}

		return new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onload = function () { resolve( reader.result ); };
			reader.onerror = reject;
			reader.readAsText( file );
		} );
	}

	function reloadWithSuccess( focusSelector ) {
		var url = new URL( window.location.href );
		url.searchParams.delete( 'icon-library-upload-error' );
		url.searchParams.set( 'icon-library-updated', '1' );
		navigateTo( url.toString(), true, focusSelector || '.icon-library-panel h2' );
	}

	// Treat navigation as a single-flight state machine, including cache hits.
	function navigateTo( url, addToHistory, moveFocus ) {
		var admin = document.querySelector( '.icon-library-admin' );
		var focusSelector = 'string' === typeof moveFocus ? moveFocus : '.icon-library-panel h2, .icon-library-empty-state p';
		var requestId = ++navigationId;
		var epoch = cacheEpoch;
		var destination = new URL( url, window.location.href );
		var feedback = destination.searchParams.has( 'icon-library-updated' ) || destination.searchParams.has( 'icon-library-upload-error' );
		var cached = feedback ? null : recall( viewCache, viewUrl( url ) );
		if ( navigationController ) {
			navigationController.abort();
		}
		navigationController = window.AbortController ? new window.AbortController() : null;

		if ( focusSelector ) {
			storePendingFocus( focusSelector );
		}

		if ( config && '0' === config.dataViewsEnqueued && 'custom' === destination.searchParams.get( 'tab' ) ) {
			window.location.assign( url );
			return;
		}

		if ( ! admin ) {
			return;
		}

		navigationInFlight = true;
		admin.classList.add( 'is-navigating' );
		admin.setAttribute( 'aria-busy', 'true' );

		var request = cached ? Promise.resolve( cached ) : fetchView( url, navigationController ? navigationController.signal : undefined ).then( function ( page ) {
			if ( requestId === navigationId ) {
				rememberView( url, page.admin, page.title, epoch );
			}
			return { html: page.admin.innerHTML, title: page.title };
		} );
		request.then( function ( view ) {
			if ( requestId !== navigationId ) {
				return;
			}
			if ( epoch !== cacheEpoch ) {
				navigateTo( url, addToHistory, moveFocus );
				return;
			}
			renderView( admin, view, url, addToHistory, requestId );
		} ).catch( function ( error ) {
			if ( requestId === navigationId && error.name !== 'AbortError' ) {
				navigationInFlight = false;
				invalidateViews();
				window.location.assign( url );
			}
		} );
	}

	function fetchView( url, signal ) {
		return window.fetch( url, { credentials: 'same-origin', signal: signal } ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Navigation request failed.' );
			}
			return response.text();
		} ).then( function ( html ) {
			var page = new window.DOMParser().parseFromString( html, 'text/html' );
			var admin = page.querySelector( '.icon-library-admin' );
			if ( ! admin ) {
				throw new Error( 'Navigation response was incomplete.' );
			}
			return { admin: admin, title: page.title };
		} );
	}

	function renderView( admin, view, url, addToHistory, requestId ) {
		if ( 'function' === typeof window.iconLibraryUnmountCustomDataViews ) {
			window.iconLibraryUnmountCustomDataViews();
		}
		admin.innerHTML = view.html;
		navigationInFlight = false;
		if ( activeToggle ) {
			lockToggleControls( admin, activeToggle );
		}
		document.title = view.title;
		if ( addToHistory ) {
			window.history.pushState( {}, '', viewUrl( url ) );
		}
		if ( activeToggle && activeToggle.refreshAfterNavigation ) {
			var status = admin.querySelector( '.icon-library-status' );
			if ( status ) {
				status.textContent = config.i18n.updated;
			}
			finishToggle( activeToggle );
		}
		window.requestAnimationFrame( function () {
			if ( requestId !== navigationId ) {
				return;
			}
			admin.classList.remove( 'is-navigating' );
			admin.removeAttribute( 'aria-busy' );
			ensureCustomDataViews();
			focusPendingTarget( admin );
		} );
	}

	function storePendingFocus( selector ) {
		try {
			// Persist across both partial navigation and the full-page fallback path.
			window.sessionStorage.setItem( pendingFocusKey, selector );
		} catch ( error ) {
			// Focus restoration remains best-effort when storage is unavailable.
		}
	}

	function ensureCustomDataViews() {
		var upload = document.querySelector( '.icon-library-custom-upload' );
		if ( upload ) {
			upload.classList.add( 'is-enhanced' );
		}
		if ( document.getElementById( 'icon-library-custom-dataviews' ) && 'function' === typeof window.iconLibraryMountCustomDataViews ) {
			window.iconLibraryMountCustomDataViews();
		}
	}

	function focusPendingTarget( admin ) {
		var selector;
		var focusTarget;

		try {
			selector = window.sessionStorage.getItem( pendingFocusKey );
			window.sessionStorage.removeItem( pendingFocusKey );
		} catch ( error ) {
			return;
		}

		if ( ! selector ) {
			return;
		}

		focusTarget = admin.querySelector( selector );
		if ( focusTarget ) {
			focusTarget.setAttribute( 'tabindex', '-1' );
			focusTarget.focus( { preventScroll: true } );
		}
	}
} )( window.wp && window.wp.apiFetch, window.iconLibraryAdmin );
