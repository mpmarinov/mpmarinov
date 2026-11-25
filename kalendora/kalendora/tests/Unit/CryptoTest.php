<?php
namespace Kalendora\Tests\Unit;

use Kalendora\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase {

	public function test_roundtrip(): void {
		$crypto = new Crypto( 'test-key' );
		$secret = 'oauth-refresh-token-äöü';
		$enc    = $crypto->encrypt( $secret );

		$this->assertStringStartsWith( 'bk1:', $enc );
		$this->assertStringNotContainsString( $secret, $enc );
		$this->assertSame( $secret, $crypto->decrypt( $enc ) );
	}

	public function test_nonce_is_random(): void {
		$crypto = new Crypto( 'test-key' );
		$this->assertNotSame( $crypto->encrypt( 'x' ), $crypto->encrypt( 'x' ) );
	}

	public function test_wrong_key_fails(): void {
		$enc = ( new Crypto( 'key-a' ) )->encrypt( 'secret' );
		$this->assertNull( ( new Crypto( 'key-b' ) )->decrypt( $enc ) );
	}

	public function test_tampered_value_fails(): void {
		$crypto = new Crypto( 'test-key' );
		$enc    = $crypto->encrypt( 'secret' );
		$last   = substr( $enc, -1 );
		$this->assertNull( $crypto->decrypt( substr( $enc, 0, -1 ) . ( 'A' === $last ? 'B' : 'A' ) ) );
		$this->assertNull( $crypto->decrypt( 'garbage' ) );
		$this->assertNull( $crypto->decrypt( 'bk1:!!!' ) );
	}

	public function test_tokens(): void {
		$a = Crypto::random_token();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $a );
		$this->assertNotSame( $a, Crypto::random_token() );
		$this->assertSame( 64, strlen( Crypto::hash_token( $a ) ) );
	}
}
