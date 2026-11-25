<?php
/**
 * Where an appointment takes place: at the customer's address, at the office or remotely.
 *
 * Stored per service in settings.location:
 *   type    ''|onsite|office|remote|choice  ('' = not specified, as before)
 *   travel  Fixed travel time in minutes (each way) for "onsite"
 *   info    Office directions or the remote session link / instructions
 *   choices Types the customer may choose from when type = "choice" (Pro)
 *
 * The travel time is blocked before and after the appointment and has to lie within the working hours.
 * Kalendora Pro replaces the fixed travel time with a calculated route (filter kalendora_location_resolve).
 *
 * @package Kalendora
 */

namespace Kalendora\Booking;

use Kalendora\Core\Plugin;
use Kalendora\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Location helpers.
 */
final class Location {

	public const ONSITE = 'onsite';
	public const OFFICE = 'office';
	public const REMOTE = 'remote';
	public const CHOICE = 'choice';

	/**
	 * Results of resolve() in this request.
	 *
	 * @var array<string, array<string, mixed>|WP_Error>
	 */
	private static array $memo = array();

	/**
	 * Location types offered in the service settings (type => label).
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		/**
		 * Location types a service can use. Kalendora Pro adds "choice" (the customer decides).
		 *
		 * @param array<string, string> $types Type => label.
		 */
		return (array) apply_filters(
			'kalendora_location_types',
			array(
				self::ONSITE => __( 'At the customer’s address', 'kalendora' ),
				self::OFFICE => __( 'At our office', 'kalendora' ),
				self::REMOTE => __( 'Remote / online', 'kalendora' ),
			)
		);
	}

	/**
	 * Location settings of a service, cleaned.
	 *
	 * @param array<string, mixed>|null $service Service row.
	 * @return array{type:string, travel:int, info:string, choices:string[]}
	 */
	public static function config( ?array $service ): array {
		$settings = $service['settings'] ?? array();
		$settings = is_array( $settings ) ? $settings : (array) json_decode( (string) $settings, true );
		$raw      = is_array( $settings['location'] ?? null ) ? $settings['location'] : array();
		$types    = self::types();
		$type     = (string) ( $raw['type'] ?? '' );
		$basic    = array( self::ONSITE, self::OFFICE, self::REMOTE );
		$choices  = array_values( array_intersect( $basic, array_map( 'strval', (array) ( $raw['choices'] ?? array() ) ) ) );

		if ( ! isset( $types[ $type ] ) ) {
			$type = '';
		}
		if ( self::CHOICE === $type && count( $choices ) < 2 ) {
			$choices = $basic;
		}

		return array(
			'type'    => $type,
			'travel'  => max( 0, min( 480, (int) ( $raw['travel'] ?? 0 ) ) ),
			'info'    => mb_substr( sanitize_textarea_field( (string) ( $raw['info'] ?? '' ) ), 0, 500 ),
			'choices' => self::CHOICE === $type ? $choices : array(),
		);
	}

	/**
	 * Types the customer can pick for a service ('' = nothing to pick, one fixed type or none).
	 *
	 * @param array<string, mixed>|null $service Service row.
	 * @return string[]
	 */
	public static function options( ?array $service ): array {
		$config = self::config( $service );
		if ( self::CHOICE === $config['type'] ) {
			return $config['choices'];
		}
		return '' === $config['type'] ? array() : array( $config['type'] );
	}

	/**
	 * Work out location, travel time and costs of a booking request.
	 *
	 * @param array<string, mixed>|null $service Service row.
	 * @param string                    $type    Chosen type (only used for "choice").
	 * @param string                    $address Customer address (for "onsite").
	 * @return array{type:string, location:string, travel_before:int, travel_after:int, km:float, cost:float, note:string}|WP_Error
	 */
	public static function resolve( ?array $service, string $type = '', string $address = '' ) {
		$config  = self::config( $service );
		$address = self::clean_address( $address );
		$key     = (int) ( $service['id'] ?? 0 ) . '|' . $type . '|' . strtolower( $address );

		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$actual = $config['type'];
		if ( self::CHOICE === $actual ) {
			$actual = in_array( $type, $config['choices'], true ) ? $type : '';
			if ( '' === $actual ) {
				return new WP_Error( 'kalendora_location_type', __( 'Please choose where the appointment should take place.', 'kalendora' ), array( 'status' => 422 ) );
			}
		}

		$result = array(
			'type'          => $actual,
			'location'      => '',
			'travel_before' => 0,
			'travel_after'  => 0,
			'km'            => 0.0,
			'cost'          => 0.0,
			'note'          => '',
		);

		if ( self::ONSITE === $actual ) {
			if ( mb_strlen( $address ) < 5 ) {
				return new WP_Error( 'kalendora_location_address', __( 'Please enter the address where the appointment should take place.', 'kalendora' ), array( 'status' => 422 ) );
			}
			$result['location']      = $address;
			$result['travel_before'] = $config['travel'];
			$result['travel_after']  = $config['travel'];
		} elseif ( self::OFFICE === $actual ) {
			$result['location'] = (string) self::settings()->get( 'business_address', '' );
		} elseif ( self::REMOTE === $actual ) {
			$result['location'] = __( 'Remote / online', 'kalendora' );
		}

		/**
		 * Location of a booking request: Kalendora Pro calculates the route (travel time, km, costs)
		 * or refuses addresses outside the service area (WP_Error).
		 *
		 * @param array<string, mixed>|WP_Error $result  Result.
		 * @param array<string, mixed>          $service Service row.
		 * @param array<string, mixed>          $config  Location settings of the service.
		 */
		$result = apply_filters( 'kalendora_location_resolve', $result, (array) $service, $config );

		if ( ! is_wp_error( $result ) ) {
			$result['travel_before'] = max( 0, min( 720, (int) $result['travel_before'] ) );
			$result['travel_after']  = max( 0, min( 720, (int) $result['travel_after'] ) );
			$result['km']            = round( max( 0.0, (float) $result['km'] ), 1 );
			$result['cost']          = round( max( 0.0, (float) $result['cost'] ), 2 );
		}

		self::$memo[ $key ] = $result;
		return $result;
	}

	/**
	 * One line, no control characters, limited length.
	 *
	 * @param string $address Address.
	 */
	public static function clean_address( string $address ): string {
		$address = sanitize_text_field( str_replace( array( "\r", "\n" ), ', ', $address ) );
		$address = (string) preg_replace( '/\s*,\s*(,\s*)+/', ', ', $address );
		return trim( mb_substr( $address, 0, 300 ), " ,\t" );
	}

	/**
	 * Label of a type.
	 *
	 * @param string $type Type.
	 */
	public static function label( string $type ): string {
		$labels = array(
			self::ONSITE => __( 'At your address', 'kalendora' ),
			self::OFFICE => __( 'At our office', 'kalendora' ),
			self::REMOTE => __( 'Remote / online', 'kalendora' ),
		);
		return $labels[ $type ] ?? '';
	}

	/**
	 * Google Maps search link for an address (no API, opens the map).
	 *
	 * @param string $address Address.
	 */
	public static function maps_url( string $address ): string {
		return '' === trim( $address ) ? '' : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $address );
	}

	/**
	 * Description of the location of a booking for emails and calendars.
	 *
	 * @param array<string, mixed>      $booking Booking (location_type, location, travel_cost, travel_km).
	 * @param array<string, mixed>|null $service Service row.
	 * @return array{label:string, place:string, link:string, info:string, cost:float, km:float}
	 */
	public static function describe( array $booking, ?array $service ): array {
		$type   = (string) ( $booking['location_type'] ?? '' );
		$config = self::config( $service );
		$place  = (string) ( $booking['location'] ?? '' );
		$link   = '';
		$info   = '';

		if ( self::ONSITE === $type || self::OFFICE === $type ) {
			$link = self::maps_url( $place );
		}
		if ( self::OFFICE === $type || self::REMOTE === $type ) {
			$info = $config['info'];
			if ( self::REMOTE === $type && preg_match( '#https?://\S+#', $info, $m ) ) {
				$link = esc_url_raw( rtrim( $m[0], '.,;)' ) );
			}
		}
		if ( self::REMOTE === $type ) {
			$place = '';
		}

		return array(
			'label' => self::label( $type ),
			'place' => $place,
			'link'  => $link,
			'info'  => $info,
			'cost'  => (float) ( $booking['travel_cost'] ?? 0 ),
			'km'    => (float) ( $booking['travel_km'] ?? 0 ),
		);
	}

	/**
	 * Settings.
	 */
	private static function settings(): Settings {
		return Plugin::instance()->container()->get( Settings::class );
	}
}
