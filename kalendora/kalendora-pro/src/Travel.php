<?php
/**
 * Travel time, distance and travel costs for appointments at the customer's address.
 *
 * The route from the business address to the customer is calculated with OpenRouteService
 * (free key, servers in Germany) or the Google Routes API. Results are cached for 30 days.
 * The travel time is blocked before and after the appointment (there and back), the travel
 * costs are added to the booking (and to the online payment, if active).
 *
 * Also: service area (max. km and/or postcodes) and "customer chooses the location".
 *
 * @package KalendoraPro
 */

namespace KalendoraPro;

use Kalendora\Booking\Location;
use Kalendora\Core\Plugin as Kalendora;
use Kalendora\Security\Crypto;
use Kalendora\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Travel calculation.
 */
final class Travel {

	public const OPTION = 'kalendora_pro_travel';

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_filter( 'kalendora_location_types', array( $this, 'types' ) );
		add_filter( 'kalendora_location_resolve', array( $this, 'resolve' ), 10, 3 );
		add_filter( 'kalendora_travel_auto', static fn() => (bool) self::provider() );
	}

	/**
	 * "Customer chooses" as an additional location type.
	 *
	 * @param array<string, string> $types Types.
	 * @return array<string, string>
	 */
	public function types( array $types ): array {
		$types[ Location::CHOICE ] = __( 'The customer chooses (on site, office or remote)', 'kalendora-pro' );
		return $types;
	}

	/* ------------------------------------------------------------------ settings */

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'provider'  => 'off',
			'api_key'   => '',
			'origin'    => '',
			'country'   => 'DE',
			'price_km'  => 0.30,
			'charge'    => 'round',
			'free_km'   => 0,
			'max_km'    => 0,
			'postcodes' => '',
			'extra'     => 10,
			'round_to'  => 5,
		);
	}

	/**
	 * Settings with decrypted key.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$s      = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		if ( '' !== (string) $s['api_key'] ) {
			$s['api_key'] = (string) Kalendora::instance()->container()->get( Crypto::class )->decrypt( (string) $s['api_key'] );
		}
		return $s;
	}

	/**
	 * Save from the admin form (empty key field keeps the stored key).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return true|WP_Error
	 */
	public static function save( array $input ) {
		$current        = self::settings();
		$number         = static fn( $v ): float => (float) str_replace( ',', '.', (string) $v );
		$out            = array(
			'provider'  => in_array( $input['provider'] ?? '', array( 'off', 'ors', 'google' ), true ) ? $input['provider'] : 'off',
			'origin'    => mb_substr( sanitize_text_field( (string) ( $input['origin'] ?? '' ) ), 0, 255 ),
			'country'   => strtoupper( mb_substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $input['country'] ?? 'DE' ) ), 0, 2 ) ),
			'price_km'  => max( 0, min( 100, round( $number( $input['price_km'] ?? 0 ), 2 ) ) ),
			'charge'    => 'oneway' === ( $input['charge'] ?? '' ) ? 'oneway' : 'round',
			'free_km'   => max( 0, min( 10000, round( $number( $input['free_km'] ?? 0 ), 1 ) ) ),
			'max_km'    => max( 0, min( 10000, round( $number( $input['max_km'] ?? 0 ), 1 ) ) ),
			'postcodes' => implode( ', ', self::postcode_rules( (string) ( $input['postcodes'] ?? '' ) ) ),
			'extra'     => max( 0, min( 100, absint( $input['extra'] ?? 0 ) ) ),
			'round_to'  => in_array( (int) ( $input['round_to'] ?? 5 ), array( 1, 5, 10, 15 ), true ) ? (int) ( $input['round_to'] ?? 5 ) : 5,
		);
		$key            = trim( sanitize_text_field( (string) ( $input['api_key'] ?? '' ) ) );
		$out['api_key'] = '' === $key ? (string) $current['api_key'] : mb_substr( $key, 0, 300 );
		if ( ! empty( $input['api_key_remove'] ) ) {
			$out['api_key'] = '';
		}
		if ( 'off' !== $out['provider'] && '' === $out['api_key'] ) {
			return new WP_Error( 'kalendora_pro_travel_key', __( 'Please enter the API key of the route service.', 'kalendora-pro' ) );
		}
		if ( 'off' !== $out['provider'] && '' === self::origin( $out ) ) {
			return new WP_Error( 'kalendora_pro_travel_origin', __( 'Please enter the starting address (or your business address under Kalendora → Settings).', 'kalendora-pro' ) );
		}
		$out['api_key'] = '' === $out['api_key'] ? '' : Kalendora::instance()->container()->get( Crypto::class )->encrypt( $out['api_key'] );
		update_option( self::OPTION, $out, false );
		return true;
	}

	/**
	 * Active provider ('' = off or not configured).
	 *
	 * @param array<string, mixed>|null $s Settings.
	 */
	public static function provider( ?array $s = null ): string {
		$s = $s ?? self::settings();
		return 'off' !== $s['provider'] && '' !== (string) $s['api_key'] ? (string) $s['provider'] : '';
	}

	/**
	 * Starting point: own address or the business address.
	 *
	 * @param array<string, mixed> $s Settings.
	 */
	public static function origin( array $s ): string {
		$origin = trim( (string) $s['origin'] );
		return '' !== $origin ? $origin : trim( (string) Kalendora::instance()->container()->get( Settings::class )->get( 'business_address', '' ) );
	}

	/**
	 * Postcode rules: "10115, 12*" → exact postcode or prefix with *.
	 *
	 * @param string $raw Input.
	 * @return string[]
	 */
	public static function postcode_rules( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', $raw ) as $rule ) {
			$rule = strtoupper( trim( (string) $rule ) );
			if ( preg_match( '/^[A-Z0-9]{1,8}\*?$/', $rule ) ) {
				$out[] = $rule;
			}
		}
		return array_values( array_unique( array_slice( $out, 0, 500 ) ) );
	}

	/**
	 * Whether an address lies in the postcode area (no rules = everywhere).
	 *
	 * @param string   $address Address.
	 * @param string[] $rules   Rules.
	 */
	public static function in_postcodes( string $address, array $rules ): bool {
		if ( ! $rules ) {
			return true;
		}
		if ( ! preg_match_all( '/\b(\d{4,5})\b/', $address, $m ) ) {
			return false;
		}
		foreach ( $m[1] as $code ) {
			foreach ( $rules as $rule ) {
				if ( str_ends_with( $rule, '*' ) ? str_starts_with( $code, rtrim( $rule, '*' ) ) : $code === $rule ) {
					return true;
				}
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ calculation */

	/**
	 * Location filter of Kalendora: route, costs, service area.
	 *
	 * @param array<string, mixed>|WP_Error $result  Result.
	 * @param array<string, mixed>          $service Service.
	 * @param array<string, mixed>          $config  Location settings of the service.
	 * @return array<string, mixed>|WP_Error
	 */
	public function resolve( $result, array $service, array $config ) {
		unset( $service );
		if ( is_wp_error( $result ) || Location::ONSITE !== $result['type'] ) {
			return $result;
		}
		$s     = self::settings();
		$rules = self::postcode_rules( (string) $s['postcodes'] );
		if ( ! self::in_postcodes( (string) $result['location'], $rules ) ) {
			return new WP_Error( 'kalendora_pro_area', __( 'Sorry, this address is outside our service area. Please contact us directly.', 'kalendora-pro' ), array( 'status' => 422 ) );
		}
		if ( ! self::provider( $s ) ) {
			return $result;
		}

		$route = $this->route( (string) $result['location'], $s );
		if ( is_wp_error( $route ) ) {
			// Address not found or too many requests: tell the visitor. Service down: keep the fixed travel time.
			return 'kalendora_pro_route_http' === $route->get_error_code() ? $result : $route;
		}

		$minutes = self::minutes( $route['seconds'], $s );
		$km      = round( $route['meters'] / 1000, 1 );
		if ( (float) $s['max_km'] > 0 && $km > (float) $s['max_km'] ) {
			return new WP_Error( 'kalendora_pro_area', __( 'Sorry, this address is outside our service area. Please contact us directly.', 'kalendora-pro' ), array( 'status' => 422 ) );
		}

		$result['travel_before'] = max( $minutes, 0 );
		$result['travel_after']  = max( $minutes, 0 );
		$result['km']            = $km;
		$result['cost']          = self::cost( $km, $s );
		unset( $config );
		return $result;
	}

	/**
	 * Travel minutes: driving time plus surcharge, rounded up.
	 *
	 * @param int                  $seconds Seconds.
	 * @param array<string, mixed> $s       Settings.
	 */
	public static function minutes( int $seconds, array $s ): int {
		$minutes = $seconds / 60 * ( 1 + (int) $s['extra'] / 100 );
		$step    = max( 1, (int) $s['round_to'] );
		return (int) ( ceil( $minutes / $step ) * $step );
	}

	/**
	 * Travel costs: km (there and back or one way) minus free km, times price per km.
	 *
	 * @param float                $km One way.
	 * @param array<string, mixed> $s  Settings.
	 */
	public static function cost( float $km, array $s ): float {
		$charged = ( 'round' === $s['charge'] ? 2 * $km : $km ) - (float) $s['free_km'];
		return round( max( 0.0, $charged ) * (float) $s['price_km'], 2 );
	}

	/**
	 * Route from the starting point to an address (cached).
	 *
	 * @param string               $address Destination.
	 * @param array<string, mixed> $s       Settings.
	 * @return array{seconds:int, meters:int}|WP_Error
	 */
	public function route( string $address, array $s ) {
		$origin = self::origin( $s );
		$key    = 'kalendora_pro_route_' . md5( $s['provider'] . '|' . strtolower( $origin ) . '|' . strtolower( $address ) . '|' . $s['country'] );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return isset( $cached['error'] ) ? new WP_Error( 'kalendora_pro_route_missing', (string) $cached['error'], array( 'status' => 422 ) ) : $cached;
		}

		// Every uncached lookup costs a request at the route service: limit per visitor.
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$limit = 'kalendora_pro_route_ip_' . md5( $ip . wp_salt( 'nonce' ) );
		$count = (int) get_transient( $limit );
		/**
		 * Uncached route lookups per visitor and hour.
		 *
		 * @param int $max Max.
		 */
		if ( ! current_user_can( 'manage_options' ) && $count >= (int) apply_filters( 'kalendora_pro_route_limit', 20 ) ) {
			return new WP_Error( 'kalendora_pro_route_limit', __( 'Too many address lookups. Please try again later or contact us directly.', 'kalendora-pro' ), array( 'status' => 429 ) );
		}
		set_transient( $limit, $count + 1, HOUR_IN_SECONDS );

		$route = 'google' === $s['provider'] ? $this->google( $origin, $address, $s ) : $this->ors( $origin, $address, $s );
		if ( ! is_wp_error( $route ) ) {
			set_transient( $key, $route, 30 * DAY_IN_SECONDS );
		} elseif ( 'kalendora_pro_route_missing' === $route->get_error_code() ) {
			set_transient( $key, array( 'error' => $route->get_error_message() ), DAY_IN_SECONDS );
		}
		return $route;
	}

	/**
	 * OpenRouteService: geocode both addresses, then driving route.
	 *
	 * @param string               $origin  Origin.
	 * @param string               $address Destination.
	 * @param array<string, mixed> $s       Settings.
	 * @return array{seconds:int, meters:int}|WP_Error
	 */
	private function ors( string $origin, string $address, array $s ) {
		$from = $this->ors_geocode( $origin, $s );
		if ( is_wp_error( $from ) ) {
			return 'kalendora_pro_route_missing' === $from->get_error_code() ? new WP_Error( 'kalendora_pro_route_http', __( 'The starting address could not be found.', 'kalendora-pro' ) ) : $from;
		}
		$to = $this->ors_geocode( $address, $s );
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		$data = $this->request(
			'https://api.openrouteservice.org/v2/directions/driving-car',
			array(
				'Authorization' => (string) $s['api_key'],
				'Content-Type'  => 'application/json',
			),
			(string) wp_json_encode( array( 'coordinates' => array( $from, $to ) ) )
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$summary = $data['routes'][0]['summary'] ?? null;
		if ( ! is_array( $summary ) ) {
			return self::missing();
		}
		return array(
			'seconds' => (int) round( (float) ( $summary['duration'] ?? 0 ) ),
			'meters'  => (int) round( (float) ( $summary['distance'] ?? 0 ) ),
		);
	}

	/**
	 * OpenRouteService geocoding (cached).
	 *
	 * @param string               $address Address.
	 * @param array<string, mixed> $s       Settings.
	 * @return float[]|WP_Error [lon, lat]
	 */
	private function ors_geocode( string $address, array $s ) {
		$key    = 'kalendora_pro_geo_' . md5( strtolower( $address ) . '|' . $s['country'] );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$query = array(
			'api_key' => (string) $s['api_key'],
			'text'    => $address,
			'size'    => 1,
			'layers'  => 'address,street,venue,postalcode,locality',
		);
		if ( '' !== (string) $s['country'] ) {
			$query['boundary.country'] = (string) $s['country'];
		}
		$data = $this->request( add_query_arg( array_map( 'rawurlencode', $query ), 'https://api.openrouteservice.org/geocode/search' ), array(), null );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$point = $data['features'][0]['geometry']['coordinates'] ?? null;
		if ( ! is_array( $point ) || count( $point ) < 2 ) {
			return self::missing();
		}
		$point = array( (float) $point[0], (float) $point[1] );
		set_transient( $key, $point, 30 * DAY_IN_SECONDS );
		return $point;
	}

	/**
	 * Google Routes API (addresses are geocoded by Google).
	 *
	 * @param string               $origin  Origin.
	 * @param string               $address Destination.
	 * @param array<string, mixed> $s       Settings.
	 * @return array{seconds:int, meters:int}|WP_Error
	 */
	private function google( string $origin, string $address, array $s ) {
		$data = $this->request(
			'https://routes.googleapis.com/directions/v2:computeRoutes',
			array(
				'X-Goog-Api-Key'   => (string) $s['api_key'],
				'X-Goog-FieldMask' => 'routes.duration,routes.distanceMeters',
				'Content-Type'     => 'application/json',
			),
			(string) wp_json_encode(
				array(
					'origin'            => array( 'address' => $origin ),
					'destination'       => array( 'address' => $address ),
					'travelMode'        => 'DRIVE',
					'routingPreference' => 'TRAFFIC_UNAWARE',
					'languageCode'      => 'de',
					'regionCode'        => strtolower( (string) $s['country'] ),
				)
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$route = $data['routes'][0] ?? null;
		if ( ! is_array( $route ) || ! isset( $route['distanceMeters'] ) ) {
			return self::missing();
		}
		return array(
			'seconds' => (int) rtrim( (string) ( $route['duration'] ?? '0s' ), 's' ),
			'meters'  => (int) $route['distanceMeters'],
		);
	}

	/**
	 * HTTP request (GET without body, POST with JSON body).
	 *
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Headers.
	 * @param string|null           $body    JSON body.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( string $url, array $headers, ?string $body ) {
		$args     = array(
			'timeout' => 10,
			'headers' => $headers + array( 'Accept' => 'application/json' ),
		);
		$response = null === $body ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args + array( 'body' => $body ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'kalendora_pro_route_http', __( 'The route service could not be reached.', 'kalendora-pro' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		// 404 from the directions endpoint: no route between the points (e.g. island without ferry).
		if ( 404 === $code ) {
			return self::missing();
		}
		if ( $code >= 400 || ! is_array( $data ) ) {
			$err = is_array( $data ) ? ( $data['error'] ?? '' ) : '';
			$msg = is_array( $err ) ? (string) ( $err['message'] ?? '' ) : (string) $err;
			return new WP_Error( 'kalendora_pro_route_http', sprintf( /* translators: %s: error from the route service. */ __( 'Route service: %s', 'kalendora-pro' ), '' !== $msg ? $msg : (string) $code ) );
		}
		return $data;
	}

	/**
	 * Address not found.
	 */
	private static function missing(): WP_Error {
		return new WP_Error( 'kalendora_pro_route_missing', __( 'The address could not be found. Please check street, postcode and town.', 'kalendora-pro' ), array( 'status' => 422 ) );
	}
}
