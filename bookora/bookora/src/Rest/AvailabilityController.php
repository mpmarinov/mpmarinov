<?php
/**
 * Public REST endpoint for free time slots.
 *
 * @package Bookora
 */

namespace Bookora\Rest;

use Bookora\Availability\AvailabilityService;
use Bookora\Repository\ServiceRepository;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /bookora/v1/availability?service=1&from=2026-12-01&to=2026-12-07[&resource=2][&seats=1][&tz=Europe/Berlin]
 * GET /bookora/v1/public/services
 *
 * Public by design (the booking form needs it); only non-sensitive data is returned.
 */
final class AvailabilityController {

	/**
	 * Constructor.
	 *
	 * @param AvailabilityService $availability Availability.
	 * @param ServiceRepository   $services     Services.
	 */
	public function __construct(
		private AvailabilityService $availability,
		private ServiceRepository $services,
	) {}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			SettingsController::NAMESPACE,
			'/availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'service'  => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'resource' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'from'     => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\d{4}-\d{2}-\d{2}$',
					),
					'to'       => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\d{4}-\d{2}-\d{2}$',
					),
					'seats'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 1,
					),
					'tz'       => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			SettingsController::NAMESPACE,
			'/public/opening',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'opening' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'from' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\\d{4}-\\d{2}-\\d{2}$',
					),
					'to'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\\d{4}-\\d{2}-\\d{2}$',
					),
				),
			)
		);

		register_rest_route(
			SettingsController::NAMESPACE,
			'/public/services',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'services' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Free slots, grouped by local date of the visitor's timezone.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function availability( WP_REST_Request $request ) {
		$resource = (int) $request['resource'];
		$slots    = $this->availability->slots( (int) $request['service'], $resource ? $resource : null, (string) $request['from'], (string) $request['to'], (int) $request['seats'] );

		if ( is_wp_error( $slots ) ) {
			return $slots;
		}

		$tz_name = (string) $request['tz'];
		$tz      = in_array( $tz_name, timezone_identifiers_list(), true ) ? new DateTimeZone( $tz_name ) : wp_timezone();
		$days    = array();

		foreach ( $slots as $slot ) {
			$local = ( new DateTimeImmutable( '@' . $slot['start'] ) )->setTimezone( $tz );

			$days[ $local->format( 'Y-m-d' ) ][] = array(
				'start'     => gmdate( 'c', $slot['start'] ),
				'end'       => gmdate( 'c', $slot['end'] ),
				'time'      => $local->format( 'H:i' ),
				'available' => $slot['available'],
				'resources' => $slot['resources'],
			);
		}

		$response = new WP_REST_Response(
			array(
				'timezone' => $tz->getName(),
				'days'     => (object) $days,
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Opening days and times of the business.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function opening( WP_REST_Request $request ) {
		$days = $this->availability->opening_days( (string) $request['from'], (string) $request['to'] );
		return is_wp_error( $days ) ? $days : new WP_REST_Response( array( 'days' => (object) $days ) );
	}

	/**
	 * Active services with public fields only.
	 */
	public function services(): WP_REST_Response {
		$out = array();
		foreach ( $this->services->all( array( 'status' => 'active' ) ) as $service ) {
			$out[] = array(
				'id'          => $service['id'],
				'name'        => $service['name'],
				'description' => $service['description'],
				'duration'    => $service['duration'],
				'price'       => $service['price'],
				'color'       => $service['color'],
				'capacity'    => $service['capacity'],
			);
		}
		return new WP_REST_Response( $out );
	}
}
