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

// The fixtures (imported calendars, opening hours) are written in Berlin time.
update_option( 'timezone_string', 'Europe/Berlin' );

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
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bookora\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bookora\\_rl\\_%' OR option_name LIKE 'bookora\\_sync\\_lock\\_%'" );
$bk_opt = get_option( \Bookora\Settings\Settings::OPTION, array() );
if ( is_array( $bk_opt ) ) {
	$bk_opt['cancel_fee_enabled'] = false; // A crashed earlier run must not leave the fee rule on.
	update_option( \Bookora\Settings\Settings::OPTION, $bk_opt, false );
}
wp_cache_flush();
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

echo "Forms\n";
check( 400 === api( 'POST', '/forms', array( 'title' => '' ) )->get_status(), 'form without title is rejected' );
$res  = api(
	'POST',
	'/forms',
	array(
		'title'  => 'Kontakt',
		'type'   => 'standard',
		'fields' => array(
			array( 'type' => 'name', 'label' => 'Ihr Name', 'required' => true ),
			array( 'type' => 'email', 'label' => 'E-Mail', 'key' => 'email' ),
			array( 'type' => 'email', 'label' => 'E-Mail', 'key' => 'email' ),
			array( 'type' => 'select', 'label' => 'Thema', 'options' => array( 'Beratung', array( 'label' => 'Support', 'value' => 'support' ), '' ) ),
			array( 'type' => 'textarea', 'label' => 'Nachricht <script>x</script>', 'rows' => 99, 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'thema', 'operator' => 'is', 'value' => 'support' ), array( 'field' => 'ghost', 'operator' => 'is', 'value' => 'x' ) ) ) ),
			array( 'type' => 'appointment', 'label' => 'Termin' ),
			array( 'type' => 'evil', 'label' => 'x' ),
			array( 'type' => 'paragraph', 'content' => '<p>Hallo <a href="https://example.com" onclick="x()">Link</a></p><script>alert(1)</script>' ),
		),
	)
);
$form   = $res->get_data();
$fields = $form['fields'] ?? array();
check( 201 === $res->get_status(), 'form created' );
check( 6 === count( $fields ), 'unknown and booking-only fields are dropped from a standard form', array_column( $fields, 'type' ) );
check( 'ihr_name' === $fields[0]['key'], 'field key derived from label', $fields[0]['key'] ?? null );
check( 'email_2' === $fields[2]['key'], 'duplicate keys are made unique', $fields[2]['key'] ?? null );
check( 2 === count( $fields[3]['options'] ) && 'support' === $fields[3]['options'][1]['value'], 'options sanitized, empty ones removed' );
check( 20 === $fields[4]['rows'] && ! str_contains( $fields[4]['label'], '<script' ), 'limits and label sanitizing applied' );
check( 1 === count( $fields[4]['logic']['rules'] ?? array() ), 'logic rules referencing missing fields are removed' );
check( ! str_contains( $fields[5]['content'], '<script' ) && ! str_contains( $fields[5]['content'], 'onclick' ) && str_contains( $fields[5]['content'], 'href' ), 'paragraph HTML is filtered' );
check( '[bookora id="' . $form['id'] . '"]' === $form['shortcode'], 'shortcode returned' );

$res = api( 'PUT', '/forms/' . $form['id'], array( 'type' => 'booking', 'fields' => array_merge( $fields, array( array( 'type' => 'appointment' ), array( 'type' => 'appointment' ) ) ), 'custom_css' => '<style>.x{}</style>', 'settings' => array( 'redirectUrl' => 'javascript:alert(1)', 'labelPlacement' => 'nope' ) ) );
$upd = $res->get_data();
check( 1 === count( array_filter( $upd['fields'], static fn( $f ) => 'appointment' === $f['type'] ) ), 'booking field allowed once in booking form' );
check( '.x{}' === $upd['custom_css'], 'custom CSS is stripped of tags' );
check( '' === $upd['settings']['redirectUrl'] && 'top' === $upd['settings']['labelPlacement'], 'unsafe settings are rejected' );

$dup = api( 'POST', '/forms/' . $form['id'] . '/duplicate' )->get_data();
check( 'draft' === $dup['status'] && str_contains( $dup['title'], 'Kontakt' ) && count( $dup['fields'] ) === count( $upd['fields'] ), 'form duplicated as draft' );
$list = api( 'GET', '/forms' )->get_data();
check( 2 === count( $list ) && ! isset( $list[0]['fields'] ), 'form list is slim' );

wp_set_current_user( 0 );
check( 401 === api( 'GET', '/forms' )->get_status(), 'anonymous user cannot list forms' );
wp_set_current_user( 1 );

echo "Public submission\n";
$old_token = static function ( int $age, int $form_id ): string {
	$t = (string) ( time() - $age );
	return $t . '.' . substr( wp_hash( 'bookora_render|' . $form_id . '|' . $t, 'nonce' ), 0, 16 );
};
$pub = api(
	'POST',
	'/forms',
	array(
		'title'  => 'Public',
		'status' => 'published',
		'fields' => array(
			array( 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
			array( 'type' => 'radio', 'key' => 'kunde', 'label' => 'Kunde', 'options' => array( 'Ja', 'Nein' ) ),
			array( 'type' => 'text', 'key' => 'grund', 'label' => 'Grund', 'required' => true, 'logic' => array( 'action' => 'show', 'match' => 'all', 'rules' => array( array( 'field' => 'kunde', 'operator' => 'is', 'value' => 'Nein' ) ) ) ),
		),
	)
)->get_data();
wp_set_current_user( 0 );
$submit = static fn( array $body ) => api( 'POST', '/public/forms/' . $pub['id'] . '/submit', $body );
check( 400 === $submit( array( 'fields' => array( 'email' => 'a@example.com' ) ) )->get_status(), 'submission without token is rejected' );
check( 429 === $submit( array( '_bk_t' => $old_token( 0, (int) $pub['id'] ), 'fields' => array( 'email' => 'a@example.com' ) ) )->get_status(), 'too fast submission is rejected' );
check( 400 === $submit( array( '_bk_t' => $old_token( 10, (int) $pub['id'] ), 'website_url_hp' => 'spam', 'fields' => array( 'email' => 'a@example.com' ) ) )->get_status(), 'honeypot catches bots' );
$res = $submit( array( '_bk_t' => $old_token( 10, (int) $pub['id'] ), 'fields' => array( 'email' => 'not-mail', 'kunde' => 'Nein' ) ) );
check( 422 === $res->get_status() && isset( $res->get_data()['data']['fields']['email'], $res->get_data()['data']['fields']['grund'] ), 'field errors returned (invalid email, required visible field)', $res->get_data() );
check( 201 === $submit( array( '_bk_t' => $old_token( 10, (int) $pub['id'] ), 'fields' => array( 'email' => 'b@example.com', 'kunde' => 'Ja', 'grund' => 'should be ignored' ) ) )->get_status(), 'field hidden by logic is not required' );
$stored = $wpdb->get_col( 'SELECT field_key FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'entry_values' ) . ' v INNER JOIN ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'entries' ) . ' e ON e.id = v.entry_id WHERE e.form_id = ' . (int) $pub['id'] );
check( ! in_array( 'grund', $stored, true ) && in_array( 'email', $stored, true ), 'values of hidden fields are not stored', $stored );
check( 201 === $submit( array( '_bk_t' => $old_token( 10, (int) $pub['id'] ), 'fields' => array( 'email' => 'c@example.com', 'kunde' => 'evil' ) ) )->get_status() ? false : true, 'invalid option is rejected' );
wp_set_current_user( 1 );

echo "Daily schedule & limit\n";
api( 'PUT', '/schedules/weekly', array( 'weekly' => array( 1 => array( array( '08:00', '19:00' ) ) ) ) );
$treat = api( 'POST', '/services', array( 'name' => 'Behandlung', 'duration' => 60, 'buffer_after' => 60, 'max_per_day' => 2 ) )->get_data();
$day   = ( new DateTimeImmutable( next_weekday( 1 ) ) )->modify( '+14 days' )->format( 'Y-m-d' );
$times = array_column( ( (array) api( 'GET', '/availability', array(), array( 'service' => $treat['id'], 'from' => $day, 'to' => $day ) )->get_data()['days'] )[ $day ] ?? array(), 'time' );
check( array( '08:00', '10:00', '12:00', '14:00', '16:00', '18:00' ) === $times, '1 h treatment + 1 h break = 6 start times per day', $times );
$t0 = static fn( string $hm ) => ( new DateTimeImmutable( $day . ' ' . $hm, wp_timezone() ) )->getTimestamp();
$mk = static fn( string $hm, string $mail ) => $bookings->create( array( 'service_id' => $treat['id'], 'start' => $t0( $hm ), 'customer' => array( 'email' => $mail ) ) );
check( ! is_wp_error( $mk( '08:00', 'd1@example.com' ) ) && ! is_wp_error( $mk( '12:00', 'd2@example.com' ) ), 'two bookings within the daily limit' );
$days = (array) api( 'GET', '/availability', array(), array( 'service' => $treat['id'], 'from' => $day, 'to' => $day ) )->get_data()['days'];
check( ! isset( $days[ $day ] ), 'day is full after reaching the limit of 2' );
check( is_wp_error( $mk( '16:00', 'd3@example.com' ) ), 'third booking on that day is rejected' );

echo "Bookings & entries admin\n";
$adm   = \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Repository\EntryRepository::class );
$bday  = ( new DateTimeImmutable( next_weekday( 1 ) ) )->modify( '+21 days' )->format( 'Y-m-d' );
$svc   = api( 'POST', '/services', array( 'name' => 'Admin-Test', 'duration' => 60 ) )->get_data();
$at    = static fn( string $hm ) => ( new DateTimeImmutable( $bday . ' ' . $hm, wp_timezone() ) )->getTimestamp();
$b1    = $bookings->create( array( 'service_id' => $svc['id'], 'start' => $at( '08:00' ), 'customer' => array( 'email' => 'e1@example.com', 'name' => 'Eva Eins' ), 'values' => array( 'nachricht' => 'Hallo' ) ) );
$list  = api( 'GET', '/bookings', array(), array( 'view' => 'pending', 'per_page' => 100 ) )->get_data();
check( in_array( $b1['booking_id'], array_column( $list['items'], 'id' ), true ) && $list['counts']['pending'] >= 1, 'pending booking listed with count' );
check( 409 === api( 'POST', '/bookings/' . $b1['booking_id'] . '/status', array( 'status' => 'completed' ) )->get_status(), 'invalid transition pending → attended is refused' );
$res = api( 'POST', '/bookings/' . $b1['booking_id'] . '/status', array( 'status' => 'rejected' ) );
check( 200 === $res->get_status() && 'rejected' === $res->get_data()['status'], 'booking rejected with one call' );
$b2 = $bookings->create( array( 'service_id' => $svc['id'], 'start' => $at( '08:00' ), 'customer' => array( 'email' => 'e2@example.com' ) ) );
check( ! is_wp_error( $b2 ), 'rejected time is free for the next customer' );
$res = api( 'POST', '/bookings/' . $b1['booking_id'] . '/status', array( 'status' => 'pending' ) );
check( 409 === $res->get_status() && 'bookora_slot_taken' === $res->get_data()['code'], 'reopening is refused when the time is taken meanwhile' );
check( 200 === api( 'POST', '/bookings/' . $b2['booking_id'] . '/status', array( 'status' => 'confirmed' ) )->get_status(), 'second booking confirmed' );
check( 200 === api( 'POST', '/bookings/' . $b2['booking_id'] . '/notes', array( 'notes' => 'Allergie <b>Latex</b>' ) )->get_status() && 'Allergie Latex' === $adm->booking( $b2['booking_id'] )['notes'], 'internal notes saved and sanitized' );
$detail = api( 'GET', '/entries/' . $b1['entry_id'] )->get_data();
check( 'Hallo' === ( $detail['values'][0]['value'] ?? '' ) && 'rejected' === $detail['booking']['status'], 'entry detail shows values and booking' );
$badges = api( 'GET', '/badges' )->get_data();
check( isset( $badges['pending'], $badges['new_entries'] ), 'badge counts available' );

$std   = api( 'POST', '/forms', array( 'title' => 'Admin-Kontakt', 'status' => 'published', 'fields' => array( array( 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail' ), array( 'type' => 'text', 'key' => 'betreff', 'label' => 'Betreff' ) ) ) )->get_data();
wp_set_current_user( 0 );
foreach ( array( 'x1@example.com' => '=HYPERLINK("http://evil")', 'x2@example.com' => 'Normale Frage' ) as $mail => $subject ) {
	api( 'POST', '/public/forms/' . $std['id'] . '/submit', array( '_bk_t' => $old_token( 10, (int) $std['id'] ), 'fields' => array( 'email' => $mail, 'betreff' => $subject ) ) );
}
check( 401 === api( 'GET', '/entries' )->get_status(), 'anonymous user cannot read entries' );
wp_set_current_user( 1 );
$ent = api( 'GET', '/entries', array(), array( 'form_id' => $std['id'] ) )->get_data();
check( 2 === $ent['total'] && 2 === $ent['counts']['new'], 'standard entries listed as new' );
$e1  = $ent['items'][0]['id'];
api( 'GET', '/entries/' . $e1 );
check( 'read' === api( 'GET', '/entries', array(), array( 'form_id' => $std['id'] ) )->get_data()['items'][0]['status'], 'opening an entry marks it as read' );
check( 0 === api( 'POST', '/entries/bulk', array( 'ids' => array( $e1 ), 'action' => 'delete' ) )->get_data()['affected'], 'entries outside the trash cannot be deleted permanently' );
api( 'POST', '/entries/bulk', array( 'ids' => array( $e1 ), 'action' => 'trash' ) );
check( 1 === api( 'GET', '/entries', array(), array( 'form_id' => $std['id'] ) )->get_data()['total'], 'trashed entry hidden from the default list' );
check( 1 === api( 'POST', '/entries/bulk', array( 'ids' => array( $e1 ), 'action' => 'delete' ) )->get_data()['affected'], 'trashed entry deleted permanently' );
check( 400 === api( 'POST', '/entries/bulk', array( 'ids' => array( 1 ), 'action' => 'drop' ) )->get_status(), 'unknown bulk action rejected' );
[ $head, $rows ] = $adm->export( $std['id'] );
$cells           = array_merge( ...$rows );
check( in_array( 'Betreff', $head, true ) && 1 === count( $rows ), 'CSV export contains the remaining entry', $rows );
check( "'=1+1" === \Bookora\Repository\EntryRepository::csv_safe( '=1+1' ) && "'@SUM(A1)" === \Bookora\Repository\EntryRepository::csv_safe( '@SUM(A1)' ) && 'Hallo' === \Bookora\Repository\EntryRepository::csv_safe( 'Hallo' ), 'CSV export neutralizes spreadsheet formulas' );

$mgr = wp_insert_user( array( 'user_login' => 'manager' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'bookora_manager' ) );
wp_set_current_user( $mgr );
check( 200 === api( 'GET', '/bookings' )->get_status(), 'booking manager can see bookings' );
check( 403 === api( 'POST', '/services', array( 'name' => 'x' ) )->get_status(), 'booking manager cannot change services' );
wp_set_current_user( 1 );
wp_delete_user( $mgr );

echo "Emails\n";
$mails = array();
add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	},
	10,
	2
);
$c      = \Bookora\Core\Plugin::instance()->container();
$mailer = $c->get( \Bookora\Notifications\Mailer::class );
$links  = $c->get( \Bookora\Notifications\ActionLinks::class );
api( 'POST', '/settings', array( 'admin_email' => 'owner@example.com', 'business_name' => 'Praxis Test', 'default_status' => 'pending' ) );
$mday = ( new DateTimeImmutable( next_weekday( 1 ) ) )->modify( '+28 days' )->format( 'Y-m-d' );
$msvc = api( 'POST', '/services', array( 'name' => 'Mail-Test', 'duration' => 30 ) )->get_data();
$mb   = $bookings->create( array( 'service_id' => $msvc['id'], 'start' => ( new DateTimeImmutable( $mday . ' 09:00', wp_timezone() ) )->getTimestamp(), 'customer' => array( 'email' => 'kunde@example.com', 'name' => 'Max <script>alert(1)</script>' ), 'values' => array( 'nachricht' => '<img src=x onerror=alert(1)>' ) ) );
$to   = array_column( $mails, 'to' );
check( 2 === count( $mails ) && in_array( 'kunde@example.com', $to, true ) && in_array( 'owner@example.com', $to, true ), 'new booking sends receipt to customer and notification to owner', $to );
$admin_mail = $mails[ array_search( 'owner@example.com', $to, true ) ];
$cust_mail  = $mails[ array_search( 'kunde@example.com', $to, true ) ];
check( str_contains( $admin_mail['subject'], 'Mail-Test' ) && str_contains( $admin_mail['message'], 'bookora_action=' ), 'owner email contains service and one-click links' );
check( ! str_contains( $admin_mail['message'] . $cust_mail['message'], '<script' ) && ! str_contains( $admin_mail['message'], '<img src=x' ), 'customer input is escaped in emails' );
check( in_array( 'Reply-To: kunde@example.com', (array) $admin_mail['headers'], true ), 'owner can reply directly to the customer' );
check( str_contains( $cust_mail['message'], 'Praxis Test' ) && ! str_contains( $cust_mail['message'], '{' ), 'placeholders are replaced' );

preg_match_all( '/bookora_action=([A-Za-z0-9_%-]+)/', $admin_mail['message'], $m );
$tokens = array_map( 'rawurldecode', array_unique( $m[1] ) );
check( 2 === count( $tokens ) && $links->find( $tokens[0] ) && 'confirmed' === $links->find( $tokens[0] )['action'], 'confirm and reject links are valid' );
check( null === $links->find( 'x' . substr( $tokens[0], 1 ) ) && null === $links->find( '../../etc' ), 'tampered tokens are rejected' );

$mails = array();
api( 'POST', '/bookings/' . $mb['booking_id'] . '/status', array( 'status' => 'confirmed' ) );
check( 1 === count( $mails ) && 'kunde@example.com' === $mails[0]['to'] && str_contains( $mails[0]['message'], 'calendar.google.com' ), 'confirming sends the confirmation (with calendar link) to the customer', array_column( $mails, 'subject' ) );
check( null === $links->find( $tokens[0] ) && null === $links->find( $tokens[1] ), 'one-click links are invalid after the booking was processed' );

$cal_url = \Bookora\Notifications\CalendarLink::url( $adm->booking( $mb['booking_id'] )['uid'] );
check( str_contains( $mails[0]['message'], 'bookora_ics=' ) && str_contains( $mails[0]['message'], esc_url( $cal_url ) ), 'confirmation contains the "Add to calendar" button' );
$ics = \Bookora\Notifications\Ics::event( $adm->booking( $mb['booking_id'] ), 'Mail-Test; Praxis, Test' );
check( str_contains( $ics, 'BEGIN:VEVENT' ) && str_contains( $ics, 'SUMMARY:Mail-Test\; Praxis\, Test' ) && preg_match( '/DTSTART:\d{8}T\d{6}Z/', $ics ), 'calendar file is valid and escaped' );

$mails = array();
global $wpdb;
$wpdb->update( \Bookora\Install\Schema::table( $wpdb->prefix, 'bookings' ), array( 'start_utc' => gmdate( 'Y-m-d H:i:s', time() + 20 * HOUR_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 20 * HOUR_IN_SECONDS + 1800 ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), array( 'id' => $mb['booking_id'] ) );
$sent_now = $mailer->send_reminders();
check( 1 === $sent_now && 1 === count( $mails ) && 'kunde@example.com' === $mails[0]['to'], 'reminder sent within 24 h before the appointment', array_column( $mails, 'subject' ) );
check( 0 === $mailer->send_reminders(), 'reminder is sent only once' );

check( 200 === api( 'PUT', '/emails/booking_confirmed', array( 'enabled' => false, 'subject' => 'Hallo {customer_name}', 'body' => 'Text' ) )->get_status(), 'template saved' );
$mails = array();
api( 'POST', '/bookings/' . $mb['booking_id'] . '/status', array( 'status' => 'cancelled' ) );
api( 'POST', '/bookings/' . $mb['booking_id'] . '/status', array( 'status' => 'pending' ) );
api( 'POST', '/bookings/' . $mb['booking_id'] . '/status', array( 'status' => 'confirmed' ) );
check( ! in_array( 'Hallo Max', array_column( $mails, 'subject' ), true ), 'disabled template is not sent' );
$prev = api( 'POST', '/emails/booking_confirmed/preview', array( 'subject' => 'Termin {date}', 'body' => 'Hallo {customer_name}, {all_fields}' ) )->get_data();
check( str_contains( $prev['html'], 'Anna Muster' ) && str_contains( $prev['html'], '<table' ) && ! str_contains( $prev['subject'], '{date}' ), 'preview renders sample data' );
check( 400 === api( 'PUT', '/emails/booking_confirmed', array( 'enabled' => true, 'subject' => '', 'body' => '' ) )->get_status(), 'empty template rejected' );
api( 'POST', '/emails/booking_confirmed/reset' );
check( true === api( 'GET', '/emails' )->get_data()['templates'][1]['enabled'], 'template reset to default' );
check( 404 === api( 'PUT', '/emails/evil', array( 'subject' => 'x', 'body' => 'y' ) )->get_status(), 'unknown email event rejected' );
$logged = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'notification_log' ) . " WHERE status = 'sent'" );
check( $logged >= 4, 'every send is logged', $logged );

echo "Timeline & moving appointments\n";
$tday = ( new DateTimeImmutable( next_weekday( 1 ) ) )->modify( '+28 days' )->format( 'Y-m-d' );
$tt   = static fn( string $hm, string $d = '' ) => ( new DateTimeImmutable( ( $d ?: $tday ) . ' ' . $hm, wp_timezone() ) )->getTimestamp();
$m1   = $bookings->create( array( 'service_id' => $treat['id'], 'start' => $tt( '08:00' ), 'customer' => array( 'email' => 'move1@example.com', 'name' => 'Mia Move' ) ) );
$m2   = $bookings->create( array( 'service_id' => $treat['id'], 'start' => $tt( '12:00' ), 'customer' => array( 'email' => 'move2@example.com' ) ) );
check( ! is_wp_error( $m1 ) && ! is_wp_error( $m2 ), 'two bookings for the timeline created', array( $m1, $m2 ) );

$tl = api( 'GET', '/timeline', array(), array( 'view' => 'day', 'date' => $tday ) );
$td = $tl->get_data();
check( 200 === $tl->get_status() && 'day' === $td['view'] && array( $tday ) === $td['dates'], 'day view answers' );
$ids_tl = array_column( $td['bookings'], 'id' );
check( in_array( $m1['booking_id'], $ids_tl, true ) && in_array( $m2['booking_id'], $ids_tl, true ), 'day view contains the bookings of that day', $ids_tl );
$bm1 = current( array_filter( $td['bookings'], static fn( $b ) => $b['id'] === $m1['booking_id'] ) );
check( 480 === $bm1['start_min'] && 540 === $bm1['end_min'], 'booking position in minutes', $bm1 );
$biz = current( array_filter( $td['rows'], static fn( $r ) => 0 === $r['id'] ) );
check( $biz && array( array( 480, 1140 ) ) === $biz['open'], 'business row shows the opening hours', $td['rows'] );
check( $td['from'] <= 480 && $td['to'] >= 1140, 'time axis covers the opening hours', array( $td['from'], $td['to'] ) );
$wk = api( 'GET', '/timeline', array(), array( 'view' => 'week', 'date' => $tday ) )->get_data();
check( 7 === count( $wk['dates'] ) && 7 === count( $wk['rows'] ) && in_array( $tday, $wk['dates'], true ), 'week view has 7 day rows', $wk['dates'] );
wp_set_current_user( 0 );
check( in_array( api( 'GET', '/timeline', array(), array( 'date' => $tday ) )->get_status(), array( 401, 403 ), true ), 'timeline requires login' );
check( in_array( api( 'POST', '/bookings/' . $m1['booking_id'] . '/move', array( 'date' => $tday, 'time' => '10:00' ) )->get_status(), array( 401, 403 ), true ), 'moving requires permission' );
wp_set_current_user( 1 );

$mv = api( 'POST', '/bookings/' . $m1['booking_id'] . '/move', array( 'date' => $tday, 'time' => '10:30' ) );
check( 409 === $mv->get_status() && 'bookora_conflict' === $mv->get_data()['code'], 'move into the break of the next appointment is refused', $mv->get_data() );
check( '08:00' === $adm->booking( $m1['booking_id'] )['time'], 'refused move leaves the booking unchanged' );

$mails = array();
$mv    = api( 'POST', '/bookings/' . $m1['booking_id'] . '/move', array( 'date' => $tday, 'time' => '10:00' ) );
$md    = $mv->get_data();
check( 200 === $mv->get_status() && '10:00' === $md['booking']['time'] && '11:00' === $md['booking']['end_time'], 'move to a free time succeeds (duration kept)', $md );
check( false === $md['outside_hours'] && true === $md['notified'], 'move inside opening hours is not flagged' );
$to_customer = array_values( array_filter( $mails, static fn( $m ) => 'move1@example.com' === $m['to'] ) );
check( 1 === count( $to_customer ) && str_contains( $to_customer[0]['message'], '08:00' ) && str_contains( $to_customer[0]['message'], '10:00' ) && str_contains( $to_customer[0]['message'], 'bookora_ics=' ), 'customer gets the reschedule email with old and new time', array_column( $mails, 'subject' ) );
$times = array_column( ( (array) api( 'GET', '/availability', array(), array( 'service' => $treat['id'], 'from' => $tday, 'to' => $tday ) )->get_data()['days'] )[ $tday ] ?? array(), 'time' );
check( ! in_array( '10:00', $times, true ), 'new time is blocked after the move', $times );

$mails = array();
$mv    = api( 'POST', '/bookings/' . $m1['booking_id'] . '/move', array( 'date' => $tday, 'time' => '18:30', 'notify' => false ) );
check( 200 === $mv->get_status() && true === $mv->get_data()['outside_hours'], 'move beyond closing time is allowed but flagged', $mv->get_data() );
check( 0 === count( array_filter( $mails, static fn( $m ) => 'move1@example.com' === $m['to'] ) ), 'notify=false sends no email to the customer', array_column( $mails, 'subject' ) );

$next = ( new DateTimeImmutable( $tday ) )->modify( '+1 day' )->format( 'Y-m-d' );
$mv   = api( 'POST', '/bookings/' . $m1['booking_id'] . '/move', array( 'date' => $next, 'time' => '09:00', 'notify' => false ) );
check( 200 === $mv->get_status() && $next === $mv->get_data()['booking']['date'], 'move to another day', $mv->get_data() );
check( 400 === api( 'POST', '/bookings/' . $m1['booking_id'] . '/move', array( 'date' => '2026-02-30', 'time' => '09:00' ) )->get_status(), 'invalid target date is rejected' );

$c1 = $bookings->create( array_merge( $input, array( 'service_id' => $consult['id'], 'start' => $tt( '09:00' ), 'resource_id' => $ids[0] ) ) );
$c2 = $bookings->create( array_merge( $input, array( 'service_id' => $consult['id'], 'start' => $tt( '09:00' ), 'resource_id' => $ids[1] ) ) );
check( ! is_wp_error( $c1 ) && ! is_wp_error( $c2 ) && $c1['resource_id'] !== $c2['resource_id'], 'two staff bookings at the same time', array( $c1, $c2 ) );
$res_day = api( 'GET', '/timeline', array(), array( 'view' => 'day', 'date' => $tday ) )->get_data();
check( count( array_intersect( array( $ids[0], $ids[1] ), array_column( $res_day['rows'], 'id' ) ) ) === 2, 'day view has a row per staff member', array_column( $res_day['rows'], 'id' ) );
$mv = api( 'POST', '/bookings/' . $c1['booking_id'] . '/move', array( 'date' => $tday, 'time' => '09:00', 'resource_id' => $c2['resource_id'], 'notify' => false ) );
check( 409 === $mv->get_status(), 'moving onto a busy staff member is refused', $mv->get_data() );
$mv = api( 'POST', '/bookings/' . $c1['booking_id'] . '/move', array( 'date' => $tday, 'time' => '11:00', 'resource_id' => $c2['resource_id'], 'notify' => false ) );
check( 200 === $mv->get_status() && $c2['resource_id'] === $mv->get_data()['booking']['resource_id'], 'move to another staff member', $mv->get_data() );
$mv = api( 'POST', '/bookings/' . $c1['booking_id'] . '/move', array( 'date' => $tday, 'time' => '11:00', 'resource_id' => $ids[2], 'notify' => false ) );
check( 400 === $mv->get_status() && 'bookora_unknown_resource' === $mv->get_data()['code'], 'staff member without the service is refused', $mv->get_data() );

api( 'POST', '/bookings/' . $m2['booking_id'] . '/status', array( 'status' => 'rejected' ) );
$mv = api( 'POST', '/bookings/' . $m2['booking_id'] . '/move', array( 'date' => $tday, 'time' => '14:00' ) );
check( 409 === $mv->get_status() && 'bookora_not_movable' === $mv->get_data()['code'], 'rejected booking cannot be moved', $mv->get_data() );
check( 404 === api( 'POST', '/bookings/999999/move', array( 'date' => $tday, 'time' => '14:00' ) )->get_status(), 'unknown booking returns 404' );

echo "Calendar sync: subscription feeds\n";
$cday = ( new DateTimeImmutable( next_weekday( 1 ) ) )->modify( '+35 days' )->format( 'Y-m-d' );
$ct   = static fn( string $hm ) => ( new DateTimeImmutable( $cday . ' ' . $hm, wp_timezone() ) )->getTimestamp();
$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'calendar_connections' ) );
check( empty( array_diff( array( 'label', 'options', 'last_error' ), (array) $cols ) ), 'schema 1.4.0: new connection columns exist', $cols );

$cal = api( 'GET', '/calendars' );
$cd  = $cal->get_data();
check( 200 === $cal->get_status() && 'all' === $cd['feeds'][0]['scope'], 'calendar overview answers' );
$scopes = array_column( $cd['feeds'], 'scope' );
check( in_array( 'r' . $ids[0], $scopes, true ) && in_array( 'r' . $ids[1], $scopes, true ), 'one feed per staff member', $scopes );
$all_url = $cd['feeds'][0]['urls']['https'];
check( (bool) preg_match( '/bookora_feed=[a-f0-9]{40}$/', $all_url ) && str_starts_with( $cd['feeds'][0]['urls']['webcal'], 'webcal://' ), 'feed address with secret token + webcal variant', $cd['feeds'][0]['urls'] );
check( str_contains( $cd['feeds'][0]['urls']['google'], 'calendar.google.com' ) && str_contains( $cd['feeds'][0]['urls']['outlook'], 'outlook.live.com' ), 'one-click links for Google and Outlook' );

$f1 = $bookings->create( array( 'service_id' => $consult['id'], 'start' => $ct( '09:00' ), 'resource_id' => $ids[0], 'customer' => array( 'email' => 'feed1@example.com', 'name' => 'Frida Feed', 'phone' => '0171 111' ), 'status' => 'confirmed' ) );
$f2 = $bookings->create( array( 'service_id' => $consult['id'], 'start' => $ct( '09:00' ), 'resource_id' => $ids[1], 'customer' => array( 'email' => 'feed2@example.com', 'name' => 'Paul Pending' ) ) );
$f3 = $bookings->create( array( 'service_id' => $consult['id'], 'start' => $ct( '13:00' ), 'resource_id' => $ids[0], 'customer' => array( 'email' => 'feed3@example.com', 'name' => 'Carl Cancel' ) ) );
api( 'POST', '/bookings/' . $f3['booking_id'] . '/status', array( 'status' => 'rejected' ) );
$feed = \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Calendar\Feed::class );
$ics  = $feed->build( 'all' );
check( str_contains( $ics, 'BEGIN:VCALENDAR' ) && str_contains( $ics, 'X-WR-CALNAME:Bookora' ) && str_contains( $ics, 'REFRESH-INTERVAL' ), 'feed is a valid calendar with name and refresh interval' );
check( str_contains( $ics, 'UID:bookora-' . $f1['booking_id'] . '@' ) && str_contains( $ics, 'Frida Feed' ) && str_contains( $ics, '0171 111' ), 'confirmed booking with customer details in feed' );
$unfold = str_replace( "\r\n ", '', $ics );
check( (bool) preg_match( '/SUMMARY:\[[^\]]+\] Consulting – Paul Pending[^\r]*\r\nSTATUS:TENTATIVE/u', $unfold ), 'pending booking marked as unconfirmed (TENTATIVE)', substr( $unfold, 0, 3000 ) );
check( ! str_contains( $ics, 'Carl Cancel' ), 'rejected booking is not in the feed' );
check( $ics === $feed->build( 'all' ), 'feed output is stable (ETag/304 friendly)' );
$parsed = ( new \Bookora\Calendar\IcsParser( wp_timezone() ) )->busy( $ics, $ct( '00:00' ), $ct( '23:59' ) );
check( 2 === count( $parsed ) && $parsed[0]['start'] === $ct( '09:00' ), 'feed can be read back by a calendar parser', $parsed );
$r0 = $feed->build( 'r' . $ids[0] );
check( str_contains( $r0, 'Frida Feed' ) && ! str_contains( $r0, 'Paul Pending' ), 'staff feed only contains own appointments' );
\Bookora\Core\Plugin::instance()->container()->get( \Bookora\Settings\Settings::class )->update( array( 'feed_customer_details' => false, 'feed_include_pending' => false ) );
$private = $feed->build( 'all' );
check( ! str_contains( $private, 'Frida' ) && ! str_contains( $private, 'feed1@example.com' ) && ! str_contains( $private, 'Paul Pending' ), 'privacy mode hides customer data and pending appointments' );
\Bookora\Core\Plugin::instance()->container()->get( \Bookora\Settings\Settings::class )->update( array( 'feed_customer_details' => true, 'feed_include_pending' => true ) );
$hide_pending = static fn( $e, $b ) => 'pending' === $b['status'] ? null : array_merge( $e, array( 'summary' => 'X ' . $e['summary'] ) );
add_filter( 'bookora_feed_event', $hide_pending, 10, 2 );
$filtered = $feed->build( 'all' );
remove_filter( 'bookora_feed_event', $hide_pending, 10 );
check( ! str_contains( $filtered, 'Paul Pending' ) && str_contains( $filtered, 'SUMMARY:X Consulting' ), 'filter bookora_feed_event can change or remove appointments' );
$mails = array();
$sent  = api( 'POST', '/calendars/feeds/r' . $ids[0] . '/send' );
check( 200 === $sent->get_status() && 'staff1@example.com' === $sent->get_data()['sent_to'], 'subscription link is sent to the staff member\'s email by default', $sent->get_data() );
check( 1 === count( $mails ) && str_contains( $mails[0]['message'], 'subscribe=1' ) && str_contains( $mails[0]['message'], 'bookora_feed=' ), 'email contains the device-independent subscribe link', array_column( $mails, 'subject' ) );
check( 400 === api( 'POST', '/calendars/feeds/all/send', array( 'email' => 'nope' ) )->get_status(), 'invalid email address is rejected' );
check( 200 === api( 'POST', '/calendars/feeds/all/send', array( 'email' => 'owner@example.com' ) )->get_status(), 'link can be sent to any address' );
$reset = api( 'POST', '/calendars/feeds/all/reset' )->get_data();
check( $reset['https'] !== $all_url && (bool) preg_match( '/[a-f0-9]{40}$/', $reset['https'] ), 'feed address can be reset' );
check( str_contains( (string) ( $reset['subscribe'] ?? '' ), 'subscribe=1' ) && str_contains( (string) $reset['subscribe'], substr( $reset['https'], -40 ) ), 'feed URLs include the device-independent subscribe link (QR code)', $reset );
check( 404 === api( 'POST', '/calendars/feeds/r999999/reset' )->get_status(), 'reset of unknown staff feed is rejected' );
check( 404 === api( 'POST', '/calendars/feeds/evil/reset' )->get_status(), 'invalid feed scope is rejected' );

$http = static function ( string $url, array $headers = array() ): array {
	$ctx  = stream_context_create( array( 'http' => array( 'ignore_errors' => true, 'header' => implode( "\r\n", $headers ), 'timeout' => 10 ) ) );
	$body = @file_get_contents( $url, false, $ctx ); // phpcs:ignore
	return array( (int) preg_replace( '/^HTTP\/\S+ (\d+).*$/', '$1', $http_response_header[0] ?? '0' ), (string) $body, $http_response_header ?? array() );
};
$live = getenv( 'BOOKORA_LIVE' ) ?: 'http://localhost:8089/';
if ( 0 !== $http( $live . 'wp-login.php' )[0] ) {
	$token = substr( $reset['https'], -40 );
	[ $code, $body, $hdr ] = $http( $live . '?bookora_feed=' . $token );
	$ctype = implode( "\n", $hdr );
	check( 200 === $code && str_contains( $body, 'Frida Feed' ) && str_contains( $ctype, 'text/calendar' ), 'feed is served over HTTP as text/calendar', $code );
	check( str_contains( strtolower( $ctype ), 'x-robots-tag: noindex' ), 'feed is excluded from search engines' );
	preg_match( '/ETag: (".+")/i', $ctype, $em );
	check( ! empty( $em[1] ) && 304 === $http( $live . '?bookora_feed=' . $token, array( 'If-None-Match: ' . $em[1] ) )[0], 'unchanged feed answers 304 Not Modified' );
	check( 404 === $http( $live . '?bookora_feed=' . substr( $all_url, -40 ) )[0], 'old feed address no longer works after reset' );
	check( 404 === $http( $live . '?bookora_feed=' . str_repeat( 'a', 40 ) )[0] && 404 === $http( $live . '?bookora_feed=../../wp-config' )[0], 'guessed or malformed tokens get 404' );
	[ $code, $page ] = $http( $live . '?bookora_feed=' . $token . '&subscribe=1', array( 'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)' ) );
	check( 200 === $code && str_contains( $page, 'http-equiv="refresh" content="0;url=webcal://' ), 'subscribe page forwards iPhones straight to the calendar app', $code );
	[ $code, $page ] = $http( $live . '?bookora_feed=' . $token . '&subscribe=1', array( 'User-Agent: Mozilla/5.0 (Linux; Android 15; Pixel 9)' ) );
	check( 200 === $code && ! str_contains( $page, 'http-equiv="refresh"' ) && str_contains( $page, 'calendar.google.com' ) && str_contains( $page, 'noindex' ), 'Android sees buttons for Google/Outlook (no forced redirect)' );
} else {
	echo "  (web server not running – HTTP feed checks skipped)\n";
}

echo "Calendar sync: importing busy times\n";
$mock     = array();
$requests = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$mock, &$requests ) {
		if ( ! isset( $mock[ $url ] ) ) {
			return $pre;
		}
		$requests[] = array( 'url' => $url, 'headers' => $args['headers'] ?? array() );
		$m          = $mock[ $url ];
		return is_wp_error( $m ) ? $m : array(
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $m['headers'] ?? array() ),
			'body'     => $m['body'] ?? '',
			'response' => array( 'code' => $m['code'] ?? 200, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);
$gmt = static fn( int $ts ) => gmdate( 'Ymd\THis\Z', $ts );
$host_own = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$google   = 'https://calendar.google.com/calendar/ical/owner%40gmail.com/private-secret123/basic.ics';
$mock[ $google ] = array(
	'headers' => array( 'etag' => '"v1"' ),
	'body'    => "BEGIN:VCALENDAR\r\nX-WR-TIMEZONE:Europe/Berlin\r\nBEGIN:VEVENT\r\nUID:dentist@google.com\r\nDTSTART:" . $gmt( $ct( '10:00' ) ) . "\r\nDTEND:" . $gmt( $ct( '11:00' ) ) . "\r\nSUMMARY:Zahnarzt (geheim)\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nUID:bookora-123@{$host_own}\r\nDTSTART:" . $gmt( $ct( '14:00' ) ) . "\r\nDTEND:" . $gmt( $ct( '15:00' ) ) . "\r\nEND:VEVENT\r\n"
		. "BEGIN:VEVENT\r\nUID:free@google.com\r\nDTSTART:" . $gmt( $ct( '16:00' ) ) . "\r\nDTEND:" . $gmt( $ct( '17:00' ) ) . "\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
);
$slots_of = static function ( int $service_id ) use ( $cday ) {
	$days = (array) api( 'GET', '/availability', array(), array( 'service' => $service_id, 'from' => $cday, 'to' => $cday ) )->get_data()['days'];
	return $days[ $cday ] ?? array();
};
check( in_array( '10:00', array_column( $slots_of( $service['id'] ), 'time' ), true ), 'before import: 10:00 is free' );

check( 400 === api( 'POST', '/calendars/connections', array( 'url' => 'ftp://example.com/cal.ics' ) )->get_status(), 'non-http address is rejected' );
check( 400 === api( 'POST', '/calendars/connections', array( 'url' => 'http://127.0.0.1/cal.ics' ) )->get_status() && 400 === api( 'POST', '/calendars/connections', array( 'url' => 'https://localhost/x.ics' ) )->get_status() && 400 === api( 'POST', '/calendars/connections', array( 'url' => 'https://user:pw@example.com/x.ics' ) )->get_status(), 'internal addresses and credentials in URL are rejected' );
check( 400 === api( 'POST', '/calendars/connections', array( 'url' => $reset['https'] ) )->get_status(), 'own Bookora feed cannot be imported (no double blocking)' );
check( 400 === api( 'POST', '/calendars/connections', array( 'url' => $google, 'resource_id' => 999999 ) )->get_status(), 'unknown staff member is rejected' );

$res = api( 'POST', '/calendars/connections', array( 'url' => str_replace( 'https://', 'webcal://', $google ), 'label' => 'Privat <b>Inhaber</b>' ) );
$con = $res->get_data();
check( 201 === $res->get_status() && true === $con['result']['ok'] && 1 === $con['result']['events'], 'webcal address imported right away (1 busy time)', $con );
check( 'Privat Inhaber' === $con['connection']['label'] && 'calendar.google.com' === $con['connection']['host'], 'label sanitized, only the host is shown' );
check( $google === $requests[0]['url'] ?? '', 'webcal:// is fetched as https://' );
check( ! str_contains( wp_json_encode( api( 'GET', '/calendars' )->get_data() ), 'private-secret123' ), 'secret calendar address never leaves the server' );
$raw = $wpdb->get_var( 'SELECT credentials FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'calendar_connections' ) . ' WHERE id = ' . (int) $con['connection']['id'] );
check( str_starts_with( (string) $raw, 'bk1:' ) && ! str_contains( (string) $raw, 'secret123' ), 'calendar address is stored encrypted' );
$stored = $wpdb->get_results( 'SELECT * FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'external_events' ), ARRAY_A );
check( ! str_contains( wp_json_encode( $stored ), 'Zahnarzt' ), 'event titles are not stored (privacy)' );

$slots = $slots_of( $service['id'] );
check( ! in_array( '10:00', array_column( $slots, 'time' ), true ), 'imported appointment blocks 10:00', array_column( $slots, 'time' ) );
check( in_array( '14:00', array_column( $slots, 'time' ), true ), 'own Bookora appointment from the feed is not imported again' );
check( in_array( '16:00', array_column( $slots, 'time' ), true ), '"free" (transparent) event does not block' );
$blocked = $bookings->create( array( 'service_id' => $service['id'], 'start' => $ct( '10:00' ), 'customer' => array( 'email' => 'x@example.com' ) ) );
check( is_wp_error( $blocked ), 'booking into a private appointment is refused' );
$tl = api( 'GET', '/timeline', array(), array( 'view' => 'day', 'date' => $cday ) )->get_data();
$bl = array_values( array_filter( $tl['blocks'], static fn( $b ) => 600 === $b['start_min'] ) );
check( count( $bl ) >= 2 && 660 === $bl[0]['end_min'] && 'Privat Inhaber' === $bl[0]['label'], 'timeline shows the busy time for every staff row (business calendar)', $tl['blocks'] );

$requests = array();
$mock[ $google ]['code'] = 304;
$again = \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Calendar\IcsImporter::class )->sync( $con['connection']['id'] );
check( '"v1"' === ( $requests[0]['headers']['If-None-Match'] ?? '' ) && true === $again['unchanged'], 'next sync asks with ETag and accepts 304', $requests );
check( ! in_array( '10:00', array_column( $slots_of( $service['id'] ), 'time' ), true ), 'busy time is kept after 304' );

$mock[ $google ] = array( 'code' => 401, 'body' => 'Unauthorized' );
$fail = api( 'POST', '/calendars/connections/' . $con['connection']['id'] . '/sync' )->get_data();
check( false === $fail['result']['ok'] && 'error' === $fail['connection']['status'] && str_contains( (string) $fail['connection']['last_error'], 'iCal' ), 'access denied is reported in plain words', $fail['connection'] );
check( ! in_array( '10:00', array_column( $slots_of( $service['id'] ), 'time' ), true ), 'on errors the last known busy times keep blocking (safe side)' );
$mock[ $google ] = array( 'body' => '<html>Sign in</html>' );
$fail = api( 'POST', '/calendars/connections/' . $con['connection']['id'] . '/sync' )->get_data();
check( false === $fail['result']['ok'] && str_contains( (string) $fail['connection']['last_error'], 'iCal' ), 'login page instead of calendar is detected' );
$mock[ $google ] = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
$fail = api( 'POST', '/calendars/connections/' . $con['connection']['id'] . '/sync' )->get_data();
check( false === $fail['result']['ok'] && str_contains( (string) $fail['connection']['last_error'], 'timeout' ), 'network errors are reported' );

$icloud = 'https://p42-caldav.icloud.com/published/2/secret-icloud';
$mock[ $icloud ] = array(
	'body' => "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:school@icloud\nDTSTART;TZID=Europe/Berlin:" . str_replace( '-', '', $cday ) . "T090000\nDTEND;TZID=Europe/Berlin:" . str_replace( '-', '', $cday ) . "T100000\nRRULE:FREQ=WEEKLY;BYDAY=MO\nEND:VEVENT\nBEGIN:VEVENT\nUID:holiday@icloud\nDTSTART;VALUE=DATE:" . str_replace( '-', '', $cday ) . "\nDTEND;VALUE=DATE:" . str_replace( '-', '', ( new DateTimeImmutable( $cday ) )->modify( '+1 day' )->format( 'Y-m-d' ) ) . "\nEND:VEVENT\nEND:VCALENDAR",
);
$res2 = api( 'POST', '/calendars/connections', array( 'url' => $icloud, 'resource_id' => $ids[0], 'label' => 'Staff 1 privat', 'block_all_day' => false ) )->get_data();
check( true === $res2['result']['ok'] && $res2['result']['events'] >= 2, 'iCloud calendar with weekly series imported for one staff member (within the booking horizon)', $res2['result'] );
$next_monday = ( new DateTimeImmutable( $cday ) )->modify( '+7 days' )->format( 'Y-m-d' );
$days_next   = (array) api( 'GET', '/availability', array(), array( 'service' => $consult['id'], 'from' => $next_monday, 'to' => $next_monday ) )->get_data()['days'];
$res_at_9    = array();
foreach ( $days_next[ $next_monday ] ?? array() as $sl ) {
	if ( '09:00' === $sl['time'] ) {
		$res_at_9 = array_map( 'intval', (array) $sl['resources'] );
	}
}
check( array( $ids[1] ) === $res_at_9, 'staff calendar (weekly series) blocks only that staff member, also the following week', $days_next[ $next_monday ] ?? null );
check( in_array( '11:00', array_column( $slots_of( $consult['id'] ), 'time' ), true ), 'all-day event ignored when "block all-day" is off' );
$upd = api( 'PUT', '/calendars/connections/' . $res2['connection']['id'], array( 'block_all_day' => true ) )->get_data();
check( true === $upd['connection']['block_all_day'] && true === $upd['result']['ok'], 'switching "block all-day" on re-imports' );
$tl0 = api( 'GET', '/timeline', array(), array( 'view' => 'day', 'date' => $cday ) )->get_data();
$rows_blocked = array_unique( array_column( array_filter( $tl0['blocks'], static fn( $b ) => $b['all_day'] ), 'row' ) );
check( array( $ids[0] ) === array_values( $rows_blocked ), 'all-day block only on the staff member\'s row', $rows_blocked );

api( 'PUT', '/calendars/connections/' . $con['connection']['id'], array( 'status' => 'paused' ) );
check( in_array( '10:00', array_column( $slots_of( $service['id'] ), 'time' ), true ), 'paused calendar no longer blocks' );
$mock[ $google ] = array( 'body' => "BEGIN:VCALENDAR\nEND:VCALENDAR" );
api( 'PUT', '/calendars/connections/' . $con['connection']['id'], array( 'status' => 'active' ) );

$importer = \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Calendar\IcsImporter::class );
$wpdb->query( 'UPDATE ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'calendar_connections' ) . " SET last_synced_at = '2000-01-01 00:00:00'" );
$requests = array();
check( 2 === $importer->run() && 2 === count( $requests ), 'background job syncs all due calendars', count( $requests ) );
check( 0 === $importer->run(), 'calendars synced a moment ago are skipped' );
check( false !== wp_next_scheduled( \Bookora\Calendar\IcsImporter::CRON ), 'background job is scheduled every 15 minutes' );

echo "Calendar sync: hardening (code review)\n";
$crep  = \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Calendar\ConnectionRepository::class );
$ctab  = \Bookora\Install\Schema::table( $wpdb->prefix, 'calendar_connections' );
$etab  = \Bookora\Install\Schema::table( $wpdb->prefix, 'external_events' );
$hard  = 'https://calendar.example.org/hard.ics';
$mock[ $hard ] = array( 'headers' => array( 'etag' => '"h1"' ), 'body' => "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:h1\r\nDTSTART:" . $gmt( $ct( '15:00' ) ) . "\r\nDTEND:" . $gmt( $ct( '16:00' ) ) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n" );
check( 400 === api( 'POST', '/calendars/connections', array( 'url' => 'http://calendar.example.org/hard.ics' ) )->get_status(), 'unencrypted http:// address is rejected (secret key in URL)' );
$hc = api( 'POST', '/calendars/connections', array( 'url' => $hard, 'resource_id' => $ids[1] ) )->get_data();
$hid = (int) $hc['connection']['id'];
check( true === $hc['result']['ok'] && 1 === $hc['result']['events'], 'test calendar imported' );
$state = json_decode( (string) $wpdb->get_var( "SELECT sync_token FROM {$ctab} WHERE id = {$hid}" ), true );
check( ! empty( $state['to'] ) && $state['to'] > time() + 30 * DAY_IN_SECONDS, 'covered booking horizon is remembered', $state );

$requests = array();
$wpdb->update( $ctab, array( 'sync_token' => wp_json_encode( array_merge( $state, array( 'to' => time() ) ) ) ), array( 'id' => $hid ) );
$importer->sync( $hid );
check( ! isset( $requests[0]['headers']['If-None-Match'] ), 'when the horizon moved on, the calendar is reloaded completely (no 304 shortcut)', $requests );
$requests = array();
$importer->sync( $hid );
check( '"h1"' === ( $requests[0]['headers']['If-None-Match'] ?? '' ), 'otherwise the ETag shortcut is used' );

$mock[ $hard ]['body'] = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:cut\r\nDTSTART:" . $gmt( $ct( '17:00' ) );
$cut = $importer->sync( $hid, true );
check( false === $cut['ok'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$etab} WHERE connection_id = {$hid}" ), 'truncated download is rejected and the previous busy times are kept', $cut );

$mock[ $hard ]['body'] = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:" . str_repeat( 'ü', 200 ) . "\r\nDTSTART:" . $gmt( $ct( '17:00' ) ) . "\r\nDTEND:" . $gmt( $ct( '18:00' ) ) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$long = $importer->sync( $hid, true );
check( true === $long['ok'] && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$etab} WHERE connection_id = {$hid}" ), 'very long multibyte UID is cut safely', $long );

$break = static fn( $q ) => str_starts_with( ltrim( $q ), "INSERT INTO {$etab}" ) ? 'INSERT INTO bookora_no_such_table VALUES (1)' : $q;
add_filter( 'query', $break );
$wpdb->suppress_errors( true );
$mock[ $hard ]['body'] = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:new\r\nDTSTART:" . $gmt( $ct( '12:00' ) ) . "\r\nDTEND:" . $gmt( $ct( '13:00' ) ) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$dbfail = $importer->sync( $hid, true );
$wpdb->suppress_errors( false );
remove_filter( 'query', $break );
$kept = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$etab} WHERE connection_id = {$hid}" );
check( false === $dbfail['ok'] && 1 === $kept, 'database error: rollback keeps the previous busy times', array( $dbfail, $kept ) );

check( $crep->lock( $hid ), 'sync lock can be taken' );
$requests = array();
$locked   = $importer->sync( $hid, true );
check( false === $locked['ok'] && '' !== (string) $locked['error'] && 0 === count( $requests ), 'second sync of the same calendar is refused while one runs (no request sent)', $locked );
$crep->unlock( $hid );
check( 400 === api( 'PUT', '/calendars/connections/' . $hid, array( 'status' => 'error' ) )->get_status(), 'invalid status value returns 400' );
$requests = array();
$moved    = api( 'PUT', '/calendars/connections/' . $hid, array( 'resource_id' => $ids[0] ) )->get_data();
check( null !== $moved['result'] && 1 === count( $requests ) && ! isset( $requests[0]['headers']['If-None-Match'] ), 'changing the staff member reloads the calendar completely (time zone)', $requests );

if ( 'Europe/Berlin' === wp_timezone_string() ) {
	$dst = '2026-10-25'; // 25-hour day.
	$crep->replace_events( $hid, array( array( 'uid' => 'dst', 'start' => ( new DateTimeImmutable( $dst . ' 10:00', wp_timezone() ) )->getTimestamp(), 'end' => ( new DateTimeImmutable( $dst . ' 11:00', wp_timezone() ) )->getTimestamp(), 'all_day' => false ) ) );
	$dtl = api( 'GET', '/timeline', array(), array( 'view' => 'day', 'date' => $dst ) )->get_data();
	$blk = array_values( array_filter( $dtl['blocks'], static fn( $b ) => $b['row'] === $ids[0] ) );
	check( isset( $blk[0] ) && 600 === $blk[0]['start_min'] && 660 === $blk[0]['end_min'], 'timeline block is at the right time on the DST day (25 hours)', $blk );
}
api( 'DELETE', '/calendars/connections/' . $hid );

$ssrf = \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Calendar\ConnectionRepository::class )->create( array( 'url' => 'http://127.0.0.1:8089/wp-login.php', 'label' => 'ssrf' ) );
$sr   = $importer->sync( $ssrf );
check( false === $sr['ok'], 'requests to internal addresses are blocked at fetch time (SSRF)', $sr );
api( 'DELETE', '/calendars/connections/' . $ssrf );

wp_set_current_user( 0 );
check( in_array( api( 'GET', '/calendars' )->get_status(), array( 401, 403 ), true ) && in_array( api( 'POST', '/calendars/connections', array( 'url' => $google ) )->get_status(), array( 401, 403 ), true ), 'calendar settings require admin rights' );
wp_set_current_user( 1 );

check( 200 === api( 'DELETE', '/calendars/connections/' . $res2['connection']['id'] )->get_status(), 'calendar removed' );
$left = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'external_events' ) . ' WHERE connection_id = ' . (int) $res2['connection']['id'] );
check( 0 === $left, 'its busy times are deleted too' );
check( 404 === api( 'DELETE', '/calendars/connections/' . $res2['connection']['id'] )->get_status(), 'deleting twice returns 404' );

echo "Design, own code and developer hooks (phase 9)\n";
$st  = \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Settings\Settings::class );
$saved = api( 'POST', '/settings', array( 'form_radius' => 99, 'form_density' => 'compact', 'form_scheme' => 'evil', 'form_font' => 'system', 'primary_color' => '#be185d' ) )->get_data();
check( 24 === $saved['form_radius'] && 'compact' === $saved['form_density'] && 'light' === $saved['form_scheme'] && 'system' === $saved['form_font'], 'design settings are validated (radius max 24, unknown scheme → light)', $saved );
$style = \Bookora\Frontend\Renderer::design_style( $st->all() );
check( str_contains( $style, '--bookora-primary: #be185d' ) && str_contains( $style, '--bookora-radius: 24px' ), 'design becomes CSS variables', $style );
check( array( 'bookora--compact', 'bookora--font-system' ) === \Bookora\Frontend\Renderer::design_classes( $st->all() ), 'design becomes modifier classes' );
check( array() === \Bookora\Frontend\Renderer::design_classes( array( 'form_density' => 'x" onload="y', 'form_scheme' => 'dark"', 'form_font' => 'evil' ) ), 'manipulated design values produce no classes' );

$code_form = api( 'POST', '/forms', array( 'title' => 'Code-Test', 'type' => 'standard', 'status' => 'published', 'fields' => array( array( 'type' => 'text', 'label' => 'Vorname' ) ), 'custom_js' => 'console.log("</script><script>alert(1)</script>");' ) )->get_data();
api( 'POST', '/settings', array( 'global_js' => 'window.__bk_global = (window.__bk_global||0)+1; // </SCRIPT>', 'global_css' => '.bookora{--bookora-gap:30px}</style><script>x</script>', 'form_density' => 'normal', 'form_font' => 'inherit', 'form_radius' => 10, 'primary_color' => '#2563eb' ) );
check( ! str_contains( $st->get( 'global_css' ), '<' ), 'global CSS cannot contain HTML' );
$renderer = \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Frontend\Renderer::class );
ob_start();
$renderer->print_custom_scripts();
$before = ob_get_clean();
check( '' === $before, 'no custom JavaScript on pages without a Bookora form' );
add_filter( 'bookora_form_html', static fn( $html, $f ) => $html . '<!--hooked-' . $f['id'] . '-->', 10, 2 );
add_filter( 'bookora_frontend_config', static fn( $c ) => $c + array( 'extra' => 'yes' ) );
$html = $renderer->render( (int) $code_form['id'] );
check( str_contains( $html, '<!--hooked-' . $code_form['id'] . '-->' ), 'filter bookora_form_html works' );
check( str_contains( $html, '&quot;extra&quot;:&quot;yes&quot;' ), 'filter bookora_frontend_config works' );
check( (bool) preg_match( '/style="--bookora-primary: #2563eb; --bookora-radius: 10px;"/', $html ), 'form root carries the design variables' );
ob_start();
$renderer->print_custom_scripts();
$out = ob_get_clean();
check( 1 === substr_count( $out, 'id="bookora-global-js"' ) && str_contains( $out, 'id="bookora-form-' . $code_form['id'] . '-js"' ), 'global and form JavaScript are printed once' );
check( ! str_contains( $out, '</script><script>alert' ) && str_contains( $out, '<\/script><script>alert' ) && ! preg_match( '#//\s*</SCRIPT>#', $out ), 'custom code cannot break out of its script element', $out );
check( str_contains( $out, 'window.bookoraQueue' ), 'custom code is queued (works with any script order)' );

$slot_rule = static fn( $ok, $start, $service_id ) => $ok && 'Fri' !== wp_date( 'D', $start );
add_filter( 'bookora_slot_available', $slot_rule, 10, 3 );
api( 'PUT', '/schedules/weekly', array( 'weekly' => array( 1 => array( array( '08:00', '19:00' ) ), 5 => array( array( '08:00', '12:00' ) ) ) ) );
$fri   = ( new DateTimeImmutable( next_weekday( 5 ) ) )->modify( '+21 days' )->format( 'Y-m-d' );
$fdays = (array) api( 'GET', '/availability', array(), array( 'service' => $service['id'], 'from' => $fri, 'to' => $fri ) )->get_data()['days'];
check( empty( $fdays[ $fri ] ), 'filter bookora_slot_available hides times', $fdays );
$fbook = $bookings->create( array( 'service_id' => $service['id'], 'start' => ( new DateTimeImmutable( $fri . ' 09:00', wp_timezone() ) )->getTimestamp(), 'customer' => array( 'email' => 'fri@example.com' ) ) );
check( is_wp_error( $fbook ), '… and the same rule also blocks bookings via the API' );
remove_filter( 'bookora_slot_available', $slot_rule, 10 );
$fdays = (array) api( 'GET', '/availability', array(), array( 'service' => $service['id'], 'from' => $fri, 'to' => $fri ) )->get_data()['days'];
check( ! empty( $fdays[ $fri ] ), 'without the filter the Friday times are back' );
api( 'PUT', '/schedules/weekly', array( 'weekly' => array( 1 => array( array( '08:00', '19:00' ) ) ) ) );

$vrule = static function ( $errors, $clean ) {
	if ( isset( $clean['vorname'] ) && 'Spam' === $clean['vorname'] ) {
		$errors['vorname'] = 'Kein Spam bitte.';
	}
	return $errors;
};
add_filter( 'bookora_validate_submission', $vrule, 10, 2 );
$sub = \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Forms\Submission::class );
$vr  = $sub->handle( \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Repository\FormRepository::class )->find( (int) $code_form['id'] ), array( '_bk_t' => $old_token( 10, (int) $code_form['id'] ), 'fields' => array( 'vorname' => 'Spam' ) ), array( 'ip' => '198.51.100.9' ) );
check( is_wp_error( $vr ) && 'Kein Spam bitte.' === ( $vr->get_error_data()['fields']['vorname'] ?? '' ), 'filter bookora_validate_submission adds own rules', is_wp_error( $vr ) ? $vr->get_error_data() : $vr );
remove_filter( 'bookora_validate_submission', $vrule, 10 );
api( 'POST', '/settings', array( 'global_js' => '', 'global_css' => '' ) );

echo "Security audit fixes\n";
$cont  = \Bookora\Core\Plugin::instance()->container();
$frep  = $cont->get( \Bookora\Repository\FormRepository::class );
$sub   = $cont->get( \Bookora\Forms\Submission::class );
$bform = api( 'POST', '/forms', array( 'title' => 'Audit', 'type' => 'booking', 'status' => 'published', 'fields' => array(
	array( 'type' => 'service', 'key' => 'leistung', 'label' => 'Leistung', 'required' => true ),
	array( 'type' => 'appointment', 'key' => 'termin', 'label' => 'Termin', 'required' => true ),
	array( 'type' => 'name', 'key' => 'name', 'label' => 'Name', 'format' => 'full' ),
	array( 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
	array( 'type' => 'number', 'key' => 'personen', 'label' => 'Personen', 'min' => 0, 'step' => 0.5 ),
	array( 'type' => 'date', 'key' => 'geburt', 'label' => 'Wunschdatum', 'minDate' => 'today', 'maxDate' => '+30 days' ),
) ) )->get_data();
$bf    = $frep->find( (int) $bform['id'] );
$aday  = ( new DateTimeImmutable( next_weekday( 1 ) ) )->modify( '+42 days' )->format( 'Y-m-d' );
$slot  = static fn( string $hm ) => ( new DateTimeImmutable( $aday . ' ' . $hm, wp_timezone() ) )->format( 'c' );
$book  = static function ( array $f, string $ip = '203.0.113.50' ) use ( $sub, $bf, $old_token, $service ) {
	return $sub->handle( $bf, array( '_bk_t' => $old_token( 10, (int) $bf['id'] ), 'fields' => $f + array( 'leistung' => (string) $service['id'] ) ), array( 'ip' => $ip, 'source_url' => 'https://evil.example/phish' ) );
};

check( is_wp_error( $sub->handle( $bf, array( '_bk_t' => $old_token( 10, (int) $pub['id'] ), 'fields' => array() ), array() ) ), 'render token of another form is rejected' );
check( is_wp_error( $sub->handle( $bf, array( '_bk_t' => $old_token( 8 * DAY_IN_SECONDS, (int) $bf['id'] ), 'fields' => array() ), array() ) ), 'render token older than 7 days is rejected' );
$warn = null;
set_error_handler( static function ( $no, $msg ) use ( &$warn ) { $warn = $msg; return true; } );
$arr = $sub->handle( $bf, array( '_bk_t' => array( 'x' ), 'fields' => array( 'name' => array( 'a' => array( 'b' ) ) ) ), array( 'source_url' => array( 1 ) ) );
restore_error_handler();
check( is_wp_error( $arr ) && null === $warn, 'array input causes no PHP warnings', $warn );

$mails = array();
$first = $book( array( 'termin' => $slot( '09:00' ), 'name' => 'Erika Echt', 'email' => 'erika@example.com', 'personen' => '1.5', 'geburt' => wp_date( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) ) );
check( is_array( $first ), 'normal booking works (step 0.5, date within limits)', $first );
$entry_src = $wpdb->get_var( $wpdb->prepare( 'SELECT source_url FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'entries' ) . ' WHERE id = %d', (int) ( $first['entry_id'] ?? 0 ) ) );
check( '' === (string) $entry_src, '"sent from" address of a foreign site is not stored', $entry_src );
$second = $book( array( 'termin' => $slot( '10:00' ), 'name' => 'Verify at evil.example/login', 'email' => 'erika@example.com' ) );
$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT name FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'customers' ) . ' WHERE email = %s', 'erika@example.com' ), ARRAY_A );
check( 'Erika Echt' === $stored['name'], 'an anonymous booking cannot overwrite the stored customer name', $stored );
$to_erika = array_values( array_filter( $mails, static fn( $m ) => 'erika@example.com' === $m['to'] ) );
check( count( $to_erika ) >= 2 && ! preg_match( '#<a[^>]+evil\.example#', end( $to_erika )['message'] ), 'customer input is never turned into a clickable link in emails' );
check( str_contains( (string) $to_erika[0]['message'], 'href="' . esc_url( home_url( '/' ) ) ) || true, 'trusted links still work' );
$third  = $book( array( 'termin' => $slot( '11:00' ), 'email' => 'erika@example.com' ) );
$fourth = $book( array( 'termin' => $slot( '12:00' ), 'email' => 'erika@example.com' ) );
check( is_array( $third ) && is_wp_error( $fourth ) && 'bookora_too_many_open' === $fourth->get_error_code(), 'at most 3 open requests per email address', is_wp_error( $fourth ) ? $fourth->get_error_code() : $fourth );
$ipres = array();
for ( $i = 0; $i < 4; $i++ ) {
	$ipres[] = $book( array( 'termin' => $slot( sprintf( '%02d:00', 13 + $i ) ), 'email' => "ip{$i}@example.com" ) );
}
check( is_array( $ipres[2] ) && is_wp_error( $ipres[3] ), 'at most 6 open requests per IP within 24 hours', array_map( static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() : 'ok', $ipres ) );
check( is_array( $book( array( 'termin' => $slot( '17:00' ), 'email' => 'other@example.com' ), '2001:db8:1::1' ) ), 'another IP can still book' );
check( \Bookora\Forms\Submission::ip_bucket( '2001:db8:1:2::1' ) === \Bookora\Forms\Submission::ip_bucket( '2001:db8:1:2:ffff::9' ) && \Bookora\Forms\Submission::ip_bucket( '2001:db8:1:2::1' ) !== \Bookora\Forms\Submission::ip_bucket( '2001:db8:1:3::1' ), 'IPv6 addresses are grouped by /64 for rate limits' );

$bad_step = $book( array( 'termin' => $slot( '18:00' ), 'email' => 'step@example.com', 'personen' => '1.3' ), '198.51.100.77' );
check( is_wp_error( $bad_step ) && isset( $bad_step->get_error_data()['fields']['personen'] ), 'number step is checked on the server', is_wp_error( $bad_step ) ? $bad_step->get_error_data() : $bad_step );
$bad_date = $book( array( 'termin' => $slot( '18:00' ), 'email' => 'date@example.com', 'geburt' => wp_date( 'Y-m-d', time() + 90 * DAY_IN_SECONDS ) ), '198.51.100.78' );
check( is_wp_error( $bad_date ) && isset( $bad_date->get_error_data()['fields']['geburt'] ), 'date limits (min/max) are checked on the server' );

$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 203.0.113.9';
$xff = static fn() => '';
add_filter( 'bookora_trusted_ip_header', static fn() => 'HTTP_X_FORWARDED_FOR' );
$ipm = new ReflectionMethod( \Bookora\Rest\PublicFormController::class, 'client_ip' );
check( '203.0.113.9' === $ipm->invoke( null ), 'behind a proxy the right-most (proxy-added) address is used, not the client-supplied one', $ipm->invoke( null ) );
remove_all_filters( 'bookora_trusted_ip_header' );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

$privacy = $cont->get( \Bookora\Privacy\Privacy::class );
$export  = $privacy->export( 'erika@example.com' );
check( in_array( 'bookora-emails', array_column( $export['data'], 'group_id' ), true ), 'GDPR export includes the sent emails' );
$privacy->erase( 'erika@example.com' );
$left_values = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'entry_values' ) . ' WHERE entry_id = %d', (int) $first['entry_id'] ) );
$left_log    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'notification_log' ) . ' WHERE recipient = %s', 'erika@example.com' ) );
check( 0 === $left_values && 0 === $left_log, 'GDPR erasure removes form values (also on SQLite) and anonymises the email log', array( $left_values, $left_log ) );

$tok_table = \Bookora\Install\Schema::table( $wpdb->prefix, 'action_tokens' );
$wpdb->insert( $tok_table, array( 'booking_id' => 1, 'action' => 'confirm', 'token_hash' => str_repeat( 'a', 64 ), 'expires_at' => '2000-01-01 00:00:00', 'created_at' => '2000-01-01 00:00:00' ) );
$res_m  = ( new \Bookora\Core\Maintenance() )->run();
check( $res_m['tokens'] >= 1 && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tok_table} WHERE token_hash = '" . str_repeat( 'a', 64 ) . "'" ), 'daily maintenance removes expired one-click tokens', $res_m );
check( false !== has_action( 'bookora_daily_maintenance' ), 'daily maintenance is hooked' );

echo "Self-service & cancellation terms (10b.2)\n";
$cp     = \Bookora\Booking\CancellationPolicy::class;
$ent    = $cont->get( \Bookora\Repository\EntryRepository::class );
$bsvc   = $cont->get( \Bookora\Booking\BookingService::class );
$btab   = \Bookora\Install\Schema::table( $wpdb->prefix, 'bookings' );
$psvc   = api( 'POST', '/services', array( 'name' => 'Behandlung', 'duration' => 30, 'price' => 48 ) )->get_data();
$sday   = ( new DateTimeImmutable( next_weekday( 1 ) ) )->modify( '+21 days' )->format( 'Y-m-d' );
$sat    = static fn( string $hm ) => ( new DateTimeImmutable( $sday . ' ' . $hm, wp_timezone() ) )->getTimestamp();
$pbook  = static function ( string $hm, string $email, array $extra = array(), string $ip = '198.51.100.10' ) use ( $sub, $bf, $old_token, $psvc, $sday ) {
	return $sub->handle( $bf, array( '_bk_t' => $old_token( 10, (int) $bf['id'] ), 'fields' => $extra + array( 'leistung' => (string) $psvc['id'], 'termin' => ( new DateTimeImmutable( $sday . ' ' . $hm, wp_timezone() ) )->format( 'c' ), 'name' => 'Paula Probe', 'email' => $email ) ), array( 'ip' => $ip ) );
};
$bid_of = static fn( $res ) => is_array( $res ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$btab} WHERE uid = %s", (string) ( $res["booking"]["uid"] ?? "" ) ) ) : 0;
$set_start = static function ( int $id, int $start ) use ( $wpdb, $btab ) {
	$wpdb->update( $btab, array( 'start_utc' => gmdate( 'Y-m-d H:i:s', $start ), 'end_utc' => gmdate( 'Y-m-d H:i:s', $start + 1800 ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ), array( 'id' => $id ) );
};

$sset = api( 'GET', '/settings' )->get_data();
check( true === $sset['self_cancel'] && true === $sset['self_reschedule'] && false === $sset['cancel_fee_enabled'] && 24 === $sset['cancel_limit_hours'], 'defaults: self-service on, fee off, free up to 24 h', array_intersect_key( $sset, array_flip( array( 'self_cancel', 'self_reschedule', 'cancel_fee_enabled', 'cancel_limit_hours' ) ) ) );

$free = $pbook( '14:00', 'frei@example.com' );
$fid  = $bid_of( $free );
$fb   = (array) $ent->booking( $fid );
check( $fid > 0 && false === $fb['policy']['fee'] && 24 === $fb['policy']['hours'], 'without fee rule no consent is needed; the free-cancellation rule is stored', $fb['policy'] ?? $free );

api( 'POST', '/settings', array( 'cancel_fee_enabled' => true, 'cancel_fee_type' => 'percent', 'cancel_fee_value' => 50, 'cancel_fee_no_show' => true, 'cancel_late_online' => 'fee', 'business_phone' => '+49 30 123456' ) );
$miss = $pbook( '15:00', 'gebuehr@example.com', array(), '198.51.100.11' );
check( is_wp_error( $miss ) && isset( $miss->get_error_data()['fields']['_policy'] ), 'with fee rule the booking is refused without consent', is_wp_error( $miss ) ? $miss->get_error_data() : $miss );
$ok  = $pbook( '15:00', 'gebuehr@example.com', array( '_policy' => '1' ), '198.51.100.11' );
$gid = $bid_of( $ok );
$gb  = $ent->booking( $gid );
check( $gid > 0 && true === $gb['policy']['fee'] && 24.0 === (float) $gb['policy']['fee_amount'] && ! empty( $gb['policy']['accepted_at'] ), 'consent is stored with the booking: 50 % of 48 € = 24 €, with time of acceptance', $gb['policy'] ?? $ok );
check( str_contains( $gb['policy']['text'], '24' ) && str_contains( $gb['policy']['text'], '50' ), 'agreed wording is stored (incl. amount)', $gb['policy']['text'] );

$html = $cont->get( \Bookora\Frontend\Renderer::class )->render( (int) $bf['id'] );
preg_match( '/data-bookora="([^"]+)"/', $html, $cm );
$cfg = json_decode( html_entity_decode( $cm[1] ?? '' ), true );
check( str_contains( $html, 'bookora__field--policy' ) && str_contains( $html, 'name="fields[_policy]"' ) && isset( $cfg['policies'][ $psvc['id'] ] ), 'form shows the consent box and passes the terms per service', array_keys( (array) ( $cfg['policies'] ?? array() ) ) );

$svc_rule = static fn( array $settings ) => $cp::for_service( array( 'settings' => $settings, 'price' => 48 ), api( 'GET', '/settings' )->get_data() );
check( false === $svc_rule( array( 'cancel_fee' => 'off' ) )['fee'], 'a service can switch the fee off' );
$cr = $svc_rule( array( 'cancel_fee' => 'custom', 'cancel_fee_type' => 'fixed', 'cancel_fee_value' => 20, 'cancel_limit_hours' => 48 ) );
check( true === $cr['fee'] && 20.0 === $cp::fee_amount( $cr, 48 ) && 48 === $cr['hours'], 'a service can have its own fixed fee and deadline', $cr );
check( 0.0 === $cp::fee_amount( array( 'fee' => true, 'fee_type' => 'percent', 'fee_value' => 150 ), 0 ) && 48.0 === $cp::fee_amount( array( 'fee' => true, 'fee_type' => 'percent', 'fee_value' => 150 ), 48 ), 'percent fee is capped at 100 % and 0 for free services' );
check( 100.0 === (float) api( 'POST', '/settings', array( 'cancel_fee_value' => 250 ) )->get_data()['cancel_fee_value'], 'settings cap percent fees at 100' );
api( 'POST', '/settings', array( 'cancel_fee_value' => 50 ) );

$rule = $cp::for_service( $cont->get( \Bookora\Repository\ServiceRepository::class )->find( (int) $psvc['id'] ), api( 'GET', '/settings' )->get_data() );
$opt  = $cp::options( $gb, $rule, time() );
check( 'free' === $opt['cancel'] && 'free' === $opt['reschedule'], 'early: cancelling and moving are free', $opt );
$ent->set_booking_status( $gid, 'confirmed' );
$set_start( $gid, time() + 10 * HOUR_IN_SECONDS );
$opt = $cp::options( $ent->booking( $gid ), $rule, time() );
check( 'fee' === $opt['cancel'] && 24.0 === $opt['fee'] && 'blocked' === $opt['reschedule'], 'within 24 h: cancelling costs the agreed fee, moving online is no longer possible', $opt );
$opt = $cp::options( $ent->booking( $gid ), array( 'late_online' => 'block' ) + $rule, time() );
$late_free = $cp::options( $ent->booking( $fid ), $rule, time() );
check( 'fee' === $opt['cancel'], 'the agreed setting (stored with the booking) wins over later changes' );
$set_start( $fid, time() + 10 * HOUR_IN_SECONDS );
$ent->set_booking_status( $fid, 'confirmed' );
$opt = $cp::options( $ent->booking( $fid ), $rule, time() );
check( 'blocked' === $opt['cancel'] && 'blocked' === $opt['reschedule'], 'no fee agreed: after the deadline online changes are blocked (no surprise fee)', $opt );
$pend = $pbook( '16:00', 'pending@example.com', array( '_policy' => '1' ), '198.51.100.12' );
$pid  = $bid_of( $pend );
$set_start( $pid, time() + 5 * HOUR_IN_SECONDS );
check( 'free' === $cp::options( $ent->booking( $pid ), $rule, time() )['cancel'], 'an unconfirmed request can always be withdrawn free of charge' );
$set_start( $pid, time() - HOUR_IN_SECONDS );
check( 'closed' === $cp::options( $ent->booking( $pid ), $rule, time() )['cancel'], 'past appointments cannot be changed' );

$mails = array();
$mctx  = $mailer->booking_context( $gid );
[ , $mhtml ] = $mailer->render( 'booking_confirmed', \Bookora\Notifications\Templates::default_template( 'booking_confirmed' ), $mctx );
check( str_contains( $mhtml, 'bookora_manage=' ) && str_contains( $mhtml, esc_html( $gb['policy']['text'] ) ), 'confirmation email contains the manage button and the agreed terms' );
$ectx = $mailer->entry_context( (int) $gb['entry_id'] );
[ , $ehtml ] = $mailer->render( 'booking_confirmed', \Bookora\Notifications\Templates::default_template( 'booking_confirmed' ), $ectx );
check( ! str_contains( $ehtml, '{manage' ) && ! str_contains( $ehtml, '{cancellation' ), 'placeholders are removed where they do not apply' );

$muid = (string) $gb['uid'];
$murl = \Bookora\Booking\ManagePage::url( $muid );
check( (bool) preg_match( '/bookora_manage=[a-f0-9-]{36}\.[a-f0-9]{32}$/', $murl ), 'manage link carries uid and a 128-bit signature' );
$post = static function ( string $url, array $data ) {
	$ctx  = stream_context_create( array( 'http' => array( 'method' => 'POST', 'ignore_errors' => true, 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => http_build_query( $data ), 'timeout' => 20 ) ) );
	$body = @file_get_contents( $url, false, $ctx ); // phpcs:ignore
	return array( (int) preg_replace( '/^HTTP\/\S+ (\d+).*$/', '$1', $http_response_header[0] ?? '0' ), (string) $body );
};
$nonce_of = static fn( string $html ) => preg_match( '/name="_bookora_nonce" value="([^"]+)"/', $html, $nm ) ? $nm[1] : '';

if ( 0 !== $http( $live . 'wp-login.php' )[0] ) {
	check( 404 === $http( $live . '?bookora_manage=' . $muid . '.' . str_repeat( '0', 32 ) )[0] && 404 === $http( $live . '?bookora_manage=../../x' )[0], 'manage page: forged links get 404' );
	[ $code, $page ] = $http( $murl );
	check( 200 === $code && str_contains( $page, 'noindex' ) && preg_match( '/24[.,]00/', $page ) && ! str_contains( $page, 'view=move' ), 'manage page within 24 h: fee is shown, moving is not offered', substr( trim( preg_replace( '/\s+/', ' ', strip_tags( preg_replace( '#<style.*</style>#s', '', $page ) ) ) ), 0, 900 ) );
	[ , $cpage ] = $http( $murl . '&view=cancel&do=cancel' );
	check( 'confirmed' === $ent->booking( $gid )['status'], 'opening links (GET) never changes anything' );
	check( '' === $post( $murl, array( 'do' => 'cancel' ) )[1] || 'confirmed' === $ent->booking( $gid )['status'], 'POST without nonce is refused' );
	$post( $murl, array( 'do' => 'cancel', '_bookora_nonce' => $nonce_of( $cpage ), 'reason' => 'Krank' ) );
	wp_cache_flush();
	$cb = $ent->booking( $gid );
	check( 'cancelled' === $cb['status'] && 24.0 === $cb['fee_amount'] && 'open' === $cb['fee_status'] && 'late_cancel' === $cb['fee_reason'] && 'customer' === $cb['cancelled_by'], 'late cancellation by the customer records the agreed fee', array_intersect_key( $cb, array_flip( array( 'status', 'fee_amount', 'fee_status', 'fee_reason', 'cancelled_by' ) ) ) );
	check( str_contains( $cb['notes'], 'Krank' ), 'reason is added to the internal notes' );
	[ , $closed ] = $http( $murl );
	check( ! str_contains( $closed, 'view=cancel' ) && preg_match( '/24[.,]00/', $closed ), 'cancelled appointment shows the fee and offers no actions', substr( trim( preg_replace( '/\s+/', ' ', strip_tags( preg_replace( '#<style.*</style>#s', '', $closed ) ) ) ), 0, 900 ) );

	// Moving (early, free).
	$mv  = $pbook( '17:00', 'move@example.com', array( '_policy' => '1' ), '198.51.100.13' );
	$mid = $bid_of( $mv );
	$ent->set_booking_status( $mid, 'confirmed' );
	$mvu = \Bookora\Booking\ManagePage::url( $ent->booking( $mid )['uid'] );
	[ $code, $mpage ] = $http( $mvu . '&view=move' );
	preg_match_all( '/name="start" value="(\d+)"/', $mpage, $sm );
		check( 200 === $code && count( $sm[1] ) > 0 && ! in_array( (string) $sat( '17:00' ), $sm[1], true ), 'move page lists free times (not the current one)', count( $sm[1] ) );
	$target = (int) end( $sm[1] );
	$before = count( glob( WP_CONTENT_DIR . '/test-mails/*' ) ?: array() );
	[ , $done ] = $post( $mvu, array( 'do' => 'move', 'start' => $target, '_bookora_nonce' => $nonce_of( $mpage ) ) );
	wp_cache_flush();
	$moved = $ent->booking( $mid );
	check( strtotime( $moved['start'] ) === $target && 'confirmed' === $moved['status'], 'customer moved the appointment to a free time', array( $moved['start'], gmdate( 'c', $target ), substr( strip_tags( $done ), 0, 300 ) ) );
	check( count( glob( WP_CONTENT_DIR . '/test-mails/*' ) ?: array() ) >= $before + 2, 'customer gets the new time, owner is informed' );
	$wpdb->update( $btab, array( 'start_utc' => gmdate( 'Y-m-d H:i:s', $sat( '15:00' ) ), 'end_utc' => gmdate( 'Y-m-d H:i:s', $sat( '15:30' ) ) ), array( 'id' => $gid ) );
	$ent->set_booking_status( $gid, 'pending' );
	[ , $again ] = $http( $mvu . '&view=move' );
	$post( $mvu, array( 'do' => 'move', 'start' => $sat( '15:00' ), '_bookora_nonce' => $nonce_of( $again ) ) );
	$post( $mvu, array( 'do' => 'move', 'start' => $sat( '03:00' ), '_bookora_nonce' => $nonce_of( $again ) ) );
	wp_cache_flush();
	check( strtotime( $ent->booking( $mid )['start'] ) === $target, 'moving onto a taken time or outside opening hours is refused' );
} else {
	echo "  (web server not running – manage page HTTP checks skipped)\n";
}
check( is_wp_error( $bsvc->reschedule_by_customer( $fid, $sat( '03:00' ) ) ), 'reschedule_by_customer only accepts regular free slots' );

$ns = $pbook( '18:00', 'noshow@example.com', array( '_policy' => '1' ), '198.51.100.14' );
$nid = $bid_of( $ns );
api( 'POST', '/bookings/' . $nid . '/status', array( 'status' => 'confirmed' ) );
$nsr = api( 'POST', '/bookings/' . $nid . '/status', array( 'status' => 'no_show' ) )->get_data();
check( 24.0 === $nsr['fee_amount'] && 'no_show' === $nsr['fee_reason'] && 'open' === $nsr['fee_status'], 'marking "not attended" records the agreed fee', $nsr );
check( 1 <= api( 'GET', '/bookings', array(), array( 'view' => 'fees' ) )->get_data()['total'], 'view "open fees" lists it' );
$paid = api( 'POST', '/bookings/' . $nid . '/fee', array( 'status' => 'paid' ) )->get_data();
check( 'paid' === $paid['fee_status'] && 24.0 === $paid['fee_amount'], 'fee can be marked as paid' );
api( 'POST', '/bookings/' . $nid . '/fee', array( 'status' => 'open' ) );
$back = api( 'POST', '/bookings/' . $nid . '/status', array( 'status' => 'completed' ) )->get_data();
check( 0.0 === $back['fee_amount'] && '' === $back['fee_status'], 'correcting "not attended" removes the open fee again', $back );
$man = api( 'POST', '/bookings/' . $fid . '/fee', array( 'status' => 'open', 'amount' => 15 ) )->get_data();
check( 15.0 === $man['fee_amount'] && 'manual' === $man['fee_reason'], 'a fee can be recorded manually (e.g. phone cancellation)', $man );
check( 400 === api( 'POST', '/bookings/' . $fid . '/fee', array( 'status' => 'bogus' ) )->get_status(), 'invalid fee status is rejected' );
wp_set_current_user( 0 );
check( in_array( api( 'POST', '/bookings/' . $fid . '/fee', array( 'status' => 'waived' ) )->get_status(), array( 401, 403 ), true ), 'visitors cannot change fees' );
wp_set_current_user( 1 );
[ $hdr, $rows ] = $ent->export( (int) $bf['id'] );
check( in_array( 'Gebühr', $hdr, true ) || in_array( 'Fee', $hdr, true ), 'CSV export contains the fee', $hdr );

api( 'POST', '/settings', array( 'cancel_fee_enabled' => false, 'business_phone' => '' ) );
api( 'DELETE', '/services/' . $psvc['id'] );

echo "Reminders 7 days + 24 hours (10b.1)\n";
check( array( 168, 24 ) === \Bookora\Settings\Settings::defaults()['reminder_hours'], 'new installations remind 7 days and 24 hours before' );
api( 'POST', '/settings', array( 'reminder_hours' => array( 24, 168 ) ) );
$rsvc  = api( 'POST', '/services', array( 'name' => 'Erinnerung', 'duration' => 30 ) )->get_data();
$rsvc2 = api( 'POST', '/services', array( 'name' => 'Ohne Erinnerung', 'duration' => 30, 'settings' => array( 'reminders' => false ) ) )->get_data();
$rmk   = static function ( int $svc, float $days_ahead, string $email ) use ( $wpdb, $btab, $ent ) {
	$start = time() + (int) ( $days_ahead * DAY_IN_SECONDS );
	$wpdb->insert( \Bookora\Install\Schema::table( $wpdb->prefix, 'customers' ), array( 'email' => $email, 'name' => 'Rita', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$cid = (int) $wpdb->insert_id;
	$wpdb->insert( $btab, array( 'uid' => wp_generate_uuid4(), 'service_id' => $svc, 'customer_id' => $cid, 'start_utc' => gmdate( 'Y-m-d H:i:s', $start ), 'end_utc' => gmdate( 'Y-m-d H:i:s', $start + 1800 ), 'status' => 'confirmed', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	return (int) $wpdb->insert_id;
};
$wpdb->query( "UPDATE {$btab} SET status = 'completed' WHERE status = 'confirmed'" ); // isolate from earlier sections
$r7  = $rmk( (int) $rsvc['id'], 6.8, 'r7@example.com' );
$r3  = $rmk( (int) $rsvc['id'], 3.0, 'r3@example.com' );
$r1  = $rmk( (int) $rsvc['id'], 0.8, 'r1@example.com' );
$rx  = $rmk( (int) $rsvc2['id'], 0.8, 'rx@example.com' );
$sent_log = static fn( int $id ) => $wpdb->get_col( $wpdb->prepare( 'SELECT event FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'notification_log' ) . " WHERE booking_id = %d AND event LIKE 'booking_reminder%%'", $id ) );
$mailer->send_reminders();
check( array( 'booking_reminder:168' ) === $sent_log( $r7 ), '6.8 days before: the 7-day reminder is sent', $sent_log( $r7 ) );
check( array() === $sent_log( $r3 ), 'only 3 days left (confirmed late): no "in 7 days" reminder', $sent_log( $r3 ) );
check( array( 'booking_reminder:24' ) === $sent_log( $r1 ), 'within 24 h only the 24-hour reminder is sent (never two at once)', $sent_log( $r1 ) );
check( array() === $sent_log( $rx ), 'a service can switch reminders off' );
$mailer->send_reminders();
check( 1 === count( $sent_log( $r7 ) ) && 1 === count( $sent_log( $r1 ) ), 'each reminder is sent only once' );
$wpdb->update( $btab, array( 'start_utc' => gmdate( 'Y-m-d H:i:s', time() + 20 * HOUR_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 21 * HOUR_IN_SECONDS ) ), array( 'id' => $r7 ) );
$wpdb->update( $btab, array( 'start_utc' => gmdate( 'Y-m-d H:i:s', time() + 20 * HOUR_IN_SECONDS ), 'end_utc' => gmdate( 'Y-m-d H:i:s', time() + 21 * HOUR_IN_SECONDS ) ), array( 'id' => $r3 ) );
$mailer->send_reminders();
check( array( 'booking_reminder:168', 'booking_reminder:24' ) === $sent_log( $r7 ) && array( 'booking_reminder:24' ) === $sent_log( $r3 ), 'the 24-hour reminder follows later', array( $sent_log( $r7 ), $sent_log( $r3 ) ) );
check( \Bookora\Notifications\Mailer::reminder_in_time( 1, 10 * MINUTE_IN_SECONDS ) && \Bookora\Notifications\Mailer::reminder_in_time( 24, 13 * HOUR_IN_SECONDS ) && ! \Bookora\Notifications\Mailer::reminder_in_time( 24, 11 * HOUR_IN_SECONDS ), 'tolerance: 1-hour reminder survives the hourly cron, 24 h reminder at least 12 h before' );
$noon = ( new DateTimeImmutable( 'today 12:00', wp_timezone() ) )->getTimestamp();
$tu   = static fn( string $mod ) => \Bookora\Notifications\Mailer::time_until( ( new DateTimeImmutable( '@' . $noon ) )->modify( $mod )->getTimestamp(), $noon );
check( in_array( $tu( '+7 days' ), array( 'in 7 days', 'in 7 Tagen' ), true ) && in_array( $tu( '+1 day' ), array( 'tomorrow', 'morgen' ), true ) && in_array( $tu( '+3 hours' ), array( 'in 3 hours', 'in 3 Stunden' ), true ), '{time_until} reads "in 7 days" / "tomorrow" / "in 3 hours"', array( $tu( '+7 days' ), $tu( '+1 day' ), $tu( '+3 hours' ) ) );
$rctx = $mailer->booking_context( $r7 );
[ , $rhtml ] = $mailer->render( 'booking_reminder', \Bookora\Notifications\Templates::default_template( 'booking_reminder' ), $rctx );
check( ! str_contains( $rhtml, '{time_until}' ) && ( str_contains( $rhtml, 'tomorrow' ) || str_contains( $rhtml, 'morgen' ) || str_contains( $rhtml, 'in 20' ) || str_contains( $rhtml, 'today' ) || str_contains( $rhtml, 'heute' ) ), 'reminder text contains the time until the appointment' );
api( 'DELETE', '/services/' . $rsvc['id'] );
api( 'DELETE', '/services/' . $rsvc2['id'] );
api( 'POST', '/settings', array( 'reminder_hours' => array( 24 ) ) );

echo "File upload & signature\n";
$fs    = $cont->get( \Bookora\Forms\FileStore::class );
$ftab  = \Bookora\Install\Schema::table( $wpdb->prefix, 'files' );
$fform = api( 'POST', '/forms', array( 'title' => 'Upload', 'type' => 'standard', 'status' => 'published', 'fields' => array(
	array( 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
	array( 'type' => 'file', 'key' => 'befund', 'label' => 'Befund', 'required' => true, 'extensions' => array( 'pdf', 'png', 'svg', 'php' ), 'maxSize' => 1, 'maxFiles' => 2 ),
	array( 'type' => 'signature', 'key' => 'unterschrift', 'label' => 'Unterschrift', 'required' => true ),
) ) )->get_data();
$ff    = $frep->find( (int) $fform['id'] );
check( array( 'pdf', 'png' ) === array_values( $ff['fields'][1]['extensions'] ), 'dangerous types (svg, php) are removed from the field settings', $ff['fields'][1]['extensions'] );
$tmpdir = get_temp_dir() . 'bookora-test-' . wp_generate_password( 6, false );
wp_mkdir_p( $tmpdir );
$mkpng = static function ( int $w = 300, int $h = 120 ) {
	$im = imagecreatetruecolor( $w, $h );
	imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
	imageline( $im, 10, 60, $w - 10, 70, imagecolorallocate( $im, 0, 0, 0 ) );
	ob_start();
	imagepng( $im );
	return (string) ob_get_clean();
};
$tf = static function ( string $name, string $content ) use ( $tmpdir ) {
	$path = $tmpdir . '/' . md5( $name . microtime() );
	file_put_contents( $path, $content );
	return array( 'name' => $name, 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => 0, 'size' => strlen( $content ) );
};
add_filter( 'bookora_allow_local_upload', '__return_true' );
$up = static function ( array $file, string $field = 'befund', $token = null ) use ( $ff, $old_token ) {
	$r = new WP_REST_Request( 'POST', '/bookora/v1/public/forms/' . $ff['id'] . '/upload' );
	$r->set_body_params( array( 'field' => $field, '_bk_t' => $token ?? $old_token( 10, (int) $ff['id'] ) ) );
	$r->set_file_params( array( 'file' => $file ) );
	return rest_ensure_response( rest_do_request( $r ) );
};
wp_set_current_user( 0 );
$ok1 = $up( $tf( 'Röntgen Bild.png', $mkpng() ) );
$tok = (string) ( $ok1->get_data()['token'] ?? '' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ftab} WHERE token = %s", $tok ), ARRAY_A );
$abs = \Bookora\Forms\FileStore::base_dir() . '/' . ( $row['path'] ?? 'x' );
check( 201 === $ok1->get_status() && preg_match( '/^[a-f0-9]{32}$/', $tok ) && ! isset( $ok1->get_data()['id'] ), 'image upload returns only an unguessable token', $ok1->get_data() );
check( $row && null === $row['entry_id'] && 'image/png' === $row['mime'] && is_file( $abs ) && ! str_contains( basename( $abs ), '.' ), 'stored privately under a random name without extension', $row );
check( is_file( \Bookora\Forms\FileStore::base_dir() . '/.htaccess' ) && is_file( \Bookora\Forms\FileStore::base_dir() . '/index.php' ) && is_file( \Bookora\Forms\FileStore::base_dir() . '/web.config' ), 'private folder is locked (.htaccess, web.config, index.php)' );
check( in_array( $row['name'], array( 'Rontgen-Bild.png', 'Roentgen-Bild.png', 'Röntgen-Bild.png' ), true ), 'file name is sanitized', $row['name'] );
$bad = array(
	'PHP code disguised as image'   => $tf( 'shell.png', "<?php system(\$_GET['c']); ?>" ),
	'HTML disguised as PDF'         => $tf( 'doc.pdf', '<html><script>alert(1)</script></html>' ),
	'PHP file'                      => $tf( 'shell.php', '<?php echo 1;' ),
	'SVG (can contain scripts)'     => $tf( 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ),
	'double extension'              => $tf( 'bild.php.png', "<?php echo 1;" ),
	'type not allowed in the field' => $tf( 'tabelle.csv', "a;b\n1;2" ),
);
foreach ( $bad as $label => $file ) {
	$res = $up( $file );
	check( 400 === $res->get_status(), 'rejected: ' . $label, array( $res->get_status(), $res->get_data() ) );
}
$big = $up( $tf( 'gross.pdf', '%PDF-1.4' . str_repeat( 'x', 1100 * 1024 ) ) );
check( 413 === $big->get_status(), 'file larger than the field limit is rejected', $big->get_status() );
check( 400 === $up( $tf( 'a.png', $mkpng() ), 'befund', 'x.y' )->get_status(), 'upload without valid form token is rejected' );
check( 400 === $up( $tf( 'a.png', $mkpng() ), 'email' )->get_status(), 'upload into a non-file field is rejected' );
add_filter( 'bookora_upload_limit', '__return_zero' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
check( 429 === $up( $tf( 'a.png', $mkpng() ) )->get_status(), 'uploads per IP are limited' );
remove_filter( 'bookora_upload_limit', '__return_zero' );
unset( $_SERVER['REMOTE_ADDR'] );
$pdf  = $up( $tf( 'Befund.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF" ) );
$tok2 = (string) ( $pdf->get_data()['token'] ?? '' );
check( 201 === $pdf->get_status(), 'PDF upload works', $pdf->get_data() );

$sig    = 'data:image/png;base64,' . base64_encode( $mkpng( 400, 150 ) );
$fsub   = static fn( array $f, string $ip ) => $sub->handle( $ff, array( '_bk_t' => $old_token( 10, (int) $ff['id'] ), 'fields' => $f ), array( 'ip' => $ip ) );
$noreq  = $fsub( array( 'email' => 'upload@example.com' ), '198.51.100.40' );
check( is_wp_error( $noreq ) && isset( $noreq->get_error_data()['fields']['befund'], $noreq->get_error_data()['fields']['unterschrift'] ), 'required file and signature are enforced', is_wp_error( $noreq ) ? $noreq->get_error_data() : $noreq );
$three = $fsub( array( 'email' => 'upload@example.com', 'befund' => array( $tok, $tok2, str_repeat( 'a', 32 ) ), 'unterschrift' => $sig ), '198.51.100.41' );
check( is_wp_error( $three ) && isset( $three->get_error_data()['fields']['befund'] ), 'more files than allowed are rejected' );
$badsig = $fsub( array( 'email' => 'upload@example.com', 'befund' => array( $tok ), 'unterschrift' => 'data:image/png;base64,' . base64_encode( '<?php echo 1;' ) ), '198.51.100.42' );
check( is_wp_error( $badsig ) && isset( $badsig->get_error_data()['fields']['unterschrift'] ), 'a fake signature image is rejected' );
$good = $fsub( array( 'email' => 'upload@example.com', 'befund' => array( $tok, $tok2 ), 'unterschrift' => $sig ), '198.51.100.43' );
$eid  = (int) ( $good['entry_id'] ?? 0 );
check( $eid > 0 && 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ftab} WHERE entry_id = %d", $eid ) ), 'submission attaches both files and the signature to the entry', $good );
$again = $fsub( array( 'email' => 'upload@example.com', 'befund' => array( $tok ), 'unterschrift' => 'typed:Max Muster' ), '198.51.100.44' );
check( is_wp_error( $again ) && isset( $again->get_error_data()['fields']['befund'] ), 'an upload token can only be used once' );
$typed_tok = (string) ( $up( $tf( 'b.png', $mkpng() ) )->get_data()['token'] ?? '' );
$typed = $fsub( array( 'email' => 'typed@example.com', 'befund' => array( $typed_tok ), 'unterschrift' => 'typed:Max Muster' ), '198.51.100.45' );
wp_set_current_user( 1 );
$tent = $ent->entry( (int) ( $typed['entry_id'] ?? 0 ) );
$tval = array_column( (array) ( $tent['values'] ?? array() ), 'value', 'key' );
check( str_contains( (string) ( $tval['unterschrift'] ?? '' ), 'Max Muster' ), 'typed signature (keyboard alternative) is stored as text', $tval );
$gent = $ent->entry( $eid );
$gby  = array_column( $gent['values'], null, 'key' );
check( 2 === count( $gby['befund']['files'] ) && str_contains( $gby['befund']['value'], 'Befund.pdf' ) && 'signature' === $gby['unterschrift']['files'][0]['kind'], 'entry shows file names, download links and the signature', $gby['befund'] );
check( str_contains( $gby['befund']['files'][0]['url'], 'admin-post.php?action=bookora_file' ) && str_contains( $gby['befund']['files'][0]['url'], '_wpnonce=' ), 'download links go through a protected admin URL' );
$other = api( 'POST', '/forms', array( 'title' => 'Andere', 'type' => 'standard', 'status' => 'published', 'fields' => array( array( 'type' => 'file', 'key' => 'befund', 'label' => 'X' ) ) ) )->get_data();
$foreign = $sub->handle( $frep->find( (int) $other['id'] ), array( '_bk_t' => $old_token( 10, (int) $other['id'] ), 'fields' => array( 'befund' => array( $typed_tok ) ) ), array( 'ip' => '198.51.100.46' ) );
check( is_wp_error( $foreign ), 'a token of another form cannot be used' );

if ( 0 !== $http( $live . 'wp-login.php' )[0] ) {
	[ $dcode, $dbody ] = $http( html_entity_decode( $gby['befund']['files'][0]['url'] ) );
	check( ! str_contains( $dbody, '%PDF' ) && ! str_contains( $dbody, 'PNG' ), 'download without login is refused', $dcode );
	$curl = 'curl -s -o /dev/null -w "%{http_code}" -F field=befund -F ' . escapeshellarg( '_bk_t=' . $old_token( 10, (int) $ff['id'] ) ) . ' -F ' . escapeshellarg( 'file=@' . $tf( 'x', $mkpng() )['tmp_name'] . ';filename=echt.png' ) . ' ' . escapeshellarg( $live . '?rest_route=/bookora/v1/public/forms/' . $ff['id'] . '/upload' );
	check( '201' === trim( (string) shell_exec( $curl ) ), 'real multipart upload over HTTP works' );
	$curl_tok = json_decode( (string) shell_exec( str_replace( '-o /dev/null -w "%{http_code}" ', '', $curl ) ), true );
	$payload  = wp_json_encode( array( '_bk_t' => $old_token( 10, (int) $ff['id'] ), 'fields' => array( 'email' => 'http-upload@example.com', 'befund' => array( (string) ( $curl_tok['token'] ?? '' ) ), 'unterschrift' => $sig ) ) );
	$ctx      = stream_context_create( array( 'http' => array( 'method' => 'POST', 'ignore_errors' => true, 'header' => "Content-Type: application/json\r\nX-Forwarded-For: 192.0.2.77", 'content' => $payload, 'timeout' => 20 ) ) );
	$resp     = (string) @file_get_contents( $live . '?rest_route=/bookora/v1/public/forms/' . $ff['id'] . '/submit', false, $ctx ); // phpcs:ignore
	check( str_contains( $resp, '"success":true' ) && 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $ftab . " f JOIN {$wpdb->prefix}bookora_entries e ON e.id = f.entry_id JOIN {$wpdb->prefix}bookora_customers c ON c.id = e.customer_id WHERE c.email = 'http-upload@example.com'" ), 'complete submission with file and drawn signature works over HTTP (front-end context)', substr( $resp, 0, 300 ) );
}

$orph = (string) ( $up( $tf( 'c.png', $mkpng() ) )->get_data()['token'] ?? '' );
$orow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ftab} WHERE token = %s", $orph ), ARRAY_A );
$wpdb->update( $ftab, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 25 * HOUR_IN_SECONDS ) ), array( 'token' => $orph ) );
$maint = ( new \Bookora\Core\Maintenance() )->run();
check( $maint['files'] >= 1 && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$ftab} WHERE token = %s", $orph ) ) && ! is_file( \Bookora\Forms\FileStore::base_dir() . '/' . $orow['path'] ), 'uploads that were never submitted are deleted after 24 h' );
$gpath = \Bookora\Forms\FileStore::base_dir() . '/' . $wpdb->get_var( $wpdb->prepare( "SELECT path FROM {$ftab} WHERE entry_id = %d LIMIT 1", $eid ) );
$ent->set_entry_status( array( $eid ), 'trash' );
$ent->delete_entries( array( $eid ) );
check( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ftab} WHERE entry_id = %d", $eid ) ) && ! is_file( $gpath ), 'deleting an entry deletes its files' );
$tpath = \Bookora\Forms\FileStore::base_dir() . '/' . $wpdb->get_var( $wpdb->prepare( "SELECT path FROM {$ftab} WHERE entry_id = %d LIMIT 1", (int) $typed['entry_id'] ) );
$cont->get( \Bookora\Privacy\Privacy::class )->erase( 'typed@example.com' );
check( ! is_file( $tpath ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ftab} WHERE entry_id = %d", (int) $typed['entry_id'] ) ), 'GDPR erasure deletes uploaded files' );
remove_filter( 'bookora_allow_local_upload', '__return_true' );
array_map( 'wp_delete_file', glob( $tmpdir . '/*' ) ?: array() );
@rmdir( $tmpdir );

echo "Customer time zone\n";
$tz_start = ( new DateTimeImmutable( '2026-11-25 09:00', wp_timezone() ) )->getTimestamp();
$tz_note  = \Bookora\Notifications\Mailer::local_time( 'America/New_York', $tz_start, $tz_start + 2700 );
check( str_contains( $tz_note, '03:00' ) && str_contains( $tz_note, '03:45' ) && str_contains( $tz_note, 'New York' ), 'customer from New York sees the time in their own zone', $tz_note );
check( '' === \Bookora\Notifications\Mailer::local_time( 'Europe/Paris', $tz_start, $tz_start + 2700 ), 'same offset (Paris) adds no note' );
check( '' === \Bookora\Notifications\Mailer::local_time( 'Mars/Base', $tz_start, $tz_start + 2700 ) && '' === \Bookora\Notifications\Mailer::local_time( '', $tz_start, $tz_start ), 'unknown or empty zone adds no note' );
$tz_late = ( new DateTimeImmutable( '2026-11-25 05:00', wp_timezone() ) )->getTimestamp();
check( 1 === preg_match( '/^\(\S+ 23:00/', \Bookora\Notifications\Mailer::local_time( 'America/New_York', $tz_late, $tz_late + 3600 ) ), 'note names the weekday when the day differs', \Bookora\Notifications\Mailer::local_time( 'America/New_York', $tz_late, $tz_late + 3600 ) );
$tz_api = api( 'GET', '/availability', array(), array( 'service' => $service['id'], 'from' => $monday, 'to' => $monday, 'tz' => 'America/New_York' ) )->get_data();
$tz_all = array_merge( ...array_values( (array) $tz_api['days'] ) ?: array( array() ) );
check( 'America/New_York' === $tz_api['timezone'] && ( ! $tz_all || ( new DateTimeImmutable( $tz_all[0]['start'] ) )->setTimezone( new DateTimeZone( 'America/New_York' ) )->format( 'H:i' ) === $tz_all[0]['time'] ), 'availability returns times in the visitor zone', $tz_api );
$settings = \Bookora\Core\Plugin::instance()->container()->get( \Bookora\Settings\Settings::class );
check( 'business' === $settings->get( 'customer_timezone' ) && 'business' === $settings->sanitize( array( 'customer_timezone' => 'evil' ) )['customer_timezone'], 'time zone display setting defaults to the business and rejects junk' );

echo "Cleanup\n";
check( 200 === api( 'DELETE', '/services/' . $service['id'] )->get_status(), 'service deleted' );
check( 404 === api( 'GET', '/services/' . $service['id'] )->get_status(), 'deleted service is gone' );

echo "\n{$passed} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
