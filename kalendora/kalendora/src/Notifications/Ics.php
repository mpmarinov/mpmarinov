<?php
/**
 * Calendar files (iCalendar, .ics) for appointments.
 *
 * @package Kalendora
 */

namespace Kalendora\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * RFC 5545 VEVENT with METHOD:PUBLISH (adds to calendar without an invitation workflow).
 */
final class Ics {

	/**
	 * Build the calendar file.
	 *
	 * @param array<string, mixed> $booking  Formatted booking (start/end ISO, uid optional).
	 * @param string               $summary  Event title.
	 * @param string               $location Location.
	 * @param string               $description Description.
	 * @param string               $status   CONFIRMED|CANCELLED|TENTATIVE.
	 */
	public static function event( array $booking, string $summary, string $location = '', string $description = '', string $status = 'CONFIRMED' ): string {
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$uid   = 'kalendora-' . (int) $booking['id'] . '@' . ( '' !== $host ? $host : 'localhost' );
		$utc   = static fn( string $iso ): string => gmdate( 'Ymd\THis\Z', (int) strtotime( $iso ) );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Marinov Solutions//Kalendora//DE',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . $utc( (string) $booking['start'] ),
			'DTEND:' . $utc( (string) $booking['end'] ),
			'SUMMARY:' . self::escape( $summary ),
			'STATUS:' . $status,
		);
		if ( '' !== $location ) {
			$lines[] = 'LOCATION:' . self::escape( $location );
		}
		if ( '' !== $description ) {
			$lines[] = 'DESCRIPTION:' . self::escape( $description );
		}
		$lines[] = 'BEGIN:VALARM';
		$lines[] = 'TRIGGER:-PT1H';
		$lines[] = 'ACTION:DISPLAY';
		$lines[] = 'DESCRIPTION:' . self::escape( $summary );
		$lines[] = 'END:VALARM';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * Calendar with many events (subscription feed). Output is stable for unchanged data (ETag-friendly).
	 *
	 * @param list<array{booking:array<string,mixed>, summary:string, location?:string, description?:string, status?:string}> $events Events.
	 * @param string                                                                                                          $name   Calendar name.
	 * @param string                                                                                                          $tz     Time zone name (informational).
	 */
	public static function calendar( array $events, string $name, string $tz = 'UTC' ): string {
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host  = '' !== $host ? $host : 'localhost';
		$utc   = static fn( string $iso ): string => gmdate( 'Ymd\THis\Z', (int) strtotime( $iso ) );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Marinov Solutions//Kalendora//DE',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escape( $name ),
			'X-WR-TIMEZONE:' . self::escape( $tz ),
			'REFRESH-INTERVAL;VALUE=DURATION:PT15M',
			'X-PUBLISHED-TTL:PT15M',
		);
		foreach ( $events as $e ) {
			$b       = $e['booking'];
			$changed = gmdate( 'Ymd\THis\Z', (int) strtotime( (string) ( $b['updated_at'] ?? $b['created_at'] ?? 'now' ) . ' UTC' ) );
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:kalendora-' . (int) $b['id'] . '@' . $host;
			$lines[] = 'DTSTAMP:' . $changed;
			$lines[] = 'LAST-MODIFIED:' . $changed;
			$lines[] = 'DTSTART:' . $utc( (string) $b['start'] );
			$lines[] = 'DTEND:' . $utc( (string) $b['end'] );
			$lines[] = 'SUMMARY:' . self::escape( $e['summary'] );
			$lines[] = 'STATUS:' . ( $e['status'] ?? 'CONFIRMED' );
			if ( ! empty( $e['location'] ) ) {
				$lines[] = 'LOCATION:' . self::escape( $e['location'] );
			}
			if ( ! empty( $e['description'] ) ) {
				$lines[] = 'DESCRIPTION:' . self::escape( $e['description'] );
			}
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * "Add to Google Calendar" link.
	 *
	 * @param array<string, mixed> $booking  Booking.
	 * @param string               $summary  Title.
	 * @param string               $location Location.
	 */
	public static function google_link( array $booking, string $summary, string $location = '' ): string {
		$fmt = static fn( string $iso ): string => gmdate( 'Ymd\THis\Z', (int) strtotime( $iso ) );
		return add_query_arg(
			array_map(
				'rawurlencode',
				array(
					'action'   => 'TEMPLATE',
					'text'     => $summary,
					'dates'    => $fmt( (string) $booking['start'] ) . '/' . $fmt( (string) $booking['end'] ),
					'location' => $location,
				)
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/**
	 * Escape text values (RFC 5545 3.3.11).
	 *
	 * @param string $text Text.
	 */
	public static function escape( string $text ): string {
		$text = str_replace( array( '\\', ';', ',', "\r\n", "\n", "\r" ), array( '\\\\', '\;', '\,', '\n', '\n', '' ), $text );
		return wp_strip_all_tags( $text );
	}

	/**
	 * Fold lines longer than 75 octets.
	 *
	 * @param string $line Line.
	 */
	public static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$chunk = '';
		foreach ( preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			if ( strlen( $chunk . $char ) > ( '' === $out ? 75 : 74 ) ) {
				$out  .= ( '' === $out ? '' : "\r\n " ) . $chunk;
				$chunk = '';
			}
			$chunk .= $char;
		}
		return $out . ( '' === $out ? '' : "\r\n " ) . $chunk;
	}
}
