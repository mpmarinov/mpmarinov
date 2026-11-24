<?php
/**
 * License client: activate/deactivate on the Marinov license server, daily check, updates.
 *
 * Fair rule: if the license expires, all Pro features keep working – only updates and
 * support stop. Nothing on the site is switched off.
 *
 * @package BookoraPro
 */

namespace BookoraPro;

defined( 'ABSPATH' ) || exit;

/**
 * License and updates.
 */
final class License {

	public const OPTION  = 'bookora_pro_license';
	public const PRODUCT = 'bookora-pro';
	public const CRON    = 'bookora_pro_license_check';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( self::CRON, array( $this, 'check' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON );
		}
	}

	/**
	 * License server (filter for tests and own installations).
	 */
	public static function server(): string {
		return untrailingslashit( (string) apply_filters( 'bookora_pro_license_server', defined( 'BOOKORA_PRO_SERVER' ) ? BOOKORA_PRO_SERVER : 'https://mpmarinov.com' ) );
	}

	/**
	 * REST base of the license API (filter for servers without pretty permalinks).
	 */
	public static function api_base(): string {
		return (string) apply_filters( 'bookora_pro_license_api', self::server() . '/wp-json/mls/v1/' );
	}

	/**
	 * Stored state.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		$data = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'key'        => '',
				'status'     => 'inactive',
				'expires'    => null,
				'sites_max'  => 0,
				'sites_used' => 0,
				'checked_at' => 0,
				'latest'     => null,
				'package'    => '',
				'error'      => '',
			),
			is_array( $data ) ? $data : array()
		);
	}

	/**
	 * Save state.
	 *
	 * @param array<string, mixed> $changes Changes.
	 */
	private static function save( array $changes ): void {
		update_option( self::OPTION, array_merge( self::data(), $changes ), false );
	}

	/**
	 * Key entered and confirmed by the server?
	 */
	public static function is_active(): bool {
		return 'active' === self::data()['status'];
	}

	/**
	 * Activate this website.
	 *
	 * @param string $key License key.
	 * @return true|\WP_Error
	 */
	public function activate( string $key ) {
		$key    = strtoupper( trim( $key ) );
		$result = $this->call( 'activate', array( 'key' => $key ) );
		if ( is_wp_error( $result ) ) {
			self::save( array( 'error' => $result->get_error_message() ) );
			return $result;
		}
		self::save(
			array(
				'key'        => $key,
				'status'     => (string) ( $result['status'] ?? 'inactive' ),
				'expires'    => $result['expires'] ?? null,
				'sites_max'  => (int) ( $result['sites_max'] ?? 0 ),
				'sites_used' => (int) ( $result['sites_used'] ?? 0 ),
				'error'      => '',
			)
		);
		$this->check();
		return true;
	}

	/**
	 * Free this website's slot.
	 *
	 * @return true|\WP_Error
	 */
	public function deactivate() {
		$data = self::data();
		if ( '' !== $data['key'] ) {
			$result = $this->call( 'deactivate', array( 'key' => $data['key'] ) );
			if ( is_wp_error( $result ) && 'bookora_pro_http' === $result->get_error_code() ) {
				return $result;
			}
		}
		delete_option( self::OPTION );
		delete_site_transient( 'update_plugins' );
		return true;
	}

	/**
	 * Daily check: status and newest version.
	 */
	public function check(): void {
		$data = self::data();
		if ( '' === $data['key'] ) {
			return;
		}
		$result = $this->call( 'check', array( 'key' => $data['key'] ) );
		if ( is_wp_error( $result ) ) {
			// Server not reachable: keep the last known state.
			self::save( array( 'checked_at' => time() ) );
			return;
		}
		self::save(
			array(
				'status'     => (string) ( $result['status'] ?? 'inactive' ),
				'expires'    => $result['expires'] ?? null,
				'sites_max'  => (int) ( $result['sites_max'] ?? 0 ),
				'sites_used' => (int) ( $result['sites_used'] ?? 0 ),
				'latest'     => is_array( $result['latest'] ?? null ) ? $result['latest'] : null,
				'package'    => (string) ( $result['package'] ?? '' ),
				'checked_at' => time(),
				'error'      => '',
			)
		);
	}

	/**
	 * WordPress update check: offer the new version if the license is valid.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$data = self::data();
		if ( '' !== $data['key'] && time() - (int) $data['checked_at'] > 12 * HOUR_IN_SECONDS ) {
			$this->check();
			$data = self::data();
		}
		$latest = $data['latest'];
		if ( ! is_array( $latest ) || ! version_compare( (string) $latest['version'], BOOKORA_PRO_VERSION, '>' ) ) {
			return $transient;
		}
		$transient->response[ BOOKORA_PRO_BASENAME ] = (object) array(
			'slug'         => self::PRODUCT,
			'plugin'       => BOOKORA_PRO_BASENAME,
			'new_version'  => (string) $latest['version'],
			'url'          => 'https://mpmarinov.com/bookora',
			// Without a valid license there is no package: WordPress shows the update but asks to renew.
			'package'      => (string) $data['package'],
			'requires'     => (string) ( $latest['requires'] ?? '' ),
			'tested'       => (string) ( $latest['tested'] ?? '' ),
			'requires_php' => (string) ( $latest['requires_php'] ?? '' ),
		);
		return $transient;
	}

	/**
	 * "View details" dialog in the plugin list.
	 *
	 * @param mixed  $result Result.
	 * @param string $action Action.
	 * @param object $args   Args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::PRODUCT !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$latest = self::data()['latest'];
		return (object) array(
			'name'          => 'Bookora Pro',
			'slug'          => self::PRODUCT,
			'version'       => is_array( $latest ) ? (string) $latest['version'] : BOOKORA_PRO_VERSION,
			'author'        => '<a href="https://mpmarinov.com">Marinov Solutions</a>',
			'homepage'      => 'https://mpmarinov.com/bookora',
			'requires'      => is_array( $latest ) ? (string) ( $latest['requires'] ?? '' ) : '',
			'tested'        => is_array( $latest ) ? (string) ( $latest['tested'] ?? '' ) : '',
			'requires_php'  => is_array( $latest ) ? (string) ( $latest['requires_php'] ?? '' ) : '',
			'last_updated'  => is_array( $latest ) ? (string) ( $latest['date'] ?? '' ) : '',
			'sections'      => array(
				'changelog' => is_array( $latest ) ? wp_kses_post( wpautop( (string) ( $latest['changelog'] ?? '' ) ) ) : '',
			),
			'download_link' => (string) self::data()['package'],
		);
	}

	/**
	 * Call the license server.
	 *
	 * @param string               $endpoint activate|deactivate|check.
	 * @param array<string, mixed> $body     Body.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function call( string $endpoint, array $body ) {
		$response = wp_remote_post(
			self::api_base() . $endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => (string) wp_json_encode(
					$body + array(
						'site'    => home_url( '/' ),
						'product' => self::PRODUCT,
						'version' => BOOKORA_PRO_VERSION,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'bookora_pro_http', __( 'The license server could not be reached. Please try again later.', 'bookora-pro' ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! is_array( $data ) ) {
			return new \WP_Error( 'bookora_pro_license', is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : __( 'The license could not be checked.', 'bookora-pro' ) );
		}
		return $data;
	}
}
