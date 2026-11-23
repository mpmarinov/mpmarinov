<?php
/**
 * Connects the slot calculator with the database.
 *
 * @package Bookora
 */

namespace Bookora\Availability;

use Bookora\Repository\BookingRepository;
use Bookora\Repository\ResourceRepository;
use Bookora\Repository\ScheduleRepository;
use Bookora\Repository\ServiceRepository;
use Bookora\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "which times are free?" for a service, a specific resource or any resource.
 */
final class AvailabilityService {

	/**
	 * Maximum number of days per availability request.
	 */
	public const MAX_RANGE_DAYS = 62;

	/**
	 * Constructor.
	 *
	 * @param ServiceRepository  $services  Services.
	 * @param ResourceRepository $resources Resources.
	 * @param ScheduleRepository $schedules Schedules.
	 * @param BookingRepository  $bookings  Bookings.
	 * @param Settings           $settings  Settings.
	 */
	public function __construct(
		private ServiceRepository $services,
		private ResourceRepository $resources,
		private ScheduleRepository $schedules,
		private BookingRepository $bookings,
		private Settings $settings,
	) {}

	/**
	 * Free slots of a service between two local dates.
	 *
	 * @param int      $service_id  Service id.
	 * @param int|null $resource_id Specific resource, or null for "any available".
	 * @param string   $from        Y-m-d.
	 * @param string   $to          Y-m-d.
	 * @param int      $seats       Requested seats.
	 * @return array<int, array{start:int,end:int,available:int,resources:int[]}>|WP_Error
	 */
	public function slots( int $service_id, ?int $resource_id, string $from, string $to, int $seats = 1 ) {
		$service = $this->services->find( $service_id );
		if ( ! $service || 'active' !== $service['status'] ) {
			return new WP_Error( 'bookora_unknown_service', __( 'This service is not available.', 'bookora' ), array( 'status' => 404 ) );
		}

		$range = $this->validate_range( $from, $to );
		if ( is_wp_error( $range ) ) {
			return $range;
		}

		$candidates = $this->candidate_resources( $service_id, $resource_id );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		$rules  = SlotRules::from_service( $service, $this->settings->all(), $seats );
		$now    = time();
		$merged = array();

		foreach ( $candidates as $candidate ) {
			$calc = $this->calculator( $service_id, $candidate, $range[0] - DAY_IN_SECONDS, $range[1] + 2 * DAY_IN_SECONDS );

			foreach ( $calc->slots( $from, $to, $rules, $now ) as $slot ) {
				$key = $slot['start'];
				if ( ! isset( $merged[ $key ] ) ) {
					$merged[ $key ] = $slot + array( 'resources' => array() );
				} else {
					$merged[ $key ]['available'] = max( $merged[ $key ]['available'], $slot['available'] );
				}
				if ( null !== $candidate ) {
					$merged[ $key ]['resources'][] = $candidate;
				}
			}
		}

		ksort( $merged );
		return array_values( $merged );
	}

	/**
	 * Opening days and possible start times of the business (no bookings considered).
	 * Used by plain date fields with "working days only" in standard forms.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<string, string[]>|WP_Error Local date => list of H:i.
	 */
	public function opening_days( string $from, string $to ) {
		$range = $this->validate_range( $from, $to );
		if ( is_wp_error( $range ) ) {
			return $range;
		}

		$settings = $this->settings->all();
		$interval = max( 5, (int) $settings['slot_interval'] );
		$rules    = new SlotRules( $interval, $interval, 0, 0, 1, (int) $settings['min_notice_minutes'], (int) $settings['max_advance_days'] );
		$tz       = wp_timezone();
		$calc     = new SlotCalculator( $tz, $this->schedules->weekly( null ), $this->schedules->exceptions( null, $from, $to ) );
		$days     = array();

		foreach ( $calc->slots( $from, $to, $rules, time() ) as $slot ) {
			$local = ( new DateTimeImmutable( '@' . $slot['start'] ) )->setTimezone( $tz );

			$days[ $local->format( 'Y-m-d' ) ][] = $local->format( 'H:i' );
		}

		return $days;
	}

	/**
	 * Build a calculator for one resource (null = business-wide).
	 *
	 * @param int      $service_id  Service id.
	 * @param int|null $resource_id Resource id.
	 * @param int      $from        UTC timestamp for busy lookup.
	 * @param int      $to          UTC timestamp for busy lookup.
	 * @param int      $exclude_id  Booking to ignore.
	 */
	public function calculator( int $service_id, ?int $resource_id, int $from, int $to, int $exclude_id = 0 ): SlotCalculator {
		$tz = $this->timezone( $resource_id );

		return new SlotCalculator(
			$tz,
			$this->schedules->weekly( $resource_id ),
			$this->schedules->exceptions( $resource_id ),
			$this->bookings->busy( $resource_id, $service_id, $from, $to, $exclude_id ),
			$this->bookings->day_counts( $resource_id, $service_id, $from, $to, $tz )
		);
	}

	/**
	 * Resources that may serve the booking. [null] means the service runs without resources.
	 *
	 * @param int      $service_id  Service id.
	 * @param int|null $resource_id Requested resource.
	 * @return array<int, int|null>|WP_Error
	 */
	public function candidate_resources( int $service_id, ?int $resource_id ) {
		$assigned = $this->services->resource_ids( $service_id );

		if ( $assigned ) {
			$active = array_column( $this->resources->all( array( 'status' => 'active' ) ), 'id' );
			$usable = array_values( array_intersect( $assigned, $active ) );

			if ( null !== $resource_id ) {
				if ( ! in_array( $resource_id, $usable, true ) ) {
					return new WP_Error( 'bookora_unknown_resource', __( 'The selected staff member or resource is not available for this service.', 'bookora' ), array( 'status' => 400 ) );
				}
				return array( $resource_id );
			}

			return $usable ? $usable : new WP_Error( 'bookora_no_resource', __( 'No staff member or resource is available for this service.', 'bookora' ), array( 'status' => 409 ) );
		}

		return array( null );
	}

	/**
	 * Timezone of a resource, falling back to the site timezone.
	 *
	 * @param int|null $resource_id Resource id.
	 */
	public function timezone( ?int $resource_id ): DateTimeZone {
		if ( null !== $resource_id ) {
			$resource = $this->resources->find( $resource_id );
			if ( $resource && '' !== $resource['timezone'] ) {
				return new DateTimeZone( $resource['timezone'] );
			}
		}
		return wp_timezone();
	}

	/**
	 * Validate a date range and return UTC bounds.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array{0:int,1:int}|WP_Error
	 */
	private function validate_range( string $from, string $to ) {
		$tz = wp_timezone();
		$f  = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $tz );
		$t  = DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $tz );

		if ( ! $f || ! $t || $f->format( 'Y-m-d' ) !== $from || $t->format( 'Y-m-d' ) !== $to || $t < $f ) {
			return new WP_Error( 'bookora_invalid_range', __( 'Invalid date range.', 'bookora' ), array( 'status' => 400 ) );
		}

		if ( $f->diff( $t )->days >= self::MAX_RANGE_DAYS ) {
			return new WP_Error( 'bookora_range_too_long', __( 'The requested date range is too long.', 'bookora' ), array( 'status' => 400 ) );
		}

		return array( $f->getTimestamp(), $t->getTimestamp() );
	}
}
