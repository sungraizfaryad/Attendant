<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-encryption.php';
require_once ATTENDANT_PLUGIN_DIR . 'includes/providers/class-attendant-google-provider.php';

/**
 * Gemini translation: contents/parts with role merging, systemInstruction,
 * functionDeclarations, and functionCall/functionResponse round-trips.
 */
final class GoogleProviderTest extends TestCase {

	/** @var array|null Body of the last captured POST. */
	private ?array $sent_body = null;

	/** @var string Last requested URL. */
	private string $sent_url = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->sent_body                 = null;
		$this->sent_url                  = '';
		Attendant_Plugin::$test_settings = array();

		Functions\when( 'wp_salt' )->alias( fn( $scheme = 'auth' ) => "salt-{$scheme}" );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( '__' )->returnArg();

		$encrypted = ATTENDANT_Encryption::encrypt( 'AIza-test' );
		Functions\when( 'get_option' )->alias(
			fn( $name, $default = '' ) => 'attendant_api_key_google' === $name ? $encrypted : $default
		);
	}

	protected function tearDown(): void {
		Attendant_Plugin::$test_settings = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function respond_with( array $reply ): void {
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( $reply ) {
				$this->sent_url  = $url;
				$this->sent_body = json_decode( $args['body'], true );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $reply ) );
	}

	private function text_reply( string $text ): array {
		return array(
			'candidates'    => array(
				array( 'content' => array( 'parts' => array( array( 'text' => $text ) ) ) ),
			),
			'usageMetadata' => array(
				'promptTokenCount'     => 7,
				'candidatesTokenCount' => 3,
			),
		);
	}

	public function test_uses_v1beta_endpoint(): void {
		$this->respond_with( $this->text_reply( 'hi' ) );
		$provider = new ATTENDANT_Google_Provider();
		$provider->chat_completion( array( array( 'role' => 'user', 'content' => 'q' ) ) );

		$this->assertStringContainsString( '/v1beta/models/gemini-2.5-flash:generateContent', $this->sent_url );
	}

	public function test_system_becomes_system_instruction(): void {
		$this->respond_with( $this->text_reply( 'hi' ) );
		$provider = new ATTENDANT_Google_Provider();
		$provider->chat_completion(
			array(
				array( 'role' => 'system', 'content' => 'Be brief.' ),
				array( 'role' => 'user', 'content' => 'Hello' ),
			)
		);

		$this->assertSame( 'Be brief.', $this->sent_body['systemInstruction']['parts'][0]['text'] );
		$this->assertSame( 'user', $this->sent_body['contents'][0]['role'] );
	}

	public function test_assistant_role_maps_to_model_and_merges_consecutive(): void {
		$this->respond_with( $this->text_reply( 'ok' ) );
		$provider = new ATTENDANT_Google_Provider();
		$provider->chat_completion(
			array(
				array( 'role' => 'user', 'content' => 'one' ),
				array( 'role' => 'user', 'content' => 'two' ),
				array( 'role' => 'assistant', 'content' => 'reply' ),
			)
		);

		$contents = $this->sent_body['contents'];
		$this->assertCount( 2, $contents );
		$this->assertSame( 'user', $contents[0]['role'] );
		$this->assertCount( 2, $contents[0]['parts'] );
		$this->assertSame( 'model', $contents[1]['role'] );
	}

	public function test_tools_wrap_in_function_declarations(): void {
		$this->respond_with( $this->text_reply( 'ok' ) );
		$provider = new ATTENDANT_Google_Provider();
		$provider->chat_completion(
			array( array( 'role' => 'user', 'content' => 'q' ) ),
			array(
				array(
					'name'        => 'search_posts',
					'description' => 'Search.',
					'parameters'  => array( 'type' => 'object' ),
				),
			)
		);

		$decl = $this->sent_body['tools'][0]['functionDeclarations'][0];
		$this->assertSame( 'search_posts', $decl['name'] );
		$this->assertSame(
			array( 'mode' => 'AUTO' ),
			$this->sent_body['toolConfig']['functionCallingConfig']
		);
	}

	public function test_forced_tool_uses_mode_any(): void {
		$this->respond_with( $this->text_reply( 'ok' ) );
		$provider = new ATTENDANT_Google_Provider();
		$provider->chat_completion(
			array( array( 'role' => 'user', 'content' => 'q' ) ),
			array( array( 'name' => 'suggest_choices', 'description' => '', 'parameters' => array() ) ),
			array( 'tool_choice' => 'suggest_choices' )
		);

		$this->assertSame(
			array(
				'mode'                 => 'ANY',
				'allowedFunctionNames' => array( 'suggest_choices' ),
			),
			$this->sent_body['toolConfig']['functionCallingConfig']
		);
	}

	public function test_function_call_response_normalised(): void {
		$this->respond_with(
			array(
				'candidates'    => array(
					array(
						'content' => array(
							'parts' => array(
								array(
									'functionCall' => array(
										'name' => 'search_posts',
										'args' => array( 'q' => 'villas' ),
										'id'   => 'fc_1',
									),
								),
							),
						),
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 5,
					'candidatesTokenCount' => 2,
				),
			)
		);
		$provider = new ATTENDANT_Google_Provider();
		$result   = $provider->chat_completion( array( array( 'role' => 'user', 'content' => 'q' ) ) );

		$this->assertSame( 'search_posts', $result['function_call']['name'] );
		$this->assertSame( array( 'q' => 'villas' ), json_decode( $result['function_call']['arguments'], true ) );
		$this->assertSame( 'fc_1', $result['function_call']['gemini_id'] );
		$this->assertSame( 5, $result['usage']['input_tokens'] );
	}

	public function test_embeddings_batch_request_shape_and_order(): void {
		$this->respond_with(
			array(
				'embeddings' => array(
					array( 'values' => array( 0.1, 0.2 ) ),
					array( 'values' => array( 0.3, 0.4 ) ),
				),
			)
		);
		$provider = new ATTENDANT_Google_Provider();
		$vectors  = $provider->generate_embeddings_batch( array( 'first text', 'second text' ) );

		$this->assertStringContainsString( ':batchEmbedContents', $this->sent_url );
		$reqs = $this->sent_body['requests'];
		$this->assertCount( 2, $reqs );
		$this->assertSame( 'models/gemini-embedding-001', $reqs[0]['model'] );
		$this->assertSame( 'first text', $reqs[0]['content']['parts'][0]['text'] );
		$this->assertSame( 1536, $reqs[0]['outputDimensionality'] );

		$this->assertSame( array( array( 0.1, 0.2 ), array( 0.3, 0.4 ) ), $vectors );
	}

	public function test_single_embedding_delegates_to_batch(): void {
		$this->respond_with(
			array( 'embeddings' => array( array( 'values' => array( 0.5, 0.6 ) ) ) )
		);
		$provider = new ATTENDANT_Google_Provider();

		$this->assertSame( array( 0.5, 0.6 ), $provider->generate_embedding( 'hello' ) );
	}

	public function test_embeddings_empty_input_makes_no_request(): void {
		$called = false;
		Brain\Monkey\Functions\when( 'wp_remote_post' )->alias(
			function () use ( &$called ) {
				$called = true;
				return array();
			}
		);
		$provider = new ATTENDANT_Google_Provider();

		$this->assertSame( array(), $provider->generate_embeddings_batch( array() ) );
		$this->assertFalse( $called );
	}

	public function test_embedding_model_reported(): void {
		$provider = new ATTENDANT_Google_Provider();
		$this->assertSame( 'gemini-embedding-001', $provider->get_embedding_model() );
	}

	public function test_tool_result_becomes_function_response(): void {
		// Message shape mirrors EXACTLY what the conversation handler's
		// build_messages_with_tool_result() produces — including 'name' and
		// 'gemini_id' on both entries. Gemini rejects the round otherwise.
		$this->respond_with( $this->text_reply( 'Found.' ) );
		$provider = new ATTENDANT_Google_Provider();
		$provider->chat_completion(
			array(
				array( 'role' => 'user', 'content' => 'find villas' ),
				array(
					'role'       => 'assistant',
					'content'    => null,
					'tool_calls' => array(
						array(
							'id'        => 'call_1',
							'type'      => 'function',
							'gemini_id' => 'fc_9',
							'function'  => array(
								'name'      => 'search_posts',
								'arguments' => '{"q":"villas"}',
							),
						),
					),
				),
				array(
					'role'         => 'tool',
					'tool_call_id' => 'call_1',
					'name'         => 'search_posts',
					'gemini_id'    => 'fc_9',
					'content'      => '{"count":3}',
				),
			)
		);

		$contents = $this->sent_body['contents'];
		$this->assertSame( 'model', $contents[1]['role'] );
		$this->assertSame( 'search_posts', $contents[1]['parts'][0]['functionCall']['name'] );
		$this->assertSame( 'fc_9', $contents[1]['parts'][0]['functionCall']['id'] );
		$this->assertSame( 'user', $contents[2]['role'] );
		$this->assertSame( 'search_posts', $contents[2]['parts'][0]['functionResponse']['name'] );
		$this->assertSame( 'fc_9', $contents[2]['parts'][0]['functionResponse']['id'] );
		$this->assertSame( array( 'count' => 3 ), $contents[2]['parts'][0]['functionResponse']['response']['result'] );
	}

	public function test_handler_round2_shape_carries_function_name(): void {
		// Regression guard for the empty functionResponse.name bug: the
		// handler always sets 'name' on the tool message, and the provider
		// must forward it — an empty name is a Gemini 400.
		$this->respond_with( $this->text_reply( 'ok' ) );
		$provider = new ATTENDANT_Google_Provider();
		$provider->chat_completion(
			array(
				array( 'role' => 'user', 'content' => 'q' ),
				array(
					'role'       => 'assistant',
					'content'    => null,
					'tool_calls' => array(
						array(
							'id'        => 'call_2',
							'type'      => 'function',
							'gemini_id' => '',
							'function'  => array(
								'name'      => 'capture_lead',
								'arguments' => '{}',
							),
						),
					),
				),
				array(
					'role'         => 'tool',
					'tool_call_id' => 'call_2',
					'name'         => 'capture_lead',
					'gemini_id'    => '',
					'content'      => '{"ok":true}',
				),
			)
		);

		$fr = $this->sent_body['contents'][2]['parts'][0]['functionResponse'];
		$this->assertSame( 'capture_lead', $fr['name'] );
		// Empty gemini_id must NOT emit an id key at all.
		$this->assertArrayNotHasKey( 'id', $fr );
	}
}
