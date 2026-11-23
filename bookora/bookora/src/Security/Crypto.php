<?php
/**
 * Authenticated encryption and token helpers.
 *
 * @package Bookora
 */

namespace Bookora\Security;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets (OAuth tokens, CalDAV passwords) with libsodium and creates
 * single-use URL tokens. The key is derived from the site's salts and never stored.
 */
final class Crypto {

	private const PREFIX = 'bk1:';

	/**
	 * Raw 32-byte key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string $key_material Secret input; defaults to WordPress salts.
	 * @throws RuntimeException When no key material is available.
	 */
	public function __construct( string $key_material = '' ) {
		if ( '' === $key_material ) {
			$key_material = self::default_key_material();
		}

		if ( '' === $key_material ) {
			throw new RuntimeException( 'No key material available for encryption.' );
		}

		$this->key = sodium_crypto_generichash( 'bookora|' . $key_material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $plaintext Value to protect.
	 */
	public function encrypt( string $plaintext ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );

		return self::PREFIX . sodium_bin2base64( $nonce . $cipher, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $encoded Encrypted value.
	 * @return string|null Plaintext, or null if tampered with or invalid.
	 */
	public function decrypt( string $encoded ): ?string {
		if ( 0 !== strncmp( $encoded, self::PREFIX, strlen( self::PREFIX ) ) ) {
			return null;
		}

		try {
			$raw = sodium_base642bin( substr( $encoded, strlen( self::PREFIX ) ), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
		} catch ( \SodiumException $e ) {
			return null;
		}

		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key );

		return false === $plain ? null : $plain;
	}

	/**
	 * Create a random URL-safe token (for one-click links).
	 *
	 * @param int $bytes Entropy in bytes.
	 */
	public static function random_token( int $bytes = 32 ): string {
		return sodium_bin2base64( random_bytes( $bytes ), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
	}

	/**
	 * Hash a token for storage. Only the hash is kept in the database.
	 *
	 * @param string $token Plain token.
	 */
	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Build key material from WordPress constants.
	 */
	private static function default_key_material(): string {
		$parts = array();
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_SALT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$parts[] = (string) constant( $constant );
			}
		}
		return implode( '|', $parts );
	}
}
