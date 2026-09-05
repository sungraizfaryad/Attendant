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

	// ── People picker ────────────────────────────────────────────────────
	let peopleLoaded = false;

	function peopleBox() {
		return document.getElementById( 'attendant-wiz-people' );
	}

	function usingEmailFallback() {
		const fb = document.getElementById( 'attendant-wiz-people-fallback' );
		return !! fb && ! fb.hidden;
	}

	function showEmailFallback( reason ) {
		const box = peopleBox();
		const fb  = document.getElementById( 'attendant-wiz-people-fallback' );
		if ( box ) {
			box.hidden = true;
		}
		const filter = document.getElementById( 'attendant-wiz-people-filter' );
		if ( filter ) {
			filter.parentNode.hidden = true;
		}
		if ( fb ) {
			fb.hidden = false;
		}
		if ( reason ) {
			say( reason, false );
		}
	}

	function renderPeople( members ) {
		const box = peopleBox();
		box.textContent = '';

		members.forEach( function ( m ) {
			const label = document.createElement( 'label' );
			label.className = 'attendant-wiz__person';
			label.dataset.search = ( m.name + ' ' + m.handle + ' ' + m.email ).toLowerCase();

			const cb = document.createElement( 'input' );
			cb.type = 'checkbox';
			cb.value = m.id;
			cb.checked = !! m.you;
			label.appendChild( cb );

			const name = document.createElement( 'strong' );
			name.textContent = m.name || m.handle;
			label.appendChild( name );

			if ( m.handle ) {
				const handle = document.createElement( 'span' );
				handle.textContent = '@' + m.handle;
				label.appendChild( handle );
			}
			if ( m.you ) {
				const tag = document.createElement( 'mark' );
				tag.textContent = 'you';
				label.appendChild( tag );
			}

			box.appendChild( label );
		} );
	}

	function loadPeople() {
		if ( peopleLoaded ) {
			return;
		}
		peopleLoaded = true;

		const box = peopleBox();
		box.textContent = 'Loading your workspace…';

		fetch( attendantAdmin.restUrl + '/slack/members', { headers: headers( false ) } )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			const members = ( json && json.members ) || [];
			if ( ! members.length ) {
				peopleLoaded = false;
				showEmailFallback(
					( json && json.friendly && json.friendly.message ) ||
					'Could not read your workspace members — type email addresses instead.'
				);
				return;
			}

			renderPeople( members );

			// No email matched this WordPress account, so the tick is a guess.
			if ( json && ! json.matched ) {
				say( 'We ticked the workspace owner — untick and pick yourself if that is not you.', true );
			}
		} )
		.catch( function () {
			peopleLoaded = false;
			showEmailFallback( 'Could not reach Slack — type email addresses instead.' );
		} );
	}

	function pickedPeople() {
		return Array.prototype.slice
			.call( peopleBox().querySelectorAll( 'input[type="checkbox"]:checked' ) )
			.map( function ( cb ) { return cb.value; } );
	}

	const peopleFilter = document.getElementById( 'attendant-wiz-people-filter' );
	if ( peopleFilter ) {
		peopleFilter.addEventListener( 'input', function () {
			const q = peopleFilter.value.trim().toLowerCase();
			peopleBox().querySelectorAll( '.attendant-wiz__person' ).forEach( function ( row ) {
				row.hidden = ( '' !== q && -1 === row.dataset.search.indexOf( q ) );
			} );
		} );
	}

	function createChannel() {
		const name    = document.getElementById( 'attendant-wiz-new-name' ).value.trim();
		const priv    = document.getElementById( 'attendant-wiz-new-private' ).checked;
		const emailsV = document.getElementById( 'attendant-wiz-new-emails' ).value;

		if ( '' === name ) {
			say( 'Type a channel name first.', false );
			return;
		}

		// One source of truth: the picker, or the email box that replaces it.
		const fallback = usingEmailFallback();
		const emails   = fallback
			? emailsV.split( /[\n,]/ ).map( function ( e ) { return e.trim(); } ).filter( Boolean )
			: [];
		const users    = fallback ? [] : pickedPeople();

		if ( ! users.length && ! emails.length ) {
			say( 'Tick at least yourself — the app creates the channel, so nobody else is in it until they are added.', false );
			return;
		}

		nextBtn.disabled = true;
		say( 'Creating the channel…', true );

		fetch( attendantAdmin.restUrl + '/slack/create-channel', {
			method:  'POST',
			headers: headers( true ),
			body: JSON.stringify( { name: name, private: priv, emails: emails, users: users } ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			if ( ! json || ! json.ok ) {
				say( ( json && json.friendly && json.friendly.message ) || 'Could not create the channel.', false );
				return;
			}
			let summary = 'Chats will arrive in #' + json.name + '.';
			if ( json.invited && json.invited.length ) {
				summary += ' Added ' + json.invited.length + ( 1 === json.invited.length ? ' person.' : ' people.' );
			}
			document.getElementById( 'attendant-wiz-summary' ).textContent = summary;
			show( 8 );

			// show() clears the status line, so any warning has to go on after.
			const problems = [];
			if ( json.failed && json.failed.length ) {
				problems.push( 'Slack would not add ' + json.failed.length + ' of the people you picked.' );
			}
			if ( json.alone ) {
				problems.push(
					'You are not in #' + json.name + ' yet, so the test message will land somewhere you cannot see it. ' +
					( json.private
						? 'A private channel never shows up in Slack search, so go Back and create a public one instead, or ask a Slack workspace admin to add you.'
						: 'Open Slack, search for #' + json.name + ', and join it.' )
				);
			}
			if ( problems.length ) {
				say( problems.join( ' ' ), false );
			}
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
					|| 'No channels yet. In Slack, type /invite @' + ( attendantAdmin.slackApp || 'Attendant Chat' ) + ' in your channel, then Reload.';
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

		const name = picked.parentNode.textContent.trim();

		fetch( attendantAdmin.restUrl + '/settings', {
			method:  'POST',
			headers: headers( true ),
			body: JSON.stringify( {
				slack_enabled:      true,
				slack_channel:      picked.value,
				slack_channel_name: name.replace( /^#/, '' ),
			} ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function () {
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
		loadPeople();
	} );

	document.getElementById( 'attendant-wiz-pick-existing' ).addEventListener( 'click', function () {
		path = 'existing';
		show( 7 );
		loadExisting();
	} );

	document.getElementById( 'attendant-wiz-reload' ).addEventListener( 'click', loadExisting );
	document.getElementById( 'attendant-wiz-test' ).addEventListener( 'click', sendTest );

	// ── Screenshot viewer ────────────────────────────────────────────────
	const shotView    = document.getElementById( 'attendant-wiz-shotview' );
	const shotScroll  = document.getElementById( 'attendant-wiz-shotview-scroll' );
	const shotImg     = document.getElementById( 'attendant-wiz-shotview-img' );
	const shotCaption = document.getElementById( 'attendant-wiz-shotview-caption' );
	let   shotOpener  = null;

	function openShot( btn ) {
		shotOpener              = btn;
		shotImg.src             = btn.dataset.shot;
		shotImg.alt             = btn.dataset.alt || '';
		shotCaption.textContent = btn.dataset.caption || '';
		shotView.hidden         = false;

		// A previous shot may have been left scrolled halfway down.
		shotScroll.scrollTop = 0;
		// Focus the scroller, not the close button, so arrow keys and space
		// page through a tall screenshot straight away.
		shotScroll.focus();
	}

	function closeShot() {
		shotView.hidden = true;
		shotImg.src     = '';
		if ( shotOpener ) {
			shotOpener.focus();
			shotOpener = null;
		}
	}

	overlay.addEventListener( 'click', function ( e ) {
		const btn = e.target.closest( '.attendant-wiz__shotlink' );
		if ( btn ) {
			openShot( btn );
		}
	} );

	document.getElementById( 'attendant-wiz-shotview-close' ).addEventListener( 'click', closeShot );
	shotView.addEventListener( 'click', function ( e ) {
		if ( e.target === shotView || e.target === shotScroll ) {
			closeShot();
		}
	} );

	function close() {
		closeShot();
		overlay.hidden = true;
	}
	document.getElementById( 'attendant-wiz-close' ).addEventListener( 'click', close );
	overlay.addEventListener( 'click', function ( e ) {
		if ( e.target === overlay ) {
			close();
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) {
			return;
		}
		// The viewer sits on top, so it gets the first Escape — otherwise one
		// key press closes the whole wizard from behind an open screenshot.
		if ( ! shotView.hidden ) {
			closeShot();
			return;
		}
		if ( ! overlay.hidden ) {
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
