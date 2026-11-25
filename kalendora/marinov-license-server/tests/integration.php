<?php
/**
 * Integration test: license server + Kalendora Pro client on one test installation.
 *
 * Usage: php tests/integration.php /path/to/wordpress http://localhost:8089
 * The web server must serve the same installation (the client talks to it over HTTP).
 * Only for test installations: creates and deletes licenses and releases.
 *
 * @package MarinovLicenseServer
 */

// phpcs:ignoreFile

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define( 'MARINOV_LICENSE_TESTING', true );
require rtrim( $argv[1], '/' ) . '/wp-load.php';
$live = rtrim( $argv[2] ?? 'http://localhost:8089', '/' );

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
function post( string $url, array $body ): array {
	$r = wp_remote_post( $url, array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $body ) ) );
	return is_wp_error( $r ) ? array( 0, array( 'error' => $r->get_error_message() ) ) : array( (int) wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ), wp_remote_retrieve_body( $r ) );
}

use MarinovLicenseServer\Licenses;
use MarinovLicenseServer\Releases;

global $wpdb;
$wpdb->query( 'DELETE FROM ' . Licenses::table( 'licenses' ) );
$wpdb->query( 'DELETE FROM ' . Licenses::table( 'activations' ) );
delete_option( Releases::OPTION );
foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_mls_fail_%'" ) as $t ) {
	delete_transient( substr( $t, 11 ) );
}
$api = $live . '/?rest_route=/mls/v1/';
$lic = new Licenses();

echo "License keys\n";
$one = $lic->create( array( 'product' => 'kalendora-pro', 'email' => 'kunde@example.com', 'sites' => 1, 'months' => 12 ) );
check( (bool) preg_match( '/^BKRA(-[A-HJKMNP-Z2-9]{4}){4}$/', $one['key'] ), 'key has the form BKRA-XXXX-XXXX-XXXX-XXXX without ambiguous characters', $one['key'] );
$row = $wpdb->get_row( 'SELECT * FROM ' . Licenses::table( 'licenses' ) . ' WHERE id = ' . (int) $one['id'], ARRAY_A );
check( ! str_contains( wp_json_encode( $row ), $one['key'] ) && Licenses::key_of( $row ) === $one['key'], 'key is stored only as hash + encrypted copy' );
check( null !== $lic->find_by_key( strtolower( str_replace( '-', ' ', $one['key'] ) ) ), 'key is found with spaces and lower case' );
check( 'shop.example.com/de' === Licenses::normalize_site( 'https://www.Shop.Example.com/de/' ) && '' === Licenses::normalize_site( 'javascript:alert(1)' ), 'website addresses are normalised' );
check( Licenses::is_dev_site( 'localhost' ) && Licenses::is_dev_site( 'staging.kunde.de' ) && Licenses::is_dev_site( 'stag.mpmarinov.com' ) && Licenses::is_dev_site( 'stg.mpmarinov.com' ) && Licenses::is_dev_site( 'kunde.local' ) && ! Licenses::is_dev_site( 'kunde.de' ), 'local and staging sites are recognised' );

echo "Activation over HTTP\n";
[ $c, $d ] = post( $api . 'activate', array( 'key' => $one['key'], 'site' => 'https://praxis-a.de', 'product' => 'kalendora-pro', 'version' => '1.0.0' ) );
check( 200 === $c && true === $d['valid'] && 1 === $d['sites_used'], 'first website is activated', $d );
[ $c, $d ] = post( $api . 'activate', array( 'key' => $one['key'], 'site' => 'praxis-a.de/', 'product' => 'kalendora-pro' ) );
check( 200 === $c && 1 === $d['sites_used'], 'activating the same website again does not count twice', $d );
[ $c, $d ] = post( $api . 'activate', array( 'key' => $one['key'], 'site' => 'https://praxis-b.de', 'product' => 'kalendora-pro' ) );
check( 403 === $c && 'mls_limit' === $d['code'], 'second website is refused for a 1-site license', $d );
[ $c, $d ] = post( $api . 'activate', array( 'key' => $one['key'], 'site' => 'https://staging.praxis-a.de', 'product' => 'kalendora-pro' ) );
check( 200 === $c && 1 === $d['sites_used'], 'staging site works and does not use up the license', $d );
[ $c, $d ] = post( $api . 'activate', array( 'key' => $one['key'], 'site' => 'https://praxis-a.de', 'product' => 'other-plugin' ) );
check( 404 === $c, 'key of another product is refused' );
[ $c, $d ] = post( $api . 'deactivate', array( 'key' => $one['key'], 'site' => 'https://praxis-a.de' ) );
[ $c2, $d2 ] = post( $api . 'activate', array( 'key' => $one['key'], 'site' => 'https://praxis-b.de', 'product' => 'kalendora-pro' ) );
check( true === $d['deactivated'] && 200 === $c2, 'after deactivating, the license moves to another website', array( $d, $d2 ) );

echo "Check and updates\n";
[ $c, $d ] = post( $api . 'check', array( 'key' => $one['key'], 'site' => 'https://praxis-b.de', 'product' => 'kalendora-pro', 'version' => '1.0.0' ) );
check( 200 === $c && true === $d['valid'] && null === $d['latest'] && ! isset( $d['package'] ), 'check without a release: valid, no update', $d );
$zip_path = tempnam( get_temp_dir(), 'mls' ) . '.zip';
$zip      = new ZipArchive();
$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
$zip->addFromString( 'kalendora-pro/kalendora-pro.php', "<?php\n/* Plugin Name: Kalendora Pro\n Version: 9.1.0 */" );
$zip->close();
$rel = Releases::add( 'kalendora-pro', $zip_path, array( 'version' => '9.1.0', 'changelog' => '<p>Neu: Warteliste</p><script>x</script>' ) );
check( ! is_wp_error( $rel ) && ! str_contains( $rel['changelog'], 'script' ) && file_exists( Releases::path( $rel ) ), 'release is stored in the protected folder, changelog sanitised', $rel );
check( is_wp_error( Releases::add( 'kalendora-pro', __FILE__, array( 'version' => '1.2.0' ) ) ), 'non-ZIP files are refused' );
$htaccess = (string) file_get_contents( Releases::dir() . '/.htaccess' );
// Note: the PHP test server ignores .htaccess; Apache/LiteSpeed block the folder, and the random name protects on nginx.
check( str_contains( $htaccess, 'Require all denied' ) && file_exists( Releases::dir() . '/web.config' ) && (bool) preg_match( '/^kalendora-pro-9\.1\.0-[A-Za-z0-9]{12}\.zip$/', $rel['file'] ), 'release folder is locked (.htaccess, web.config) and the file name is random', $rel['file'] );
[ $c, $d ] = post( $api . 'check', array( 'key' => $one['key'], 'site' => 'https://praxis-b.de', 'product' => 'kalendora-pro', 'version' => '1.0.0' ) );
check( '9.1.0' === ( $d['latest']['version'] ?? '' ) && ! isset( $d['latest']['file'] ) && str_contains( (string) ( $d['package'] ?? '' ), 'sig=' ), 'check offers 9.1.0 with a signed download link (file name stays secret)', $d );
$dl = wp_remote_get( $d['package'], array( 'timeout' => 20 ) );
check( 200 === wp_remote_retrieve_response_code( $dl ) && str_starts_with( wp_remote_retrieve_body( $dl ), "PK\x03\x04" ), 'signed link delivers the ZIP' );
$bad = wp_remote_get( str_replace( 'sig=', 'sig=0', $d['package'] ) );
check( 403 === wp_remote_retrieve_response_code( $bad ), 'manipulated link is refused' );
[ $c, $d ] = post( $api . 'check', array( 'key' => $one['key'], 'site' => 'https://fremde-seite.de', 'product' => 'kalendora-pro' ) );
check( false === $d['valid'] && 'inactive' === $d['status'] && ! isset( $d['package'] ), 'a website that is not activated gets no download', $d );

echo "Expiry and revocation\n";
$old = $lic->create( array( 'product' => 'kalendora-pro', 'email' => 'alt@example.com', 'sites' => 1 ) );
post( $api . 'activate', array( 'key' => $old['key'], 'site' => 'https://alt.de', 'product' => 'kalendora-pro' ) );
[ , $before ] = post( $api . 'check', array( 'key' => $old['key'], 'site' => 'https://alt.de', 'product' => 'kalendora-pro' ) );
$lic->update( $old['id'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
[ , $d ] = post( $api . 'check', array( 'key' => $old['key'], 'site' => 'https://alt.de', 'product' => 'kalendora-pro' ) );
check( 'expired' === $d['status'] && ! isset( $d['package'] ) && '9.1.0' === $d['latest']['version'], 'expired license: update is shown, but without download', $d );
$dl = wp_remote_get( $before['package'] );
check( 403 === wp_remote_retrieve_response_code( $dl ), 'an earlier download link stops working after expiry' );
[ $c, $d ] = post( $api . 'activate', array( 'key' => $old['key'], 'site' => 'https://neu.de', 'product' => 'kalendora-pro' ) );
check( 403 === $c && 'mls_expired' === $d['code'], 'expired license cannot be activated on a new website' );
$lic->extend( $old['id'], 12 );
[ , $d ] = post( $api . 'check', array( 'key' => $old['key'], 'site' => 'https://alt.de', 'product' => 'kalendora-pro' ) );
check( true === $d['valid'] && strtotime( $d['expires'] ) > strtotime( '+11 months' ), 'renewal extends by 12 months from today', $d );
$lic->update( $old['id'], array( 'status' => 'revoked' ) );
[ , $d ] = post( $api . 'check', array( 'key' => $old['key'], 'site' => 'https://alt.de', 'product' => 'kalendora-pro' ) );
check( 'revoked' === $d['status'], 'revoked license (refund) is reported as revoked' );
$life = $lic->create( array( 'product' => 'kalendora-pro', 'email' => 'agentur@example.com', 'sites' => 0, 'months' => 0 ) );
foreach ( range( 1, 12 ) as $n ) {
	$lic->activate( $life['key'], "https://kunde{$n}.de", 'kalendora-pro' );
}
check( 12 === count( $lic->activations( $life['id'] ) ) && null === $lic->find( $life['id'] )['expires_at'], 'agency license: unlimited websites, lifetime' );

echo "Brute force protection\n";
for ( $i = 0; $i < 21; $i++ ) {
	[ $c ] = post( $api . 'check', array( 'key' => 'BKRA-AAAA-AAAA-AAAA-' . sprintf( '%04d', $i ), 'site' => 'x.de', 'product' => 'kalendora-pro' ) );
}
[ $c ] = post( $api . 'activate', array( 'key' => $one['key'], 'site' => 'https://praxis-b.de', 'product' => 'kalendora-pro' ) );
check( 429 === $c, 'after 20 wrong keys from one address, requests are blocked for an hour', $c );
foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_mls_fail_%'" ) as $t ) {
	delete_transient( substr( $t, 11 ) );
}

echo "Kalendora Pro client\n";
add_filter( 'kalendora_pro_license_api', static fn() => $live . '/?rest_route=/mls/v1/' );
$client = new \KalendoraPro\License();
delete_option( \KalendoraPro\License::OPTION );
$two = $lic->create( array( 'product' => 'kalendora-pro', 'email' => 'pro@example.com', 'sites' => 5 ) );
check( is_wp_error( $client->activate( 'BKRA-0000-0000-0000-0000' ) ), 'wrong key: readable error' );
$res = $client->activate( strtolower( $two['key'] ) );
check( true === $res && \KalendoraPro\License::is_active() && 5 === \KalendoraPro\License::data()['sites_max'], 'client activates the key (also in lower case)', \KalendoraPro\License::data() );
$t  = (object) array( 'response' => array(), 'checked' => array() );
$t  = $client->inject_update( $t );
$up = $t->response[ KALENDORA_PRO_BASENAME ] ?? null;
check( $up && '9.1.0' === $up->new_version && str_contains( $up->package, 'sig=' ), 'WordPress update screen gets version 9.1.0 with download', $up );
$info = $client->plugin_info( false, 'plugin_information', (object) array( 'slug' => 'kalendora-pro' ) );
check( is_object( $info ) && str_contains( $info->sections['changelog'], 'Warteliste' ), '"View details" shows the changelog' );
check( PHP_INT_MAX === (int) apply_filters( 'kalendora_resource_limit', 3 ) && true === apply_filters( 'kalendora_is_pro', false ), 'Pro lifts the staff limit' );
$wpdb->update( Licenses::table( 'licenses' ), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'id' => $two['id'] ) );
$client->check();
check( 'expired' === \KalendoraPro\License::data()['status'] && PHP_INT_MAX === (int) apply_filters( 'kalendora_resource_limit', 3 ), 'after expiry the features keep working (only updates stop)' );
check( true === $client->deactivate() && ! get_option( \KalendoraPro\License::OPTION ), 'license can be removed from the website' );

echo "Webhooks\n";
delete_option( \KalendoraPro\Webhooks::OPTION );
delete_option( \KalendoraPro\Webhooks::LOG );
check( is_wp_error( \KalendoraPro\Webhooks::add( 'http://hooks.example.com/x', array( 'booking.created' ) ) ), 'only https addresses' );
check( is_wp_error( \KalendoraPro\Webhooks::add( 'https://127.0.0.1/x', array( 'booking.created' ) ) ), 'internal addresses are refused (SSRF)' );
$sent = array();
$code = 200;
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$sent, &$code ) {
		if ( ! str_contains( $url, 'hooks.zapier.com' ) ) {
			return $pre;
		}
		$sent[] = array( 'url' => $url, 'args' => $args );
		return array( 'headers' => array(), 'body' => 'ok', 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	},
	1,
	3
);
$hook = \KalendoraPro\Webhooks::add( 'https://hooks.zapier.com/hooks/catch/123/abc', array( 'booking.created', 'entry.created', 'evil' ) );
check( ! is_wp_error( $hook ) && array( 'booking.created', 'entry.created' ) === $hook['events'] && 32 === strlen( $hook['secret'] ), 'webhook is saved with its own secret', $hook );
wp_set_current_user( 1 );
$svc_req = new WP_REST_Request( 'POST', '/kalendora/v1/services' );
$svc_req->set_header( 'Content-Type', 'application/json' );
$svc_req->set_body( wp_json_encode( array( 'name' => 'Webhook-Test', 'duration' => 30 ) ) );
$svc  = rest_do_request( $svc_req )->get_data();
$d    = ( new DateTimeImmutable( 'next monday 16:00', wp_timezone() ) )->modify( '+21 days' );
// Test installation only: free the test slot from earlier runs.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}kalendora_bookings WHERE start_utc >= %s AND start_utc < %s", get_gmt_from_date( $d->modify( '+7 days' )->format( 'Y-m-d 00:00:00' ) ), get_gmt_from_date( $d->modify( '+8 days' )->format( 'Y-m-d 00:00:00' ) ) ) );
$book = \Kalendora\Core\Plugin::instance()->container()->get( \Kalendora\Booking\BookingService::class )->create( array( 'service_id' => $svc['id'], 'start' => $d->modify( '+7 days' )->getTimestamp(), 'customer' => array( 'email' => 'webhook@example.com', 'name' => 'Wera Webhook' ) ) );
$last = end( $sent );
$body = $last ? json_decode( $last['args']['body'], true ) : null;
check( $last && 'booking.created' === $body['event'] && 'Wera Webhook' === $body['data']['customer']['name'] && 'Webhook-Test' === $body['data']['service'], 'new booking is sent as JSON', $body );
check( $last && 'sha256=' . hash_hmac( 'sha256', $last['args']['body'], $hook['secret'] ) === $last['args']['headers']['X-Kalendora-Signature'] && false === $last['args']['redirection'] > 0, 'message is signed with HMAC-SHA256, no redirects followed' );
$code = 500;
$n    = count( $sent );
( new \KalendoraPro\Webhooks() )->test( $hook['id'] );
$next = wp_next_scheduled( \KalendoraPro\Webhooks::RETRY );
check( count( $sent ) === $n + 1 && false === $next, 'a failing test is reported (no retry for tests)' );
$code = 503;
do_action( 'kalendora_customer_rescheduled', is_wp_error( $book ) ? 0 : (int) $book['booking_id'], '' );
\KalendoraPro\Webhooks::add( 'https://hooks.zapier.com/hooks/catch/999/retry', array( 'booking.moved' ) );
do_action( 'kalendora_booking_moved', is_wp_error( $book ) ? 0 : (int) $book['booking_id'], gmdate( 'Y-m-d H:i:s' ) );
$crons = _get_cron_array();
$retry = 0;
foreach ( $crons as $ts => $hooks ) {
	$retry += count( $hooks[ \KalendoraPro\Webhooks::RETRY ] ?? array() );
}
check( $retry >= 1, 'failed delivery is retried later (5 / 30 minutes)', $retry );
$log = get_option( \KalendoraPro\Webhooks::LOG );
check( is_array( $log ) && 'hooks.zapier.com' === $log[0]['host'] && 503 === $log[0]['code'], 'deliveries are logged (host, event, status)' );
wp_clear_scheduled_hook( \KalendoraPro\Webhooks::RETRY );
foreach ( $crons as $ts => $hooks ) {
	foreach ( (array) ( $hooks[ \KalendoraPro\Webhooks::RETRY ] ?? array() ) as $e ) {
		wp_unschedule_event( $ts, \KalendoraPro\Webhooks::RETRY, $e['args'] );
	}
}
rest_do_request( new WP_REST_Request( 'DELETE', '/kalendora/v1/services/' . $svc['id'] ) );
delete_option( \KalendoraPro\Webhooks::OPTION );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
