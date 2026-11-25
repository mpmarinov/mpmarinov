<?php
/**
 * Booking queries.
 *
 * @package Kalendora
 */

namespace Kalendora\Repository;

use Kalendora\Availability\SlotCalculator;
use Kalendora\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Read access for bookings and busy times. Writes happen in BookingService inside a lock.
 */
final class BookingRepository {

	/**
	 * Statuses that occupy a time slot.
	 */
	public const BLOCKING_STATUSES = array( 'pending', 'confirmed' );

	/**
	 * Busy intervals for a resource between two UTC timestamps.
	 *
	 * Bookings of the same service count their seats (group appointments);
	 * bookings of other services and external calendar events block the resource completely.
	 * Each booking is expanded by the buffers of its own service.
	 *
	 * @param int|null $resource_id Resource id or null for the business-wide calendar.
	 * @param int      $service_id  Service that is being booked.
	 * @param int      $from        UTC timestamp.
	 * @param int      $to          UTC timestamp.
	 * @param int      $exclude_id  Booking to ignore (when moving it).
	 * @return array<int, array{start:int,end:int,seats:int}>
	 */
	public function busy( ?int $resource_id, int $service_id, int $from, int $to, int $exclude_id = 0 ): array {
		global $wpdb;

		$bookings    = Schema::table( $wpdb->prefix, 'bookings' );
		$services    = Schema::table( $wpdb->prefix, 'services' );
		$events      = Schema::table( $wpdb->prefix, 'external_events' );
		$connections = Schema::table( $wpdb->prefix, 'calendar_connections' );

		// Widen the window by one day so long buffers of neighbouring bookings are included.
		$from_sql = gmdate( 'Y-m-d H:i:s', $from - DAY_IN_SECONDS );
		$to_sql   = gmdate( 'Y-m-d H:i:s', $to + DAY_IN_SECONDS );
		$statuses = "'" . implode( "','", self::BLOCKING_STATUSES ) . "'";
		$res_sql  = null === $resource_id ? 'IS NULL' : '= ' . (int) $resource_id;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names, integer/constant values only.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.start_utc, b.end_utc, b.seats, b.service_id,
					COALESCE(s.buffer_before, 0) + b.travel_before AS buffer_before, COALESCE(s.buffer_after, 0) + b.travel_after AS buffer_after
				FROM {$bookings} b LEFT JOIN {$services} s ON s.id = b.service_id
				WHERE b.resource_id {$res_sql} AND b.status IN ({$statuses}) AND b.id <> %d
				AND b.start_utc < %s AND b.end_utc > %s",
				$exclude_id,
				$to_sql,
				$from_sql
			),
			ARRAY_A
		);

		$external = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.start_utc, e.end_utc FROM {$events} e
				INNER JOIN {$connections} c ON c.id = e.connection_id
				WHERE ( c.resource_id {$res_sql} OR c.resource_id IS NULL ) AND c.status <> 'paused'
				AND e.start_utc < %s AND e.end_utc > %s",
				$to_sql,
				$from_sql
			),
			ARRAY_A
		);
		// phpcs:enable

		$busy = array();

		foreach ( $rows as $row ) {
			$busy[] = array(
				'start' => self::ts( $row['start_utc'] ) - (int) $row['buffer_before'] * 60,
				'end'   => self::ts( $row['end_utc'] ) + (int) $row['buffer_after'] * 60,
				'seats' => (int) $row['service_id'] === $service_id ? (int) $row['seats'] : SlotCalculator::FULL_BLOCK,
			);
		}

		foreach ( $external as $row ) {
			$busy[] = array(
				'start' => self::ts( $row['start_utc'] ),
				'end'   => self::ts( $row['end_utc'] ),
				'seats' => SlotCalculator::FULL_BLOCK,
			);
		}

		return $busy;
	}

	/**
	 * Booked appointments per local day for a resource (null = business-wide).
	 * A group session with several participants counts once.
	 *
	 * @param int|null      $resource_id Resource id.
	 * @param int           $service_id  Service to count separately.
	 * @param int           $from        UTC timestamp.
	 * @param int           $to          UTC timestamp.
	 * @param \DateTimeZone $tz          Local timezone.
	 * @return array{service: array<string, int>, all: array<string, int>}
	 */
	public function day_counts( ?int $resource_id, int $service_id, int $from, int $to, \DateTimeZone $tz ): array {
		global $wpdb;

		$bookings = Schema::table( $wpdb->prefix, 'bookings' );
		$statuses = "'" . implode( "','", self::BLOCKING_STATUSES ) . "'";
		$res_sql  = null === $resource_id ? 'IS NULL' : '= ' . (int) $resource_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name, integer/constant values only.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT service_id, start_utc FROM {$bookings} WHERE resource_id {$res_sql} AND status IN ({$statuses}) AND start_utc >= %s AND start_utc < %s", gmdate( 'Y-m-d H:i:s', $from - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $to + DAY_IN_SECONDS ) ), ARRAY_A );

		$counts = array(
			'service' => array(),
			'all'     => array(),
		);
		foreach ( $rows as $row ) {
			$date = ( new \DateTimeImmutable( '@' . self::ts( $row['start_utc'] ) ) )->setTimezone( $tz )->format( 'Y-m-d' );

			$counts['all'][ $date ] = ( $counts['all'][ $date ] ?? 0 ) + 1;
			if ( (int) $row['service_id'] === $service_id ) {
				$counts['service'][ $date ] = ( $counts['service'][ $date ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * Find a booking.
	 *
	 * @param int $id Booking id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'bookings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Convert a UTC MySQL datetime to a timestamp.
	 *
	 * @param string $datetime UTC datetime.
	 */
	public static function ts( string $datetime ): int {
		return (int) ( new \DateTimeImmutable( $datetime, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
	}
}
