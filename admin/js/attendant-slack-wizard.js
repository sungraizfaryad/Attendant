/**
 * Attendant — Slack setup wizard.
 *
 * Drives the step-by-step modal in Settings → Integrations. Each step is a
 * <section data-step="N">; this file owns navigation plus the REST calls that
 * save the credentials, create or pick the channel, and send the test message.
 *
 * Step order: 1 sign in · 2 create app · 3 install · 4 credentials ·
 * 5 channel choice · 6 create channel | 7 pick existing · 8 test + finish.
 */
( function () {
	'use strict';

	const overlay = document.getElementById( 'attendant-wiz' );
	if ( ! overlay || 'undefined' === typeof attendantAdmin ) {
		return;
	}

	const stepsEls = overlay.querySelectorAll( '.attendant-wiz__step' );
	const backBtn  = document.getElementById( 'attendant-wiz-back' );
	const nextBtn  = document.getElementById( 'attendant-wiz-next' );
	const statusEl = document.getElementById( 'attendant-wiz-status' );
	const countEl  = document.getElementById( 'attendant-wiz-count' );
	const barEl    = document.getElementById( 'attendant-wiz-bar' );

	let step = 1;
	let path = 'new'; // 'new' | 'existing'

	// Visible steps in order, for the counter/progress bar.
	function planned() {
		return [ 1, 2, 3, 4, 5, ( 'new' === path ? 6 : 7 ), 8 ];
	}

	function say( text, ok ) {
		statusEl.textContent = '' === text ? '' : ( ok ? '✓ ' : '✗ ' ) + text;
		statusEl.style.color = ok ? '#00a32a' : '#d63638';
	}

	function headers( json ) {
		const h = { 'X-WP-Nonce': attendantAdmin.nonce };
		if ( json ) {
			h['Content-Type'] = 'application/json';
		}
		return h;
	}

	function show( n ) {
		step = n;
		stepsEls.forEach( function ( el ) {
			el.hidden = ( parseInt( el.dataset.step, 10 ) !== n );
		} );

		const order = planned();
		const idx   = order.indexOf( n );
		countEl.textContent = 'Step ' + ( idx + 1 ) + ' of ' + order.length;
		barEl.style.width   = ( ( idx + 1 ) / order.length * 100 ) + '%';

		backBtn.style.visibility = ( 1 === n ) ? 'hidden' : 'visible';
		// Step 5 is answered by the two choice buttons, not Next.
		nextBtn.hidden = ( 5 === n );
		nextBtn.textContent = ( 8 === n ) ? 'Finish' : 'Next';
		say( '', true );
	}

	// ── Actions ──────────────────────────────────────────────────────────
	function saveCredentials() {
		const secret = document.getElementById( 'attendant-wiz-secret' ).value.trim();
		const token  = document.getElementById( 'attendant-wiz-token' ).value.trim();

		if ( '' === secret && '' === token ) {
			say( 'Paste both values from Slack to continue.', false );
			return;
		}

		nextBtn.disabled = true;
		say( 'Saving…', true );

		fetch( attendantAdmin.restUrl + '/settings', {
			method:  'POST',
			headers: headers( true ),
			body: JSON.stringify( {
				slack_enabled:        true,
				slack_signing_secret: secret,
				slack_bot_token:      token,
			} ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function () { show( 5 ); } )
		.catch( function () { say( 'Could not save. Please try again.', false ); } )
		.finally( function () { nextBtn.disabled = false; } );
	}

	function createChannel() {
		const name    = document.getElementById( 'attendant-wiz-new-name' ).value.trim();
		const priv    = document.getElementById( 'attendant-wiz-new-private' ).checked;
		const emailsV = document.getElementById( 'attendant-wiz-new-emails' ).value;

		if ( '' === name ) {
			say( 'Type a channel name first.', false );
			return;
		}

		const emails = emailsV.split( /[\n,]/ ).map( function ( e ) { return e.trim(); } ).filter( Boolean );

		nextBtn.disabled = true;
		say( 'Creating the channel…', true );

		fetch( attendantAdmin.restUrl + '/slack/create-channel', {
			method:  'POST',
			headers: headers( true ),
			body: JSON.stringify( { name: name, private: priv, emails: emails } ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			if ( ! json || ! json.ok ) {
				say( ( json && json.friendly && json.friendly.message ) || 'Could not create the channel.', false );
				return;
			}
			let msg = 'Created #' + json.name + '.';
			if ( json.invited && json.invited.length ) {
				msg += ' Invited ' + json.invited.length + '.';
			}
			if ( json.failed && json.failed.length ) {
				msg += ' Not found: ' + json.failed.join( ', ' ) + '.';
			}
			document.getElementById( 'attendant-wiz-summary' ).textContent =
				'Chats will arrive in #' + json.name + '. ' + msg;
			show( 8 );
		} )
		.catch( function () { say( 'Could not create the channel.', false ); } )
		.finally( function () { nextBtn.disabled = false; } );
	}

	function loadExisting() {
		const box = document.getElementById( 'attendant-wiz-existing' );
		box.textContent = 'Loading…';

		fetch( attendantAdmin.restUrl + '/slack/channels', { headers: headers( false ) } )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			const list = ( json && json.channels ) || [];
			box.textContent = '';

			if ( ! list.length ) {
				box.textContent = ( json && json.friendly && json.friendly.message )
					|| 'No channels yet. In Slack, type /invite @Attendant Chat in your channel, then Reload.';
				return;
			}

			[ true, false ].forEach( function ( isPriv ) {
				const group = list.filter( function ( c ) { return !! c.private === isPriv; } );
				if ( ! group.length ) {
					return;
				}
				const h = document.createElement( 'div' );
				h.className = 'attendant-wiz__group';
				h.textContent = isPriv ? 'Private channels' : 'Public channels';
				box.appendChild( h );

				group.forEach( function ( c ) {
					const label = document.createElement( 'label' );
					label.className = 'attendant-wiz__chan';
					const radio = document.createElement( 'input' );
					radio.type = 'radio';
					radio.name = 'attendant-wiz-chan';
					radio.value = c.id;
					label.appendChild( radio );
					label.appendChild( document.createTextNode( ' #' + c.name ) );
					box.appendChild( label );
				} );
			} );
		} )
		.catch( function () { box.textContent = 'Could not load channels.'; } );
	}

	function saveExisting() {
		const picked = overlay.querySelector( 'input[name="attendant-wiz-chan"]:checked' );
		if ( ! picked ) {
			say( 'Choose a channel to continue.', false );
			return;
		}

		nextBtn.disabled = true;
		say( 'Saving…', true );

		fetch( attendantAdmin.restUrl + '/settings', {
			method:  'POST',
			headers: headers( true ),
			body: JSON.stringify( { slack_enabled: true, slack_channel: picked.value } ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function () {
			const name = picked.parentNode.textContent.trim();
			document.getElementById( 'attendant-wiz-summary' ).textContent =
				'Chats will arrive in ' + name + '.';
			show( 8 );
		} )
		.catch( function () { say( 'Could not save. Please try again.', false ); } )
		.finally( function () { nextBtn.disabled = false; } );
	}

	function sendTest() {
		const btn = document.getElementById( 'attendant-wiz-test' );
		btn.disabled = true;
		say( 'Sending…', true );

		fetch( attendantAdmin.restUrl + '/slack/test', { method: 'POST', headers: headers( false ) } )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			if ( json && json.ok ) {
				say( 'Sent — check your Slack channel. You can click Finish.', true );
			} else {
				say( ( json && json.friendly && json.friendly.message ) || 'Could not send the test message.', false );
			}
		} )
		.catch( function () { say( 'Could not send the test message.', false ); } )
		.finally( function () { btn.disabled = false; } );
	}

	// ── Navigation ───────────────────────────────────────────────────────
	nextBtn.addEventListener( 'click', function () {
		if ( 4 === step ) { saveCredentials(); return; }
		if ( 6 === step ) { createChannel();   return; }
		if ( 7 === step ) { saveExisting();    return; }
		if ( 8 === step ) { window.location.reload(); return; }
		show( step + 1 );
	} );

	backBtn.addEventListener( 'click', function () {
		if ( 8 === step ) { show( 'new' === path ? 6 : 7 ); return; }
		if ( 6 === step || 7 === step ) { show( 5 ); return; }
		show( Math.max( 1, step - 1 ) );
	} );

	document.getElementById( 'attendant-wiz-pick-new' ).addEventListener( 'click', function () {
		path = 'new';
		show( 6 );
	} );

	document.getElementById( 'attendant-wiz-pick-existing' ).addEventListener( 'click', function () {
		path = 'existing';
		show( 7 );
		loadExisting();
	} );

	document.getElementById( 'attendant-wiz-reload' ).addEventListener( 'click', loadExisting );
	document.getElementById( 'attendant-wiz-test' ).addEventListener( 'click', sendTest );

	function close() {
		overlay.hidden = true;
	}
	document.getElementById( 'attendant-wiz-close' ).addEventListener( 'click', close );
	overlay.addEventListener( 'click', function ( e ) {
		if ( e.target === overlay ) {
			close();
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! overlay.hidden ) {
			close();
		}
	} );

	// Opened from the Integrations tab.
	document.querySelectorAll( '.attendant-open-slack-wizard' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			overlay.hidden = false;
			path = 'new';
			show( 1 );
		} );
	} );
}() );
