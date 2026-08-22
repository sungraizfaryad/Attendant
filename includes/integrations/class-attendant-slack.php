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
		if ( '' !== $ts ) {
			delete_transient( 'attendant_slack_session_' . str_replace( '.', '_', $ts ) );
		}
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
	 * @return bool Whether the thread was created.
	 */
	public static function start_handoff( string $session_id, array $transcript ): bool {
		if ( ! self::is_configured() ) {
			return false;
		}

		// Already live — nothing to do.
		if ( '' !== self::thread_for_session( $session_id ) ) {
			return true;
		}

		$lines   = array();
		$lines[] = '*A website visitor asked to talk to a human.*';
		$lines[] = '_Reply in this thread — the visitor sees your messages in the chat widget._';
		$lines[] = '';

		$recent = array_slice( $transcript, -10 );
		foreach ( $recent as $m ) {
			$who     = 'user' === ( $m['role'] ?? '' ) ? 'Visitor' : 'Assistant';
			$lines[] = '*' . $who . ':* ' . self::clean_for_slack( (string) ( $m['text'] ?? '' ) );
		}

		$ts = self::post_message( implode( "\n", $lines ) );
		if ( '' === $ts ) {
			return false;
		}

		self::map( $session_id, $ts );

		return true;
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
	 * chat.postMessage. Returns the message ts, '' on failure.
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

		return empty( $response['ok'] ) ? '' : (string) ( $response['ts'] ?? '' );
	}

	// -------------------------------------------------------------------------
	// Slack → site (queue drained by the widget's poll)
	// -------------------------------------------------------------------------

	/**
	 * Queue an agent reply for the visitor's widget.
	 *
	 * @param string $session_id Chat session id.
	 * @param string $text       Agent message (already cleaned).
	 */
	public static function queue_agent_message( string $session_id, string $text ): void {
		$key   = 'attendant_agent_q_' . md5( $session_id );
		$queue = get_transient( $key );
		$queue = is_array( $queue ) ? $queue : array();

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

	/**
	 * Return queued agent messages for a session and clear the queue.
	 *
	 * @param string $session_id Chat session id.
	 * @return array[] Each: ['text' => string, 't' => int].
	 */
	public static function drain_agent_messages( string $session_id ): array {
		$key   = 'attendant_agent_q_' . md5( $session_id );
		$queue = get_transient( $key );

		if ( ! is_array( $queue ) || array() === $queue ) {
			return array();
		}

		delete_transient( $key );

		return $queue;
	}

	// -------------------------------------------------------------------------
	// Admin helpers (settings screen)
	// -------------------------------------------------------------------------

	/**
	 * Channels the bot can see, for the settings dropdown.
	 *
	 * @return array[] Each: ['id' => string, 'name' => string, 'private' => bool].
	 */
	public static function list_channels(): array {
		$response = self::api_call(
			'conversations.list',
			array(
				'types'            => 'public_channel,private_channel',
				'exclude_archived' => true,
				'limit'            => 200,
			)
		);

		if ( empty( $response['ok'] ) || empty( $response['channels'] ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) $response['channels'] as $ch ) {
			$out[] = array(
				'id'      => (string) ( $ch['id'] ?? '' ),
				'name'    => (string) ( $ch['name'] ?? '' ),
				'private' => ! empty( $ch['is_private'] ),
			);
		}

		return $out;
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
	 * The pre-filled app manifest. Opened via
	 * https://api.slack.com/apps?new_app=1&manifest_json=<urlencoded>
	 * so the user's app is created with the right name, scopes, and OUR
	 * events URL already filled in — nothing to type on the Slack side.
	 *
	 * @return array
	 */
	public static function manifest(): array {
		return array(
			'display_information' => array(
				'name'        => 'Attendant Chat',
				'description' => 'Live-agent handoff for the Attendant chat widget',
				'background_color' => '#0073aa',
			),
			'features'            => array(
				'bot_user' => array(
					'display_name'   => 'Attendant Chat',
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

		return trim( $text );
	}

	/**
	 * Minimal Slack Web API POST.
	 *
	 * @param string $method Slack API method name.
	 * @param array  $body   JSON body.
	 * @return array Decoded response (empty array on transport failure).
	 */
	private static function api_call( string $method, array $body ): array {
		$token = self::bot_token();
		if ( '' === $token ) {
			return array();
		}

		$response = wp_remote_post(
			self::API . $method,
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
