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

echo "Cleanup\n";
check( 200 === api( 'DELETE', '/services/' . $service['id'] )->get_status(), 'service deleted' );
check( 404 === api( 'GET', '/services/' . $service['id'] )->get_status(), 'deleted service is gone' );

echo "\n{$passed} passed, {$failures} failed\n";
exit( $failures ? 1 : 0 );
