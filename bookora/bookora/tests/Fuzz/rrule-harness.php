<?php
/**
 * Reads JSON lines {rule, start, tz, from, to} from STDIN and prints the instances computed by Bookora's Rrule.
 * Used by rrule-fuzz.py to compare against python-dateutil.
 *
 * @package Bookora
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

while ( ( $line = fgets( STDIN ) ) !== false ) { // phpcs:ignore
	$c    = json_decode( $line, true );
	$tz   = new DateTimeZone( $c['tz'] );
	$rule = \Bookora\Calendar\IcsParser::rrule( $c['rule'] );
	$st   = ( new DateTimeImmutable( $c['start'], $tz ) )->getTimestamp();
	echo wp_json( ( new \Bookora\Calendar\Rrule( $rule, $st, $tz ) )->between( $c['from'], $c['to'] ) ), "\n"; // phpcs:ignore
}

function wp_json( $v ) { return json_encode( $v ); } // phpcs:ignore
