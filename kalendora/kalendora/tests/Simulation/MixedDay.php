<?php
/**
 * Simulates a working day where random customers book random treatments until the day is full.
 *
 * @package Kalendora
 */

namespace Kalendora\Tests\Simulation;

use Kalendora\Availability\SlotCalculator;
use Kalendora\Availability\SlotRules;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pure simulation on top of the real SlotCalculator (no database).
 */
final class MixedDay {

	public const DATE = '2026-12-07'; // A Monday.

	/**
	 * Default practice: treatments with different duration and break.
	 *
	 * @return array<string, array<string, int>>
	 */
	public static function services(): array {
		return array(
			'Medizinische Fußpflege (60 + 60 Pause)' => array( 'duration' => 60, 'buffer_after' => 60 ),
			'Nagelkorrektur (30 + 15 Pause)'         => array( 'duration' => 30, 'buffer_after' => 15 ),
			'Komfort mit Massage (90 + 30 Pause)'    => array( 'duration' => 90, 'buffer_after' => 30 ),
			'Basis (45 + 15 Pause)'                  => array( 'duration' => 45, 'buffer_after' => 15 ),
		);
	}

	/**
	 * Working hours used in the simulation.
	 *
	 * @return array<int, array<int, array{0:string,1:string}>>
	 */
	public static function weekly(): array {
		return array( 1 => array( array( '08:00', '12:30' ), array( '13:30', '19:00' ) ) );
	}

	/**
	 * Run one day.
	 *
	 * @param int                               $seed      Random seed.
	 * @param bool                              $fill_gaps Variant.
	 * @param array<string, array<string, int>> $services  Services.
	 * @param int                               $max_day   Daily limit for all services (0 = none).
	 * @return array<int, array{service:string, start:int, end:int, block_start:int, block_end:int}>
	 */
	public static function run( int $seed, bool $fill_gaps, array $services = array(), int $max_day = 0 ): array {
		mt_srand( $seed );
		$services = $services ? $services : self::services();
		$tz       = new DateTimeZone( 'Europe/Berlin' );
		$bookings = array();
		$names    = array_keys( $services );

		for ( $guard = 0; $guard < 200; $guard++ ) {
			$busy = array_map(
				static fn( $b ) => array(
					'start' => $b['block_start'],
					'end'   => $b['block_end'],
					'seats' => SlotCalculator::FULL_BLOCK,
				),
				$bookings
			);
			$calc = new SlotCalculator( $tz, self::weekly(), array(), $busy, array( 'all' => array( self::DATE => count( $bookings ) ) ) );

			// A customer wants a random treatment; if it is not available, they pick another one.
			$order = $names;
			usort( $order, static fn() => mt_rand( -1, 1 ) );
			$booked = false;

			foreach ( $order as $name ) {
				$rules = SlotRules::from_service(
					$services[ $name ],
					array(
						'fill_gaps'   => $fill_gaps,
						'max_per_day' => $max_day,
					)
				);
				$slots = $calc->slots( self::DATE, self::DATE, $rules, 0 );
				if ( ! $slots ) {
					continue;
				}
				$slot       = $slots[ mt_rand( 0, count( $slots ) - 1 ) ];
				$bookings[] = array(
					'service'     => $name,
					'start'       => $slot['start'],
					'end'         => $slot['end'],
					'block_start' => $slot['start'] - $rules->buffer_before * 60,
					'block_end'   => $slot['end'] + $rules->buffer_after * 60,
				);
				$booked     = true;
				break;
			}

			if ( ! $booked ) {
				break;
			}
		}

		usort( $bookings, static fn( $a, $b ) => $a['start'] <=> $b['start'] );
		return $bookings;
	}

	/**
	 * Opening windows of the simulated day as UTC timestamps.
	 *
	 * @return array<int, array{0:int,1:int}>
	 */
	public static function windows(): array {
		$tz  = new DateTimeZone( 'Europe/Berlin' );
		$out = array();
		foreach ( self::weekly()[1] as [ $a, $b ] ) {
			$out[] = array(
				( new DateTimeImmutable( self::DATE . ' ' . $a, $tz ) )->getTimestamp(),
				( new DateTimeImmutable( self::DATE . ' ' . $b, $tz ) )->getTimestamp(),
			);
		}
		return $out;
	}
}
