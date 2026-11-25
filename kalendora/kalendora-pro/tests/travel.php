<?php
/**
 * Travel time, kilometre allowance, service area, customer choice – with simulated route services.
 *
 * Usage: php tests/travel.php /path/to/wordpress   (Kalendora + Kalendora Pro active; test installation only)
 *
 * @package KalendoraPro
 */

// phpcs:ignoreFile

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.2.' . random_int( 2, 250 );
require rtrim( $argv[1], '/' ) . '/wp-load.php';

use Kalendora\Booking\Location;
use KalendoraPro\Payments;
use KalendoraPro\Travel;

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
/** Forget cached routes (new "request"). */
function fresh(): void {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE ( option_name LIKE '\\_transient\\_%kalendora\\_pro\\_route\\_%' AND option_name NOT LIKE '%route\\_ip\\_%' ) OR option_name LIKE '\\_transient\\_%kalendora\\_pro\\_geo\\_%'" );
	wp_cache_flush();
	$memo = new ReflectionProperty( Location::class, 'memo' );
	$memo->setValue( null, array() );
}

global $wpdb;
add_filter( 'kalendora_min_submit_seconds', static fn() => 0 );
add_filter( 'kalendora_rate_limit', static fn() => 1000 );
wp_set_current_user( 1 );
$c        = \Kalendora\Core\Plugin::instance()->container();
$settings = $c->get( \Kalendora\Settings\Settings::class );
$before   = $settings->all();
$settings->update( array( 'business_address' => 'Hauptstraße 1, 10115 Berlin', 'min_notice_minutes' => 0, 'max_advance_days' => 365, 'default_status' => 'confirmed' ) );
$weekly_before = api( 'GET', '/schedules/weekly' )->get_data()['weekly'];
api( 'PUT', '/schedules/weekly', array( 'weekly' => array_fill_keys( array( 0, 1, 2, 3, 4, 5, 6 ), array( array( '08:00', '18:00' ) ) ) ) );
delete_option( Travel::OPTION );
delete_option( Payments::OPTION );
fresh();

// Simulated route services: Musterweg = 12 km / 20 min; Mond = not found.
$calls = array();
$down  = false;
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$calls, &$down ) {
		if ( ! preg_match( '#^https://(api\.openrouteservice\.org|routes\.googleapis\.com)/#', $url ) ) {
			return $pre;
		}
		$calls[] = array( 'url' => $url, 'args' => $args );
		$reply   = static fn( int $code, $body ) => array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
		if ( $down ) {
			return $reply( 503, array( 'error' => 'maintenance' ) );
		}
		if ( str_contains( $url, '/geocode/search' ) ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			if ( str_contains( $q['text'], 'Mond' ) ) {
				return $reply( 200, array( 'features' => array() ) );
			}
			$lon = str_contains( $q['text'], 'Hauptstraße' ) ? 13.38 : ( str_contains( $q['text'], 'Hamburg' ) ? 10.0 : 13.5 );
			return $reply( 200, array( 'features' => array( array( 'geometry' => array( 'coordinates' => array( $lon, 52.5 ) ) ) ) ) );
		}
		if ( str_contains( $url, '/v2/directions/' ) ) {
			$body = json_decode( $args['body'], true );
			$far  = 10.0 == $body['coordinates'][1][0];
			return $reply( 200, array( 'routes' => array( array( 'summary' => array( 'distance' => $far ? 289000 : 12000, 'duration' => $far ? 10800 : 1200 ) ) ) ) );
		}
		if ( str_contains( $url, 'computeRoutes' ) ) {
			$body = json_decode( $args['body'], true );
			return str_contains( $body['destination']['address'], 'Mond' )
				? $reply( 200, new stdClass() )
				: $reply( 200, array( 'routes' => array( array( 'distanceMeters' => 30500, 'duration' => '2100s' ) ) ) );
		}
		return $reply( 500, array() );
	},
	1,
	3
);

echo "Settings\n";
check( is_wp_error( Travel::save( array( 'provider' => 'ors' ) ) ), 'a route service needs an API key' );
check( true === Travel::save( array( 'provider' => 'ors', 'api_key' => 'ors-test-key-123456', 'price_km' => '0,30', 'charge' => 'round', 'free_km' => 10, 'extra' => 10, 'round_to' => 5 ) ), 'route service with key is saved (0,30 with comma)' );
check( ! str_contains( (string) wp_json_encode( get_option( Travel::OPTION ) ), 'ors-test-key-123456' ) && 'ors-test-key-123456' === Travel::settings()['api_key'] && 0.3 === Travel::settings()['price_km'], 'the key is stored encrypted' );
check( isset( Location::types()['choice'] ) && apply_filters( 'kalendora_travel_auto', false ), 'Pro offers "customer chooses" and automatic travel time' );

echo "Calculation\n";
$s = Travel::settings();
check( 25 === Travel::minutes( 1200, $s ), '20 min driving + 10 % surcharge = 22 → rounded up to 25 min' );
check( 4.2 === Travel::cost( 12.0, $s ), '12 km there and back, 10 free km, 0.30 €/km = 4.20 €' );
check( 0.6 === Travel::cost( 12.0, array( 'charge' => 'oneway', 'free_km' => 10, 'price_km' => 0.3 ) + $s ), 'one way only: (12 − 10) × 0.30 = 0.60 €' );
check( Travel::in_postcodes( 'Musterweg 5, 10117 Berlin', Travel::postcode_rules( '10115, 101*' ) ) && ! Travel::in_postcodes( 'Musterweg 5, 20095 Hamburg', Travel::postcode_rules( '10115, 101*' ) ) && Travel::in_postcodes( 'irgendwo', array() ), 'postcode rules: exact and prefix with *' );

echo "Booking with calculated route\n";
foreach ( $c->get( \Kalendora\Repository\ServiceRepository::class )->all() as $old ) {
	if ( str_starts_with( $old['name'], 'Fahrt-Test' ) ) {
		api( 'DELETE', '/services/' . $old['id'] );
	}
}
$svc    = api( 'POST', '/services', array( 'name' => 'Fahrt-Test Netzwerk', 'duration' => 60, 'price' => 90, 'settings' => array( 'location' => array( 'type' => 'onsite', 'travel' => 15 ) ) ) )->get_data();
$choice = api( 'POST', '/services', array( 'name' => 'Fahrt-Test Wahl', 'duration' => 60, 'price' => 70, 'settings' => array( 'location' => array( 'type' => 'choice', 'choices' => array( 'onsite', 'remote' ), 'info' => 'TeamViewer-ID kommt per SMS' ) ) ) )->get_data();
$day    = ( new DateTimeImmutable( 'tomorrow', wp_timezone() ) )->modify( '+33 days' );
$date   = $day->format( 'Y-m-d' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}kalendora_bookings WHERE start_utc >= %s AND start_utc < %s", get_gmt_from_date( $date . ' 00:00:00' ), get_gmt_from_date( $day->modify( '+1 day' )->format( 'Y-m-d 00:00:00' ) ) ) );
$avail = static function ( array $service, string $address, string $place = '' ) use ( $date ) {
	$res = api( 'GET', '/availability?' . http_build_query( array( 'service' => $service['id'], 'from' => $date, 'to' => $date, 'address' => $address, 'place' => $place ) ) );
	if ( $res->is_error() ) {
		return $res->as_error();
	}
	$data = $res->get_data();
	return array( array_column( (array) ( ( (array) $data['days'] )[ $date ] ?? array() ), 'time' ), $data['place'] );
};
wp_set_current_user( 0 );
$calls             = array();
[ $times, $place ] = $avail( $svc, 'Musterweg 5, 10117 Berlin' );
check( 25 === $place['travel'] && 12.0 === (float) $place['km'] && 4.2 === (float) $place['cost'], 'the form shows 25 min, 12 km and 4.20 € travel costs', $place );
check( '08:25' === ( $times[0] ?? '' ), 'first start 08:25 (leave at 08:00, arrive 08:25)', $times );
check( 3 === count( $calls ) && 'ors-test-key-123456' === ( $calls[2]['args']['headers']['Authorization'] ?? '' ), 'OpenRouteService: two geocodings and one route with the key', array_column( $calls, 'url' ) );
$calls = array();
$memo = new ReflectionProperty( Location::class, 'memo' );
$memo->setValue( null, array() );
$avail( $svc, 'Musterweg 5, 10117 Berlin' );
check( 0 === count( $calls ), 'the same address again: from the cache, no new request' );

wp_set_current_user( 1 );
$form = api( 'POST', '/forms', array( 'title' => 'Fahrt-Test Formular', 'type' => 'booking', 'status' => 'published', 'fields' => array( array( 'type' => 'service', 'key' => 'leistung', 'label' => 'Leistung', 'required' => true ), array( 'type' => 'appointment', 'key' => 'termin', 'label' => 'Termin', 'required' => true ), array( 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ) ) ) )->get_data();
wp_set_current_user( 0 );
$submit = static function ( array $service, string $time, string $email, array $extra ) use ( $form, $day ) {
	$start = new DateTimeImmutable( $day->format( 'Y-m-d' ) . ' ' . $time, wp_timezone() );
	return api( 'POST', '/public/forms/' . $form['id'] . '/submit', array( '_bk_t' => \Kalendora\Forms\Submission::render_token( (int) $form['id'] ), 'fields' => array( 'email' => $email, 'leistung' => (string) $service['id'], 'termin' => array( 'start' => $start->format( 'c' ) ) + $extra ) ) );
};
$res = $submit( $svc, '08:25', 'it-kunde@example.com', array( 'address' => 'Musterweg 5, 10117 Berlin' ) );
$b   = $c->get( \Kalendora\Repository\EntryRepository::class )->booking( (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}kalendora_bookings" ) );
check( ! $res->is_error() && 25 === $b['travel_before'] && 25 === $b['travel_after'] && 12.0 === $b['travel_km'] && 4.2 === $b['travel_cost'], 'booking stores 25 min there and back, 12 km and 4.20 €', array( $res->get_data(), $b ) );

echo "Service area and errors\n";
fresh();
$missing = $avail( $svc, 'Mondstraße 1, 99999 Mond' );
check( is_wp_error( $missing ) && str_contains( $missing->get_error_message(), 'could not be found' ), 'unknown address: clear message to the customer', $missing );
Travel::save( array( 'provider' => 'ors', 'price_km' => '0.30', 'free_km' => 10, 'max_km' => 50 ) + array( 'charge' => 'round', 'extra' => 10, 'round_to' => 5 ) );
fresh();
$far = $avail( $svc, 'Elbchaussee 1, 22763 Hamburg' );
check( is_wp_error( $far ) && str_contains( $far->get_error_message(), 'outside our service area' ), 'more than 50 km: outside the service area', $far );
Travel::save( array( 'provider' => 'ors', 'price_km' => '0.30', 'free_km' => 10, 'postcodes' => '101*, 10245' ) + array( 'charge' => 'round', 'extra' => 10, 'round_to' => 5 ) );
fresh();
$calls = array();
$plz   = $avail( $svc, 'Karl-Marx-Allee 1, 10243 Berlin' );
check( is_wp_error( $plz ) && 0 === count( $calls ), 'postcode outside the list: refused without asking the route service', $plz );
Travel::save( array( 'provider' => 'ors', 'price_km' => '0.30', 'free_km' => 10 ) + array( 'charge' => 'round', 'extra' => 10, 'round_to' => 5 ) );
fresh();
$down               = true;
[ $times, $place ] = $avail( $svc, 'Musterweg 7, 10117 Berlin' );
$down               = false;
check( 15 === $place['travel'] && 0.0 === (float) $place['cost'] && ! empty( $times ), 'route service down: the fixed travel time of the service is used, booking still works', $place );

fresh();
$limit = 0;
add_filter( 'kalendora_pro_route_limit', static fn() => 3 );
for ( $i = 1; $i <= 4; $i++ ) {
	fresh();
	$r = $avail( $svc, "Teststraße {$i}, 10117 Berlin" );
	if ( is_wp_error( $r ) && 'kalendora_pro_route_limit' === $r->get_error_code() ) {
		$limit = $i;
	}
}
check( 4 === $limit, 'too many new addresses from one visitor are refused (cost protection)' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%kalendora\\_pro\\_route\\_ip\\_%'" );
wp_cache_flush();

echo "Customer chooses\n";
fresh();
$need = $avail( $choice, '' );
check( is_wp_error( $need ) && str_contains( $need->get_error_message(), 'choose where' ), 'without a choice the customer is asked where the appointment takes place' );
[ $times, $place ] = $avail( $choice, '', 'remote' );
check( 'remote' === $place['type'] && 0 === $place['travel'] && in_array( '10:00', $times, true ), 'remote: no travel time (free again at 10:00 after the on-site appointment incl. drive back)', $times );
[ , $place ] = $avail( $choice, 'Musterweg 5, 10117 Berlin', 'onsite' );
check( 'onsite' === $place['type'] && 25 === $place['travel'], 'on site: route is calculated' );
check( is_wp_error( $avail( $choice, '', 'office' ) ), 'types that are not offered for the service are refused' );

echo "Google\n";
Travel::save( array( 'provider' => 'google', 'api_key' => 'AIza-test-987654', 'price_km' => '0.30', 'free_km' => 0 ) + array( 'charge' => 'oneway', 'extra' => 0, 'round_to' => 5 ) );
fresh();
$calls       = array();
[ , $place ] = $avail( $svc, 'Bahnhofstraße 3, 14467 Potsdam' );
$g           = $calls[0] ?? array();
check( 35 === $place['travel'] && 30.5 === (float) $place['km'] && 9.15 === (float) $place['cost'], 'Google Routes: 30.5 km, 35 min, one way 9.15 €', $place );
check( 'AIza-test-987654' === ( $g['args']['headers']['X-Goog-Api-Key'] ?? '' ) && str_contains( (string) ( $g['args']['headers']['X-Goog-FieldMask'] ?? '' ), 'distanceMeters' ) && 'Hauptstraße 1, 10115 Berlin' === json_decode( $g['args']['body'], true )['origin']['address'], 'Google gets key, field mask and your business address as start' );

echo "Online payment\n";
check( 94.2 === Payments::amount( $svc, 1, array( 'mode' => 'full', 'stripe_secret' => 'sk_test_x', 'services' => array() ) + Payments::defaults(), 4.2 )['total'], 'travel costs are added to the online payment (90 € + 4.20 €)' );

// Clean up.
wp_set_current_user( 1 );
foreach ( array( $svc, $choice ) as $x ) {
	api( 'DELETE', '/services/' . $x['id'] );
}
api( 'DELETE', '/forms/' . $form['id'] );
api( 'PUT', '/schedules/weekly', array( 'weekly' => $weekly_before ) );
$settings->update( $before );
delete_option( Travel::OPTION );
fresh();
echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
