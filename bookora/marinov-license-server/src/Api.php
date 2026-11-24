<?php
/**
 * Public REST API for the Pro plugins (activate, deactivate, check/update, download)
 * and the release store.
 *
 * POST /wp-json/mls/v1/activate   {key, site, product, version}
 * POST /wp-json/mls/v1/deactivate {key, site}
 * POST /wp-json/mls/v1/check      {key, site, product, version} → status + update
 * GET  /wp-json/mls/v1/download?l=&s=&p=&e=&sig= → ZIP (signed link, valid 1 hour)
 *
 * @package MarinovLicenseServer
 */

namespace MarinovLicenseServer;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * API.
 */
final class Api {

	/** Failed key lookups per IP and hour before blocking. */
	public const MAX_FAILS = 20;

	/**
	 * Constructor.
	 *
	 * @param Licenses $licenses Licenses.
	 */
	public function __construct( private Licenses $licenses ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Routes (public: authenticated by the license key).
	 */
	public function routes(): void {
		foreach ( array( 'activate', 'deactivate', 'check' ) as $action ) {
			register_rest_route(
				'mls/v1',
				'/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
					'permission_callback' => '__return_true',
				)
			);
		}
		register_rest_route(
			'mls/v1',
			'/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* ------------------------------------------------------------------ endpoints */

	/**
	 * Activate.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate( WP_REST_Request $r ) {
		$blocked = $this->throttle();
		if ( $blocked ) {
			return $blocked;
		}
		$result = $this->licenses->activate( (string) $r['key'], (string) $r['site'], (string) $r['product'], (string) $r['version'] );
		if ( is_wp_error( $result ) ) {
			if ( 'mls_invalid_key' === $result->get_error_code() ) {
				$this->fail();
			}
			return $result;
		}
		return $this->no_store( new WP_REST_Response( $result ) );
	}

	/**
	 * Deactivate.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deactivate( WP_REST_Request $r ) {
		$blocked = $this->throttle();
		if ( $blocked ) {
			return $blocked;
		}
		$ok = $this->licenses->deactivate( (string) $r['key'], (string) $r['site'] );
		if ( ! $ok && ! $this->licenses->find_by_key( (string) $r['key'] ) ) {
			$this->fail();
		}
		return $this->no_store( new WP_REST_Response( array( 'deactivated' => $ok ) ) );
	}

	/**
	 * Daily check: status of this site and update information.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function check( WP_REST_Request $r ) {
		$blocked = $this->throttle();
		if ( $blocked ) {
			return $blocked;
		}
		$product = sanitize_key( (string) $r['product'] );
		$license = $this->licenses->find_by_key( (string) $r['key'] );
		$site    = Licenses::normalize_site( (string) $r['site'] );
		$release = Releases::latest( $product );
		$public  = $release ? Releases::public_info( $release ) : null;

		if ( ! $license || $license['product'] !== $product ) {
			$this->fail();
			return $this->no_store(
				new WP_REST_Response(
					array(
						'valid'  => false,
						'status' => 'invalid',
						'latest' => $public,
					)
				)
			);
		}

		$status = $this->licenses->status( $license, $site );
		if ( 'active' === $status['status'] ) {
			$this->licenses->touch( (int) $license['id'], $site, (string) $r['version'] );
		}
		$status['latest'] = $public;
		// Only valid, activated licenses get a download link.
		if ( $release && $status['valid'] ) {
			$status['package'] = self::download_url( (int) $license['id'], $site, $product );
		}
		return $this->no_store( new WP_REST_Response( $status ) );
	}

	/**
	 * Signed download of the latest release.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_Error|void
	 */
	public function download( WP_REST_Request $r ) {
		$id      = (int) $r['l'];
		$site    = (string) $r['s'];
		$product = sanitize_key( (string) $r['p'] );
		$expires = (int) $r['e'];
		$sig     = (string) $r['sig'];
		if ( $expires < time() || ! hash_equals( self::sign( $id, $site, $product, $expires ), $sig ) ) {
			return new WP_Error( 'mls_link', __( 'This download link has expired. Please check for updates again.', 'marinov-license-server' ), array( 'status' => 403 ) );
		}
		$license = $this->licenses->find( $id );
		if ( ! $license || $license['product'] !== $product || ! Licenses::is_valid( $license ) ) {
			return new WP_Error( 'mls_license', __( 'The license is no longer valid.', 'marinov-license-server' ), array( 'status' => 403 ) );
		}
		$release = Releases::latest( $product );
		$file    = $release ? Releases::path( $release ) : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			return new WP_Error( 'mls_missing', __( 'The file is not available.', 'marinov-license-server' ), array( 'status' => 404 ) );
		}
		/**
		 * Before a release is delivered (statistics, logging).
		 *
		 * @param array<string, mixed> $license License.
		 * @param array<string, mixed> $release Release.
		 * @param string               $site    Site.
		 */
		do_action( 'marinov_license_download', $license, $release, $site );
		if ( defined( 'MARINOV_LICENSE_TESTING' ) ) {
			return new WP_REST_Response( array( 'file' => basename( $file ) ) );
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $product . '-' . $release['version'] . '.zip' ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a protected file.
		exit;
	}

	/* ------------------------------------------------------------------ helpers */

	/**
	 * Signed download link, valid for one hour.
	 *
	 * @param int    $license_id License.
	 * @param string $site       Site.
	 * @param string $product    Product.
	 */
	public static function download_url( int $license_id, string $site, string $product ): string {
		$expires = time() + HOUR_IN_SECONDS;
		return add_query_arg(
			array(
				'l'   => $license_id,
				's'   => rawurlencode( $site ),
				'p'   => $product,
				'e'   => $expires,
				'sig' => self::sign( $license_id, $site, $product, $expires ),
			),
			rest_url( 'mls/v1/download' )
		);
	}

	/**
	 * HMAC signature.
	 *
	 * @param int    $license_id License.
	 * @param string $site       Site.
	 * @param string $product    Product.
	 * @param int    $expires    Unix time.
	 */
	private static function sign( int $license_id, string $site, string $product, int $expires ): string {
		return hash_hmac( 'sha256', $license_id . '|' . $site . '|' . $product . '|' . $expires, wp_salt( 'nonce' ) . 'mls-download' );
	}

	/**
	 * Blocked because of too many wrong keys from this IP?
	 */
	private function throttle(): ?WP_Error {
		if ( (int) get_transient( $this->fail_key() ) >= (int) apply_filters( 'marinov_license_max_fails', self::MAX_FAILS ) ) {
			return new WP_Error( 'mls_throttled', __( 'Too many invalid attempts. Please try again in an hour.', 'marinov-license-server' ), array( 'status' => 429 ) );
		}
		return null;
	}

	/**
	 * Count a failed key lookup.
	 */
	private function fail(): void {
		$key = $this->fail_key();
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	/**
	 * Transient key per client IP (hashed).
	 */
	private function fail_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'mls_fail_' . substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 24 );
	}

	/**
	 * No caching of license answers.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private function no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
