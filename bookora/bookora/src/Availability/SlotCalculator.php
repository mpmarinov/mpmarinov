<?php
/**
 * Calculates bookable time slots.
 *
 * @package Bookora
 */

namespace Bookora\Availability;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Pure calculation without database or WordPress calls, so it can be unit tested in isolation.
 *
 * Inputs:
 * - Weekly opening hours per weekday (0 = Sunday … 6 = Saturday) in local time.
 * - Exceptions: "closed" (whole day or a time window) and "open" (special opening hours that
 *   replace the weekly hours of that day).
 * - Busy intervals (existing bookings, external calendar events) as UTC timestamps,
 *   already expanded by their own buffers, each with the number of seats it occupies.
 *
 * All slot starts and ends are returned as UTC timestamps.
 */
final class SlotCalculator {

	/**
	 * Seats value used for intervals that block a resource completely (e.g. external events).
	 */
	public const FULL_BLOCK = PHP_INT_MAX;

	/**
	 * Local timezone of the resource/business.
	 *
	 * @var DateTimeZone
	 */
	private DateTimeZone $tz;

	/**
	 * Weekly hours: weekday => list of [start 'H:i', end 'H:i'].
	 *
	 * @var array<int, array<int, array{0:string,1:string}>>
	 */
	private array $weekly;

	/**
	 * Exceptions.
	 *
	 * @var array<int, array{date_start:string,date_end:string,type:string,start_time?:?string,end_time?:?string}>
	 */
	private array $exceptions;

	/**
	 * Busy intervals sorted by start.
	 *
	 * @var array<int, array{start:int,end:int,seats:int}>
	 */
	private array $busy;

	/**
	 * Constructor.
	 *
	 * @param DateTimeZone                                                                                           $tz         Local timezone.
	 * @param array<int, array<int, array{0:string,1:string}>>                                                       $weekly     Weekly hours.
	 * @param array<int, array{date_start:string,date_end:string,type:string,start_time?:?string,end_time?:?string}> $exceptions Exceptions.
	 * @param array<int, array{start:int,end:int,seats?:int}>                                                        $busy       Busy intervals.
	 */
	public function __construct( DateTimeZone $tz, array $weekly, array $exceptions = array(), array $busy = array() ) {
		$this->tz         = $tz;
		$this->weekly     = $weekly;
		$this->exceptions = $exceptions;

		$this->busy = array_map(
			static fn( array $b ): array => array(
				'start' => (int) $b['start'],
				'end'   => (int) $b['end'],
				'seats' => isset( $b['seats'] ) ? (int) $b['seats'] : 1,
			),
			$busy
		);
		usort( $this->busy, static fn( array $a, array $b ): int => $a['start'] <=> $b['start'] );
	}

	/**
	 * Calculate slots between two local dates (inclusive).
	 *
	 * @param string    $from_date First local date (Y-m-d).
	 * @param string    $to_date   Last local date (Y-m-d).
	 * @param SlotRules $rules     Service rules.
	 * @param int       $now       Current UTC timestamp.
	 * @return array<int, array{start:int,end:int,available:int}>
	 */
	public function slots( string $from_date, string $to_date, SlotRules $rules, int $now ): array {
		$from = DateTimeImmutable::createFromFormat( '!Y-m-d', $from_date, $this->tz );
		$to   = DateTimeImmutable::createFromFormat( '!Y-m-d', $to_date, $this->tz );

		if ( ! $from || ! $to || $to < $from ) {
			return array();
		}

		$earliest = $now + $rules->min_notice * 60;
		$latest   = $rules->max_advance > 0 ? $now + $rules->max_advance * 86400 : PHP_INT_MAX;
		$slots    = array();

		foreach ( new DatePeriod( $from, new DateInterval( 'P1D' ), $to->modify( '+1 day' ) ) as $day ) {
			foreach ( $this->windows_for( $day ) as [ $win_start, $win_end ] ) {
				for ( $start = $win_start; $start + $rules->duration * 60 <= $win_end; $start += $rules->interval * 60 ) {
					if ( $start < $earliest ) {
						continue;
					}
					if ( $start > $latest ) {
						break 3;
					}

					$end  = $start + $rules->duration * 60;
					$left = $this->seats_left( $start, $end, $rules );

					if ( $left >= $rules->seats ) {
						$slots[] = array(
							'start'     => $start,
							'end'       => $end,
							'available' => $left,
						);
					}
				}
			}
		}

		return $slots;
	}

	/**
	 * Check whether one concrete slot can be booked (used inside the booking transaction).
	 *
	 * @param int       $start UTC start timestamp.
	 * @param SlotRules $rules Service rules.
	 * @param int       $now   Current UTC timestamp.
	 */
	public function is_available( int $start, SlotRules $rules, int $now ): bool {
		$date = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( $this->tz )->format( 'Y-m-d' );

		foreach ( $this->slots( $date, $date, $rules, $now ) as $slot ) {
			if ( $slot['start'] === $start ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Free seats for a candidate slot, considering buffers of the requested service.
	 *
	 * @param int       $start Slot start.
	 * @param int       $end   Slot end.
	 * @param SlotRules $rules Rules.
	 */
	private function seats_left( int $start, int $end, SlotRules $rules ): int {
		$block_start = $start - $rules->buffer_before * 60;
		$block_end   = $end + $rules->buffer_after * 60;
		$used        = 0;

		foreach ( $this->busy as $busy ) {
			if ( $busy['start'] >= $block_end ) {
				break;
			}
			if ( $busy['end'] > $block_start ) {
				if ( self::FULL_BLOCK === $busy['seats'] ) {
					return 0;
				}
				$used += $busy['seats'];
			}
		}

		return max( 0, $rules->capacity - $used );
	}

	/**
	 * Opening windows of a local day as UTC timestamp pairs.
	 *
	 * @param DateTimeImmutable $day Local midnight.
	 * @return array<int, array{0:int,1:int}>
	 */
	private function windows_for( DateTimeImmutable $day ): array {
		$date    = $day->format( 'Y-m-d' );
		$weekday = (int) $day->format( 'w' );
		$hours   = $this->weekly[ $weekday ] ?? array();
		$closed  = array();
		$special = array();

		foreach ( $this->exceptions as $ex ) {
			if ( $date < $ex['date_start'] || $date > $ex['date_end'] ) {
				continue;
			}

			$has_times = ! empty( $ex['start_time'] ) && ! empty( $ex['end_time'] );

			if ( 'open' === $ex['type'] && $has_times ) {
				$special[] = array( $ex['start_time'], $ex['end_time'] );
			} elseif ( 'closed' === $ex['type'] ) {
				if ( ! $has_times ) {
					return array();
				}
				$closed[] = $this->to_range( $date, $ex['start_time'], $ex['end_time'] );
			}
		}

		if ( $special ) {
			$hours = $special;
		}

		$windows = array();
		foreach ( $hours as [ $open, $close ] ) {
			$windows[] = $this->to_range( $date, $open, $close );
		}

		foreach ( $closed as $block ) {
			$windows = self::subtract( $windows, $block );
		}

		usort( $windows, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

		return $windows;
	}

	/**
	 * Convert local date + times to a UTC timestamp range. "24:00" or an end before start means midnight.
	 *
	 * @param string $date  Local date.
	 * @param string $start Local start time.
	 * @param string $end   Local end time.
	 * @return array{0:int,1:int}
	 */
	private function to_range( string $date, string $start, string $end ): array {
		$s = new DateTimeImmutable( $date . ' ' . substr( $start, 0, 5 ), $this->tz );

		if ( '24:00' === substr( $end, 0, 5 ) || substr( $end, 0, 5 ) <= substr( $start, 0, 5 ) ) {
			$e = ( new DateTimeImmutable( $date, $this->tz ) )->modify( '+1 day' );
		} else {
			$e = new DateTimeImmutable( $date . ' ' . substr( $end, 0, 5 ), $this->tz );
		}

		return array( $s->getTimestamp(), $e->getTimestamp() );
	}

	/**
	 * Remove a range from a list of ranges.
	 *
	 * @param array<int, array{0:int,1:int}> $ranges Ranges.
	 * @param array{0:int,1:int}             $cut    Range to remove.
	 * @return array<int, array{0:int,1:int}>
	 */
	private static function subtract( array $ranges, array $cut ): array {
		$out = array();
		foreach ( $ranges as [ $s, $e ] ) {
			if ( $cut[1] <= $s || $cut[0] >= $e ) {
				$out[] = array( $s, $e );
				continue;
			}
			if ( $cut[0] > $s ) {
				$out[] = array( $s, $cut[0] );
			}
			if ( $cut[1] < $e ) {
				$out[] = array( $cut[1], $e );
			}
		}
		return $out;
	}
}
