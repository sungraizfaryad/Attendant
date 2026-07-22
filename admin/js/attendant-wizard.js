/**
 * AI ChatMate onboarding wizard.
 *
 * Drives the Phase 3a REST endpoints. attendantAdmin (restUrl/nonce/i18n/settingsUrl)
 * is localized by AICM_Admin. No build step; vanilla JS.
 *
 * Security: every dynamic value inserted via innerHTML is passed through esc()
 * which HTML-encodes it (textContent round-trip), so user/site data cannot
 * inject markup.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'attendant-wizard' );
	if ( ! root || ! window.attendantAdmin ) {
		return;
	}

	var state  = { step: 0, schema: null, types: [] };
	var panels = root.querySelectorAll( '.attendant-panel' );
	var steps  = root.querySelectorAll( '.attendant-wizard-step' );
	var notice = document.getElementById( 'attendant-wizard-notice' );

	function api( path, method, body ) {
		return fetch( attendantAdmin.restUrl + path, {
			method: method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': attendantAdmin.nonce },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) {
			return r.json().then( function ( j ) { return { ok: r.ok, json: j }; } );
		} );
	}

	function showNotice( msg, type ) {
		notice.className = 'notice notice-' + ( type || 'info' ) + ' inline';
		notice.textContent = msg;
		notice.style.display = 'block';
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = ( s === null || s === undefined ) ? '' : String( s );
		return d.innerHTML;
	}

	function go( step ) {
		state.step = step;
		panels.forEach( function ( p ) { p.classList.toggle( 'is-active', Number( p.dataset.panel ) === step ); } );
		steps.forEach( function ( s ) {
			s.classList.toggle( 'is-active', Number( s.dataset.step ) === step );
			s.classList.toggle( 'is-done', Number( s.dataset.step ) < step );
		} );
		notice.style.display = 'none';
		if ( step === 1 ) { detect(); }
		if ( step === 2 ) { renderTypes(); }
		if ( step === 3 ) { renderFields(); }
	}

	function detect() {
		var box  = document.getElementById( 'attendant-detect-result' );
		var next = document.getElementById( 'attendant-detect-next' );
		box.innerHTML = '<span class="spinner is-active" style="float:none;"></span> ' + esc( 'Scanning…' );
		api( '/onboarding/detect', 'POST', {} ).then( function ( res ) {
			if ( ! res.ok ) { showNotice( ( res.json && res.json.message ) || 'Scan failed.', 'error' ); return; }
			state.schema = res.json.schema;
			var pts  = ( state.schema && state.schema.post_types ) ? state.schema.post_types : {};
			var keys = Object.keys( pts );
			var html = '<p>' + esc( res.json.post_types ) + ' content types found:</p><ul class="attendant-detect-types">';
			keys.forEach( function ( k ) {
				var pt   = pts[ k ];
				var taxc = pt.taxonomies ? Object.keys( pt.taxonomies ).length : 0;
				var metc = pt.meta_fields ? Object.keys( pt.meta_fields ).length : 0;
				html += '<li><strong>' + esc( pt.label || k ) + '</strong> <code>' + esc( k ) + '</code> — ' + esc( pt.count || 0 ) + ' published, ' + taxc + ' taxonomies, ' + metc + ' fields</li>';
			} );
			html += '</ul>';
			box.innerHTML = html;
			next.disabled = false;
		} ).catch( function () { showNotice( 'Scan failed.', 'error' ); } );
	}

	function renderTypes() {
		var list = document.getElementById( 'attendant-types-list' );
		var pts  = ( state.schema && state.schema.post_types ) ? state.schema.post_types : {};
		var html = '';
		Object.keys( pts ).forEach( function ( k ) {
			var pt      = pts[ k ];
			var checked = ( pt.count || 0 ) > 0 ? ' checked' : '';
			html += '<p><label><input type="checkbox" class="attendant-type-cb" value="' + esc( k ) + '"' + checked + '> <strong>' + esc( pt.label || k ) + '</strong> <span class="description">(' + esc( pt.count || 0 ) + ')</span></label></p>';
		} );
		list.innerHTML = html || '<p class="description">No public content types found.</p>';
	}

	function chosenTypes() {
		return Array.prototype.map.call( document.querySelectorAll( '.attendant-type-cb:checked' ), function ( c ) { return c.value; } );
	}

	function renderFields() {
		var wrap = document.getElementById( 'attendant-fields-list' );
		var pts  = ( state.schema && state.schema.post_types ) ? state.schema.post_types : {};
		state.types = chosenTypes();
		var html = '';
		state.types.forEach( function ( k ) {
			var pt = pts[ k ];
			if ( ! pt ) { return; }
			html += '<h3>' + esc( pt.label || k ) + '</h3>';
			var taxes = pt.taxonomies || {};
			Object.keys( taxes ).forEach( function ( t ) {
				html += '<p><label><input type="checkbox" class="attendant-tax-cb" data-pt="' + esc( k ) + '" data-tax="' + esc( t ) + '" checked> ' + esc( taxes[ t ].label || t ) + ' <code>' + esc( t ) + '</code></label></p>';
			} );
			var metas = pt.meta_fields || {};
			Object.keys( metas ).forEach( function ( m ) {
				html += '<p><label><input type="checkbox" class="attendant-meta-cb" data-pt="' + esc( k ) + '" data-key="' + esc( m ) + '" checked> ' + esc( m ) + ' <span class="description">[' + esc( metas[ m ].type || 'text' ) + ']</span></label> <input type="text" class="attendant-meta-label" data-pt="' + esc( k ) + '" data-key="' + esc( m ) + '" placeholder="' + esc( metas[ m ].label || '' ) + '" style="width:200px;"></p>';
			} );
			if ( ! Object.keys( taxes ).length && ! Object.keys( metas ).length ) {
				html += '<p class="description">No taxonomies or custom fields detected.</p>';
			}
		} );
		wrap.innerHTML = html || '<p class="description">Choose at least one content type first.</p>';
	}

	function buildConfig() {
		var cfg = {};
		state.types.forEach( function ( k ) { cfg[ k ] = { taxonomies: {}, meta: {} }; } );
		document.querySelectorAll( '.attendant-tax-cb' ).forEach( function ( c ) {
			cfg[ c.dataset.pt ] = cfg[ c.dataset.pt ] || { taxonomies: {}, meta: {} };
			cfg[ c.dataset.pt ].taxonomies[ c.dataset.tax ] = c.checked;
		} );
		document.querySelectorAll( '.attendant-meta-cb' ).forEach( function ( c ) {
			cfg[ c.dataset.pt ] = cfg[ c.dataset.pt ] || { taxonomies: {}, meta: {} };
			var lbl = document.querySelector( '.attendant-meta-label[data-pt="' + c.dataset.pt + '"][data-key="' + c.dataset.key + '"]' );
			cfg[ c.dataset.pt ].meta[ c.dataset.key ] = { included: c.checked, label: lbl ? lbl.value : '' };
		} );
		return cfg;
	}

	function finish() {
		var btn    = document.getElementById( 'attendant-wiz-finish' );
		var status = document.getElementById( 'attendant-wiz-finish-status' );
		btn.disabled = true;
		status.textContent = ( attendantAdmin.i18n && attendantAdmin.i18n.saving ) || 'Saving…';

		var key = document.getElementById( 'attendant-wiz-key' ).value;
		var ctx = document.getElementById( 'attendant-wiz-context' );
		var settings = {
			semantic_mode:  document.getElementById( 'attendant-wiz-semantic' ).checked,
			widget_color:   document.getElementById( 'attendant-wiz-color' ).value,
			widget_enabled: document.getElementById( 'attendant-wiz-enable' ).checked
		};
		if ( key ) {
			settings[ 'api_key_' + wizProvider() ] = key;
			settings.active_provider = wizProvider();
		}
		if ( ctx && ctx.value.trim() ) { settings.site_context = ctx.value.trim(); }

		api( '/settings', 'POST', settings ).then( function () {
			return api( '/onboarding/complete', 'POST', { index_post_types: state.types, config: buildConfig() } );
		} ).then( function ( res ) {
			if ( res.ok && res.json.success ) {
				status.textContent = ( attendantAdmin.i18n && attendantAdmin.i18n.saved ) || 'Done.';
				window.location = attendantAdmin.settingsUrl || ( window.location.pathname + '?page=ai-chatmate' );
			} else {
				btn.disabled = false;
				showNotice( 'Could not finish setup.', 'error' );
			}
		} ).catch( function () {
			btn.disabled = false;
			showNotice( 'Could not finish setup.', 'error' );
		} );
	}

	// Selected wizard provider ('google' default, 'openai' optional).
	function wizProvider() {
		var checked = document.querySelector( 'input[name="attendant-wiz-provider"]:checked' );
		return checked ? checked.value : 'google';
	}

	// Swap the key label/placeholder/link when the provider radio changes.
	root.addEventListener( 'change', function ( e ) {
		if ( ! e.target.matches( 'input[name="attendant-wiz-provider"]' ) ) {
			return;
		}
		var google = wizProvider() === 'google';
		var label  = document.getElementById( 'attendant-wiz-key-label' );
		var input  = document.getElementById( 'attendant-wiz-key' );
		var link   = document.getElementById( 'attendant-wiz-key-link' );
		if ( label ) { label.textContent = google ? 'Google Gemini API Key' : 'OpenAI API Key'; }
		if ( input ) { input.placeholder = google ? 'AIza…' : 'sk-...'; }
		if ( link ) {
			link.href        = google ? 'https://aistudio.google.com/apikey' : 'https://platform.openai.com/api-keys';
			link.textContent = google ? 'aistudio.google.com/apikey' : 'platform.openai.com/api-keys';
		}
	} );

	function test() {
		var btn = document.getElementById( 'attendant-wiz-test' );
		var out = document.getElementById( 'attendant-wiz-test-result' );
		var key = document.getElementById( 'attendant-wiz-key' ).value;
		btn.disabled = true;
		out.textContent = ( attendantAdmin.i18n && attendantAdmin.i18n.testing ) || 'Testing…';
		out.className = 'attendant-test-result';
		api( '/test-connection', 'POST', { provider: wizProvider(), api_key: key } ).then( function ( res ) {
			var j = res.json || {};
			out.textContent = ( j.success ? '✓ ' : '✗ ' ) + ( j.message || '' );
			out.className = 'attendant-test-result ' + ( j.success ? 'attendant-test-ok' : 'attendant-test-fail' );
		} ).catch( function () {
			out.textContent = '✗';
			out.className = 'attendant-test-result attendant-test-fail';
		} ).finally( function () { btn.disabled = false; } );
	}

	root.addEventListener( 'click', function ( e ) {
		if ( e.target.matches( '[data-attendant-next]' ) ) {
			go( Math.min( state.step + 1, panels.length - 1 ) );
		} else if ( e.target.matches( '[data-attendant-prev]' ) ) {
			go( Math.max( state.step - 1, 0 ) );
		} else if ( e.target.id === 'attendant-wiz-finish' ) {
			finish();
		} else if ( e.target.id === 'attendant-wiz-test' ) {
			test();
		}
	} );
}() );
