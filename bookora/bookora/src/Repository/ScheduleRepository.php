<?php
/**
 * Weekly opening hours and exceptions.
 *
 * @package Bookora
 */

namespace Bookora\Repository;

use Bookora\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Resource id NULL means the global (business-wide) schedule.
 */
final class ScheduleRepository {

	/**
	 * Weekly hours of a resource, falling back to the global schedule when none are set.
	 *
	 * @param int|null $resource_id Resource id or null for global.
	 * @param bool     $fallback    Use the global schedule when the resource has none.
	 * @return array<int, array<int, array{0:string,1:string}>> weekday => [[start, end], …]
	 */
	public function weekly( ?int $resource_id, bool $fallback = true ): array {
		$rows = $this->weekly_rows( $resource_id );

		if ( ! $rows && null !== $resource_id && $fallback ) {
			$rows = $this->weekly_rows( null );
		}

		$weekly = array();
		foreach ( $rows as $row ) {
			$weekly[ (int) $row['weekday'] ][] = array( substr( $row['start_time'], 0, 5 ), substr( $row['end_time'], 0, 5 ) );
		}
		ksort( $weekly );

		return $weekly;
	}

	/**
	 * Replace the weekly hours of a resource (or global).
	 *
	 * @param int|null                                          $resource_id Resource id or null.
	 * @param array<int|string, array<int, array<int, string>>> $weekly      weekday => [[start, end], …].
	 * @return array<int, array<int, array{0:string,1:string}>> Saved schedule.
	 */
	public function save_weekly( ?int $resource_id, array $weekly ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'schedules' );

		$clean = self::sanitize_weekly( $weekly );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		if ( null === $resource_id ) {
			$wpdb->query( "DELETE FROM {$table} WHERE resource_id IS NULL" );
		} else {
			$wpdb->delete( $table, array( 'resource_id' => $resource_id ), array( '%d' ) );
		}

		foreach ( $clean as $weekday => $ranges ) {
			foreach ( $ranges as [ $start, $end ] ) {
				$wpdb->insert(
					$table,
					array(
						'resource_id' => $resource_id,
						'weekday'     => $weekday,
						'start_time'  => $start . ':00',
						'end_time'    => $end . ':00',
					)
				);
			}
		}
		// phpcs:enable

		return $clean;
	}

	/**
	 * Validate weekly hours: valid weekdays, HH:MM times, start < end, max 10 ranges per day, sorted, no overlaps.
	 *
	 * @param array<int|string, mixed> $weekly Raw input.
	 * @return array<int, array<int, array{0:string,1:string}>>
	 */
	public static function sanitize_weekly( array $weekly ): array {
		$clean = array();

		foreach ( $weekly as $weekday => $ranges ) {
			$weekday = (int) $weekday;
			if ( $weekday < 0 || $weekday > 6 || ! is_array( $ranges ) ) {
				continue;
			}

			$valid = array();
			foreach ( array_slice( $ranges, 0, 10 ) as $range ) {
				$start = self::time( $range[0] ?? ( $range['start'] ?? '' ) );
				$end   = self::time( $range[1] ?? ( $range['end'] ?? '' ) );
				if ( $start && $end && ( $start < $end || '24:00' === $end ) ) {
					$valid[] = array( $start, $end );
				}
			}

			usort( $valid, static fn( array $a, array $b ): int => strcmp( $a[0], $b[0] ) );

			$merged = array();
			foreach ( $valid as $range ) {
				$last = count( $merged ) - 1;
				if ( $last >= 0 && $range[0] <= $merged[ $last ][1] ) {
					$merged[ $last ][1] = max( $merged[ $last ][1], $range[1] );
				} else {
					$merged[] = $range;
				}
			}

			if ( $merged ) {
				$clean[ $weekday ] = $merged;
			}
		}

		ksort( $clean );
		return $clean;
	}

	/**
	 * Exceptions affecting a resource in a date range (global ones included).
	 *
	 * @param int|null $resource_id Resource id.
	 * @param string   $from        Y-m-d.
	 * @param string   $to          Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	public function exceptions( ?int $resource_id, string $from = '0000-01-01', string $to = '9999-12-31' ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'schedule_exceptions' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		if ( null === $resource_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE resource_id IS NULL AND date_end >= %s AND date_start <= %s ORDER BY date_start", $from, $to ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ( resource_id IS NULL OR resource_id = %d ) AND date_end >= %s AND date_start <= %s ORDER BY date_start", $resource_id, $from, $to ), ARRAY_A );
		}
		// phpcs:enable

		return array_map(
			static function ( array $row ): array {
				$row['id']          = (int) $row['id'];
				$row['resource_id'] = null === $row['resource_id'] ? null : (int) $row['resource_id'];
				$row['start_time']  = $row['start_time'] ? substr( $row['start_time'], 0, 5 ) : null;
				$row['end_time']    = $row['end_time'] ? substr( $row['end_time'], 0, 5 ) : null;
				return $row;
			},
			(array) $rows
		);
	}

	/**
	 * Create an exception.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return int|\WP_Error New id or error.
	 */
	public function create_exception( array $data ) {
		global $wpdb;

		$date_start = self::date( $data['date_start'] ?? '' );
		$date_end   = self::date( $data['date_end'] ?? ( $data['date_start'] ?? '' ) );
		$start      = self::time( $data['start_time'] ?? '' );
		$end        = self::time( $data['end_time'] ?? '' );
		$type       = in_array( $data['type'] ?? '', array( 'closed', 'open' ), true ) ? $data['type'] : 'closed';

		if ( ! $date_start || ! $date_end || $date_end < $date_start ) {
			return new \WP_Error( 'bookora_invalid_dates', __( 'Please enter a valid date range.', 'bookora' ), array( 'status' => 400 ) );
		}
		if ( ( $start xor $end ) || ( $start && $end && $start >= $end && '24:00' !== $end ) ) {
			return new \WP_Error( 'bookora_invalid_times', __( 'Please enter a valid time range.', 'bookora' ), array( 'status' => 400 ) );
		}
		if ( 'open' === $type && ! $start ) {
			return new \WP_Error( 'bookora_invalid_times', __( 'Special opening hours need a start and end time.', 'bookora' ), array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::table( $wpdb->prefix, 'schedule_exceptions' ),
			array(
				'resource_id' => empty( $data['resource_id'] ) ? null : (int) $data['resource_id'],
				'type'        => $type,
				'date_start'  => $date_start,
				'date_end'    => $date_end,
				'start_time'  => $start ? $start . ':00' : null,
				'end_time'    => $end ? $end . ':00' : null,
				'note'        => mb_substr( sanitize_text_field( (string) ( $data['note'] ?? '' ) ), 0, 191 ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete an exception.
	 *
	 * @param int $id Exception id.
	 */
	public function delete_exception( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( Schema::table( $wpdb->prefix, 'schedule_exceptions' ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Raw weekly rows.
	 *
	 * @param int|null $resource_id Resource id.
	 * @return array<int, array<string, string>>
	 */
	private function weekly_rows( ?int $resource_id ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'schedules' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		if ( null === $resource_id ) {
			$rows = $wpdb->get_results( "SELECT weekday, start_time, end_time FROM {$table} WHERE resource_id IS NULL ORDER BY weekday, start_time", ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT weekday, start_time, end_time FROM {$table} WHERE resource_id = %d ORDER BY weekday, start_time", $resource_id ), ARRAY_A );
		}
		// phpcs:enable

		return (array) $rows;
	}

	/**
	 * Normalize HH:MM (24:00 allowed as end of day).
	 *
	 * @param mixed $value Raw value.
	 */
	private static function time( $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '24:00' === substr( $value, 0, 5 ) ) {
			return '24:00';
		}
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d/', $value ) ? substr( $value, 0, 5 ) : null;
	}

	/**
	 * Validate Y-m-d.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function date( $value ): ?string {
		$value = is_string( $value ) ? $value : '';
		$d     = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $d && $d->format( 'Y-m-d' ) === $value ? $value : null;
	}
}
