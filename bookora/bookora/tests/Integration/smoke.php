<?php
/**
 * End-to-end smoke test against a real WordPress installation (MySQL or SQLite).
 *
 * Usage: php tests/Integration/smoke.php /path/to/wordpress
 *
 * Never run this against a production site: it creates and deletes data.
 *
 * @package Bookora
 */

// phpcs:ignoreFile

if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) {
	fwrite( STDERR, "Usage: php smoke.php /path/to/wordpress\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require rtrim( $argv[1], '/' ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$failures = 0;
$passed   = 0;

function check( bool $condition, string $label, $debug = null ): void {
	global $failures, $passed;
	if ( $condition ) {
		$passed++;
		echo "  ✓ {$label}\n";
	} else {
		$failures++;
		echo "  ✗ {$label}\n";
		if ( null !== $debug ) {
			echo '    ' . str_replace( "\n", "\n    ", print_r( $debug, true ) ) . "\n";
		}
	}
}

function api( string $method, string $route, array $body = array(), array $query = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/bookora/v1' . $route );
	if ( $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	if ( $query ) {
		$request->set_query_params( $query );
	}
	return rest_ensure_response( rest_do_request( $request ) );
}

function next_weekday( int $weekday ): string {
	$d = new DateTimeImmutable( 'tomorrow', wp_timezone() );
	while ( (int) $d->format( 'w' ) !== $weekday ) {
		$d = $d->modify( '+1 day' );
	}
	return $d->modify( '+7 days' )->format( 'Y-m-d' );
}

global $wpdb;

if ( ! is_plugin_active( 'bookora/bookora.php' ) ) {
	$result = activate_plugin( 'bookora/bookora.php' );
	if ( null !== $result ) {
		echo "Activation failed:\n";
		print_r( $result );
		exit( 1 );
	}
	// Restart so WordPress loads the plugin through its normal boot sequence.
	passthru( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $argv[1] ), $code );
	exit( $code );
}

echo "Reset test data\n";
foreach ( \Bookora\Install\Schema::TABLES as $table ) {
	$wpdb->query( 'DELETE FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, $table ) );
}
\Bookora\Install\Installer::install();
rest_get_server();

echo "Activation\n";
check( is_plugin_active( 'bookora/bookora.php' ), 'plugin is active' );

foreach ( \Bookora\Install\Schema::TABLES as $table ) {
	$name   = \Bookora\Install\Schema::table( $wpdb->prefix, $table );
	$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
	check( $exists, "table {$table} exists" );
}
check( get_option( 'bookora_db_version' ) === \Bookora\Install\Schema::VERSION, 'schema version stored' );
check( (bool) get_role( 'bookora_manager' ), 'role "Booking Manager" created' );
check( get_role( 'administrator' )->has_cap( 'bookora_manage_settings' ), 'administrator has Bookora capabilities' );

echo "Permissions\n";
wp_set_current_user( 0 );
check( 401 === api( 'GET', '/settings' )->get_status(), 'anonymous user cannot read settings' );
check( 401 === api( 'POST', '/services', array( 'name' => 'Hack' ) )->get_status(), 'anonymous user cannot create services' );
check( 200 === api( 'GET', '/public/services' )->get_status(), 'public service list is reachable' );

wp_set_current_user( 1 );

echo "Settings\n";
$res = api( 'POST', '/settings', array( 'slot_interval' => 60, 'min_notice_minutes' => 0, 'max_advance_days' => 60, 'default_status' => 'pending', 'evil_key' => 'x', 'primary_color' => 'javascript:alert(1)' ) );
$set = $res->get_data();
check( 60 === $set['slot_interval'], 'slot interval saved' );
check( ! isset( $set['evil_key'] ), 'unknown keys are dropped' );
check( '#2563eb' === $set['primary_color'], 'invalid color is rejected' );

echo "Opening hours\n";
$res    = api( 'PUT', '/schedules/weekly', array( 'weekly' => array( 1 => array( array( '09:00', '12:00' ), array( '11:00', '13:00' ), array( '14:00', '16:00' ) ), 9 => array( array( '09:00', '10:00' ) ), 2 => array( array( 'bad', '10:00' ) ) ) ) );
$weekly = (array) $res->get_data()['weekly'];
check( array( array( '09:00', '13:00' ), array( '14:00', '16:00' ) ) === $weekly[1], 'overlapping ranges are merged', $weekly );
check( ! isset( $weekly[9] ) && ! isset( $weekly[2] ), 'invalid weekday and times are ignored', $weekly );

echo "Services\n";
check( 400 === api( 'POST', '/services', array( 'name' => '  ' ) )->get_status(), 'service without name is rejected' );
$res     = api( 'POST', '/services', array( 'name' => 'Haircut <script>', 'duration' => 60, 'buffer_after' => 0, 'capacity' => 1 ) );
$service = $res->get_data();
check( 201 === $res->get_status(), 'service created' );
check( 'Haircut' === $service['name'], 'name is sanitized', $service['name'] );
$res   = api( 'POST', '/services', array( 'name' => 'Yoga class', 'duration' => 60, 'capacity' => 3 ) );
$group = $res->get_data();

echo "Exceptions\n";
$monday   = next_weekday( 1 );
$monday2  = ( new DateTimeImmutable( $monday ) )->modify( '+7 days' )->format( 'Y-m-d' );
check( 400 === api( 'POST', '/schedules/exceptions', array( 'date_start' => '2026-02-30' ) )->get_status(), 'invalid date is rejected' );
check( 201 === api( 'POST', '/schedules/exceptions', array( 'date_start' => $monday2, 'type' => 'closed', 'note' => 'Holiday' ) )->get_status(), 'holiday created' );

echo "Availability\n";
$res  = api( 'GET', '/availability', array(), array( 'service' => $service['id'], 'from' => $monday, 'to' => $monday2 ) );
$days = (array) $res->get_data()['days'];
check( 200 === $res->get_status(), 'availability endpoint answers' );
$times = array_column( $days[ $monday ] ?? array(), 'time' );
check( array( '09:00', '10:00', '11:00', '12:00', '14:00', '15:00' ) === $times, 'monday slots match opening hours', $times );
check( ! isset( $days[ $monday2 ] ), 'holiday has no slots' );
check( 400 === api( 'GET', '/availability', array(), array( 'service' => $service['id'], 'from' => '2026-01-01', 'to' => '2026-12-31' ) )->get_status(), 'too long range is rejected' );
check( 404 === api( 'GET', '/availability', array(), array( 'service' => 99999, 'from' => $monday, 'to' => $monday ) )->get_status(), 'unknown service returns 404' );

echo "Booking & double-booking protection\n";
$bookings = \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Booking\BookingService::class );
$start    = ( new DateTimeImmutable( $monday . ' 10:00', wp_timezone() ) )->getTimestamp();
$input    = array(
	'service_id' => $service['id'],
	'start'      => $start,
	'customer'   => array( 'email' => 'anna@example.com', 'name' => 'Anna Test', 'phone' => '+49 123' ),
	'values'     => array( 'message' => 'Hello <b>world</b>' ),
	'ip'         => '203.0.113.5',
);
$first = $bookings->create( $input );
check( is_array( $first ) && $first['booking_id'] > 0, 'first booking succeeds', $first );
check( is_array( $first ) && 'pending' === $first['status'], 'default status is pending' );

$second = $bookings->create( array_merge( $input, array( 'customer' => array( 'email' => 'ben@example.com' ) ) ) );
check( is_wp_error( $second ) && 'bookora_slot_taken' === $second->get_error_code(), 'same slot cannot be booked twice', $second );

$times = array_column( ( (array) api( 'GET', '/availability', array(), array( 'service' => $service['id'], 'from' => $monday, 'to' => $monday ) )->get_data()['days'] )[ $monday ] ?? array(), 'time' );
check( ! in_array( '10:00', $times, true ), 'booked time disappears from availability', $times );

$offgrid = $bookings->create( array_merge( $input, array( 'start' => $start + 600 ) ) );
check( is_wp_error( $offgrid ), 'start time outside the slot grid is rejected' );
$closed = $bookings->create( array_merge( $input, array( 'start' => ( new DateTimeImmutable( $monday2 . ' 10:00', wp_timezone() ) )->getTimestamp() ) ) );
check( is_wp_error( $closed ), 'booking on a holiday is rejected' );
$bad_mail = $bookings->create( array_merge( $input, array( 'customer' => array( 'email' => 'not-an-email' ) ) ) );
check( is_wp_error( $bad_mail ) && 'bookora_invalid_email' === $bad_mail->get_error_code(), 'invalid email is rejected' );

echo "Group capacity\n";
$g = array_merge( $input, array( 'service_id' => $group['id'], 'start' => $start + 3600 ) );
check( ! is_wp_error( $bookings->create( array_merge( $g, array( 'seats' => 2 ) ) ) ), '2 of 3 seats booked' );
check( ! is_wp_error( $bookings->create( $g ) ), 'last seat booked' );
check( is_wp_error( $bookings->create( $g ) ), 'fourth participant is rejected' );
$times = array_column( ( (array) api( 'GET', '/availability', array(), array( 'service' => $service['id'], 'from' => $monday, 'to' => $monday ) )->get_data()['days'] )[ $monday ] ?? array(), 'time' );
check( ! in_array( '11:00', $times, true ), 'group class blocks the time for other services', $times );

echo "Resources\n";
$ids = array();
for ( $i = 1; $i <= 3; $i++ ) {
	$res   = api( 'POST', '/resources', array( 'name' => "Staff {$i}", 'email' => "staff{$i}@example.com" ) );
	$ids[] = $res->get_data()['id'] ?? 0;
}
check( 3 === count( array_filter( $ids ) ), 'three resources created' );
$res = api( 'POST', '/resources', array( 'name' => 'Staff 4' ) );
check( 403 === $res->get_status() && 'bookora_resource_limit' === $res->get_data()['code'], 'free limit of 3 resources is enforced' );
check( 400 === api( 'PUT', '/resources/' . $ids[0], array( 'email' => 'nope' ) )->get_status(), 'invalid resource email is rejected' );

$res = api( 'POST', '/services', array( 'name' => 'Consulting', 'duration' => 60, 'resource_ids' => array( $ids[0], $ids[1] ) ) );
$consult = $res->get_data();
check( array( $ids[0], $ids[1] ) === $consult['resource_ids'], 'resources assigned to service' );

$c  = array_merge( $input, array( 'service_id' => $consult['id'], 'start' => $start ) );
$b1 = $bookings->create( $c );
$b2 = $bookings->create( $c );
$b3 = $bookings->create( $c );
check( ! is_wp_error( $b1 ) && ! is_wp_error( $b2 ) && $b1['resource_id'] !== $b2['resource_id'], '"any staff" picks a free resource each time', array( $b1, $b2 ) );
check( is_wp_error( $b3 ), 'third booking fails when both staff members are busy' );

$res   = api( 'GET', '/availability', array(), array( 'service' => $consult['id'], 'from' => $monday, 'to' => $monday ) );
$slots = ( (array) $res->get_data()['days'] )[ $monday ] ?? array();
check( ! in_array( '10:00', array_column( $slots, 'time' ), true ), 'slot hidden when all resources are busy' );
check( ! empty( $slots[0]['resources'] ), 'slots list the available resources' );

echo "Privacy\n";
$export = ( new \Bookora\Privacy\Privacy() )->export( 'anna@example.com' );
check( count( $export['data'] ) >= 5, 'export contains the customer entries', count( $export['data'] ) );
( new \Bookora\Privacy\Privacy() )->erase( 'anna@example.com' );
$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'customers' ) . ' WHERE email = %s', 'anna@example.com' ) );
check( 0 === $left, 'customer is anonymized after erase request' );
check( 0 === count( ( new \Bookora\Privacy\Privacy() )->export( 'anna@example.com' )['data'] ), 'nothing left to export after erase' );

echo "Cleanup\n";
check( 200 === api( 'DELETE', '/services/' . $service['id'] )->get_status(), 'service deleted' );
check( 404 === api( 'GET', '/services/' . $service['id'] )->get_status(), 'deleted service is gone' );

echo "\n{$passed} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
