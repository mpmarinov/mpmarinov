<?php
/**
 * Calendar files (iCalendar, .ics) for appointments.
 *
 * @package Bookora
 */

namespace Bookora\Notifications;

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
		$uid   = 'bookora-' . (int) $booking['id'] . '@' . ( '' !== $host ? $host : 'localhost' );
		$utc   = static fn( string $iso ): string => gmdate( 'Ymd\THis\Z', (int) strtotime( $iso ) );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Marinov Solutions//Bookora//DE',
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
