<?php
/**
 * Location of appointments: customer address with travel time, office, remote.
 *
 * Usage: php tests/Integration/location.php /path/to/wordpress   (test installation only)
 *
 * @package Kalendora
 */

// phpcs:ignoreFile

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.1.' . random_int( 2, 250 );
require rtrim( $argv[1], '/' ) . '/wp-load.php';

use Kalendora\Booking\Location;

$passed = 0;
$failed = 0;
function check( bool $ok, string $label, $debug = null ): void {
	global $passed, $failed;
	$ok ? $passed++ : $failed++;
	echo ( $ok ? '  ✓ ' : '  ✗ ' ) . $label . "\n";
	if ( ! $ok && null !== $debug ) {
		echo '    ' . str_replace( "\n", "\n    ", print_r( $debug, true ) ) . "\n";
	}
}
function api( string $m, string $r, array $b = array() ) {
	$q = new WP_REST_Request( $m, '/kalendora/v1' . ( str_contains( $r, '?' ) ? strstr( $r, '?', true ) : $r ) );
	if ( str_contains( $r, '?' ) ) {
		parse_str( substr( strstr( $r, '?' ), 1 ), $query );
		$q->set_query_params( $query );
	}
	if ( $b ) {
		$q->set_header( 'Content-Type', 'application/json' );
		$q->set_body( wp_json_encode( $b ) );
	}
	return rest_do_request( $q );
}

global $wpdb;
add_filter( 'kalendora_min_submit_seconds', static fn() => 0 );
add_filter( 'kalendora_rate_limit', static fn() => 1000 );
// Catch outgoing emails (with the content of attached calendar files).
$sent = array();
add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) use ( &$sent ) {
		$files = '';
		foreach ( (array) ( $atts['attachments'] ?? array() ) as $file ) {
			$files .= is_string( $file ) && is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		}
		$sent[] = array( 'recipient' => is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'], 'body' => (string) $atts['message'], 'attachments' => $files );
		return true;
	},
	10,
	2
);
wp_set_current_user( 1 );

$c        = \Kalendora\Core\Plugin::instance()->container();
$settings = $c->get( \Kalendora\Settings\Settings::class );
$entries  = $c->get( \Kalendora\Repository\EntryRepository::class );
$before   = $settings->all();
if ( $before['max_advance_days'] < 30 ) {
	$before['max_advance_days'] = 60; // Repair earlier runs.
}
$settings->update( array( 'business_address' => 'Hauptstraße 1, 10115 Berlin', 'min_notice_minutes' => 0, 'max_advance_days' => 365, 'default_status' => 'confirmed' ) );
$weekly_before = api( 'GET', '/schedules/weekly' )->get_data()['weekly'];
api( 'PUT', '/schedules/weekly', array( 'weekly' => array_fill_keys( array( 0, 1, 2, 3, 4, 5, 6 ), array( array( '08:00', '18:00' ) ) ) ) );

foreach ( $c->get( \Kalendora\Repository\ServiceRepository::class )->all() as $old ) {
	if ( str_starts_with( $old['name'], 'Ort-Test' ) ) {
		api( 'DELETE', '/services/' . $old['id'] );
	}
}
$svc = static function ( string $name, array $location, int $duration = 60 ) {
	return api( 'POST', '/services', array( 'name' => 'Ort-Test ' . $name, 'duration' => $duration, 'price' => 80, 'settings' => array( 'location' => $location ) ) )->get_data();
};
$onsite = $svc( 'Netzwerk vor Ort', array( 'type' => 'onsite', 'travel' => 30 ) );
$office = $svc( 'Beratung Büro', array( 'type' => 'office', 'info' => 'Eingang im Hof, 2. OG' ) );
$remote = $svc( 'Fernwartung', array( 'type' => 'remote', 'info' => 'AnyDesk: https://anydesk.com/de/downloads – wir rufen Sie an.' ) );
$plain  = $svc( 'Ohne Ort', array() );

echo "Settings of a service\n";
check( 'onsite' === Location::config( $onsite )['type'] && 30 === Location::config( $onsite )['travel'], 'location type and travel time are stored with the service' );
check( array() === Location::options( $plain ) && array( 'onsite' ) === Location::options( $onsite ), 'services without a location work as before' );
check( '' === Location::config( array( 'settings' => array( 'location' => array( 'type' => 'evil' ) ) ) )['type'], 'unknown location types are ignored' );
check( ! isset( Location::types()['choice'] ) || apply_filters( 'kalendora_is_pro', false ), '"customer chooses" is only offered with Pro' );

// A test day far enough ahead, free of earlier test bookings.
$day = ( new DateTimeImmutable( 'tomorrow', wp_timezone() ) )->modify( '+27 days' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}kalendora_bookings WHERE start_utc >= %s AND start_utc < %s", get_gmt_from_date( $day->format( 'Y-m-d 00:00:00' ) ), get_gmt_from_date( $day->modify( '+1 day' )->format( 'Y-m-d 00:00:00' ) ) ) );
$date  = $day->format( 'Y-m-d' );
$times = static function ( array $service, string $address = '', string $place = '' ) use ( $date ) {
	$res = api( 'GET', '/availability?' . http_build_query( array( 'service' => $service['id'], 'from' => $date, 'to' => $date, 'address' => $address, 'place' => $place ) ) );
	if ( $res->is_error() ) {
		return $res->as_error();
	}
	$data = $res->get_data();
	return array( array_column( (array) ( ( (array) $data['days'] )[ $date ] ?? array() ), 'time' ), $data['place'] );
};

echo "Travel time and working hours\n";
$missing = $times( $onsite );
check( is_wp_error( $missing ) && 'kalendora_location_address' === $missing->get_error_code(), 'without an address no times are shown for appointments at the customer', $missing );
[ $slots, $place ] = $times( $onsite, 'Musterweg 5, 10117 Berlin' );
check( '08:30' === ( $slots[0] ?? '' ) && '16:30' === end( $slots ), 'travel lies within the working hours: first start 08:30, last 16:30 (back by 18:00)', $slots );
check( 30 === $place['travel'] && 'onsite' === $place['type'], 'the form gets the travel time', $place );
[ $plain_slots ] = $times( $plain );
check( '08:00' === ( $plain_slots[0] ?? '' ), 'services without location still start at opening time' );

echo "Booking\n";
$form = api(
	'POST',
	'/forms',
	array(
		'title'  => 'Ort-Test Formular',
		'type'   => 'booking',
		'status' => 'published',
		'fields' => array(
			array( 'type' => 'service', 'key' => 'leistung', 'label' => 'Leistung', 'required' => true ),
			array( 'type' => 'appointment', 'key' => 'termin', 'label' => 'Termin', 'required' => true ),
			array( 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
		),
	)
)->get_data();
wp_set_current_user( 0 );
$submit = static function ( array $service, string $time, string $email, array $extra = array() ) use ( $form, $day ) {
	$start = new DateTimeImmutable( $day->format( 'Y-m-d' ) . ' ' . $time, wp_timezone() );
	return api(
		'POST',
		'/public/forms/' . $form['id'] . '/submit',
		array(
			'_kalendora_t' => \Kalendora\Forms\Submission::render_token( (int) $form['id'] ),
			'_bk_t'        => \Kalendora\Forms\Submission::render_token( (int) $form['id'] ),
			'fields'       => array(
				'email'    => $email,
				'leistung' => (string) $service['id'],
				'termin'   => array( 'start' => $start->format( 'c' ) ) + $extra,
			),
		)
	);
};
$res = $submit( $onsite, '09:00', 'vorort@example.com' );
check( $res->is_error() && ! empty( $res->get_data()['data']['fields']['termin'] ?? $res->get_data()['data']['errors']['termin'] ?? '' ), 'booking at the customer without an address is refused', $res->get_data() );
$sent = array();
$res  = $submit( $onsite, '09:30', 'vorort@example.com', array( 'address' => "Musterweg 5\n10117 Berlin" ) );
$mails = $sent;
$bid = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}kalendora_bookings" );
$b   = $entries->booking( $bid );
check( ! $res->is_error() && 'onsite' === $b['location_type'] && 'Musterweg 5, 10117 Berlin' === $b['location'] && 30 === $b['travel_before'] && 30 === $b['travel_after'], 'address and travel time are stored with the booking', array( $res->get_data(), $b ) );
[ $after ] = $times( $onsite, 'Musterweg 5, 10117 Berlin' );
check( ! in_array( '10:30', $after, true ) && ! in_array( '09:30', $after, true ) && in_array( '11:30', $after, true ), 'next appointment only after driving back and to the next customer (11:30)', $after );
[ $office_slots ] = $times( $office );
check( in_array( '08:00', $office_slots, true ) && ! in_array( '09:00', $office_slots, true ) && ! in_array( '10:00', $office_slots, true ) && in_array( '11:00', $office_slots, true ), 'office appointments respect travel there and back (busy 09:00–11:00)', $office_slots );

echo "Emails and calendar\n";
$cust  = current( array_filter( $mails, static fn( $m ) => 'vorort@example.com' === $m['recipient'] ) );
$owner = current( array_filter( $mails, static fn( $m ) => 'vorort@example.com' !== $m['recipient'] ) );
check( $cust && str_contains( $cust['body'], 'Musterweg 5, 10117 Berlin' ) && str_contains( $cust['body'], 'google.com/maps/search' ), 'confirmation shows the address with a map link', $cust['body'] ?? $mails );
check( $owner && str_contains( $owner['body'], 'Musterweg 5' ) && preg_match( '/30 min/i', $owner['body'] ), 'your notification shows address and blocked travel time' );
$loc = new ReflectionMethod( \Kalendora\Notifications\Mailer::class, 'event_location' );
$ics = \Kalendora\Notifications\Ics::event( $b, 'Test', (string) $loc->invoke( null, $b, Location::describe( $b, null ), 'Hauptstraße 1' ), '', 'CONFIRMED' );
check( str_contains( $ics, 'LOCATION:Musterweg 5\\, 10117 Berlin' ), 'calendar file (.ics) has the customer address as location', $ics );

$sent = array();
$submit( $remote, '14:00', 'remote@example.com' );
$rb    = $entries->booking( (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}kalendora_bookings" ) );
$rmail = implode( '', array_column( array_filter( $sent, static fn( $m ) => 'remote@example.com' === $m['recipient'] ), 'body' ) );
check( 'remote' === $rb['location_type'] && 0 === $rb['travel_before'] && str_contains( $rmail, 'https://anydesk.com/de/downloads' ) && ! str_contains( $rmail, 'google.com/maps' ), 'remote appointment: access details in the email, no travel time, no map' );

$sent = array();
$submit( $office, '15:00', 'buero@example.com' );
$ob    = $entries->booking( (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}kalendora_bookings" ) );
$omail = implode( '', array_column( array_filter( $sent, static fn( $m ) => 'buero@example.com' === $m['recipient'] ), 'body' ) );
check( 'office' === $ob['location_type'] && 'Hauptstraße 1, 10115 Berlin' === $ob['location'] && str_contains( $omail, 'Eingang im Hof' ), 'office appointment: your address and directions in the email' );

echo "Moving\n";
wp_set_current_user( 1 );
$move = api( 'POST', '/bookings/' . $ob['id'] . '/move', array( 'start' => gmdate( 'c', strtotime( $b['end'] ) ) ) );
check( $move->is_error(), 'an appointment cannot be moved into the travel time back from the customer', $move->get_data() );

// Clean up.
foreach ( array( $onsite, $office, $remote, $plain ) as $s ) {
	api( 'DELETE', '/services/' . $s['id'] );
}
api( 'DELETE', '/forms/' . $form['id'] );
api( 'PUT', '/schedules/weekly', array( 'weekly' => $weekly_before ) );
$settings->update( $before );
echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
