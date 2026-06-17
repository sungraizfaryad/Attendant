/**
 * Attendant — Schema page handler (Rescan button).
 *
 * Enqueued by AICM_Admin::enqueue_assets() on the Schema page.
 * Depends on the global `attendantAdmin` (REST URL + nonce) and on `attendantSchema.i18n`
 * (translated strings) added via wp_localize_script().
 */
( function () {
	'use strict';

	const i18n   = ( window.attendantSchema && attendantSchema.i18n ) || {};
	const btn    = document.getElementById( 'attendant-rescan-btn' );
	const status = document.getElementById( 'attendant-rescan-status' );

	if ( ! btn || ! window.attendantAdmin ) {
		return;
	}

	btn.addEventListener( 'click', function () {
		btn.disabled       = true;
		status.textContent = i18n.scanning;

		fetch( attendantAdmin.restUrl + '/schema/rescan', {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   attendantAdmin.nonce,
			},
		} )
		.then( function ( res ) { return res.json(); } )
		.then( function ( json ) {
			if ( json.success ) {
				status.textContent = i18n.doneReloading;
				setTimeout( function () { window.location.reload(); }, 800 );
			} else {
				status.textContent = json.message || i18n.errorRetry;
				btn.disabled = false;
			}
		} )
		.catch( function () {
			status.textContent = i18n.networkError;
			btn.disabled = false;
		} );
	} );
}() );
