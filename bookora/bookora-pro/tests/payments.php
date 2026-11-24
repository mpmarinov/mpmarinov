<?php
/**
 * Online payment: full flow with simulated Stripe and PayPal answers (no real requests).
 *
 * Usage: php tests/payments.php /path/to/wordpress   (Bookora + Bookora Pro active; test installation only)
 *
 * @package BookoraPro
 */

// phpcs:ignoreFile

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.' . random_int( 2, 250 );
define( 'BOOKORA_PRO_TESTING', true );
require rtrim( $argv[1], '/' ) . '/wp-load.php';

use BookoraPro\Payments;

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
	$q = new WP_REST_Request( $m, '/bookora/v1' . $r );
	if ( $b ) {
		$q->set_header( 'Content-Type', 'application/json' );
		$q->set_body( wp_json_encode( $b ) );
	}
	return rest_do_request( $q );
}
/** Call the payment page like a browser; returns "page:…" or "redirect:…". */
function visit( array $get, array $post = array() ): string {
	$_GET  = $get;
	$_POST = $post;
	try {
		( new Payments() )->page();
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	} finally {
		$_GET  = array();
		$_POST = array();
	}
	return 'nothing';
}
function query( string $url ): array {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
	return $q;
}

global $wpdb;
$c        = \Bookora\Core\Plugin::instance()->container();
$entries  = $c->get( \Bookora\Repository\EntryRepository::class );
$payments = new Payments();
add_filter( 'bookora_min_submit_seconds', static fn() => 0 );
add_filter( 'bookora_rate_limit', static fn() => 1000 );
$wpdb->query( 'DELETE FROM ' . Payments::table() );
delete_option( Payments::OPTION );

// Simulated providers.
$calls = array();
$reply = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$calls, &$reply ) {
		if ( ! preg_match( '#^https://(api\.stripe\.com|api-m\.(sandbox\.)?paypal\.com)/#', $url ) ) {
			return $pre;
		}
		$calls[] = array( 'url' => $url, 'args' => $args );
		foreach ( $reply as $needle => $r ) {
			if ( str_contains( $url, $needle ) && ( ! isset( $r[2] ) || $r[2] === ( $args['method'] ?? 'GET' ) ) ) {
				return array( 'headers' => array(), 'body' => is_string( $r[1] ) ? $r[1] : wp_json_encode( $r[1] ), 'response' => array( 'code' => $r[0], 'message' => '' ), 'cookies' => array(), 'filename' => null );
			}
		}
		return array( 'headers' => array(), 'body' => '{"error":{"message":"not mocked"}}', 'response' => array( 'code' => 500, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	},
	1,
	3
);
$last = static function ( string $needle ) use ( &$calls ) {
	foreach ( array_reverse( $calls ) as $c ) {
		if ( str_contains( $c['url'], $needle ) ) {
			return $c;
		}
	}
	return null;
};

wp_set_current_user( 1 );
echo "Settings\n";
check( is_wp_error( Payments::save( array( 'mode' => 'full' ) ) ), 'online payment needs Stripe or PayPal access data' );
check( is_wp_error( Payments::save( array( 'mode' => 'full', 'stripe_secret' => 'pk_live_123' ) ) ), 'publishable key instead of secret key is refused' );
check( true === Payments::save( array( 'mode' => 'full', 'stripe_secret' => 'sk_test_ABCDEFGHIJKL123', 'stripe_webhook' => 'whsec_testsecret', 'minutes' => 10 ) ), 'Stripe test key is saved' );
$raw = (string) wp_json_encode( get_option( Payments::OPTION ) );
check( ! str_contains( $raw, 'sk_test_ABCDEFGHIJKL123' ) && ! str_contains( $raw, 'whsec_testsecret' ) && 30 === Payments::settings()['minutes'], 'keys are stored encrypted; time to pay is at least 30 minutes' );
Payments::save( array( 'mode' => 'full', 'refund_cancel' => 1 ) );
check( 'sk_test_ABCDEFGHIJKL123' === Payments::settings()['stripe_secret'], 'saving with empty key fields keeps the stored keys' );

echo "Amounts\n";
foreach ( $c->get( \Bookora\Repository\ServiceRepository::class )->all() as $old ) {
	if ( in_array( $old['name'], array( 'Bezahl-Massage', 'Gratis-Beratung' ), true ) ) {
		api( 'DELETE', '/services/' . $old['id'] );
	}
}
$svc  = api( 'POST', '/services', array( 'name' => 'Bezahl-Massage', 'duration' => 60, 'price' => 49 ) )->get_data();
$free = api( 'POST', '/services', array( 'name' => 'Gratis-Beratung', 'duration' => 30, 'price' => 0 ) )->get_data();
$s    = Payments::settings();
check( 49.0 === Payments::amount( $svc, 1, $s )['amount'] && 98.0 === Payments::amount( $svc, 2, $s )['amount'], 'full price, times places' );
check( 0.0 === Payments::amount( $free, 1, $s )['amount'], 'free services need no payment' );
check( 14.7 === Payments::amount( $svc, 1, array( 'mode' => 'deposit', 'deposit_type' => 'percent', 'deposit_value' => 30 ) + $s )['amount'], 'deposit 30 % of 49 € = 14,70 €' );
check( 49.0 === Payments::amount( $svc, 1, array( 'mode' => 'deposit', 'deposit_type' => 'fixed', 'deposit_value' => 80 ) + $s )['amount'], 'fixed deposit never higher than the price' );
check( 0.0 === Payments::amount( $svc, 1, array( 'services' => array( (int) $free['id'] ) ) + $s )['amount'], 'only the chosen services' );

echo "Booking with Stripe\n";
$form = api(
	'POST',
	'/forms',
	array(
		'title'  => 'Bezahlformular',
		'type'   => 'booking',
		'status' => 'published',
		'fields' => array(
			array( 'type' => 'service', 'label' => 'Leistung', 'required' => true ),
			array( 'type' => 'appointment', 'label' => 'Termin', 'required' => true ),
			array( 'type' => 'email', 'key' => 'email', 'label' => 'E-Mail', 'required' => true ),
		),
	)
)->get_data();
$day  = new DateTimeImmutable( 'monday next week', wp_timezone() );
// Test installation only: free the test day from bookings of earlier runs.
$tday = $day->modify( '+49 days' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}bookora_bookings WHERE start_utc >= %s AND start_utc < %s", get_gmt_from_date( $tday->format( 'Y-m-d 00:00:00' ) ), get_gmt_from_date( $tday->modify( '+1 day' )->format( 'Y-m-d 00:00:00' ) ) ) );
$slot = static function ( int $hour ) use ( $day ) {
	return $day->modify( '+49 days' )->setTime( $hour, 0 )->getTimestamp();
};
wp_set_current_user( 0 );
$book = static function ( int $service, int $hour, string $email ) use ( $form, $slot ) {
	return api(
		'POST',
		'/public/forms/' . $form['id'] . '/submit',
		array(
			'_bk_t'  => \Bookora\Forms\Submission::render_token( (int) $form['id'] ),
			'fields' => array( 'email' => $email, 'leistung' => (string) $service ),
			'_appointment' => array(),
		) + array( 'fields' => array() )
	);
};
// The appointment field posts start + service in the fields array.
$submit = static function ( int $service, int $hour, string $email ) use ( $form, $slot ) {
	$fields = array( 'email' => $email );
	foreach ( (array) $form['fields'] as $f ) {
		if ( 'service' === $f['type'] ) {
			$fields[ $f['key'] ] = (string) $service;
		}
		if ( 'appointment' === $f['type'] ) {
			$fields[ $f['key'] ] = array( 'start' => gmdate( 'c', $slot( $hour ) ) );
		}
	}
	return api( 'POST', '/public/forms/' . $form['id'] . '/submit', array( '_bk_t' => \Bookora\Forms\Submission::render_token( (int) $form['id'] ), 'fields' => $fields ) );
};
$log_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bookora_notification_log" );
$res        = $submit( (int) $svc['id'], 9, 'zahler@example.com' );
$data       = $res->get_data();
$booking_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}bookora_bookings" );
$pay        = Payments::for_booking( $booking_id );
check( $res->get_status() < 300 && str_contains( (string) ( $data['redirect'] ?? '' ), 'bookora_pay=' ), 'after booking the customer is sent to the payment page', $data );
check( $pay && 'open' === $pay['status'] && 49.0 === (float) $pay['amount'] && 'pending' === $entries->booking( $booking_id )['status'], 'booking is reserved (unconfirmed) with an open payment of 49 €' );
check( $log_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bookora_notification_log" ), 'no emails before the payment' );
$fresh = $submit( (int) $free['id'], 11, 'gratis@example.com' );
check( '' === (string) ( $fresh->get_data()['redirect'] ?? '' ), 'free service: booking as before, no payment page' );

$q = query( $data['redirect'] );
check( str_starts_with( visit( array( 'bookora_pay' => $q['bookora_pay'], 't' => 'falsch' ) ), 'page:Payment|' ), 'a payment link with wrong token is refused' );
$page = visit( $q );
check( str_contains( $page, 'Pay to confirm' ) && str_contains( $page, '49' ) && str_contains( $page, 'Pay by card' ) && ! str_contains( $page, 'PayPal' ), 'payment page shows amount and the configured provider', $page );

$reply = array( 'checkout/sessions' => array( 200, array( 'id' => 'cs_test_abc123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc123' ), 'POST' ) );
$go    = visit( $q + array( 'go' => 'stripe' ) );
$sent  = $last( 'checkout/sessions' );
check( 'redirect:https://checkout.stripe.com/c/pay/cs_test_abc123' === $go, 'customer is sent to the Stripe payment page', $go );
check( $sent && 4900 === (int) $sent['args']['body']['line_items[0][price_data][unit_amount]'] && 'eur' === $sent['args']['body']['line_items[0][price_data][currency]'] && 'Bearer sk_test_ABCDEFGHIJKL123' === $sent['args']['headers']['Authorization'] && ! empty( $sent['args']['headers']['Idempotency-Key'] ), 'Stripe gets 4900 cents in EUR, secret key and idempotency key', $sent['args'] ?? null );
check( str_contains( (string) $sent['args']['body']['success_url'], '{CHECKOUT_SESSION_ID}' ), 'Stripe returns to the payment page with the session id' );

$reply = array( 'checkout/sessions/cs_test_abc123' => array( 200, array( 'payment_status' => 'unpaid', 'amount_total' => 4900 ), 'GET' ) );
check( str_contains( visit( $q + array( 'return' => 'stripe', 'session' => 'cs_test_OTHER999' ) ), 'could not be assigned' ), 'a foreign session id is not accepted' );
check( str_contains( visit( $q + array( 'return' => 'stripe', 'session' => 'cs_test_abc123' ) ), 'not been completed' ) && 'open' === Payments::find( (int) $pay['id'] )['status'], 'returning without payment does not confirm anything' );
$reply = array( 'checkout/sessions/cs_test_abc123' => array( 200, array( 'payment_status' => 'paid', 'amount_total' => 4900, 'payment_intent' => 'pi_123' ), 'GET' ) );
$done  = visit( $q + array( 'return' => 'stripe', 'session' => 'cs_test_abc123' ) );
$after = Payments::find( (int) $pay['id'] );
check( str_contains( $done, 'Thank you for your payment' ) && 'paid' === $after['status'] && 'pi_123' === $after['reference'], 'paid: the payment is checked at Stripe and stored', $done );
check( 'confirmed' === $entries->booking( $booking_id )['status'], 'booking is confirmed automatically' );
$events = $wpdb->get_col( $wpdb->prepare( "SELECT event FROM {$wpdb->prefix}bookora_notification_log WHERE booking_id = %d", $booking_id ) );
check( in_array( 'booking_confirmed', $events, true ) && in_array( 'admin_new_booking', $events, true ) && ! in_array( 'booking_received', $events, true ), 'only now: confirmation to the customer and notice to you', $events );
visit( $q + array( 'return' => 'stripe', 'session' => 'cs_test_abc123' ) );
check( 2 === count( $wpdb->get_col( $wpdb->prepare( "SELECT event FROM {$wpdb->prefix}bookora_notification_log WHERE booking_id = %d", $booking_id ) ) ), 'reloading the page sends nothing twice' );
$badges = $entries->booking( $booking_id )['badges'] ?? array();
check( $badges && str_contains( $badges[0]['label'], '49' ) && 'success' === $badges[0]['tone'], 'admin list shows "paid online"', $badges );

echo "Stripe webhook\n";
$submit( (int) $svc['id'], 10, 'webhook@example.com' );
$b2 = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}bookora_bookings" );
$p2 = Payments::for_booking( $b2 );
$wpdb->update( Payments::table(), array( 'provider' => 'stripe', 'session_id' => 'cs_test_hook2' ), array( 'id' => $p2['id'] ) );
$payload = wp_json_encode( array( 'type' => 'checkout.session.completed', 'data' => array( 'object' => array( 'id' => 'cs_test_hook2', 'payment_status' => 'paid', 'amount_total' => 4900, 'payment_intent' => 'pi_hook2', 'metadata' => array( 'payment_id' => (string) $p2['id'] ) ) ) ) );
$hook    = static function ( string $sig ) use ( $payload ) {
	$r = new WP_REST_Request( 'POST', '/bookora/v1/pro/stripe-webhook' );
	$r->set_body( $payload );
	$r->set_header( 'Stripe-Signature', $sig );
	return rest_do_request( $r );
};
$t = time();
check( 400 === $hook( 't=' . $t . ',v1=' . str_repeat( 'a', 64 ) )->get_status() && 'open' === Payments::find( (int) $p2['id'] )['status'], 'webhook with a wrong signature is refused' );
check( 400 === $hook( 't=' . ( $t - 3600 ) . ',v1=' . hash_hmac( 'sha256', ( $t - 3600 ) . '.' . $payload, 'whsec_testsecret' ) )->get_status(), 'old webhooks (replay) are refused' );
check( 200 === $hook( 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $payload, 'whsec_testsecret' ) )->get_status() && 'paid' === Payments::find( (int) $p2['id'] )['status'] && 'confirmed' === $entries->booking( $b2 )['status'], 'signed webhook confirms the booking (even if the customer closed the browser)' );

echo "Not paid in time\n";
$submit( (int) $svc['id'], 12, 'spaet@example.com' );
$b3 = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}bookora_bookings" );
$p3 = Payments::for_booking( $b3 );
check( false !== wp_next_scheduled( Payments::CRON, array( (int) $p3['id'] ) ), 'expiry is scheduled' );
$payments->expire( (int) $p3['id'] );
check( 'open' === Payments::find( (int) $p3['id'] )['status'], 'nothing happens before the time is up' );
$wpdb->update( Payments::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $p3['id'] ) );
$payments->expire( (int) $p3['id'] );
$b3row = $entries->booking( $b3 );
check( 'expired' === Payments::find( (int) $p3['id'] )['status'] && 'cancelled' === $b3row['status'] && 'payment' === $b3row['cancelled_by'], 'after the time: payment expired, booking cancelled, time free again' );
$slots = $c->get( \Bookora\Availability\AvailabilityService::class )->slots( (int) $svc['id'], null, $day->modify( '+49 days' )->format( 'Y-m-d' ), $day->modify( '+49 days' )->format( 'Y-m-d' ) );
check( in_array( 12, array_map( static fn( $s ) => (int) wp_date( 'G', (int) $s['start'] ), (array) $slots ), true ), 'the slot can be booked again', $slots );
check( str_contains( visit( query( Payments::url( Payments::find( (int) $p3['id'] ) ) ) ), 'Time expired' ), 'payment page explains that the time expired' );

echo "Refunds\n";
$reply = array( '/v1/refunds' => array( 200, array( 'id' => 're_1', 'status' => 'succeeded' ), 'POST' ) );
do_action( 'bookora_customer_cancelled', $booking_id, 24.5, '' );
$r = $last( '/v1/refunds' );
check( $r && 2450 === (int) $r['args']['body']['amount'] && 'pi_123' === $r['args']['body']['payment_intent'] && 'partially_refunded' === Payments::find( (int) $pay['id'] )['status'], 'late cancellation: 49 € minus 24,50 € fee = 24,50 € refunded', $r['args']['body'] ?? null );
$calls = array();
wp_set_current_user( 1 );
$entries->set_booking_status( $b2, 'cancelled' );
$r = $last( '/v1/refunds' );
check( $r && 4900 === (int) $r['args']['body']['amount'] && 'refunded' === Payments::find( (int) $p2['id'] )['status'], 'cancelled by you: full refund' );
$calls = array();
$payments->refund( Payments::find( (int) $p2['id'] ), 49 );
check( null === $last( '/v1/refunds' ), 'never refunded twice' );
$reply = array( '/v1/refunds' => array( 402, array( 'error' => array( 'message' => 'Insufficient funds' ) ), 'POST' ) );
$res   = $payments->refund( Payments::find( (int) $pay['id'] ), 10 );
check( is_wp_error( $res ) && str_contains( $res->get_error_message(), 'Insufficient' ) && 'partially_refunded' === Payments::find( (int) $pay['id'] )['status'], 'a failed refund is reported and nothing is marked' );

echo "PayPal and deposit\n";
wp_set_current_user( 1 );
Payments::save( array( 'mode' => 'deposit', 'deposit_type' => 'percent', 'deposit_value' => 30, 'stripe_secret_remove' => 1, 'stripe_webhook_remove' => 1, 'paypal_client' => 'client-abc', 'paypal_secret' => 'secret-xyz', 'paypal_sandbox' => 1, 'refund_cancel' => 1 ) );
check( array( 'paypal' ) === Payments::providers(), 'only PayPal is offered after removing the Stripe key' );
wp_set_current_user( 0 );
$submit( (int) $svc['id'], 14, 'paypal@example.com' );
$b4 = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}bookora_bookings" );
$p4 = Payments::for_booking( $b4 );
check( 14.7 === (float) $p4['amount'] && 'deposit' === $p4['kind'], 'deposit of 14,70 € is requested' );
$q4    = query( Payments::url( $p4 ) );
$page4 = visit( $q4 );
check( str_contains( $page4, 'Deposit' ) && str_contains( $page4, 'Pay with PayPal' ) && ! str_contains( $page4, 'Pay by card' ), 'payment page: deposit, PayPal only', $page4 );
visit( $q4, array( 'onsite' => '1' ) );
check( ! str_contains( $page4, 'Pay on site' ) && 'open' === Payments::find( (int) $p4['id'] )['status'], '"pay on site" switched off: not offered and not accepted' );
$reply = array(
	'/v1/oauth2/token'                => array( 200, array( 'access_token' => 'TOKEN1', 'expires_in' => 32000 ), 'POST' ),
	'/v2/checkout/orders/ORDER12345/capture' => array( 201, array( 'status' => 'COMPLETED', 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'id' => 'CAP777', 'status' => 'COMPLETED', 'amount' => array( 'value' => '14.70', 'currency_code' => 'EUR' ) ) ) ) ) ) ), 'POST' ),
	'/v2/checkout/orders'             => array( 201, array( 'id' => 'ORDER12345', 'links' => array( array( 'rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER12345' ) ) ), 'POST' ),
);
delete_transient( 'bookora_pro_pp_' . md5( 'client-abc' . '1' ) );
$go4   = visit( $q4 + array( 'go' => 'paypal' ) );
$order = json_decode( (string) $last( '/v2/checkout/orders' )['args']['body'], true );
check( 'redirect:https://www.sandbox.paypal.com/checkoutnow?token=ORDER12345' === $go4 && '14.70' === $order['purchase_units'][0]['amount']['value'] && str_contains( $last( '/v2/checkout/orders' )['url'], 'sandbox' ), 'PayPal sandbox order over 14.70 EUR, customer goes to PayPal', $go4 );
$done4 = visit( $q4 + array( 'return' => 'paypal', 'token' => 'ORDER12345' ) );
check( str_contains( $done4, 'Thank you' ) && 'CAP777' === Payments::find( (int) $p4['id'] )['reference'] && 'confirmed' === $entries->booking( $b4 )['status'], 'PayPal payment is captured and the booking confirmed', $done4 );
check( str_contains( ( $entries->booking( $b4 )['badges'][0]['label'] ?? '' ), 'Deposit' ), 'admin list shows "deposit paid"' );
$reply['/v2/payments/captures/CAP777/refund'] = array( 201, array( 'status' => 'COMPLETED' ), 'POST' );
$reply = array( '/v2/payments/captures/CAP777/refund' => array( 201, array( 'status' => 'COMPLETED' ), 'POST' ) ) + $reply;
do_action( 'bookora_customer_cancelled', $b4, 0.0, '' );
$rr = json_decode( (string) ( $last( '/refund' )['args']['body'] ?? '' ), true );
check( '14.70' === ( $rr['amount']['value'] ?? '' ) && 'refunded' === Payments::find( (int) $p4['id'] )['status'], 'free cancellation: the deposit goes back via PayPal' );

$submit( (int) $svc['id'], 15, 'falschbetrag@example.com' );
$p5 = Payments::for_booking( (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}bookora_bookings" ) );
$wpdb->update( Payments::table(), array( 'provider' => 'paypal', 'session_id' => 'ORDER55555' ), array( 'id' => $p5['id'] ) );
$reply = array( '/v2/checkout/orders/ORDER55555/capture' => array( 201, array( 'status' => 'COMPLETED', 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'id' => 'CAP5', 'status' => 'COMPLETED', 'amount' => array( 'value' => '0.01' ) ) ) ) ) ) ), 'POST' ) ) + $reply;
$q5    = query( Payments::url( Payments::find( (int) $p5['id'] ) ) );
check( str_contains( visit( $q5 + array( 'return' => 'paypal', 'token' => 'ORDER55555' ) ), 'does not match' ) && 'open' === Payments::find( (int) $p5['id'] )['status'], 'a manipulated amount (0,01 €) is not accepted' );

echo "Pay on site\n";
wp_set_current_user( 1 );
Payments::save( array( 'mode' => 'deposit', 'deposit_type' => 'percent', 'deposit_value' => 30, 'paypal_client' => 'client-abc', 'refund_cancel' => 1, 'onsite' => 1 ) );
check( true === Payments::settings()['onsite'] && 'secret-xyz' === Payments::settings()['paypal_secret'], 'setting "pay on site" is saved' );
wp_set_current_user( 0 );
$submit( (int) $svc['id'], 16, 'bar@example.com' );
$b6    = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}bookora_bookings" );
$p6    = Payments::for_booking( $b6 );
$q6    = query( Payments::url( $p6 ) );
$page6 = visit( $q6 );
check( str_contains( $page6, 'Pay with PayPal' ) && str_contains( $page6, 'Pay on site instead' ) && str_contains( $page6, '49' ), 'payment page offers PayPal and "pay on site" with the full price', $page6 );
$log6  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bookora_notification_log" );
$calls = array();
$done6 = visit( $q6, array( 'onsite' => '1' ) );
$row6  = Payments::find( (int) $p6['id'] );
$want  = (string) $c->get( \Bookora\Settings\Settings::class )->get( 'default_status', 'pending' );
check( str_contains( $done6, 'Thank you for your booking' ) && str_contains( $done6, 'To pay on site' ) && str_contains( $done6, '49' ) && 'onsite' === $row6['status'], 'customer chooses "pay on site": booking done, 49 € to pay there', $done6 );
check( $want === $entries->booking( $b6 )['status'] && false === wp_next_scheduled( Payments::CRON, array( (int) $p6['id'] ) ) && ! $calls, 'booking follows the normal confirmation rule, no expiry, no call to PayPal' );
$ev6 = $wpdb->get_col( $wpdb->prepare( "SELECT event FROM {$wpdb->prefix}bookora_notification_log WHERE booking_id = %d", $b6 ) );
check( in_array( 'admin_new_booking', $ev6, true ) && in_array( 'confirmed' === $want ? 'booking_confirmed' : 'booking_received', $ev6, true ) && 2 === count( $ev6 ), 'emails to the customer and to you go out now', $ev6 );
visit( $q6, array( 'onsite' => '1' ) );
$wpdb->update( Payments::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $p6['id'] ) );
$payments->expire( (int) $p6['id'] );
check( 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bookora_notification_log WHERE booking_id = %d", $b6 ) ) && 'onsite' === Payments::find( (int) $p6['id'] )['status'] && 'cancelled' !== $entries->booking( $b6 )['status'], 'nothing twice, and the booking is never released for lack of payment' );
check( str_contains( ( $entries->booking( $b6 )['badges'][0]['label'] ?? '' ), 'on site' ) && 'warning' === $entries->booking( $b6 )['badges'][0]['tone'], 'admin list shows "pays on site: 49 €"', $entries->booking( $b6 )['badges'] ?? null );
check( is_wp_error( $payments->refund( Payments::find( (int) $p6['id'] ), 10 ) ), 'nothing can be refunded online for an on-site payment' );
check( $payments->mark_received( (int) $p6['id'] ) && ! $payments->mark_received( (int) $p6['id'] ), 'you mark it as received – only once' );
$row6 = Payments::find( (int) $p6['id'] );
check( 'paid' === $row6['status'] && 'onsite' === $row6['provider'] && 49.0 === (float) $row6['amount'] && 'full' === $row6['kind'], 'stored as 49 € paid on site' );
check( str_contains( ( $entries->booking( $b6 )['badges'][0]['label'] ?? '' ), 'Paid on site' ), 'admin list shows "paid on site"' );
$calls = array();
do_action( 'bookora_customer_cancelled', $b6, 0.0, '' );
check( ! $calls && 'paid' === Payments::find( (int) $p6['id'] )['status'], 'cancellation of an on-site payment: no online refund attempted' );

// Started PayPal, then chose "on site", then paid in the PayPal tab after all: the money counts, no duplicate emails.
$submit( (int) $svc['id'], 17, 'doch-online@example.com' );
$b7 = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}bookora_bookings" );
$p7 = Payments::for_booking( $b7 );
$wpdb->update( Payments::table(), array( 'provider' => 'paypal', 'session_id' => 'ORDER77777' ), array( 'id' => $p7['id'] ) );
$q7 = query( Payments::url( Payments::find( (int) $p7['id'] ) ) );
visit( $q7, array( 'onsite' => '1' ) );
$n7 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bookora_notification_log WHERE booking_id = %d", $b7 ) );
$payments->mark_paid( (int) $p7['id'], 'CAP7' );
check( 'paid' === Payments::find( (int) $p7['id'] )['status'] && $n7 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bookora_notification_log WHERE booking_id = %d", $b7 ) ), 'online payment after choosing "on site" is recorded without duplicate emails' );

add_filter( 'bookora_pro_onsite_allowed', '__return_false' );
$submit( (int) $svc['id'], 18, 'nur-online@example.com' );
$p8 = Payments::for_booking( (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}bookora_bookings" ) );
$q8 = query( Payments::url( $p8 ) );
visit( $q8, array( 'onsite' => '1' ) );
check( 'open' === Payments::find( (int) $p8['id'] )['status'] && ! str_contains( visit( $q8 ), 'Pay on site' ), 'filter can switch "pay on site" off (e.g. for certain services)' );
remove_filter( 'bookora_pro_onsite_allowed', '__return_false' );

// Clean up.
wp_set_current_user( 1 );
foreach ( array( $svc, $free ) as $s ) {
	api( 'DELETE', '/services/' . $s['id'] );
}
api( 'DELETE', '/forms/' . $form['id'] );
delete_option( Payments::OPTION );
wp_clear_scheduled_hook( Payments::CRON );
echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
