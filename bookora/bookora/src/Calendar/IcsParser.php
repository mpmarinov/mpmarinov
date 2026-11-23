<?php
/**
 * Minimal, robust iCalendar (RFC 5545) reader for busy times.
 *
 * @package Bookora
 */

namespace Bookora\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Reads VEVENTs and expands them (incl. RRULE, EXDATE, RECURRENCE-ID) into concrete busy intervals.
 *
 * Only what matters for availability is kept: start, end, all-day flag and the UID.
 * Titles and descriptions are never stored (privacy).
 */
final class IcsParser {

	/**
	 * Hard limits against huge or malicious calendars.
	 */
	public const MAX_EVENTS      = 20000;
	public const MAX_OCCURRENCES = 20000;

	/**
	 * Common Windows / Outlook time zone names.
	 *
	 * @var array<string, string>
	 */
	private const WINDOWS_ZONES = array(
		'W. Europe Standard Time'         => 'Europe/Berlin',
		'Central Europe Standard Time'    => 'Europe/Budapest',
		'Central European Standard Time'  => 'Europe/Warsaw',
		'Romance Standard Time'           => 'Europe/Paris',
		'GMT Standard Time'               => 'Europe/London',
		'Greenwich Standard Time'         => 'Atlantic/Reykjavik',
		'FLE Standard Time'               => 'Europe/Kiev',
		'GTB Standard Time'               => 'Europe/Bucharest',
		'E. Europe Standard Time'         => 'Europe/Chisinau',
		'Russian Standard Time'           => 'Europe/Moscow',
		'Turkey Standard Time'            => 'Europe/Istanbul',
		'Eastern Standard Time'           => 'America/New_York',
		'Central Standard Time'           => 'America/Chicago',
		'Mountain Standard Time'          => 'America/Denver',
		'Pacific Standard Time'           => 'America/Los_Angeles',
		'UTC'                             => 'UTC',
		'Coordinated Universal Time'      => 'UTC',
		'Tokyo Standard Time'             => 'Asia/Tokyo',
		'China Standard Time'             => 'Asia/Shanghai',
		'India Standard Time'             => 'Asia/Kolkata',
		'AUS Eastern Standard Time'       => 'Australia/Sydney',
		'W. Central Africa Standard Time' => 'Africa/Lagos',
		'South Africa Standard Time'      => 'Africa/Johannesburg',
		'Arabian Standard Time'           => 'Asia/Dubai',
		'Israel Standard Time'            => 'Asia/Jerusalem',
		'Egypt Standard Time'             => 'Africa/Cairo',
		'Atlantic Standard Time'          => 'America/Halifax',
		'E. South America Standard Time'  => 'America/Sao_Paulo',
		'New Zealand Standard Time'       => 'Pacific/Auckland',
		'Singapore Standard Time'         => 'Asia/Singapore',
		'Korea Standard Time'             => 'Asia/Seoul',
		'Central America Standard Time'   => 'America/Guatemala',
		'US Mountain Standard Time'       => 'America/Phoenix',
		'Alaskan Standard Time'           => 'America/Anchorage',
		'Hawaiian Standard Time'          => 'Pacific/Honolulu',
		'Canada Central Standard Time'    => 'America/Regina',
		'SA Pacific Standard Time'        => 'America/Bogota',
		'Argentina Standard Time'         => 'America/Buenos_Aires',
		'Morocco Standard Time'           => 'Africa/Casablanca',
		'Middle East Standard Time'       => 'Asia/Beirut',
		'Jordan Standard Time'            => 'Asia/Amman',
		'Arab Standard Time'              => 'Asia/Riyadh',
		'Iran Standard Time'              => 'Asia/Tehran',
		'Pakistan Standard Time'          => 'Asia/Karachi',
		'SE Asia Standard Time'           => 'Asia/Bangkok',
		'Taipei Standard Time'            => 'Asia/Taipei',
		'W. Australia Standard Time'      => 'Australia/Perth',
		'Cen. Australia Standard Time'    => 'Australia/Adelaide',
		'E. Australia Standard Time'      => 'Australia/Brisbane',
		'Tasmania Standard Time'          => 'Australia/Hobart',
		'Sri Lanka Standard Time'         => 'Asia/Colombo',
		'Bangladesh Standard Time'        => 'Asia/Dhaka',
		'Nepal Standard Time'             => 'Asia/Kathmandu',
		'Ekaterinburg Standard Time'      => 'Asia/Yekaterinburg',
		'Mexico Standard Time'            => 'America/Mexico_City',
		'Central Standard Time (Mexico)'  => 'America/Mexico_City',
		'Pacific SA Standard Time'        => 'America/Santiago',
		'Venezuela Standard Time'         => 'America/Caracas',
		'Newfoundland Standard Time'      => 'America/St_Johns',
		'Azores Standard Time'            => 'Atlantic/Azores',
		'Cape Verde Standard Time'        => 'Atlantic/Cape_Verde',
		'Kaliningrad Standard Time'       => 'Europe/Kaliningrad',
		'Belarus Standard Time'           => 'Europe/Minsk',
		'Georgian Standard Time'          => 'Asia/Tbilisi',
		'Caucasus Standard Time'          => 'Asia/Yerevan',
		'Azerbaijan Standard Time'        => 'Asia/Baku',
		'West Asia Standard Time'         => 'Asia/Tashkent',
		'Central Asia Standard Time'      => 'Asia/Almaty',
		'N. Central Asia Standard Time'   => 'Asia/Novosibirsk',
		'North Asia Standard Time'        => 'Asia/Krasnoyarsk',
		'North Asia East Standard Time'   => 'Asia/Irkutsk',
		'Yakutsk Standard Time'           => 'Asia/Yakutsk',
		'Vladivostok Standard Time'       => 'Asia/Vladivostok',
		'Magadan Standard Time'           => 'Asia/Magadan',
		'Fiji Standard Time'              => 'Pacific/Fiji',
		'Tonga Standard Time'             => 'Pacific/Tongatapu',
		'Samoa Standard Time'             => 'Pacific/Apia',
		'Dateline Standard Time'          => 'Etc/GMT+12',
		'Mid-Atlantic Standard Time'      => 'Etc/GMT+2',
		'UTC-02'                          => 'Etc/GMT+2',
		'UTC-11'                          => 'Etc/GMT+11',
		'UTC+12'                          => 'Etc/GMT-12',
		'Afghanistan Standard Time'       => 'Asia/Kabul',
		'Myanmar Standard Time'           => 'Asia/Rangoon',
		'North Korea Standard Time'       => 'Asia/Pyongyang',
		'Syria Standard Time'             => 'Asia/Damascus',
		'Libya Standard Time'             => 'Africa/Tripoli',
		'Namibia Standard Time'           => 'Africa/Windhoek',
		'E. Africa Standard Time'         => 'Africa/Nairobi',
		'Mauritius Standard Time'         => 'Indian/Mauritius',
		'Paraguay Standard Time'          => 'America/Asuncion',
		'Montevideo Standard Time'        => 'America/Montevideo',
		'SA Eastern Standard Time'        => 'America/Cayenne',
		'SA Western Standard Time'        => 'America/La_Paz',
		'Greenland Standard Time'         => 'America/Godthab',
		'Central Brazilian Standard Time' => 'America/Cuiaba',
		'Eastern Standard Time (Mexico)'  => 'America/Cancun',
		'Mountain Standard Time (Mexico)' => 'America/Chihuahua',
		'Pacific Standard Time (Mexico)'  => 'America/Tijuana',
		'Sudan Standard Time'             => 'Africa/Khartoum',
		'Saint Pierre Standard Time'      => 'America/Miquelon',
		'Cuba Standard Time'              => 'America/Havana',
		'Haiti Standard Time'             => 'America/Port-au-Prince',
		'Turks And Caicos Standard Time'  => 'America/Grand_Turk',
		'Bahia Standard Time'             => 'America/Bahia',
		'Tocantins Standard Time'         => 'America/Araguaina',
		'Magallanes Standard Time'        => 'America/Punta_Arenas',
		'Easter Island Standard Time'     => 'Pacific/Easter',
		'Marquesas Standard Time'         => 'Pacific/Marquesas',
		'Norfolk Standard Time'           => 'Pacific/Norfolk',
		'Lord Howe Standard Time'         => 'Australia/Lord_Howe',
		'Chatham Islands Standard Time'   => 'Pacific/Chatham',
		'Line Islands Standard Time'      => 'Pacific/Kiritimati',
		'Aus Central W. Standard Time'    => 'Australia/Eucla',
		'AUS Central Standard Time'       => 'Australia/Darwin',
		'Transbaikal Standard Time'       => 'Asia/Chita',
		'Sakhalin Standard Time'          => 'Asia/Sakhalin',
		'Russia Time Zone 3'              => 'Europe/Samara',
		'Russia Time Zone 10'             => 'Asia/Srednekolymsk',
		'Russia Time Zone 11'             => 'Asia/Kamchatka',
		'Astrakhan Standard Time'         => 'Europe/Astrakhan',
		'Saratov Standard Time'           => 'Europe/Saratov',
		'Volgograd Standard Time'         => 'Europe/Volgograd',
		'Altai Standard Time'             => 'Asia/Barnaul',
		'Tomsk Standard Time'             => 'Asia/Tomsk',
		'Omsk Standard Time'              => 'Asia/Omsk',
		'W. Mongolia Standard Time'       => 'Asia/Hovd',
		'Ulaanbaatar Standard Time'       => 'Asia/Ulaanbaatar',
		'Qyzylorda Standard Time'         => 'Asia/Qyzylorda',
		'West Bank Standard Time'         => 'Asia/Hebron',
		'Sao Tome Standard Time'          => 'Africa/Sao_Tome',
		'South Sudan Standard Time'       => 'Africa/Juba',
		'Bougainville Standard Time'      => 'Pacific/Bougainville',
		'Kamchatka Standard Time'         => 'Asia/Kamchatka',
		'Yukon Standard Time'             => 'America/Whitehorse',
	);

	/**
	 * Default time zone for floating times and all-day events.
	 *
	 * @var DateTimeZone
	 */
	private DateTimeZone $tz;

	/**
	 * Constructor.
	 *
	 * @param DateTimeZone $tz Time zone for floating times (the calendar owner's zone).
	 */
	public function __construct( DateTimeZone $tz ) {
		$this->tz = $tz;
	}

	/**
	 * Parse a calendar and return busy intervals overlapping [from, to).
	 *
	 * @param string $ics  Calendar text.
	 * @param int    $from UTC timestamp.
	 * @param int    $to   UTC timestamp.
	 * @return list<array{uid:string, start:int, end:int, all_day:bool}>
	 */
	public function busy( string $ics, int $from, int $to ): array {
		$cal_tz = $this->calendar_timezone( $ics );
		if ( $cal_tz ) {
			$this->tz = $cal_tz;
		}
		$events = $this->events( $ics );

		// Overrides of single instances (RECURRENCE-ID) replace the original instance.
		$overridden = array();
		foreach ( $events as $e ) {
			if ( null !== $e['recurrence_id'] ) {
				$overridden[ $e['uid'] ][ $e['recurrence_id'] ] = true;
			}
		}

		$out = array();
		foreach ( $events as $e ) {
			if ( null !== $e['recurrence_id'] ) {
				// Override: a cancelled instance simply removes the original occurrence.
				if ( $e['blocking'] && $e['end'] > $from && $e['start'] < $to ) {
					$out[] = array(
						'uid'     => $e['uid'],
						'start'   => $e['start'],
						'end'     => $e['end'],
						'all_day' => $e['all_day'],
					);
				}
				continue;
			}
			if ( ! $e['blocking'] ) {
				continue;
			}
			$skip = $overridden[ $e['uid'] ] ?? array();
			foreach ( $this->occurrences( $e, $from, $to ) as $start ) {
				if ( isset( $e['exdates'][ $start ] ) || isset( $skip[ $start ] ) ) {
					continue;
				}
				$end = $e['all_day'] ? $this->add_days( $start, $e['days'] ) : $start + $e['duration'];
				if ( $end > $from && $start < $to ) {
					$out[] = array(
						'uid'     => $e['uid'],
						'start'   => $start,
						'end'     => $end,
						'all_day' => $e['all_day'],
					);
				}
				if ( count( $out ) >= self::MAX_OCCURRENCES ) {
					break 2;
				}
			}
		}

		usort( $out, static fn( $a, $b ) => $a['start'] <=> $b['start'] );
		return $out;
	}

	/**
	 * X-WR-TIMEZONE of the calendar (Google, Apple use it for floating times).
	 *
	 * @param string $ics Calendar.
	 */
	private function calendar_timezone( string $ics ): ?DateTimeZone {
		if ( preg_match( '/^X-WR-TIMEZONE(?:;[^:]*)?:(.+)$/mi', $ics, $m ) ) {
			return $this->zone( trim( $m[1] ) );
		}
		return null;
	}

	/**
	 * Unfold and split into content lines.
	 *
	 * @param string $ics Calendar.
	 * @return list<array{name:string, params:array<string,string>, value:string}>
	 */
	public static function lines( string $ics ): array {
		$ics   = str_replace( array( "\r\n", "\r" ), "\n", $ics );
		$ics   = preg_replace( "/\n[ \t]/", '', $ics ) ?? '';
		$lines = array();

		foreach ( explode( "\n", $ics ) as $raw ) {
			if ( '' === trim( $raw ) ) {
				continue;
			}
			// Split name;params:value respecting quoted parameter values.
			if ( ! preg_match( '/^([A-Za-z0-9-]+)((?:;[A-Za-z0-9-]+=(?:"[^"]*"|[^";:,]*)(?:,(?:"[^"]*"|[^";:,]*))*)*):(.*)$/', $raw, $m ) ) {
				continue;
			}
			$params = array();
			if ( '' !== $m[2] && preg_match_all( '/;([A-Za-z0-9-]+)=((?:"[^"]*"|[^";:,]*)(?:,(?:"[^"]*"|[^";:,]*))*)/', $m[2], $pm, PREG_SET_ORDER ) ) {
				foreach ( $pm as $p ) {
					$params[ strtoupper( $p[1] ) ] = trim( $p[2], '"' );
				}
			}
			$lines[] = array(
				'name'   => strtoupper( $m[1] ),
				'params' => $params,
				'value'  => $m[3],
			);
		}

		return $lines;
	}

	/**
	 * VEVENTs with resolved times.
	 *
	 * @param string $ics Calendar.
	 * @return list<array<string, mixed>>
	 */
	public function events( string $ics ): array {
		$events = array();
		$cur    = null;
		$depth  = 0;

		foreach ( self::lines( $ics ) as $line ) {
			$name = $line['name'];
			if ( 'BEGIN' === $name ) {
				if ( 'VEVENT' === strtoupper( $line['value'] ) && null === $cur ) {
					$cur   = array();
					$depth = 0;
				} elseif ( null !== $cur ) {
					++$depth; // VALARM etc. inside the event.
				}
				continue;
			}
			if ( 'END' === $name ) {
				if ( null !== $cur && $depth > 0 ) {
					--$depth;
				} elseif ( null !== $cur && 'VEVENT' === strtoupper( $line['value'] ) ) {
					$event = $this->build( $cur );
					if ( $event ) {
						$events[] = $event;
						if ( count( $events ) >= self::MAX_EVENTS ) {
							break;
						}
					}
					$cur = null;
				}
				continue;
			}
			if ( null === $cur || $depth > 0 ) {
				continue;
			}
			if ( in_array( $name, array( 'EXDATE', 'RDATE' ), true ) ) {
				$cur[ $name ][] = $line;
			} else {
				$cur[ $name ] = $line;
			}
		}

		return $events;
	}

	/**
	 * Turn raw properties into an event.
	 *
	 * @param array<string, mixed> $p Properties.
	 * @return array<string, mixed>|null
	 */
	private function build( array $p ): ?array {
		if ( empty( $p['DTSTART'] ) ) {
			return null;
		}
		$start = $this->time( $p['DTSTART'] );
		if ( ! $start ) {
			return null;
		}
		$all_day = $start['date'];

		$days     = 1;
		$duration = 0;
		if ( ! empty( $p['DTEND'] ) && ( $end = $this->time( $p['DTEND'] ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
			if ( $all_day ) {
				$days = max( 1, (int) round( ( $end['ts'] - $start['ts'] ) / 86400 ) );
			}
			$duration = $end['ts'] - $start['ts'];
		} elseif ( ! empty( $p['DURATION'] ) ) {
			$seconds = self::duration( (string) $p['DURATION']['value'] );
			if ( $all_day ) {
				$days = max( 1, (int) round( $seconds / 86400 ) );
			}
			$duration = $seconds;
		} elseif ( $all_day ) {
			$duration = 86400;
		}

		$status   = strtoupper( trim( (string) ( $p['STATUS']['value'] ?? '' ) ) );
		$transp   = strtoupper( trim( (string) ( $p['TRANSP']['value'] ?? '' ) ) );
		$busy     = strtoupper( trim( (string) ( $p['X-MICROSOFT-CDO-BUSYSTATUS']['value'] ?? '' ) ) );
		$blocking = 'CANCELLED' !== $status && 'TRANSPARENT' !== $transp && 'FREE' !== $busy && ( $all_day || $duration > 0 );

		$exdates = array();
		foreach ( (array) ( $p['EXDATE'] ?? array() ) as $ex ) {
			foreach ( explode( ',', (string) $ex['value'] ) as $v ) {
				$t = $this->time(
					array(
						'params' => $ex['params'],
						'value'  => $v,
					)
				);
				if ( $t ) {
					$exdates[ $this->align( $t, $start ) ] = true;
				}
			}
		}

		$rdates = array();
		foreach ( (array) ( $p['RDATE'] ?? array() ) as $rd ) {
			if ( 'PERIOD' === strtoupper( $rd['params']['VALUE'] ?? '' ) ) {
				continue;
			}
			foreach ( explode( ',', (string) $rd['value'] ) as $v ) {
				$t = $this->time(
					array(
						'params' => $rd['params'],
						'value'  => $v,
					)
				);
				if ( $t ) {
					$rdates[] = $this->align( $t, $start );
				}
			}
		}

		$recurrence = null;
		if ( ! empty( $p['RECURRENCE-ID'] ) ) {
			$r = $this->time( $p['RECURRENCE-ID'] );
			if ( $r ) {
				$recurrence = $this->align( $r, $start );
			}
		}

		return array(
			'uid'           => trim( (string) ( $p['UID']['value'] ?? '' ) ),
			'start'         => $start['ts'],
			'tz'            => $start['tz'],
			'end'           => $all_day ? $this->add_days( $start['ts'], $days ) : $start['ts'] + $duration,
			'duration'      => $duration,
			'days'          => $days,
			'all_day'       => $all_day,
			'blocking'      => $blocking,
			'rrule'         => ! empty( $p['RRULE'] ) ? self::rrule( (string) $p['RRULE']['value'] ) : null,
			'exdates'       => $exdates,
			'rdates'        => $rdates,
			'recurrence_id' => $recurrence,
		);
	}

	/**
	 * Map a date-only value to the time of day of DTSTART (EXDATE;VALUE=DATE on timed events).
	 *
	 * @param array{ts:int, date:bool, tz:DateTimeZone} $t     Time.
	 * @param array{ts:int, date:bool, tz:DateTimeZone} $start Event start.
	 */
	private function align( array $t, array $start ): int {
		if ( ! $t['date'] || $start['date'] ) {
			return $t['ts'];
		}
		$day = ( new DateTimeImmutable( '@' . $t['ts'] ) )->setTimezone( $start['tz'] );
		$ref = ( new DateTimeImmutable( '@' . $start['ts'] ) )->setTimezone( $start['tz'] );
		return $day->setTime( (int) $ref->format( 'H' ), (int) $ref->format( 'i' ), (int) $ref->format( 's' ) )->getTimestamp();
	}

	/**
	 * Parse a DATE or DATE-TIME property.
	 *
	 * @param array{params:array<string,string>, value:string} $line Line.
	 * @return array{ts:int, date:bool, tz:DateTimeZone}|null
	 */
	private function time( array $line ): ?array {
		$value = strtoupper( trim( (string) $line['value'] ) );
		$tz    = isset( $line['params']['TZID'] ) ? ( $this->zone( $line['params']['TZID'] ) ?? $this->tz ) : $this->tz;

		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
			if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return null;
			}
			$d = new DateTimeImmutable( "{$m[1]}-{$m[2]}-{$m[3]} 00:00:00", $this->tz );
			return array(
				'ts'   => $d->getTimestamp(),
				'date' => true,
				'tz'   => $this->tz,
			);
		}

		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $value, $m ) ) {
			if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 60 ) {
				return null;
			}
			$zone = 'Z' === $m[7] ? new DateTimeZone( 'UTC' ) : $tz;
			try {
				$d = new DateTimeImmutable( "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:" . min( 59, (int) $m[6] ), $zone );
			} catch ( Exception $e ) {
				return null;
			}
			return array(
				'ts'   => $d->getTimestamp(),
				'date' => false,
				// Recurrences of UTC events are expanded in the owner's zone so DST shifts behave like in calendar apps.
				'tz'   => 'Z' === $m[7] ? $this->tz : $tz,
			);
		}

		return null;
	}

	/**
	 * Resolve a TZID (IANA, Outlook/Windows names, Mozilla-style prefixes).
	 *
	 * @param string $tzid TZID.
	 */
	private function zone( string $tzid ): ?DateTimeZone {
		$tzid = trim( $tzid, " \"\t" );
		if ( isset( self::WINDOWS_ZONES[ $tzid ] ) ) {
			$tzid = self::WINDOWS_ZONES[ $tzid ];
		}
		if ( preg_match( '#([A-Za-z]+/[A-Za-z_+\-]+(?:/[A-Za-z_+\-]+)?)$#', $tzid, $m ) ) {
			$tzid = $m[1];
		}
		try {
			return new DateTimeZone( $tzid );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * ISO 8601 duration to seconds (P1W, P1DT2H, PT30M, -PT15M).
	 *
	 * @param string $value Duration.
	 */
	public static function duration( string $value ): int {
		if ( ! preg_match( '/^([+-]?)P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', trim( $value ), $m ) ) {
			return 0;
		}
		$s = (int) ( $m[2] ?? 0 ) * 604800 + (int) ( $m[3] ?? 0 ) * 86400 + (int) ( $m[4] ?? 0 ) * 3600 + (int) ( $m[5] ?? 0 ) * 60 + (int) ( $m[6] ?? 0 );
		return '-' === $m[1] ? -$s : $s;
	}

	/**
	 * Parse an RRULE value.
	 *
	 * @param string $value RRULE.
	 * @return array<string, string>
	 */
	public static function rrule( string $value ): array {
		$rule = array();
		foreach ( explode( ';', strtoupper( $value ) ) as $part ) {
			if ( str_contains( $part, '=' ) ) {
				[ $k, $v ]          = explode( '=', $part, 2 );
				$rule[ trim( $k ) ] = trim( $v );
			}
		}
		return $rule;
	}

	/**
	 * Add whole calendar days in the owner's zone (keeps midnight across DST).
	 *
	 * @param int $ts   Timestamp.
	 * @param int $days Days.
	 */
	private function add_days( int $ts, int $days ): int {
		return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $this->tz )->modify( "+{$days} days" )->getTimestamp();
	}

	/**
	 * Start timestamps of all instances that may overlap the window.
	 *
	 * @param array<string, mixed> $e    Event.
	 * @param int                  $from Window start.
	 * @param int                  $to   Window end.
	 * @return list<int>
	 */
	private function occurrences( array $e, int $from, int $to ): array {
		$starts = array( $e['start'] );
		foreach ( $e['rdates'] as $rd ) {
			$starts[] = $rd;
		}
		if ( $e['rrule'] ) {
			$length = max( $e['all_day'] ? $e['days'] * 86400 + 7200 : $e['duration'], 0 );
			$starts = array_merge( $starts, ( new Rrule( $e['rrule'], $e['start'], $e['tz'] ) )->between( $from - $length, $to ) );
		}
		return array_values( array_unique( $starts ) );
	}
}
