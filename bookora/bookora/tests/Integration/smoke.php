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
$old_token = static function ( int $age ): string {
	$t = (string) ( time() - $age );
	return $t . '.' . substr( wp_hash( 'bookora_render|' . $t, 'nonce' ), 0, 16 );
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
check( 429 === $submit( array( '_bk_t' => $old_token( 0 ), 'fields' => array( 'email' => 'a@example.com' ) ) )->get_status(), 'too fast submission is rejected' );
check( 400 === $submit( array( '_bk_t' => $old_token( 10 ), 'website_url_hp' => 'spam', 'fields' => array( 'email' => 'a@example.com' ) ) )->get_status(), 'honeypot catches bots' );
$res = $submit( array( '_bk_t' => $old_token( 10 ), 'fields' => array( 'email' => 'not-mail', 'kunde' => 'Nein' ) ) );
check( 422 === $res->get_status() && isset( $res->get_data()['data']['fields']['email'], $res->get_data()['data']['fields']['grund'] ), 'field errors returned (invalid email, required visible field)', $res->get_data() );
check( 201 === $submit( array( '_bk_t' => $old_token( 10 ), 'fields' => array( 'email' => 'b@example.com', 'kunde' => 'Ja', 'grund' => 'should be ignored' ) ) )->get_status(), 'field hidden by logic is not required' );
$stored = $wpdb->get_col( 'SELECT field_key FROM ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'entry_values' ) . ' v INNER JOIN ' . \Bookora\Install\Schema::table( $wpdb->prefix, 'entries' ) . ' e ON e.id = v.entry_id WHERE e.form_id = ' . (int) $pub['id'] );
check( ! in_array( 'grund', $stored, true ) && in_array( 'email', $stored, true ), 'values of hidden fields are not stored', $stored );
check( 201 === $submit( array( '_bk_t' => $old_token( 10 ), 'fields' => array( 'email' => 'c@example.com', 'kunde' => 'evil' ) ) )->get_status() ? false : true, 'invalid option is rejected' );
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
	api( 'POST', '/public/forms/' . $std['id'] . '/submit', array( '_bk_t' => $old_token( 10 ), 'fields' => array( 'email' => $mail, 'betreff' => $subject ) ) );
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
$mails = array();
$sent  = api( 'POST', '/calendars/feeds/r' . $ids[0] . '/send' );
check( 200 === $sent->get_status() && 'staff1@example.com' === $sent->get_data()['sent_to'], 'subscription link is sent to the staff member\'s email by default', $sent->get_data() );
check( 1 === count( $mails ) && str_contains( $mails[0]['message'], 'subscribe=1' ) && str_contains( $mails[0]['message'], 'bookora_feed=' ), 'email contains the device-independent subscribe link', array_column( $mails, 'subject' ) );
check( 400 === api( 'POST', '/calendars/feeds/all/send', array( 'email' => 'nope' ) )->get_status(), 'invalid email address is rejected' );
check( 200 === api( 'POST', '/calendars/feeds/all/send', array( 'email' => 'owner@example.com' ) )->get_status(), 'link can be sent to any address' );
$reset = api( 'POST', '/calendars/feeds/all/reset' )->get_data();
check( $reset['https'] !== $all_url && (bool) preg_match( '/[a-f0-9]{40}$/', $reset['https'] ), 'feed address can be reset' );
check( 404 === api( 'POST', '/calendars/feeds/r999999/reset' )->get_status(), 'reset of unknown staff feed is rejected' );
check( 404 === api( 'POST', '/calendars/feeds/evil/reset' )->get_status(), 'invalid feed scope is rejected' );

$http = static function ( string $url, array $headers = array() ): array {
	$ctx  = stream_context_create( array( 'http' => array( 'ignore_errors' => true, 'header' => implode( "\r\n", $headers ), 'timeout' => 10 ) ) );
	$body = @file_get_contents( $url, false, $ctx ); // phpcs:ignore
	return array( (int) preg_replace( '/^HTTP\/\S+ (\d+).*$/', '$1', $http_response_header[0] ?? '0' ), (string) $body, $http_response_header ?? array() );
};
$live = 'http://localhost:8089/';
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

echo "Cleanup\n";
check( 200 === api( 'DELETE', '/services/' . $service['id'] )->get_status(), 'service deleted' );
check( 404 === api( 'GET', '/services/' . $service['id'] )->get_status(), 'deleted service is gone' );

echo "\n{$passed} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
