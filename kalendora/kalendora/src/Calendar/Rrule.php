<?php
/**
 * Recurrence rule expansion (RFC 5545 RRULE, the parts calendars really use).
 *
 * @package Kalendora
 */

namespace Kalendora\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Supports FREQ=DAILY|WEEKLY|MONTHLY|YEARLY with INTERVAL, COUNT, UNTIL, BYDAY (incl. ordinals
 * such as 2TU or -1FR), BYMONTHDAY (incl. negative), BYMONTH, BYSETPOS and WKST.
 * Instances are computed in local wall-clock time, so a weekly 09:00 meeting stays at 09:00 across DST.
 */
final class Rrule {

	private const DAYS = array(
		'MO' => 1,
		'TU' => 2,
		'WE' => 3,
		'TH' => 4,
		'FR' => 5,
		'SA' => 6,
		'SU' => 7,
	);

	/**
	 * Safety limit for generated periods.
	 */
	private const MAX_PERIODS = 50000;

	/**
	 * FREQ value.
	 *
	 * @var string
	 */
	private string $freq;

	/**
	 * INTERVAL (>= 1).
	 *
	 * @var int
	 */
	private int $interval;

	/**
	 * COUNT or null.
	 *
	 * @var int|null
	 */
	private ?int $count;

	/**
	 * UNTIL as timestamp or null.
	 *
	 * @var int|null
	 */
	private ?int $until;

	/**
	 * BYDAY as [ordinal|null, ISO weekday].
	 *
	 * @var array<int, array{0:int|null,1:int}>
	 */
	private array $byday = array();

	/**
	 * BYMONTHDAY values.
	 *
	 * @var array<int, int>
	 */
	private array $bymonthday = array();

	/**
	 * BYMONTH values.
	 *
	 * @var array<int, int>
	 */
	private array $bymonth = array();

	/**
	 * BYSETPOS values.
	 *
	 * @var array<int, int>
	 */
	private array $bysetpos = array();

	/**
	 * Week start (ISO weekday).
	 *
	 * @var int
	 */
	private int $wkst;

	/**
	 * First instance in the event's zone.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $start;

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $rule    Parsed RRULE.
	 * @param int                   $dtstart First instance (UTC timestamp).
	 * @param DateTimeZone          $tz      Zone of the event.
	 */
	public function __construct( array $rule, int $dtstart, DateTimeZone $tz ) {
		$this->freq     = $rule['FREQ'] ?? '';
		$this->interval = max( 1, (int) ( $rule['INTERVAL'] ?? 1 ) );
		$this->count    = isset( $rule['COUNT'] ) ? max( 0, (int) $rule['COUNT'] ) : null;
		$this->start    = ( new DateTimeImmutable( '@' . $dtstart ) )->setTimezone( $tz );
		$this->until    = isset( $rule['UNTIL'] ) ? $this->until_ts( $rule['UNTIL'], $tz ) : null;
		$this->wkst     = self::DAYS[ $rule['WKST'] ?? 'MO' ] ?? 1;

		foreach ( array_filter( explode( ',', $rule['BYDAY'] ?? '' ) ) as $d ) {
			if ( preg_match( '/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', trim( $d ), $m ) ) {
				$this->byday[] = array( '' !== $m[1] ? (int) $m[1] : null, self::DAYS[ $m[2] ] );
			}
		}
		foreach ( array(
			'BYMONTHDAY' => 'bymonthday',
			'BYMONTH'    => 'bymonth',
			'BYSETPOS'   => 'bysetpos',
		) as $key => $prop ) {
			foreach ( array_filter( explode( ',', $rule[ $key ] ?? '' ), 'strlen' ) as $v ) {
				if ( is_numeric( $v ) && 0 !== (int) $v ) {
					$this->{$prop}[] = (int) $v;
				}
			}
		}
	}

	/**
	 * UNTIL as timestamp (inclusive). A DATE value means the whole day.
	 *
	 * @param string       $value UNTIL.
	 * @param DateTimeZone $tz    Zone.
	 */
	private function until_ts( string $value, DateTimeZone $tz ): ?int {
		try {
			if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
				return ( new DateTimeImmutable( "{$m[1]}-{$m[2]}-{$m[3]} 23:59:59", $tz ) )->getTimestamp();
			}
			if ( preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $value, $m ) ) {
				return ( new DateTimeImmutable( "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}", 'Z' === $m[7] ? new DateTimeZone( 'UTC' ) : $tz ) )->getTimestamp();
			}
		} catch ( Exception $e ) {
			return null;
		}
		return null;
	}

	/**
	 * Instance starts within [from, to].
	 *
	 * @param int   $from     UTC timestamp.
	 * @param int   $to       UTC timestamp.
	 * @param float $deadline microtime() after which expansion stops (0 = none).
	 * @return list<int>
	 */
	public function between( int $from, int $to, float $deadline = 0.0 ): array {
		if ( ! in_array( $this->freq, array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) ) {
			return array();
		}

		$out             = array();
		$first           = $this->start->getTimestamp();
		[ $k, $emitted ] = $this->skip_ahead( $from );

		for ( $i = 0; $i < self::MAX_PERIODS; $i++, $k++ ) {
			if ( $deadline > 0 && 0 === ( $i & 63 ) && microtime( true ) > $deadline ) {
				break;
			}
			$dates = $this->period( $k );
			if ( null === $dates ) {
				break;
			}
			$stamps = array_map( array( $this, 'at' ), $dates );
			sort( $stamps );
			$stamps = array_values( array_unique( $stamps ) );
			if ( $this->bysetpos ) {
				$stamps = $this->setpos( $stamps ); // BYSETPOS counts within the full period, before DTSTART filtering.
			}
			$stamps = array_values( array_filter( $stamps, static fn( $ts ) => $ts >= $first ) );

			foreach ( $stamps as $ts ) {
				if ( null !== $this->until && $ts > $this->until ) {
					return $out;
				}
				if ( null !== $this->count && $emitted >= $this->count ) {
					return $out;
				}
				++$emitted;
				if ( $ts > $to ) {
					return $out;
				}
				if ( $ts >= $from ) {
					$out[] = $ts;
				}
			}

			// Periods are chronological: stop once a whole period starts after the window.
			if ( $this->period_start( $k ) > $to + 86400 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Jump close to the window. With COUNT this is only possible for rules with a fixed number of
	 * instances per period (DAILY without filters, WEEKLY without BYMONTH/BYSETPOS); the skipped
	 * instances are counted arithmetically.
	 *
	 * @param int $from Window start.
	 * @return array{0:int, 1:int} Period index and instances already emitted before it.
	 */
	private function skip_ahead( int $from ): array {
		$diff = $from - $this->start->getTimestamp();
		if ( $diff <= 0 ) {
			return array( 0, 0 );
		}
		$unit = array(
			'DAILY'   => 86400,
			'WEEKLY'  => 604800,
			'MONTHLY' => 2678400,
			'YEARLY'  => 31622400,
		)[ $this->freq ] * $this->interval;
		$k    = max( 0, (int) floor( $diff / $unit ) - 2 );

		if ( null === $this->count || 0 === $k ) {
			return array( $k, 0 );
		}

		$per_period = null;
		if ( 'DAILY' === $this->freq && ! $this->byday && ! $this->bymonth && ! $this->bymonthday && ! $this->bysetpos ) {
			$per_period = 1;
		} elseif ( 'WEEKLY' === $this->freq && ! $this->bymonth && ! $this->bysetpos ) {
			$per_period = $this->byday ? count( array_unique( array_map( static fn( $b ) => $b[1], $this->byday ) ) ) : 1;
		}
		if ( null === $per_period ) {
			return array( 0, 0 );
		}

		// Period 0 may contain days before DTSTART, which do not count.
		$first  = $this->start->getTimestamp();
		$period = 0;
		foreach ( (array) $this->period( 0 ) as $ymd ) {
			if ( $this->at( $ymd ) >= $first ) {
				++$period;
			}
		}
		return array( $k, $period + ( $k - 1 ) * $per_period );
	}

	/**
	 * Timestamp of the first day of period k (for the stop condition).
	 *
	 * @param int $k Period index.
	 */
	private function period_start( int $k ): int {
		$n = $k * $this->interval;
		$d = $this->start->setTime( 0, 0 );
		switch ( $this->freq ) {
			case 'DAILY':
				return $d->modify( "+{$n} days" )->getTimestamp();
			case 'WEEKLY':
				return $this->week_start( $d )->modify( "+{$n} weeks" )->getTimestamp();
			case 'MONTHLY':
				return $d->setDate( (int) $d->format( 'Y' ), (int) $d->format( 'n' ), 1 )->modify( "+{$n} months" )->getTimestamp();
			default:
				return $d->setDate( (int) $d->format( 'Y' ) + $n, 1, 1 )->getTimestamp();
		}
	}

	/**
	 * Monday (or WKST) of the week containing $d.
	 *
	 * @param DateTimeImmutable $d Date.
	 */
	private function week_start( DateTimeImmutable $d ): DateTimeImmutable {
		$back = ( (int) $d->format( 'N' ) - $this->wkst + 7 ) % 7;
		return $d->modify( "-{$back} days" );
	}

	/**
	 * Candidate dates (Y-m-d) of period k.
	 *
	 * @param int $k Period index.
	 * @return list<string>|null Null when the date range is exhausted.
	 */
	private function period( int $k ): ?array {
		$n   = $k * $this->interval;
		$day = $this->start->setTime( 0, 0 );

		switch ( $this->freq ) {
			case 'DAILY':
				$d = $day->modify( "+{$n} days" );
				return $this->filter_day( $d ) ? array( $d->format( 'Y-m-d' ) ) : array();

			case 'WEEKLY':
				$week = $this->week_start( $day )->modify( "+{$n} weeks" );
				$dows = $this->byday ? array_unique( array_map( static fn( $b ) => $b[1], $this->byday ) ) : array( (int) $day->format( 'N' ) );
				$out  = array();
				foreach ( $dows as $dow ) {
					$d = $week->modify( '+' . ( ( $dow - $this->wkst + 7 ) % 7 ) . ' days' );
					if ( ! $this->bymonth || in_array( (int) $d->format( 'n' ), $this->bymonth, true ) ) {
						$out[] = $d->format( 'Y-m-d' );
					}
				}
				return $out;

			case 'MONTHLY':
				$first = $day->setDate( (int) $day->format( 'Y' ), (int) $day->format( 'n' ), 1 )->modify( "+{$n} months" );
				if ( (int) $first->format( 'Y' ) > 9999 ) {
					return null;
				}
				if ( $this->bymonth && ! in_array( (int) $first->format( 'n' ), $this->bymonth, true ) ) {
					return array();
				}
				return $this->month_days( (int) $first->format( 'Y' ), (int) $first->format( 'n' ) );

			case 'YEARLY':
				$year = (int) $day->format( 'Y' ) + $n;
				if ( $year > 9999 ) {
					return null;
				}
				$out = array();
				if ( ! $this->bymonth && $this->byday && null !== $this->byday[0][0] && ! $this->bymonthday ) {
					// e.g. FREQ=YEARLY;BYDAY=20MO – n-th weekday of the year.
					foreach ( $this->byday as [ $ord, $dow ] ) {
						$d = $this->nth_in_range( new DateTimeImmutable( "{$year}-01-01", $day->getTimezone() ), new DateTimeImmutable( "{$year}-12-31", $day->getTimezone() ), (int) $ord, $dow );
						if ( $d ) {
							$out[] = $d;
						}
					}
					return $out;
				}
				// Without BYMONTH, BYMONTHDAY/BYDAY expand over every month of the year (RFC 5545 table).
				if ( $this->bymonth ) {
					$months = $this->bymonth;
				} elseif ( $this->bymonthday || $this->byday ) {
					$months = range( 1, 12 );
				} else {
					$months = array( (int) $day->format( 'n' ) );
				}
				foreach ( $months as $month ) {
					$out = array_merge( $out, $this->month_days( $year, $month ) );
				}
				return $out;
		}

		return null;
	}

	/**
	 * Days of one month according to BYMONTHDAY / BYDAY (default: day of DTSTART).
	 *
	 * @param int $year  Year.
	 * @param int $month Month.
	 * @return list<string>
	 */
	private function month_days( int $year, int $month ): array {
		$tz    = $this->start->getTimezone();
		$first = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $tz );
		$len   = (int) $first->format( 't' );
		$days  = array();

		if ( $this->bymonthday ) {
			foreach ( $this->bymonthday as $md ) {
				$d = $md > 0 ? $md : $len + $md + 1;
				if ( $d >= 1 && $d <= $len ) {
					$days[] = $d;
				}
			}
		}

		if ( $this->byday ) {
			$from_byday = array();
			foreach ( $this->byday as [ $ord, $dow ] ) {
				if ( null === $ord ) {
					for ( $d = 1; $d <= $len; $d++ ) {
						if ( (int) $first->setDate( $year, $month, $d )->format( 'N' ) === $dow ) {
							$from_byday[] = $d;
						}
					}
				} else {
					$hit = $this->nth_in_range( $first, $first->setDate( $year, $month, $len ), $ord, $dow );
					if ( $hit ) {
						$from_byday[] = (int) substr( $hit, 8, 2 );
					}
				}
			}
			$days = $this->bymonthday ? array_values( array_intersect( $days, $from_byday ) ) : $from_byday;
		}

		if ( ! $this->bymonthday && ! $this->byday ) {
			$d = (int) $this->start->format( 'j' );
			if ( $d <= $len ) {
				$days[] = $d; // Months without the 31st are skipped (RFC 5545).
			}
		}

		$days = array_unique( $days );
		sort( $days );
		return array_map( static fn( $d ) => sprintf( '%04d-%02d-%02d', $year, $month, $d ), $days );
	}

	/**
	 * N-th (or -N-th) weekday between two dates.
	 *
	 * @param DateTimeImmutable $first First day.
	 * @param DateTimeImmutable $last  Last day.
	 * @param int               $ord   Ordinal (1 = first, -1 = last).
	 * @param int               $dow   ISO weekday.
	 */
	private function nth_in_range( DateTimeImmutable $first, DateTimeImmutable $last, int $ord, int $dow ): ?string {
		if ( $ord > 0 ) {
			$d = $first->modify( '+' . ( ( $dow - (int) $first->format( 'N' ) + 7 ) % 7 ) . ' days' )->modify( '+' . ( $ord - 1 ) . ' weeks' );
		} else {
			$d = $last->modify( '-' . ( ( (int) $last->format( 'N' ) - $dow + 7 ) % 7 ) . ' days' )->modify( '-' . ( -$ord - 1 ) . ' weeks' );
		}
		return $d >= $first && $d <= $last ? $d->format( 'Y-m-d' ) : null;
	}

	/**
	 * BYDAY / BYMONTH / BYMONTHDAY as filters for DAILY rules.
	 *
	 * @param DateTimeImmutable $d Day.
	 */
	private function filter_day( DateTimeImmutable $d ): bool {
		if ( $this->bymonth && ! in_array( (int) $d->format( 'n' ), $this->bymonth, true ) ) {
			return false;
		}
		if ( $this->byday && ! in_array( (int) $d->format( 'N' ), array_map( static fn( $b ) => $b[1], $this->byday ), true ) ) {
			return false;
		}
		if ( $this->bymonthday ) {
			$len = (int) $d->format( 't' );
			$day = (int) $d->format( 'j' );
			$ok  = false;
			foreach ( $this->bymonthday as $md ) {
				$ok = $ok || ( $md > 0 ? $md === $day : $len + $md + 1 === $day );
			}
			return $ok;
		}
		return true;
	}

	/**
	 * Apply BYSETPOS to the sorted instances of one period.
	 *
	 * @param array<int, int> $stamps Sorted timestamps.
	 * @return list<int>
	 */
	private function setpos( array $stamps ): array {
		$n   = count( $stamps );
		$out = array();
		foreach ( $this->bysetpos as $pos ) {
			$i = $pos > 0 ? $pos - 1 : $n + $pos;
			if ( $i >= 0 && $i < $n ) {
				$out[] = $stamps[ $i ];
			}
		}
		sort( $out );
		return array_values( array_unique( $out ) );
	}

	/**
	 * Local date + DTSTART time of day → timestamp.
	 *
	 * @param string $ymd Y-m-d.
	 */
	private function at( string $ymd ): int {
		[ $y, $m, $d ] = array_map( 'intval', explode( '-', $ymd ) );
		return $this->start->setDate( $y, $m, $d )->getTimestamp();
	}
}
