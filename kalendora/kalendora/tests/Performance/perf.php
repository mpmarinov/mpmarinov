<?php
/**
 * Load test on a real database: seeds a busy practice and measures the hot paths.
 *
 * Usage: php tests/Performance/perf.php /path/to/wordpress [staff=20] [--keep]
 * Needs an installation that is only used for testing: it deletes all Kalendora data first.
 *
 * @package Kalendora
 */

// phpcs:ignoreFile

if ( empty( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php perf.php /path/to/wordpress [staff] [--keep]\n" );
	exit( 2 );
}
define( 'SAVEQUERIES', true );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require rtrim( $argv[1], '/' ) . '/wp-load.php';
update_option( 'timezone_string', 'Europe/Berlin' );
wp_set_current_user( 1 );
// A large practice is a Pro case: lift the Free staff limit for this test only.
add_filter( 'kalendora_resource_limit', static fn() => 1000 );

global $wpdb;
$staff_count = max( 1, (int) ( $argv[2] ?? 20 ) );
$keep        = in_array( '--keep', $argv, true );
$t           = static fn( string $name ): string => \Kalendora\Install\Schema::table( $wpdb->prefix, $name );
$c           = \Kalendora\Core\Plugin::instance()->container();

function api( string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/kalendora/v1' . $route );
	if ( $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	if ( $query ) {
		$request->set_query_params( $query );
	}
	return rest_ensure_response( rest_do_request( $request ) );
}

/** Multi-row insert in chunks. */
function bulk( string $table, array $columns, array $rows ): void {
	global $wpdb;
	foreach ( array_chunk( $rows, 1000 ) as $chunk ) {
		$values = array();
		foreach ( $chunk as $row ) {
			$values[] = '(' . implode( ',', array_map( static fn( $v ) => null === $v ? 'NULL' : "'" . esc_sql( (string) $v ) . "'", $row ) ) . ')';
		}
		$wpdb->query( "INSERT INTO {$table} (" . implode( ',', $columns ) . ') VALUES ' . implode( ',', $values ) );
	}
}

$results = array();
/** Median time and query count of a callable. */
function measure( string $label, callable $fn, int $runs = 5 ) {
	global $wpdb, $results;
	$times = array();
	$out   = null;
	memory_reset_peak_usage();
	$base  = memory_get_usage();
	$slow  = array();
	for ( $i = 0; $i < $runs; $i++ ) {
		$wpdb->queries = array();
		$start         = microtime( true );
		$out           = $fn();
		$times[]       = ( microtime( true ) - $start ) * 1000;
		$queries       = count( $wpdb->queries );
		foreach ( $wpdb->queries as $q ) {
			if ( $q[1] > 0.05 ) {
				$slow[ substr( preg_replace( '/\s+/', ' ', $q[0] ), 0, 160 ) ] = round( $q[1] * 1000 );
			}
		}
	}
	sort( $times );
	$median    = $times[ intdiv( count( $times ), 2 ) ];
	$results[] = array( $label, $median, $queries );
	$mem = ( memory_get_peak_usage() - $base ) / 1048576;
	printf( "  %-52s %8.1f ms %5d queries %6.1f MB\n", $label, $median, $queries, $mem );
	foreach ( $slow as $sql => $ms ) {
		printf( "      slow query %4d ms: %s\n", $ms, $sql );
	}
	return $out;
}

echo "Database: " . $wpdb->db_server_info() . "\n";

// ---------------------------------------------------------------- seed
$seed_start = microtime( true );
foreach ( array( 'files', 'booking_sync', 'action_tokens', 'notification_log', 'entry_values', 'bookings', 'entries', 'customers', 'resource_services', 'schedules', 'schedule_exceptions', 'external_events', 'calendar_connections', 'services', 'resources', 'forms' ) as $table ) {
	$wpdb->query( "TRUNCATE TABLE {$t( $table )}" );
}
$c->get( \Kalendora\Settings\Settings::class )->update( array( 'reminder_hours' => array( 168, 24 ) ) );

api( 'PUT', '/schedules/weekly', array( 'weekly' => array_fill_keys( array( 1, 2, 3, 4, 5 ), array( array( '08:00', '18:00' ) ) ) ) );
$staff = array();
for ( $i = 1; $i <= $staff_count; $i++ ) {
	$staff[] = (int) api( 'POST', '/resources', array( 'name' => "Mitarbeiter {$i}", 'email' => "staff{$i}@example.com" ) )->get_data()['id'];
}
$services = array();
foreach ( array( 'Massage 60' => 60, 'Physio 30' => 30, 'Beratung 45' => 45, 'Fußpflege 60' => 60, 'Kontrolle 15' => 15, 'Behandlung 90' => 90 ) as $name => $duration ) {
	$services[] = (int) api( 'POST', '/services', array( 'name' => $name, 'duration' => $duration, 'price' => 50, 'resource_ids' => $staff ) )->get_data()['id'];
}
$now   = current_time( 'mysql', true );
$fields = wp_json_encode( array( array( 'type' => 'name', 'key' => 'name', 'label' => 'Name' ), array( 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail' ), array( 'type' => 'phone', 'key' => 'phone', 'label' => 'Telefon' ), array( 'type' => 'textarea', 'key' => 'note', 'label' => 'Nachricht' ) ) );
$wpdb->insert( $t( 'forms' ), array( 'title' => 'Termin buchen', 'type' => 'booking', 'status' => 'published', 'fields' => $fields, 'settings' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
$booking_form = (int) $wpdb->insert_id;
$wpdb->insert( $t( 'forms' ), array( 'title' => 'Kontakt', 'type' => 'standard', 'status' => 'published', 'fields' => $fields, 'settings' => '{}', 'created_at' => $now, 'updated_at' => $now ) );
$contact_form = (int) $wpdb->insert_id;

// Customers.
$first = array( 'Anna', 'Ben', 'Clara', 'David', 'Eva', 'Felix', 'Greta', 'Hans', 'Ida', 'Jonas', 'Katja', 'Lukas', 'Mia', 'Noah', 'Olga', 'Paul' );
$last  = array( 'Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner', 'Becker', 'Hoffmann', 'Koch', 'Petrov', 'Ivanova' );
$customer_count = 15000 * max( 1, intdiv( $staff_count, 10 ) );
$rows = array();
for ( $i = 1; $i <= $customer_count; $i++ ) {
	$name   = $first[ $i % 16 ] . ' ' . $last[ intdiv( $i, 16 ) % 12 ];
	$rows[] = array( $i, "kunde{$i}@example.com", $name, '+49 30 ' . ( 1000000 + $i ), $now, $now );
}
bulk( $t( 'customers' ), array( 'id', 'email', 'name', 'phone', 'created_at', 'updated_at' ), $rows );

// Bookings: every staff member, every weekday from 2 years ago to 3 months ahead, ~85 % booked.
mt_srand( 42 );
$tz       = wp_timezone();
$day      = new DateTimeImmutable( '-2 years monday', $tz );
$end      = new DateTimeImmutable( '+3 months', $tz );
$today    = new DateTimeImmutable( 'today', $tz );
$b_rows   = array();
$e_rows   = array();
$v_rows   = array();
$entry_id = 0;
$book_id  = 0;
$cols_b   = array( 'id', 'uid', 'entry_id', 'form_id', 'service_id', 'resource_id', 'customer_id', 'start_utc', 'end_utc', 'timezone', 'status', 'policy', 'fee_amount', 'fee_status', 'created_at', 'updated_at' );
$cols_e   = array( 'id', 'uid', 'form_id', 'customer_id', 'status', 'created_at', 'updated_at' );
$cols_v   = array( 'entry_id', 'field_key', 'value' );
$flush    = static function () use ( &$b_rows, &$e_rows, &$v_rows, $t, $cols_b, $cols_e, $cols_v ) {
	bulk( $t( 'entries' ), $cols_e, $e_rows );
	bulk( $t( 'entry_values' ), $cols_v, $v_rows );
	bulk( $t( 'bookings' ), $cols_b, $b_rows );
	$b_rows = $e_rows = $v_rows = array();
};
for ( ; $day < $end; $day = $day->modify( '+1 day' ) ) {
	if ( (int) $day->format( 'N' ) > 5 ) {
		continue;
	}
	$future = $day >= $today;
	foreach ( $staff as $rid ) {
		for ( $h = 8; $h < 18; $h++ ) {
			if ( mt_rand( 1, 100 ) > ( $future ? 55 : 85 ) ) {
				continue;
			}
			$start = $day->setTime( $h, 0 )->setTimezone( new DateTimeZone( 'UTC' ) );
			$cust  = mt_rand( 1, $customer_count );
			$roll  = mt_rand( 1, 100 );
			if ( $future ) {
				$status = $roll <= 20 ? 'pending' : ( $roll <= 95 ? 'confirmed' : 'cancelled' );
			} else {
				$status = $roll <= 85 ? 'completed' : ( $roll <= 92 ? 'cancelled' : ( $roll <= 96 ? 'no_show' : 'confirmed' ) );
			}
			$created  = $start->modify( '-' . mt_rand( 1, 30 ) . ' days' )->format( 'Y-m-d H:i:s' );
			$uid      = wp_generate_uuid4();
			$e_rows[] = array( ++$entry_id, wp_generate_uuid4(), $booking_form, $cust, 'read', $created, $created );
			$v_rows[] = array( $entry_id, 'name', '{"first":"' . $first[ $cust % 16 ] . '","last":"' . $last[ intdiv( $cust, 16 ) % 12 ] . '"}' );
			$v_rows[] = array( $entry_id, 'email', "kunde{$cust}@example.com" );
			$v_rows[] = array( $entry_id, 'phone', '+49 30 ' . ( 1000000 + $cust ) );
			$v_rows[] = array( $entry_id, 'note', 'Bitte Parkplatz, Termin ' . $start->format( 'd.m.' ) );
			$fee      = 'no_show' === $status ? 25 : 0;
			$b_rows[] = array( ++$book_id, $uid, $entry_id, $booking_form, $services[ $book_id % count( $services ) ], $rid, $cust, $start->format( 'Y-m-d H:i:s' ), $start->modify( '+1 hour' )->format( 'Y-m-d H:i:s' ), 'Europe/Berlin', $status, '{"fee":true,"fee_type":"percent","fee_value":50,"hours":24,"accepted_at":"' . $created . '"}', $fee, $fee ? 'open' : '', $created, $created );
		}
	}
	if ( count( $b_rows ) > 4000 ) {
		$flush();
	}
}
$flush();

// Contact form entries (no booking).
$rows = array();
$vals = array();
for ( $i = 0; $i < 20000; $i++ ) {
	$cust    = mt_rand( 1, $customer_count );
	$created = gmdate( 'Y-m-d H:i:s', time() - mt_rand( 0, 730 * 86400 ) );
	$rows[]  = array( ++$entry_id, wp_generate_uuid4(), $contact_form, $cust, 0 === $i % 10 ? 'new' : ( 0 === $i % 37 ? 'spam' : 'read' ), $created, $created );
	$vals[]  = array( $entry_id, 'email', "kunde{$cust}@example.com" );
	$vals[]  = array( $entry_id, 'note', "Anfrage Nummer {$i}: Haben Sie am Samstag geöffnet?" );
}
bulk( $t( 'entries' ), $cols_e, $rows );
bulk( $t( 'entry_values' ), $cols_v, $vals );
$wpdb->query( "ANALYZE TABLE {$t('bookings')}, {$t('entries')}, {$t('entry_values')}, {$t('customers')}" );

$counts = array();
foreach ( array( 'bookings', 'entries', 'entry_values', 'customers' ) as $table ) {
	$counts[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t( $table )}" );
}
printf( "Seeded in %.1f s: %d staff, %s\n\n", microtime( true ) - $seed_start, $staff_count, implode( ', ', array_map( static fn( $k, $v ) => number_format( $v, 0, ',', '.' ) . " {$k}", array_keys( $counts ), $counts ) ) );

// ---------------------------------------------------------------- measure
$next_week = ( new DateTimeImmutable( 'monday next week', $tz ) )->format( 'Y-m-d' );
$month_end = ( new DateTimeImmutable( 'monday next week', $tz ) )->modify( '+30 days' )->format( 'Y-m-d' );
$avail     = $c->get( \Kalendora\Availability\AvailabilityService::class );
$entries   = $c->get( \Kalendora\Repository\EntryRepository::class );

echo "Visitors (public)\n";
measure( 'slots: 1 service, any staff, 1 week', static fn() => $avail->slots( $services[0], null, $next_week, ( new DateTimeImmutable( $next_week ) )->modify( '+6 days' )->format( 'Y-m-d' ) ) );
$month = measure( 'slots: 1 service, any staff, 31 days (month view)', static fn() => $avail->slots( $services[1], null, $next_week, $month_end ) );
printf( "  (%d free start times)\n", count( $month ) );
measure( 'slots: 1 service, one staff, 31 days', static fn() => $avail->slots( $services[0], $staff[0], $next_week, $month_end ) );
measure( 'REST /availability (31 days)', static fn() => api( 'GET', '/availability', array(), array( 'service' => $services[2], 'from' => $next_week, 'to' => $month_end ) ) );
$booked = 0;
measure(
	'create booking (incl. conflict check, mails)',
	static function () use ( $c, $services, $avail, $next_week, $month_end, &$booked ) {
		$days = $avail->slots( $services[4], null, $next_week, $month_end );
		$slot = $days[ mt_rand( 0, count( $days ) - 1 ) ];
		$r    = $c->get( \Kalendora\Booking\BookingService::class )->create( array( 'service_id' => $services[4], 'start' => (int) $slot['start'], 'customer' => array( 'email' => 'perf' . mt_rand() . '@example.com', 'name' => 'Perf Test' ) ) );
		$booked += is_wp_error( $r ) ? 0 : 1;
		return $r;
	}
);
echo "  (bookings created: {$booked})\n";

echo "\nAdmin\n";
measure( 'bookings list: pending (default tab)', static fn() => $entries->bookings( array( 'view' => 'pending' ) ) );
measure( 'bookings list: upcoming', static fn() => $entries->bookings( array( 'view' => 'upcoming' ) ) );
measure( 'bookings list: past, page 200', static fn() => $entries->bookings( array( 'view' => 'past', 'page' => 200 ) ) );
measure( 'bookings list: all, filter by staff', static fn() => $entries->bookings( array( 'view' => 'all', 'resource_id' => $staff[ min( 3, count( $staff ) - 1 ) ] ) ) );
measure( 'bookings list: search name "Weber"', static fn() => $entries->bookings( array( 'view' => 'all', 'search' => 'Weber' ) ) );
measure( 'bookings list: open fees', static fn() => $entries->bookings( array( 'view' => 'fees' ) ) );
$day_tl = measure( 'timeline: day', static fn() => api( 'GET', '/timeline', array(), array( 'view' => 'day', 'date' => $next_week ) )->get_data() );
$week   = measure( 'timeline: week', static fn() => api( 'GET', '/timeline', array(), array( 'view' => 'week', 'date' => $next_week ) )->get_data() );
$expect = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t('bookings')} WHERE status NOT IN ('rejected','cancelled') AND start_utc >= %s AND start_utc < %s", get_gmt_from_date( $next_week . ' 00:00:00' ), get_gmt_from_date( ( new DateTimeImmutable( $next_week ) )->modify( '+7 days' )->format( 'Y-m-d' ) . ' 00:00:00' ) ) );
printf( "  (week shows %d of %d bookings)\n", count( $week['bookings'] ), $expect );
measure( 'entries list: contact form', static fn() => $entries->entries( array( 'form_id' => $contact_form ) ) );
measure( 'entries list: search in all values', static fn() => $entries->entries( array( 'search' => 'Samstag' ) ) );
measure( 'entry detail', static fn() => $entries->entry( 5000 ) );
measure( 'menu badges', static fn() => $entries->badge_counts() );
measure( 'CSV export: contact form (20.000 entries)', static fn() => $entries->export( $contact_form, static function ( $row ) {} ), 1 );
measure( 'CSV export: booking form (all bookings)', static fn() => $entries->export( $booking_form, static function ( $row ) {} ), 1 );

echo "\nBackground jobs\n";
$feed = $c->get( \Kalendora\Calendar\Feed::class );
$ics  = measure( 'calendar feed: whole business', static fn() => $feed->build( 'all' ), 3 );
printf( "  (feed size %.1f MB, %d events)\n", strlen( $ics ) / 1048576, substr_count( $ics, 'BEGIN:VEVENT' ) );
measure( 'calendar feed: one staff member', static fn() => $feed->build( 'r' . $staff[0] ), 3 );
$sent = measure( 'reminders (cron run, mails to the catcher)', static fn() => $c->get( \Kalendora\Notifications\Mailer::class )->send_reminders(), 1 );
echo "  (reminders sent: {$sent})\n";
measure( 'reminders: follow-up run (next batch)', static fn() => $c->get( \Kalendora\Notifications\Mailer::class )->send_reminders(), 1 );
measure( 'daily maintenance', static fn() => ( new \Kalendora\Core\Maintenance() )->run(), 1 );

printf( "\nPeak memory: %.0f MB\n", memory_get_peak_usage( true ) / 1048576 );
if ( ! $keep ) {
	foreach ( array( 'entry_values', 'bookings', 'entries', 'customers' ) as $table ) {
		$wpdb->query( "TRUNCATE TABLE {$t( $table )}" );
	}
}
