<?php
/**
 * Google Gemini provider.
 *
 * Gemini's generateContent API needs the deepest translation of all the
 * providers:
 *  - messages become `contents` with roles user/model, and consecutive
 *    same-role turns must be merged (the API rejects non-alternating runs)
 *  - the system prompt moves to `systemInstruction`
 *  - tools nest as functionDeclarations; forcing one is done via
 *    toolConfig.functionCallingConfig mode=ANY + allowedFunctionNames
 *  - always call the v1beta endpoint: v1 silently drops the tools array
 *  - Gemini 3 attaches an id to functionCall parts that must be echoed
 *    back inside functionResponse
 *
 * We always request the blocking endpoint — the plugin does not stream.
 * No embeddings here either: retrieval stays OpenAI-or-FULLTEXT (stored
 * vectors are 1536-dim OpenAI embeddings; mixing dimensions breaks cosine).
 *
 * @package Attendant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ATTENDANT_PLUGIN_DIR . 'includes/providers/interface-attendant-llm-provider.php';

/**
 * Class ATTENDANT_Google_Provider
 */
class ATTENDANT_Google_Provider implements ATTENDANT_LLM_Provider {

	private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

	/** Embedding model — free of charge on the Gemini API free tier. */
	private const EMBEDDING_MODEL = 'gemini-embedding-001';

	/** Must match the chunks table schema (and the old OpenAI vectors' size). */
	private const EMBEDDING_DIMENSIONS = 1536;

	/** USD per 1M tokens. */
	private const PRICING = array(
		'gemini-2.5-flash' => array(
			'input'  => 0.30,
			'output' => 2.50,
		),
	);

	/**
	 * Decrypted API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Selected chat model.
	 *
	 * @var string
	 */
	private string $chat_model;

	/**
	 * Reads the stored key + model selection.
	 */
	public function __construct() {
		$encrypted        = (string) get_option( 'attendant_api_key_google', '' );
		$this->api_key    = ATTENDANT_Encryption::decrypt( $encrypted );
		$this->chat_model = (string) Attendant_Plugin::get_setting( 'chat_model_google', 'gemini-2.5-flash' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function chat_completion( array $messages, array $functions = array(), array $options = array() ): array {
		$empty_result = array(
			'content'       => null,
			'function_call' => null,
			'usage'         => array(
				'input_tokens'  => 0,
				'output_tokens' => 0,
			),
		);

		if ( '' === $this->api_key ) {
			return $empty_result;
		}

		$model = $options['model'] ?? $this->chat_model;

		list( $system, $contents ) = $this->translate_messages( $messages );

		$body = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'maxOutputTokens' => $options['max_tokens'] ?? 1024,
				'temperature'     => $options['temperature'] ?? 0.7,
			),
		);

		if ( '' !== $system ) {
			$body['systemInstruction'] = array(
				'parts' => array( array( 'text' => $system ) ),
			);
		}

		if ( ! empty( $functions ) ) {
			$body['tools'] = array(
				array(
					'functionDeclarations' => array_map(
						static fn( array $fn ): array => array(
							'name'        => $fn['name'] ?? '',
							'description' => $fn['description'] ?? '',
							'parameters'  => $fn['parameters'] ?? array( 'type' => 'object' ),
						),
						$functions
					),
				),
			);

			$forced             = (string) ( $options['tool_choice'] ?? '' );
			$body['toolConfig'] = array(
				'functionCallingConfig' => '' !== $forced
					? array(
						'mode'                 => 'ANY',
						'allowedFunctionNames' => array( $forced ),
					)
					: array( 'mode' => 'AUTO' ),
			);
		}

		$url      = self::API_BASE . '/models/' . rawurlencode( $model ) . ':generateContent';
		$response = $this->post( $url, $body, 60 );

		if ( null === $response ) {
			return $empty_result;
		}

		$usage  = $response['usageMetadata'] ?? array();
		$result = array(
			'content'       => null,
			'function_call' => null,
			'usage'         => array(
				'input_tokens'  => (int) ( $usage['promptTokenCount'] ?? 0 ),
				'output_tokens' => (int) ( $usage['candidatesTokenCount'] ?? 0 ),
			),
		);

		foreach ( (array) ( $response['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( isset( $part['text'] ) && null === $result['content'] ) {
				$result['content'] = (string) $part['text'];
			}

			if ( isset( $part['functionCall'] ) && null === $result['function_call'] ) {
				$args                    = $part['functionCall']['args'] ?? array();
				$result['function_call'] = array(
					'name'      => (string) ( $part['functionCall']['name'] ?? '' ),
					'arguments' => (string) wp_json_encode( is_array( $args ) ? $args : array() ),
					// Gemini 3 ids must round-trip into functionResponse.
					'gemini_id' => (string) ( $part['functionCall']['id'] ?? '' ),
				);
			}
		}

		return $result;
	}

	/**
	 * Translate OpenAI-format messages into Gemini contents.
	 *
	 * @param array $messages OpenAI-style messages.
	 * @return array{0: string, 1: array} System prompt + contents array.
	 */
	private function translate_messages( array $messages ): array {
		$system   = '';
		$contents = array();

		$append = static function ( string $role, array $part ) use ( &$contents ): void {
			$last = count( $contents ) - 1;
			// Merge consecutive same-role turns — Gemini requires alternation.
			if ( $last >= 0 && $contents[ $last ]['role'] === $role ) {
				$contents[ $last ]['parts'][] = $part;
				return;
			}
			$contents[] = array(
				'role'  => $role,
				'parts' => array( $part ),
			);
		};

		foreach ( $messages as $message ) {
			$role = $message['role'] ?? '';

			if ( 'system' === $role ) {
				$system .= ( '' !== $system ? "\n\n" : '' ) . (string) ( $message['content'] ?? '' );
				continue;
			}

			if ( 'assistant' === $role && ! empty( $message['tool_calls'] ) ) {
				foreach ( $message['tool_calls'] as $call ) {
					$args = json_decode( (string) ( $call['function']['arguments'] ?? '{}' ), true );
					$part = array(
						'functionCall' => array(
							'name' => (string) ( $call['function']['name'] ?? '' ),
							'args' => is_array( $args ) ? $args : array(),
						),
					);
					if ( '' !== (string) ( $call['gemini_id'] ?? '' ) ) {
						$part['functionCall']['id'] = (string) $call['gemini_id'];
					}
					$append( 'model', $part );
				}
				continue;
			}

			if ( 'tool' === $role ) {
				$decoded = json_decode( (string) ( $message['content'] ?? '' ), true );
				$part    = array(
					'functionResponse' => array(
						'name'     => (string) ( $message['name'] ?? '' ),
						'response' => array( 'result' => is_array( $decoded ) ? $decoded : (string) ( $message['content'] ?? '' ) ),
					),
				);
				if ( '' !== (string) ( $message['gemini_id'] ?? '' ) ) {
					$part['functionResponse']['id'] = (string) $message['gemini_id'];
				}
				$append( 'user', $part );
				continue;
			}

			if ( 'user' === $role || 'assistant' === $role ) {
				$append(
					'assistant' === $role ? 'model' : 'user',
					array( 'text' => (string) ( $message['content'] ?? '' ) )
				);
			}
		}

		return array( $system, $contents );
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embedding( string $text ): array {
		return $this->generate_embeddings_batch( array( $text ) )[0] ?? array();
	}

	/**
	 * {@inheritdoc}
	 *
	 * gemini-embedding-001 at 1536 dims — matches the chunks table schema.
	 * Vectors below 3072 dims come back unnormalised; that's fine here
	 * because retrieval computes full cosine (magnitudes on both sides).
	 * Free tier: embeddings are free of charge, only rate-limited.
	 */
	public function generate_embeddings_batch( array $texts ): array {
		if ( empty( $texts ) || '' === $this->api_key ) {
			return array();
		}

		// Same safety cap as the old OpenAI embedder — callers batch anyway.
		$texts = array_slice( $texts, 0, 100 );

		$body = array(
			'requests' => array_map(
				static fn( string $text ): array => array(
					'model'                => 'models/' . self::EMBEDDING_MODEL,
					'content'              => array( 'parts' => array( array( 'text' => $text ) ) ),
					'outputDimensionality' => self::EMBEDDING_DIMENSIONS,
				),
				array_values( $texts )
			),
		);

		$url      = self::API_BASE . '/models/' . self::EMBEDDING_MODEL . ':batchEmbedContents';
		$response = $this->post( $url, $body, 30 );

		if ( null === $response || empty( $response['embeddings'] ) ) {
			return array();
		}

		return array_map(
			static fn( array $item ): array => array_map( 'floatval', (array) ( $item['values'] ?? array() ) ),
			$response['embeddings']
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection(): array {
		if ( '' === $this->api_key ) {
			return array(
				'success' => false,
				'message' => __( 'No API key is stored. Enter your Google AI API key in the settings.', 'attendant' ),
				'model'   => '',
			);
		}

		$response = wp_remote_get(
			self::API_BASE . '/models',
			array(
				'timeout' => 15,
				'headers' => $this->build_headers(),
			)
		);

		$data = $this->parse_response( $response );

		if ( null === $data ) {
			return array(
				'success' => false,
				'message' => __( 'Could not connect to Google AI. Check your API key and network.', 'attendant' ),
				'model'   => '',
			);
		}

		// Model names come back as 'models/gemini-…'.
		$model_ids = array_map(
			static fn( array $m ): string => str_replace( 'models/', '', (string) ( $m['name'] ?? '' ) ),
			$data['models'] ?? array()
		);
		$confirmed = in_array( $this->chat_model, $model_ids, true )
			? $this->chat_model
			: ( $model_ids[0] ?? 'unknown' );

		return array(
			'success' => true,
			'message' => __( 'Connected successfully.', 'attendant' ),
			'model'   => $confirmed,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_provider_name(): string {
		return 'google';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_chat_model(): string {
		return $this->chat_model;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_embedding_model(): string {
		return self::EMBEDDING_MODEL;
	}

	/**
	 * {@inheritdoc}
	 */
	public function estimate_cost( int $input_tokens, int $output_tokens ): float {
		$pricing = self::PRICING[ $this->chat_model ] ?? array(
			'input'  => 0.00,
			'output' => 0.00,
		);

		return round(
			( $input_tokens / 1_000_000 ) * $pricing['input']
			+ ( $output_tokens / 1_000_000 ) * $pricing['output'],
			6
		);
	}

	// -------------------------------------------------------------------------
	// HTTP helpers
	// -------------------------------------------------------------------------

	/**
	 * POST JSON to the Gemini API.
	 *
	 * @param string $url     Endpoint URL (without key).
	 * @param array  $body    Request body.
	 * @param int    $timeout Timeout seconds.
	 * @return array|null
	 */
	private function post( string $url, array $body, int $timeout = 30 ): ?array {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => $timeout,
				'headers'     => $this->build_headers(),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		return $this->parse_response( $response );
	}

	/**
	 * Auth via the x-goog-api-key header — keeps the key out of URLs
	 * (query strings end up in server access logs).
	 *
	 * @return array<string, string>
	 */
	private function build_headers(): array {
		return array(
			'x-goog-api-key' => $this->api_key,
			'Content-Type'   => 'application/json',
			'User-Agent'     => 'Attendant/' . ATTENDANT_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
		);
	}

	/**
	 * Validate a wp_remote_*() response.
	 *
	 * @param array|WP_Error $response Raw response.
	 * @return array|null
	 */
	private function parse_response( array|WP_Error $response ): ?array {
		if ( is_wp_error( $response ) ) {
			$this->log_error( 'Network error: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$api_error = $data['error']['message'] ?? "HTTP {$code}";
			$this->log_error( "Gemini API error ({$code}): {$api_error}" );
			return null;
		}

		if ( ! is_array( $data ) ) {
			$this->log_error( 'Gemini returned non-JSON or empty body.' );
			return null;
		}

		return $data;
	}

	/**
	 * WP_DEBUG-gated logging. Never logs keys or message content.
	 *
	 * @param string $message Error description.
	 */
	private function log_error( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Attendant / Gemini] ' . $message );
		}
	}
}
