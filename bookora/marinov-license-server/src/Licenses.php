<?php
/**
 * License core: keys, activations, expiry. Independent of WooCommerce (see WooCommerce.php).
 *
 * @package MarinovLicenseServer
 */

namespace MarinovLicenseServer;

defined( 'ABSPATH' ) || exit;

/**
 * Licenses and activations.
 */
final class Licenses {

	public const DB_VERSION = '1.0.0';

	/** Unambiguous characters (no 0/O, 1/I/L). */
	private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	/**
	 * Table name.
	 *
	 * @param string $name licenses|activations.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'mls_' . $name;
	}

	/**
	 * Create/upgrade tables.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table( 'licenses' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  key_hash char(64) NOT NULL,
  key_enc text NOT NULL,
  key_last4 char(4) NOT NULL DEFAULT '',
  product varchar(64) NOT NULL,
  user_id bigint(20) unsigned NULL,
  email varchar(191) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned NULL,
  subscription_id bigint(20) unsigned NULL,
  sites_max int(10) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'active',
  expires_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY key_hash (key_hash),
  KEY user_id (user_id),
  KEY order_id (order_id),
  KEY subscription_id (subscription_id),
  KEY email (email)
) $collate;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'activations' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  license_id bigint(20) unsigned NOT NULL,
  site varchar(191) NOT NULL,
  version varchar(20) NOT NULL DEFAULT '',
  activated_at datetime NOT NULL,
  last_check datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY license_site (license_id,site)
) $collate;"
		);
		update_option( 'mls_db_version', self::DB_VERSION, false );
	}

	/**
	 * New random key, e.g. BKRA-7QH2-M9XK-4TPC-WN3D.
	 *
	 * @param string $prefix 4 letters.
	 */
	public static function generate_key( string $prefix = 'BKRA' ): string {
		$parts = array( strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $prefix ) . 'XXXX', 0, 4 ) ) );
		for ( $g = 0; $g < 4; $g++ ) {
			$chunk = '';
			for ( $i = 0; $i < 4; $i++ ) {
				$chunk .= self::ALPHABET[ random_int( 0, strlen( self::ALPHABET ) - 1 ) ];
			}
			$parts[] = $chunk;
		}
		return implode( '-', $parts );
	}

	/**
	 * Normalise user input: upper case, no spaces, dashes in place.
	 *
	 * @param string $key Key.
	 */
	public static function normalize_key( string $key ): string {
		$raw = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $key ) );
		return 20 === strlen( $raw ) ? implode( '-', str_split( $raw, 4 ) ) : '';
	}

	/**
	 * Lookup hash of a key (keyed with the site salt).
	 *
	 * @param string $key Normalised key.
	 */
	public static function hash( string $key ): string {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	/**
	 * "example.com/shop" – scheme, www. and trailing slash removed, lower case.
	 *
	 * @param string $url Site URL.
	 */
	public static function normalize_site( string $url ): string {
		$url  = strtolower( trim( $url ) );
		$host = (string) wp_parse_url( ( str_contains( $url, '://' ) ? '' : 'https://' ) . $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( ( str_contains( $url, '://' ) ? '' : 'https://' ) . $url, PHP_URL_PATH );
		$host = (string) preg_replace( '/^www\./', '', $host );
		if ( '' === $host || ! preg_match( '/^[a-z0-9.\-]+$/', $host ) ) {
			return '';
		}
		return mb_substr( $host . rtrim( $path, '/' ), 0, 191 );
	}

	/**
	 * Local and staging sites do not use up an activation.
	 *
	 * @param string $site Normalised site.
	 */
	public static function is_dev_site( string $site ): bool {
		$host = explode( '/', $site )[0];
		return in_array( $host, array( 'localhost', '127.0.0.1' ), true )
			|| (bool) preg_match( '/\.(local|test|localhost|invalid|example)$/', $host )
			|| (bool) preg_match( '/^(staging|stage|stag|dev|test)\./', $host );
	}

	/**
	 * Create a license.
	 *
	 * @param array{product:string, email:string, user_id?:int|null, order_id?:int|null, subscription_id?:int|null, sites?:int, months?:int, prefix?:string} $data Data. sites 0 = unlimited; months 0 = lifetime.
	 * @return array{id:int, key:string}
	 */
	public function create( array $data ): array {
		global $wpdb;
		do {
			$key  = self::generate_key( $data['prefix'] ?? 'BKRA' );
			$hash = self::hash( $key );
		} while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table( 'licenses' ) . ' WHERE key_hash = %s', $hash ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		$now    = current_time( 'mysql', true );
		$months = (int) ( $data['months'] ?? 12 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::table( 'licenses' ),
			array(
				'key_hash'        => $hash,
				'key_enc'         => self::encrypt( $key ),
				'key_last4'       => substr( $key, -4 ),
				'product'         => sanitize_key( $data['product'] ),
				'user_id'         => $data['user_id'] ?? null,
				'email'           => sanitize_email( $data['email'] ),
				'order_id'        => $data['order_id'] ?? null,
				'subscription_id' => $data['subscription_id'] ?? null,
				'sites_max'       => max( 0, (int) ( $data['sites'] ?? 1 ) ),
				'status'          => 'active',
				'expires_at'      => $months > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( "+{$months} months" ) ) : null,
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		return array(
			'id'  => (int) $wpdb->insert_id,
			'key' => $key,
		);
	}

	/**
	 * License by key.
	 *
	 * @param string $key Key (any format).
	 * @return array<string, mixed>|null
	 */
	public function find_by_key( string $key ): ?array {
		global $wpdb;
		$key = self::normalize_key( $key );
		if ( '' === $key ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'licenses' ) . ' WHERE key_hash = %s', self::hash( $key ) ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * License by id.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'licenses' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Licenses of a customer (My Account).
	 *
	 * @param int    $user_id User id.
	 * @param string $email   Email (licenses bought as guest).
	 * @return list<array<string, mixed>>
	 */
	public function for_customer( int $user_id, string $email ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'licenses' ) . ' WHERE user_id = %d OR ( email = %s AND email <> \'\' ) ORDER BY id DESC', $user_id, $email ), ARRAY_A );
		foreach ( $rows as &$row ) {
			$row['key']         = self::decrypt( (string) $row['key_enc'] );
			$row['activations'] = $this->activations( (int) $row['id'] );
		}
		return $rows;
	}

	/**
	 * Activations of a license.
	 *
	 * @param int $license_id License id.
	 * @return list<array<string, mixed>>
	 */
	public function activations( int $license_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'activations' ) . ' WHERE license_id = %d ORDER BY id', $license_id ), ARRAY_A );
	}

	/**
	 * Valid right now (status and expiry)?
	 *
	 * @param array<string, mixed> $license License.
	 */
	public static function is_valid( array $license ): bool {
		return 'active' === $license['status'] && ( empty( $license['expires_at'] ) || strtotime( $license['expires_at'] . ' UTC' ) > time() );
	}

	/**
	 * Activate a site. Re-activating the same site is fine and does not count twice.
	 *
	 * @param string $key     Key.
	 * @param string $site    Site URL.
	 * @param string $product Product slug.
	 * @param string $version Installed version.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function activate( string $key, string $site, string $product, string $version = '' ) {
		global $wpdb;
		$license = $this->find_by_key( $key );
		$site    = self::normalize_site( $site );
		if ( ! $license || sanitize_key( $product ) !== $license['product'] ) {
			return new \WP_Error( 'mls_invalid_key', __( 'This license key is not valid for this product.', 'marinov-license-server' ), array( 'status' => 404 ) );
		}
		if ( '' === $site ) {
			return new \WP_Error( 'mls_invalid_site', __( 'The website address is not valid.', 'marinov-license-server' ), array( 'status' => 400 ) );
		}
		if ( ! self::is_valid( $license ) ) {
			return new \WP_Error( 'mls_expired', 'revoked' === $license['status'] ? __( 'This license has been revoked.', 'marinov-license-server' ) : __( 'This license has expired. Please renew it in your account.', 'marinov-license-server' ), array( 'status' => 403 ) );
		}
		$existing = array_column( $this->activations( (int) $license['id'] ), null, 'site' );
		$now      = current_time( 'mysql', true );
		if ( ! isset( $existing[ $site ] ) ) {
			$used = count( array_filter( array_keys( $existing ), static fn( $s ) => ! self::is_dev_site( (string) $s ) ) );
			if ( (int) $license['sites_max'] > 0 && ! self::is_dev_site( $site ) && $used >= (int) $license['sites_max'] ) {
				return new \WP_Error(
					'mls_limit',
					/* translators: %d: number of websites. */
					sprintf( _n( 'This license is already used on %d website. Deactivate it there first or upgrade the license.', 'This license is already used on %d websites. Deactivate it on one of them first or upgrade the license.', (int) $license['sites_max'], 'marinov-license-server' ), (int) $license['sites_max'] ),
					array( 'status' => 403 )
				);
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert(
				self::table( 'activations' ),
				array(
					'license_id'   => (int) $license['id'],
					'site'         => $site,
					'version'      => mb_substr( sanitize_text_field( $version ), 0, 20 ),
					'activated_at' => $now,
					'last_check'   => $now,
				)
			);
		} else {
			$this->touch( (int) $license['id'], $site, $version );
		}
		return $this->status( $license, $site );
	}

	/**
	 * Deactivate a site (frees the slot).
	 *
	 * @param string $key  Key.
	 * @param string $site Site URL.
	 */
	public function deactivate( string $key, string $site ): bool {
		global $wpdb;
		$license = $this->find_by_key( $key );
		if ( ! $license ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete(
			self::table( 'activations' ),
			array(
				'license_id' => (int) $license['id'],
				'site'       => self::normalize_site( $site ),
			)
		);
	}

	/**
	 * Status for a site (used by check and activate).
	 *
	 * @param array<string, mixed> $license License.
	 * @param string               $site    Normalised site.
	 * @return array<string, mixed>
	 */
	public function status( array $license, string $site ): array {
		$activations = $this->activations( (int) $license['id'] );
		$active_here = in_array( $site, array_column( $activations, 'site' ), true );
		return array(
			'valid'      => self::is_valid( $license ) && $active_here,
			'status'     => self::is_valid( $license ) ? ( $active_here ? 'active' : 'inactive' ) : ( 'revoked' === $license['status'] ? 'revoked' : 'expired' ),
			'expires'    => $license['expires_at'] ? gmdate( 'c', (int) strtotime( $license['expires_at'] . ' UTC' ) ) : null,
			'sites_max'  => (int) $license['sites_max'],
			'sites_used' => count( array_filter( array_column( $activations, 'site' ), static fn( $s ) => ! self::is_dev_site( (string) $s ) ) ),
		);
	}

	/**
	 * Remember the last check of a site.
	 *
	 * @param int    $license_id License.
	 * @param string $site       Normalised site.
	 * @param string $version    Installed version.
	 */
	public function touch( int $license_id, string $site, string $version ): void {
		global $wpdb;
		$data = array( 'last_check' => current_time( 'mysql', true ) );
		if ( '' !== $version ) {
			$data['version'] = mb_substr( sanitize_text_field( $version ), 0, 20 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table( 'activations' ),
			$data,
			array(
				'license_id' => $license_id,
				'site'       => $site,
			)
		);
	}

	/**
	 * Set status / expiry (subscriptions, admin).
	 *
	 * @param int                  $id      License id.
	 * @param array<string, mixed> $changes status, expires_at, sites_max.
	 */
	public function update( int $id, array $changes ): void {
		global $wpdb;
		$data = array_intersect_key( $changes, array_flip( array( 'status', 'expires_at', 'sites_max', 'subscription_id' ) ) );
		if ( isset( $data['status'] ) && ! in_array( $data['status'], array( 'active', 'expired', 'revoked' ), true ) ) {
			unset( $data['status'] );
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table( 'licenses' ), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Extend from the later of "now" and the current expiry.
	 *
	 * @param int $id     License id.
	 * @param int $months Months.
	 */
	public function extend( int $id, int $months ): void {
		$license = $this->find( $id );
		if ( ! $license ) {
			return;
		}
		$base = max( time(), $license['expires_at'] ? (int) strtotime( $license['expires_at'] . ' UTC' ) : time() );
		$this->update(
			$id,
			array(
				'status'     => 'active',
				'expires_at' => gmdate( 'Y-m-d H:i:s', (int) strtotime( "+{$months} months", $base ) ),
			)
		);
	}

	/**
	 * Plain key of a license row (for the customer's email and account).
	 *
	 * @param array<string, mixed> $license License row.
	 */
	public static function key_of( array $license ): string {
		return self::decrypt( (string) $license['key_enc'] );
	}

	/**
	 * Encrypt a key for display in the customer's account (libsodium, key from wp_salt).
	 *
	 * @param string $plain Plain text.
	 */
	private static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::secret() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary storage.
	}

	/**
	 * Decrypt.
	 *
	 * @param string $encoded Encoded.
	 */
	private static function decrypt( string $encoded ): string {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary storage.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::secret() );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Encryption key.
	 */
	private static function secret(): string {
		return hash( 'sha256', 'mls-key|' . wp_salt( 'secure_auth' ), true );
	}
}
