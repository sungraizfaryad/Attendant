<?php
/**
 * Slack live-agent handoff.
 *
 * Each website conversation that asks for a human becomes ONE THREAD in the
 * site owner's (ideally private) Slack channel. The parent message carries
 * the transcript so far; the visitor's further messages append to the thread;
 * anything a teammate types in that thread is queued for the visitor's chat
 * widget, which polls for it while the handoff is active.
 *
 * Mapping lives in transients, both directions, 24 hours:
 *   session → thread_ts   (does this conversation already have a thread?)
 *   thread_ts → session   (which visitor does this Slack reply belong to?)
 * The queue of not-yet-delivered agent messages is also a transient keyed by
 * session. Nothing is stored permanently and no new tables are created.
 *
 * Security: the events endpoint only accepts requests signed by Slack
 * (X-Slack-Signature HMAC over "v0:{timestamp}:{body}" with the signing
 * secret), rejects stale timestamps, skips Slack's automatic retries, and
 * de-duplicates by event_id. The one unauthenticated response is the
 * url_verification challenge BEFORE a signing secret is saved — it only
 * echoes Slack's own challenge string, which is required so the app can be
 * created from our pre-filled manifest before setup finishes.
 *
 * @package Attendant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ATTENDANT_Slack
 */
final class ATTENDANT_Slack {

	/** How long a session↔thread mapping (and so a handoff) stays alive. */
	private const THREAD_TTL = DAY_IN_SECONDS;

	/** Queued agent messages wait at most this long for the widget to poll. */
	private const QUEUE_TTL = DAY_IN_SECONDS;

	/** Slack Web API base. */
	private const API = 'https://slack.com/api/';

	/** Option holding a summary of the last inbound event (debug panel). */
	private const DEBUG_OPTION = 'attendant_slack_debug';

	// -------------------------------------------------------------------------
	// Inbound-event debug trail
	// -------------------------------------------------------------------------

	/**
	 * Record what the last inbound Slack request looked like and how it was
	 * handled. Surfaced on the settings page so a site owner can see whether
	 * Slack is reaching the site and why a reply was or was not forwarded —
	 * the fastest way to diagnose the Slack→site direction on a live server.
	 *
	 * @param string $outcome Short status, e.g. 'forwarded', 'bad_signature'.
	 * @param array  $extra   Extra fields to show (channel, subtype, …).
	 */
	public static function log_debug( string $outcome, array $extra = array() ): void {
		update_option(
			self::DEBUG_OPTION,
			array_merge(
				array(
					'time'    => current_time( 'mysql' ),
					'outcome' => $outcome,
				),
				$extra
			),
			false
		);
	}

	/**
	 * The last recorded inbound-event summary ('' fields when never hit).
	 *
	 * @return array
	 */
	public static function get_debug(): array {
		return (array) get_option( self::DEBUG_OPTION, array() );
	}

	// -------------------------------------------------------------------------
	// Configuration
	// -------------------------------------------------------------------------

	/**
	 * Feature toggle + all three credentials present.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return (bool) Attendant_Plugin::get_setting( 'slack_enabled', false )
			&& '' !== self::bot_token()
			&& '' !== self::signing_secret()
			&& '' !== self::channel();
	}

	/**
	 * Decrypted bot token (xoxb-…), '' when unset.
	 *
	 * @return string
	 */
	public static function bot_token(): string {
		$stored = (string) get_option( 'attendant_slack_bot_token', '' );
		return '' === $stored ? '' : ATTENDANT_Encryption::decrypt( $stored );
	}

	/**
	 * Decrypted signing secret, '' when unset.
	 *
	 * @return string
	 */
	public static function signing_secret(): string {
		$stored = (string) get_option( 'attendant_slack_signing_secret', '' );
		return '' === $stored ? '' : ATTENDANT_Encryption::decrypt( $stored );
	}

	/**
	 * Configured channel id (C…/G…).
	 *
	 * @return string
	 */
	public static function channel(): string {
		return (string) Attendant_Plugin::get_setting( 'slack_channel', '' );
	}

	// -------------------------------------------------------------------------
	// Request signature (Slack → site)
	// -------------------------------------------------------------------------

	/**
	 * Verify Slack's request signature.
	 *
	 * @param string $timestamp X-Slack-Request-Timestamp header.
	 * @param string $signature X-Slack-Signature header.
	 * @param string $raw_body  Raw request body.
	 * @param int    $now       Current unix time (injectable for tests).
	 * @return bool
	 */
	public static function verify_signature( string $timestamp, string $signature, string $raw_body, int $now = 0 ): bool {
		$secret = self::signing_secret();
		if ( '' === $secret || '' === $timestamp || '' === $signature ) {
			return false;
		}

		$now = $now > 0 ? $now : time();
		if ( abs( $now - (int) $timestamp ) > 5 * MINUTE_IN_SECONDS ) {
			return false;
		}

		$expected = 'v0=' . hash_hmac( 'sha256', 'v0:' . $timestamp . ':' . $raw_body, $secret );

		return hash_equals( $expected, $signature );
	}

	// -------------------------------------------------------------------------
	// Session ↔ thread mapping
	// -------------------------------------------------------------------------

	/**
	 * Thread ts for a session, '' when no handoff is active.
	 *
	 * @param string $session_id Chat session id.
	 * @return string
	 */
	public static function thread_for_session( string $session_id ): string {
		return (string) get_transient( 'attendant_slack_thread_' . md5( $session_id ) );
	}

	/**
	 * Session id for a thread ts, '' when unknown/expired.
	 *
	 * @param string $thread_ts Slack thread timestamp.
	 * @return string
	 */
	public static function session_for_thread( string $thread_ts ): string {
		return (string) get_transient( 'attendant_slack_session_' . str_replace( '.', '_', $thread_ts ) );
	}

	/**
	 * Store the two-way mapping.
	 *
	 * @param string $session_id Chat session id.
	 * @param string $thread_ts  Slack thread timestamp.
	 */
	private static function map( string $session_id, string $thread_ts ): void {
		set_transient( 'attendant_slack_thread_' . md5( $session_id ), $thread_ts, self::THREAD_TTL );
		set_transient( 'attendant_slack_session_' . str_replace( '.', '_', $thread_ts ), $session_id, self::THREAD_TTL );
	}

	/**
	 * End a handoff (visitor went back to the AI, or timeout logic).
	 *
	 * @param string $session_id Chat session id.
	 */
	public static function end_handoff( string $session_id ): void {
		$ts = self::thread_for_session( $session_id );
		delete_transient( 'attendant_slack_thread_' . md5( $session_id ) );
		delete_transient( 'attendant_slack_auth_' . md5( $session_id ) );
		delete_transient( 'attendant_agent_q_' . md5( $session_id ) );
		if ( '' !== $ts ) {
			delete_transient( 'attendant_slack_session_' . str_replace( '.', '_', $ts ) );
		}
	}

	// -------------------------------------------------------------------------
	// Per-session capability secret
	// -------------------------------------------------------------------------

	/**
	 * Mint a high-entropy secret that ties a live handoff to the ONE browser
	 * that started it, and return the plaintext (stored only as a hash).
	 *
	 * The visitor's chat session id travels in URLs and localStorage and is
	 * therefore not a credential — this secret is. It is sent back only in
	 * the handoff response body, kept in that browser, and presented as a
	 * header on every poll / live message. Without it, knowing a session id
	 * grants nothing: no reading another visitor's agent replies, no posting
	 * into their Slack thread.
	 *
	 * @param string $session_id Chat session id.
	 * @return string Plaintext secret to hand to the browser.
	 */
	public static function issue_session_secret( string $session_id ): string {
		$secret = wp_generate_password( 48, false );
		set_transient( 'attendant_slack_auth_' . md5( $session_id ), wp_hash( $secret ), self::THREAD_TTL );

		return $secret;
	}

	/**
	 * Constant-time check that a presented secret owns this session.
	 *
	 * @param string $session_id Chat session id.
	 * @param string $secret     Secret presented by the caller.
	 * @return bool
	 */
	public static function verify_session_secret( string $session_id, string $secret ): bool {
		if ( '' === $secret ) {
			return false;
		}

		$stored = (string) get_transient( 'attendant_slack_auth_' . md5( $session_id ) );

		return '' !== $stored && hash_equals( $stored, wp_hash( $secret ) );
	}

	// -------------------------------------------------------------------------
	// Site → Slack
	// -------------------------------------------------------------------------

	/**
	 * Start a handoff: post the transcript as a new channel message and map
	 * the resulting thread to the session.
	 *
	 * @param string $session_id Chat session id.
	 * @param array  $transcript Recent messages: array of ['role','text'].
	 * @param string $summary    Optional AI-written brief for the agent.
	 * @return string The capability secret to hand to the browser, or '' on
	 *                failure. A caller with an already-live session gets a
	 *                freshly re-issued secret so a reload can rebind.
	 */
	public static function start_handoff( string $session_id, array $transcript, string $summary = '' ): string {
		if ( ! self::is_configured() ) {
			return '';
		}

		// Already live — re-issue the secret (the browser may have lost it).
		if ( '' !== self::thread_for_session( $session_id ) ) {
			return self::issue_session_secret( $session_id );
		}

		$lines   = array();
		$lines[] = '*A website visitor asked to talk to a human.*';
		$lines[] = '_Reply in this thread — the visitor sees your messages in the chat widget._';
		$lines[] = '';

		// Lead with the AI's brief so the agent knows the situation at a glance,
		// then the raw exchange underneath for anyone who wants the detail.
		if ( '' !== $summary ) {
			$lines[] = '*What they need (AI summary):*';
			$lines[] = self::clean_for_slack( $summary );
			$lines[] = '';
			$lines[] = '*Full conversation so far:*';
		}

		$recent = array_slice( $transcript, -10 );
		foreach ( $recent as $m ) {
			$who     = 'user' === ( $m['role'] ?? '' ) ? 'Visitor' : 'Assistant';
			$lines[] = '*' . $who . ':* ' . self::clean_for_slack( (string) ( $m['text'] ?? '' ) );
		}

		$ts = self::post_message( implode( "\n", $lines ) );
		if ( '' === $ts ) {
			return '';
		}

		self::map( $session_id, $ts );

		return self::issue_session_secret( $session_id );
	}

	/**
	 * Forward a visitor message into the session's thread.
	 *
	 * @param string $session_id Chat session id.
	 * @param string $text       Visitor message.
	 * @return bool
	 */
	public static function forward_visitor_message( string $session_id, string $text ): bool {
		$ts = self::thread_for_session( $session_id );
		if ( '' === $ts ) {
			return false;
		}

		return '' !== self::post_message( '> ' . self::clean_for_slack( $text ), $ts );
	}

	/**
	 * chat.postMessage. Returns the message ts, '' on failure. The Slack
	 * error from a failed post is kept in last_post_error() so the test
	 * button can explain WHY (bad channel, app not invited, …).
	 *
	 * @param string $text      Message text (mrkdwn).
	 * @param string $thread_ts Optional thread to reply into.
	 * @return string
	 */
	public static function post_message( string $text, string $thread_ts = '' ): string {
		$body = array(
			'channel' => self::channel(),
			'text'    => $text,
		);
		if ( '' !== $thread_ts ) {
			$body['thread_ts'] = $thread_ts;
		}

		$response = self::api_call( 'chat.postMessage', $body );

		if ( empty( $response['ok'] ) ) {
			self::$last_post_error = (string) ( $response['error'] ?? 'unreachable' );
			return '';
		}

		self::$last_post_error = '';

		return (string) ( $response['ts'] ?? '' );
	}

	/** Slack error from the most recent failed post_message(), '' otherwise. */
	private static string $last_post_error = '';

	/**
	 * The Slack error from the last failed post_message().
	 *
	 * @return string
	 */
	public static function last_post_error(): string {
		return self::$last_post_error;
	}

	// -------------------------------------------------------------------------
	// Slack → site (queue drained by the widget's poll)
	// -------------------------------------------------------------------------

	/**
	 * Queue an agent reply for the visitor's widget.
	 *
	 * The append and the drain both run under a per-session MySQL advisory
	 * lock: two agent replies landing within the same poll interval, or a
	 * reply landing exactly as the widget drains, would otherwise race on the
	 * read-modify-write and lose a message.
	 *
	 * @param string $session_id Chat session id.
	 * @param string $text       Agent message (already cleaned).
	 */
	public static function queue_agent_message( string $session_id, string $text ): void {
		$key = 'attendant_agent_q_' . md5( $session_id );

		self::with_session_lock(
			$session_id,
			static function () use ( $key, $text ) {
				$queue   = get_transient( $key );
				$queue   = is_array( $queue ) ? $queue : array();
				$queue[] = array(
					'text' => $text,
					't'    => time(),
				);

				// A visitor that never polls again must not grow this unbounded.
				if ( count( $queue ) > 50 ) {
					$queue = array_slice( $queue, -50 );
				}

				set_transient( $key, $queue, self::QUEUE_TTL );
			}
		);
	}

	/**
	 * Return queued agent messages for a session and clear the queue.
	 *
	 * @param string $session_id Chat session id.
	 * @return array[] Each: ['text' => string, 't' => int].
	 */
	public static function drain_agent_messages( string $session_id ): array {
		$key = 'attendant_agent_q_' . md5( $session_id );

		return self::with_session_lock(
			$session_id,
			static function () use ( $key ) {
				$queue = get_transient( $key );
				if ( ! is_array( $queue ) || array() === $queue ) {
					return array();
				}
				delete_transient( $key );

				return $queue;
			}
		);
	}

	/**
	 * Run a callback holding a per-session MySQL advisory lock. Falls back to
	 * running unlocked if the lock cannot be acquired (never blocks delivery).
	 *
	 * @param string   $session_id Chat session id.
	 * @param callable $fn         Work to run under the lock.
	 * @return mixed The callback's return value.
	 */
	private static function with_session_lock( string $session_id, callable $fn ) {
		global $wpdb;

		$name   = substr( 'att_q_' . md5( $session_id ), 0, 60 );
		$locked = false;

		if ( isset( $wpdb ) && is_object( $wpdb )
			&& method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
			try {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$locked = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 3 ) );
			} catch ( \Throwable $e ) {
				$locked = false;
			}
		}

		try {
			return $fn();
		} finally {
			if ( $locked ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Admin helpers (settings screen)
	// -------------------------------------------------------------------------

	/**
	 * Last error from list_channels(), '' on success.
	 *
	 * @var string
	 */
	private static string $last_list_error = '';

	/**
	 * The Slack error from the most recent list_channels() call, '' on success.
	 * 'missing_scope' means the app needs private-channel permission and must
	 * be reinstalled.
	 *
	 * @return string
	 */
	public static function last_list_error(): string {
		return self::$last_list_error;
	}

	/**
	 * Channels the bot is a MEMBER of — both public and private — for the
	 * settings dropdown.
	 *
	 * Uses users.conversations rather than conversations.list: it returns the
	 * exact set the bot can post to (a private channel only appears once the
	 * app is /invite-d in), so the picker never lists a channel the handoff
	 * would then fail to post into, and it surfaces private channels reliably.
	 *
	 * @return array[] Each: ['id' => string, 'name' => string, 'private' => bool].
	 */
	public static function list_channels(): array {
		self::$last_list_error = '';
		$out                   = array();
		$cursor                = '';

		// Cursor-paginated; cap pages so a huge workspace can't spin forever.
		for ( $page = 0; $page < 10; $page++ ) {
			$args = array(
				'types'            => 'public_channel,private_channel',
				'exclude_archived' => true,
				'limit'            => 200,
			);
			if ( '' !== $cursor ) {
				$args['cursor'] = $cursor;
			}

			$response = self::api_call( 'users.conversations', $args );
			if ( empty( $response['ok'] ) ) {
				self::$last_list_error = (string) ( $response['error'] ?? 'unreachable' );
				break;
			}

			foreach ( (array) ( $response['channels'] ?? array() ) as $ch ) {
				$out[] = array(
					'id'      => (string) ( $ch['id'] ?? '' ),
					'name'    => (string) ( $ch['name'] ?? '' ),
					'private' => ! empty( $ch['is_private'] ) || ! empty( $ch['is_group'] ),
				);
			}

			$cursor = (string) ( $response['response_metadata']['next_cursor'] ?? '' );
			if ( '' === $cursor ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Last error from list_members(), '' on success.
	 *
	 * @var string
	 */
	private static string $last_members_error = '';

	/**
	 * The Slack error from the most recent list_members() call, '' on success.
	 *
	 * @return string
	 */
	public static function last_members_error(): string {
		return self::$last_members_error;
	}

	/**
	 * The people in the workspace, so setup can offer a pick-list instead of
	 * asking anyone to type an email address.
	 *
	 * Bots, deactivated accounts and Slackbot are filtered out. `owner` marks
	 * the workspace's primary owner — the safest guess at who is standing in
	 * front of the screen when nothing else identifies them.
	 *
	 * @return array[] Each: ['id','name','handle','email','owner'].
	 */
	public static function list_members(): array {
		self::$last_members_error = '';
		$out                      = array();
		$cursor                   = '';

		for ( $page = 0; $page < 10; $page++ ) {
			$args = array( 'limit' => 200 );
			if ( '' !== $cursor ) {
				$args['cursor'] = $cursor;
			}

			$response = self::api_call( 'users.list', $args );
			if ( empty( $response['ok'] ) ) {
				self::$last_members_error = (string) ( $response['error'] ?? 'unreachable' );
				break;
			}

			foreach ( (array) ( $response['members'] ?? array() ) as $user ) {
				if ( ! empty( $user['is_bot'] ) || ! empty( $user['deleted'] ) || 'USLACKBOT' === ( $user['id'] ?? '' ) ) {
					continue;
				}

				$profile = (array) ( $user['profile'] ?? array() );
				$name    = (string) ( $profile['real_name'] ?? '' );
				if ( '' === $name ) {
					$name = (string) ( $user['name'] ?? '' );
				}

				$out[] = array(
					'id'     => (string) ( $user['id'] ?? '' ),
					'name'   => $name,
					'handle' => (string) ( $user['name'] ?? '' ),
					'email'  => (string) ( $profile['email'] ?? '' ),
					'owner'  => ! empty( $user['is_primary_owner'] ),
				);
			}

			$cursor = (string) ( $response['response_metadata']['next_cursor'] ?? '' );
			if ( '' === $cursor ) {
				break;
			}
		}

		usort(
			$out,
			static fn( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] )
		);

		return $out;
	}

	/**
	 * Best guess at the Slack account belonging to whoever is using wp-admin:
	 * an email that matches, else the workspace's primary owner. A bot token
	 * carries no identity, so this is as close as Slack lets us get.
	 *
	 * @param string $email The WordPress user's email.
	 * @return string Slack user id, '' when the member list is unreadable.
	 */
	public static function guess_owner_id( string $email ): string {
		$email   = strtolower( trim( $email ) );
		$members = self::list_members();
		$owner   = '';

		foreach ( $members as $member ) {
			if ( '' !== $email && strtolower( $member['email'] ) === $email ) {
				return $member['id'];
			}
			if ( '' === $owner && ! empty( $member['owner'] ) ) {
				$owner = $member['id'];
			}
		}

		return $owner;
	}

	/**
	 * Invite people to a channel by Slack user id.
	 *
	 * One call carries every id; Slack reports partial failures in `errors`,
	 * so a single bad id does not cost everyone else their invite.
	 *
	 * @param string   $channel_id Channel id.
	 * @param string[] $user_ids   Slack user ids.
	 * @return array{invited: string[], failed: string[]}
	 */
	public static function invite_by_ids( string $channel_id, array $user_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'strval', $user_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array(
				'invited' => array(),
				'failed'  => array(),
			);
		}

		$response = self::api_call(
			'conversations.invite',
			array(
				'channel' => $channel_id,
				'users'   => implode( ',', $ids ),
			)
		);

		if ( ! empty( $response['ok'] ) ) {
			return array(
				'invited' => $ids,
				'failed'  => array(),
			);
		}

		// Already-a-member is not a failure, and it is the whole-call error
		// when every id was already in the channel.
		if ( 'already_in_channel' === ( $response['error'] ?? '' ) ) {
			return array(
				'invited' => $ids,
				'failed'  => array(),
			);
		}

		$failed = array();
		foreach ( (array) ( $response['errors'] ?? array() ) as $problem ) {
			$user = (string) ( $problem['user'] ?? '' );
			if ( '' !== $user && 'already_in_channel' !== ( $problem['error'] ?? '' ) ) {
				$failed[] = $user;
			}
		}

		// No per-user detail means the whole call failed.
		if ( empty( $failed ) ) {
			$failed = $ids;
		}

		return array(
			'invited' => array_values( array_diff( $ids, $failed ) ),
			'failed'  => $failed,
		);
	}

	/**
	 * Create a channel the bot owns (so it is automatically a member — no
	 * manual /invite needed) and put the people who will answer chats in it.
	 *
	 * The bot being a member is not the same as setup working: a channel whose
	 * only member is the bot is invisible to the owner (a private one cannot
	 * even be searched for), so the result reports `alone` and the caller can
	 * say so instead of showing a green tick.
	 *
	 * @param string   $name     Desired channel name (sanitised to Slack rules).
	 * @param bool     $private  Whether to make it private.
	 * @param string[] $emails   Teammate emails to invite — fallback for when
	 *                           the workspace member list cannot be read.
	 * @param string[] $user_ids Slack user ids to invite; the normal path.
	 * @return array{ok: bool, id?: string, name?: string, error?: string,
	 *               invited?: string[], failed?: string[], alone?: bool}
	 */
	public static function create_channel( string $name, bool $private, array $emails = array(), array $user_ids = array() ): array {
		$clean = self::normalize_channel_name( $name );
		if ( '' === $clean ) {
			return array( 'ok' => false, 'error' => 'invalid_name' );
		}

		$resp = self::api_call(
			'conversations.create',
			array(
				'name'       => $clean,
				'is_private' => $private,
			)
		);

		if ( empty( $resp['ok'] ) ) {
			return array( 'ok' => false, 'error' => (string) ( $resp['error'] ?? 'unreachable' ) );
		}

		$channel_id = (string) ( $resp['channel']['id'] ?? '' );

		$result = array(
			'ok'   => true,
			'id'   => $channel_id,
			'name' => (string) ( $resp['channel']['name'] ?? $clean ),
		);

		if ( '' !== $channel_id && ! empty( $user_ids ) ) {
			$invite            = self::invite_by_ids( $channel_id, $user_ids );
			$result['invited'] = $invite['invited'];
			$result['failed']  = $invite['failed'];
		} elseif ( '' !== $channel_id && ! empty( $emails ) ) {
			$invite            = self::invite_by_emails( $channel_id, $emails );
			$result['invited'] = $invite['invited'];
			$result['failed']  = $invite['failed'];
		}

		if ( '' !== $channel_id ) {
			$result['alone'] = ! self::has_human_member( $channel_id );
		}

		return $result;
	}

	/**
	 * Is anyone other than our bot in this channel?
	 *
	 * @param string $channel_id Channel id.
	 * @return bool False when the bot is talking to an empty room, or when the
	 *               membership could not be read at all.
	 */
	public static function has_human_member( string $channel_id ): bool {
		$bot = self::bot_user_id();

		$cursor = '';
		for ( $page = 0; $page < 5; $page++ ) {
			$args = array(
				'channel' => $channel_id,
				'limit'   => 200,
			);
			if ( '' !== $cursor ) {
				$args['cursor'] = $cursor;
			}

			$response = self::api_call( 'conversations.members', $args );
			if ( empty( $response['ok'] ) ) {
				return false;
			}

			foreach ( (array) ( $response['members'] ?? array() ) as $member ) {
				if ( (string) $member !== $bot ) {
					return true;
				}
			}

			$cursor = (string) ( $response['response_metadata']['next_cursor'] ?? '' );
			if ( '' === $cursor ) {
				break;
			}
		}

		return false;
	}

	/**
	 * Our own bot user id, so we can tell ourselves apart from real people.
	 * Cached for a day — it only changes on reinstall, and the token change
	 * that comes with one clears the cache.
	 *
	 * @return string '' when the token is unusable.
	 */
	public static function bot_user_id(): string {
		$key    = 'attendant_slack_bot_uid_' . substr( md5( self::bot_token() ), 0, 12 );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = self::api_call( 'auth.test', array() );
		$uid      = ! empty( $response['ok'] ) ? (string) ( $response['user_id'] ?? '' ) : '';
		if ( '' !== $uid ) {
			set_transient( $key, $uid, DAY_IN_SECONDS );
		}

		return $uid;
	}

	/**
	 * Invite teammates to a channel by email address.
	 *
	 * Each email is resolved to a Slack user id (users.lookupByEmail) and
	 * invited (conversations.invite). "already in the channel" counts as
	 * success; anything else lands in `failed` so the UI can report it.
	 *
	 * @param string   $channel_id Channel id.
	 * @param string[] $emails     Teammate emails.
	 * @return array{invited: string[], failed: string[]}
	 */
	public static function invite_by_emails( string $channel_id, array $emails ): array {
		$invited = array();
		$failed  = array();

		foreach ( $emails as $email ) {
			$email = sanitize_email( (string) $email );
			if ( '' === $email ) {
				continue;
			}

			$lookup = self::api_call( 'users.lookupByEmail', array( 'email' => $email ) );
			$uid    = ! empty( $lookup['ok'] ) ? (string) ( $lookup['user']['id'] ?? '' ) : '';
			if ( '' === $uid ) {
				$failed[] = $email;
				continue;
			}

			$invite = self::api_call(
				'conversations.invite',
				array(
					'channel' => $channel_id,
					'users'   => $uid,
				)
			);
			if ( ! empty( $invite['ok'] ) || 'already_in_channel' === ( $invite['error'] ?? '' ) ) {
				$invited[] = $email;
			} else {
				$failed[] = $email;
			}
		}

		return array(
			'invited' => $invited,
			'failed'  => $failed,
		);
	}

	/**
	 * Coerce a display name into a valid Slack channel name: lowercase, only
	 * letters/digits/hyphen/underscore, no leading/trailing hyphen, ≤80 chars.
	 *
	 * @param string $name Raw name.
	 * @return string '' when nothing usable remains.
	 */
	public static function normalize_channel_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		$name = (string) preg_replace( '/[^a-z0-9_-]+/', '-', $name );
		$name = trim( $name, '-' );

		return substr( $name, 0, 80 );
	}

	/**
	 * auth.test — is the saved token valid?
	 *
	 * @return array{ok: bool, team?: string, error?: string}
	 */
	public static function test_auth(): array {
		$response = self::api_call( 'auth.test', array() );

		if ( ! empty( $response['ok'] ) ) {
			return array(
				'ok'   => true,
				'team' => (string) ( $response['team'] ?? '' ),
			);
		}

		return array(
			'ok'    => false,
			'error' => (string) ( $response['error'] ?? 'unreachable' ),
		);
	}

	/**
	 * Turn a raw Slack error code into a plain-language explanation with a
	 * concrete fix and, where one helps, a link the owner can click. Keeps
	 * every setup failure legible to a non-technical site owner instead of
	 * leaking codes like "not_in_channel".
	 *
	 * @param string $code Raw Slack `error` value (or 'unreachable').
	 * @return array{message: string, link?: string, link_label?: string}
	 */
	public static function friendly_error( string $code ): array {
		$apps    = 'https://api.slack.com/apps';
		$recreate = self::manifest_url();

		switch ( $code ) {
			case 'invalid_auth':
			case 'not_authed':
			case 'token_revoked':
			case 'token_expired':
			case 'account_inactive':
				return array(
					'message'    => __( 'The Bot Token is missing, wrong, or was reset in Slack. Open your app, copy the Bot User OAuth Token (it starts with xoxb-), and paste it into the Bot Token field above.', 'attendant' ),
					'link'       => $apps,
					'link_label' => __( 'Open your Slack apps', 'attendant' ),
				);

			case 'missing_scope':
			case 'not_allowed_token_type':
				return array(
					'message'    => __( 'Your Slack app is missing a permission it needs. The simplest fix is to re-create the app from the button below (it comes with every permission pre-set), then reinstall it and paste the new Bot Token.', 'attendant' ),
					'link'       => $recreate,
					'link_label' => __( 'Re-create the Slack app', 'attendant' ),
				);

			case 'channel_not_found':
				return array(
					'message' => __( 'That channel could not be found — it may have been deleted or renamed. Click "Reload channels" and pick your channel again.', 'attendant' ),
				);

			case 'name_taken':
				return array(
					'message' => __( 'A channel with that name already exists. Pick a different name, or switch to "Choose an existing channel" and select it from the list.', 'attendant' ),
				);

			case 'invalid_name':
			case 'invalid_name_specials':
			case 'invalid_name_maxlength':
			case 'invalid_name_required':
				return array(
					'message' => __( 'That channel name is not allowed. Use lowercase letters, numbers, and hyphens (for example: website-chat).', 'attendant' ),
				);

			case 'not_in_channel':
			case 'is_archived':
				return array(
					'message' => sprintf(
						/* translators: %s: the Slack app's name, e.g. Attendant Chat (My Site) */
						__( 'The app is not in that channel yet. Open the channel in Slack, type /invite @%s, then click "Reload channels" and choose it again.', 'attendant' ),
						self::app_name()
					),
				);

			case 'restricted_action':
			case 'no_permission':
				return array(
					'message' => sprintf(
						/* translators: %s: the Slack app's name, e.g. Attendant Chat (My Site) */
						__( 'Slack blocked this action. Ask a Slack workspace admin to allow the %s app, then try again.', 'attendant' ),
						self::app_name()
					),
				);

			case 'ratelimited':
				return array(
					'message' => __( 'Slack is temporarily rate-limiting requests. Wait about a minute and try again.', 'attendant' ),
				);

			case 'unreachable':
				return array(
					'message' => __( 'Could not reach Slack. Check that your server can make outbound web requests, then try again.', 'attendant' ),
				);

			default:
				return array(
					/* translators: %s: raw Slack error code */
					'message' => sprintf( __( 'Slack reported a problem (%s). Re-check your Bot Token and Signing Secret, and that the app is installed and invited to your channel.', 'attendant' ), $code ),
					'link'       => $apps,
					'link_label' => __( 'Open your Slack apps', 'attendant' ),
				);
		}
	}

	/**
	 * The pre-filled app manifest. Opened via
	 * https://api.slack.com/apps?new_app=1&manifest_json=<urlencoded>
	 * so the user's app is created with the right name, scopes, and OUR
	 * events URL already filled in — nothing to type on the Slack side.
	 *
	 * @return array
	 */
	public static function manifest(): array {
		$name = self::app_name();

		return array(
			'display_information' => array(
				'name'        => $name,
				'description' => 'Live-agent handoff for the Attendant chat widget',
				'background_color' => '#0073aa',
			),
			'features'            => array(
				'bot_user' => array(
					'display_name'   => $name,
					'always_online'  => true,
				),
			),
			'oauth_config'        => array(
				'scopes' => array(
					'bot' => array(
						'chat:write',
						'channels:history',
						'groups:history',
						'channels:read',
						'groups:read',
						// Lets the wizard create the channel for the owner and
						// invite teammates, so nobody has to make a channel and
						// /invite the bot by hand.
						'channels:manage',
						'groups:write',
						'users:read',
						'users:read.email',
					),
				),
			),
			'settings'            => array(
				'event_subscriptions' => array(
					'request_url' => rest_url( 'attendant/v1/slack/events' ),
					'bot_events'  => array( 'message.channels', 'message.groups' ),
				),
				'org_deploy_enabled'     => false,
				'socket_mode_enabled'    => false,
				'token_rotation_enabled' => false,
			),
		);
	}

	/**
	 * What this site's Slack app is called: "Attendant Chat (Site Name)".
	 *
	 * One app can only carry one event URL, so a site running the plugin needs
	 * its own app. Several apps in one workspace all called "Attendant Chat"
	 * are impossible to tell apart, and "/invite @Attendant Chat" stops meaning
	 * anything, so the site's own name goes in the title.
	 *
	 * Slack caps an app name at 35 characters. "Attendant Chat (" plus ")" eats
	 * 17, so a long site name is trimmed on a word boundary where it can be.
	 *
	 * @return string
	 */
	public static function app_name(): string {
		$base = 'Attendant Chat';
		$site = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		$site = (string) preg_replace( '/\s+/', ' ', $site );
		if ( '' === $site ) {
			return $base;
		}

		$room = 35 - ( strlen( $base ) + 3 ); // " (" + ")".
		if ( mb_strlen( $site ) > $room ) {
			$cut   = mb_substr( $site, 0, $room );
			$space = mb_strrpos( $cut, ' ' );
			// Only respect a word boundary if it leaves something readable.
			$site  = ( false !== $space && $space >= 6 ) ? mb_substr( $cut, 0, $space ) : $cut;
			$site  = rtrim( $site, " -–—," );
		}

		return $base . ' (' . $site . ')';
	}

	/**
	 * The one-click "create this app" URL for the settings guide.
	 *
	 * @return string
	 */
	public static function manifest_url(): string {
		return 'https://api.slack.com/apps?new_app=1&manifest_json=' . rawurlencode( (string) wp_json_encode( self::manifest() ) );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Strip Slack mrkdwn/mentions so visitor text can't ping or format.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean_for_slack( string $text ): string {
		// Neutralise mentions/links (<@U…>, <!channel>, <http…|label>).
		$text = str_replace( array( '<', '>' ), array( '&lt;', '&gt;' ), $text );

		return trim( $text );
	}

	/**
	 * Turn a Slack message into plain text for the widget.
	 *
	 * @param string $text Slack mrkdwn text.
	 * @return string
	 */
	public static function clean_from_slack( string $text ): string {
		// <http://x|label> → label, <http://x> → http://x, <@U123> → dropped.
		$text = (string) preg_replace( '/<([^|>]+)\|([^>]+)>/', '$2', $text );
		$text = (string) preg_replace( '/<@[A-Z0-9]+>/', '', $text );
		$text = (string) preg_replace( '/<!([a-z]+)>/', '', $text );
		$text = str_replace( array( '<', '>' ), '', $text );

		// Slack entity-escapes exactly &, < and > in event text — decode them
		// LAST (after mrkdwn stripping) so "5 &lt; 10 &amp; up" reads normally
		// in the widget instead of showing the raw entities.
		$text = str_replace( array( '&lt;', '&gt;', '&amp;' ), array( '<', '>', '&' ), $text );

		return trim( $text );
	}

	/**
	 * Minimal Slack Web API POST.
	 *
	 * Arguments go out form-encoded, never as JSON. Slack's read methods
	 * (users.conversations, conversations.info/members, users.lookupByEmail)
	 * drop a JSON body on the floor: the call still returns 200, but with no
	 * arguments at all — so a lookup 400s with `invalid_arguments` and a list
	 * quietly answers with defaults instead of what you asked for.
	 *
	 * @param string $method Slack API method name.
	 * @param array  $body   Arguments.
	 * @return array Decoded response (empty array on transport failure).
	 */
	private static function api_call( string $method, array $body ): array {
		$token = self::bot_token();
		if ( '' === $token ) {
			return array();
		}

		$form = array();
		foreach ( $body as $key => $value ) {
			if ( is_bool( $value ) ) {
				$form[ $key ] = $value ? 'true' : 'false';
			} elseif ( is_array( $value ) ) {
				// Structured args (blocks, attachments) travel as a JSON string.
				$form[ $key ] = (string) wp_json_encode( $value );
			} else {
				$form[ $key ] = (string) $value;
			}
		}

		$response = wp_remote_post(
			self::API . $method,
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/x-www-form-urlencoded; charset=utf-8',
				),
				'body'    => $form,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
