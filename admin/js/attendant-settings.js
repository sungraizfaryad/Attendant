/**
 * Attendant — Settings page handler.
 *
 * Enqueued by AICM_Admin::enqueue_assets() on the top-level settings page.
 * Depends on the global `attendantAdmin` object (REST URL, nonce, i18n) that is
 * attached to the `wp-api` handle via wp_add_inline_script().
 *
 * All user-visible strings come from attendantAdmin.i18n (already translated/escaped
 * server-side); no markup is built from untrusted input.
 */
( function () {
	'use strict';

	const form       = document.getElementById( 'attendant-settings-form' );
	const saveBtn    = document.getElementById( 'attendant-save-settings' );
	const saveStatus = document.getElementById( 'attendant-save-status' );
	const notice     = document.getElementById( 'attendant-notice' );

	if ( ! form || ! window.attendantAdmin ) {
		return;
	}

	// -----------------------------------------------------------------
	// Tabs: one panel visible at a time; active tab tracked in the URL
	// hash so a reload (or a link to #indexing) lands on the right tab.
	// -----------------------------------------------------------------
	const tabs   = document.querySelectorAll( '.attendant-tabs .nav-tab' );
	const panels = document.querySelectorAll( '.attendant-tab-panel' );

	function activateTab( name ) {
		let found = false;
		tabs.forEach( function ( t ) {
			const match = t.dataset.tab === name;
			t.classList.toggle( 'nav-tab-active', match );
			found = found || match;
		} );
		if ( ! found ) {
			return false;
		}
		panels.forEach( function ( p ) {
			p.classList.toggle( 'is-active', p.dataset.tab === name );
		} );
		return true;
	}

	tabs.forEach( function ( t ) {
		t.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			activateTab( t.dataset.tab );
			history.replaceState( null, '', '#' + t.dataset.tab );
		} );
	} );

	if ( window.location.hash ) {
		activateTab( window.location.hash.slice( 1 ) );
	}

	// Checkboxes that must be sent as explicit booleans: unchecked boxes are
	// absent from FormData, so without this they could never be turned off.
	const CHECKBOXES = [ 'logging_enabled', 'auto_sync', 'file_logging', 'lead_capture' ];

	// -----------------------------------------------------------------
	// Helper: show an admin notice banner.
	// -----------------------------------------------------------------
	function showNotice( message, type ) {
		notice.className = 'notice notice-' + ( type || 'info' ) + ' inline';
		notice.textContent = message;
		notice.style.display = 'block';
		notice.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	// -----------------------------------------------------------------
	// Save settings via REST.
	// -----------------------------------------------------------------
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		saveBtn.disabled  = true;
		saveStatus.textContent = attendantAdmin.i18n.saving;

		const data = {};
		new FormData( form ).forEach( function ( value, key ) {
			// Checkboxes: if present in FormData it is '1' (checked).
			if ( CHECKBOXES.indexOf( key ) !== -1 ) {
				data[ key ] = true;
			} else {
				data[ key ] = value;
			}
		} );

		// Checkboxes not in FormData are unchecked (false).
		CHECKBOXES.forEach( function ( key ) {
			if ( ! ( key in data ) ) {
				data[ key ] = false;
			}
		} );

		fetch( attendantAdmin.restUrl + '/settings', {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   attendantAdmin.nonce,
			},
			body: JSON.stringify( data ),
		} )
		.then( function ( res ) { return res.json(); } )
		.then( function ( json ) {
			if ( json.success ) {
				saveStatus.textContent = attendantAdmin.i18n.saved;
				showNotice( attendantAdmin.i18n.saved, 'success' );
				// The key-stored placeholders, test buttons and the
				// re-index notice are server-rendered — refresh so they
				// reflect what was just saved.
				window.location.reload();
			} else {
				saveStatus.textContent = attendantAdmin.i18n.error;
				showNotice( json.message || attendantAdmin.i18n.error, 'error' );
			}
		} )
		.catch( function () {
			saveStatus.textContent = attendantAdmin.i18n.error;
			showNotice( attendantAdmin.i18n.error, 'error' );
		} )
		.finally( function () {
			saveBtn.disabled = false;
		} );
	} );

	// -----------------------------------------------------------------
	// Provider switcher: show only the active provider's key/model row.
	// -----------------------------------------------------------------
	const providerSelect = document.getElementById( 'attendant-active-provider' );

	if ( providerSelect ) {
		providerSelect.addEventListener( 'change', function () {
			document.querySelectorAll( '.attendant-provider-config' ).forEach( function ( row ) {
				row.style.display = row.dataset.provider === providerSelect.value ? '' : 'none';
			} );
		} );
	}

	// -----------------------------------------------------------------
	// Test connection button.
	// -----------------------------------------------------------------
	document.querySelectorAll( '.attendant-test-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			const provider   = btn.dataset.provider;
			const resultSpan = document.getElementById( 'attendant-test-' + provider + '-result' );

			btn.disabled           = true;
			resultSpan.textContent = attendantAdmin.i18n.testing;
			resultSpan.className   = 'attendant-test-result';

			fetch( attendantAdmin.restUrl + '/test-connection', {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   attendantAdmin.nonce,
				},
				body: JSON.stringify( { provider: provider, api_key: '' } ),
			} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( json.success ) {
					resultSpan.textContent = '✓ ' + json.message + ( json.model ? ' (' + json.model + ')' : '' );
					resultSpan.className   = 'attendant-test-result attendant-test-ok';
				} else {
					resultSpan.textContent = '✗ ' + json.message;
					resultSpan.className   = 'attendant-test-result attendant-test-fail';
				}
			} )
			.catch( function () {
				resultSpan.textContent = '✗ ' + attendantAdmin.i18n.error;
				resultSpan.className   = 'attendant-test-result attendant-test-fail';
			} )
			.finally( function () {
				btn.disabled = false;
			} );
		} );
	} );

}() );
