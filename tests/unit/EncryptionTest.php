<?php
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once ATTENDANT_PLUGIN_DIR . 'includes/class-attendant-encryption.php';

/**
 * Regression coverage for the AES cipher round-trip.
 *
 * Guards against the OpenSSL 3.x bug where openssl_get_cipher_methods() returns
 * lower-cased names and a strict, case-sensitive availability check silently
 * disabled all key storage.
 */
final class EncryptionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// wp_salt is the only WordPress dependency of the encryption class.
		Functions\when( 'wp_salt' )->alias( static fn( $scheme = '' ) => 'unit-test-salt-' . $scheme . '-0123456789abcdef' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_openssl_is_available_on_this_build(): void {
		// If this fails, encrypt() returns '' and no API key can ever be stored.
		$this->assertTrue( ATTENDANT_Encryption::openssl_available() );
	}

	public function test_encrypt_decrypt_round_trip(): void {
		$plain = 'sk-proj-EXAMPLE-not-a-real-key-1234567890ABCDEF';
		$cipher = ATTENDANT_Encryption::encrypt( $plain );

		$this->assertNotSame( '', $cipher, 'encrypt() returned empty — key storage is broken.' );
		$this->assertNotSame( $plain, $cipher, 'value was not actually encrypted.' );
		$this->assertSame( $plain, ATTENDANT_Encryption::decrypt( $cipher ) );
	}

	public function test_empty_input_returns_empty(): void {
		$this->assertSame( '', ATTENDANT_Encryption::encrypt( '' ) );
		$this->assertSame( '', ATTENDANT_Encryption::decrypt( '' ) );
	}
}
