<?php
/**
 * Stripe Checkout (hosted payment page): card data never touches the website.
 *
 * @package BookoraPro
 */

namespace BookoraPro;

defined( 'ABSPATH' ) || exit;

/**
 * Stripe API calls.
 */
final class Stripe {

	public const API = 'https://api.stripe.com/v1/';

	/**
	 * Constructor.
	 *
	 * @param string $secret Secret key (sk_live_… / sk_test_… / rk_…).
	 */
	public function __construct( private string $secret ) {}

	/**
	 * Create a Checkout Session.
	 *
	 * @param array<string, mixed> $p amount (float), currency, name, email, success_url, cancel_url, payment_id, booking_id, minutes.
	 * @return array{id:string, url:string}|\WP_Error
	 */
	public function create_session( array $p ) {
		$data = $this->request(
			'POST',
			'checkout/sessions',
			array(
				'mode'                                   => 'payment',
				'success_url'                            => $p['success_url'],
				'cancel_url'                             => $p['cancel_url'],
				'customer_email'                         => $p['email'],
				'client_reference_id'                    => (string) $p['payment_id'],
				'locale'                                 => 'auto',
				'expires_at'                             => time() + max( 30, (int) $p['minutes'] ) * MINUTE_IN_SECONDS,
				'line_items[0][quantity]'                => 1,
				'line_items[0][price_data][currency]'    => strtolower( (string) $p['currency'] ),
				'line_items[0][price_data][unit_amount]' => self::cents( (float) $p['amount'] ),
				'line_items[0][price_data][product_data][name]' => mb_substr( (string) $p['name'], 0, 250 ),
				'metadata[payment_id]'                   => (string) $p['payment_id'],
				'metadata[booking_id]'                   => (string) $p['booking_id'],
				'payment_intent_data[metadata][booking_id]' => (string) $p['booking_id'],
			),
			'bookora-pay-' . $p['payment_id'] . '-' . $p['attempt']
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['id'] ) || empty( $data['url'] ) ) {
			return new \WP_Error( 'bookora_pro_stripe', __( 'Stripe did not return a payment page.', 'bookora-pro' ) );
		}
		return array(
			'id'  => (string) $data['id'],
			'url' => (string) $data['url'],
		);
	}

	/**
	 * Is the session paid? Returns the payment intent id or null.
	 *
	 * @param string $session_id Session id.
	 * @return array{paid:bool, reference:string, amount:float}|\WP_Error
	 */
	public function session_status( string $session_id ) {
		if ( ! preg_match( '/^cs_(test|live)_[A-Za-z0-9]+$/', $session_id ) ) {
			return new \WP_Error( 'bookora_pro_stripe', __( 'Invalid payment reference.', 'bookora-pro' ) );
		}
		$data = $this->request( 'GET', 'checkout/sessions/' . $session_id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return array(
			'paid'      => 'paid' === ( $data['payment_status'] ?? '' ),
			'reference' => (string) ( $data['payment_intent'] ?? '' ),
			'amount'    => (int) ( $data['amount_total'] ?? 0 ) / 100,
		);
	}

	/**
	 * Refund (part of) a payment.
	 *
	 * @param string $payment_intent Payment intent id.
	 * @param float  $amount         Amount.
	 * @return true|\WP_Error
	 */
	public function refund( string $payment_intent, float $amount ) {
		$data = $this->request(
			'POST',
			'refunds',
			array(
				'payment_intent' => $payment_intent,
				'amount'         => self::cents( $amount ),
			)
		);
		return is_wp_error( $data ) ? $data : true;
	}

	/**
	 * Verify a webhook signature (header Stripe-Signature: t=…,v1=…).
	 *
	 * @param string $payload   Raw body.
	 * @param string $header    Header value.
	 * @param string $secret    Webhook signing secret (whsec_…).
	 * @param int    $tolerance Seconds.
	 */
	public static function verify_signature( string $payload, string $header, string $secret, int $tolerance = 300 ): bool {
		if ( '' === $secret ) {
			return false;
		}
		$time = 0;
		$sigs = array();
		foreach ( explode( ',', $header ) as $part ) {
			[ $k, $v ] = array_pad( explode( '=', trim( $part ), 2 ), 2, '' );
			if ( 't' === $k ) {
				$time = (int) $v;
			} elseif ( 'v1' === $k ) {
				$sigs[] = $v;
			}
		}
		if ( ! $time || abs( time() - $time ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $time . '.' . $payload, $secret );
		foreach ( $sigs as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Euro → cents without float errors.
	 *
	 * @param float $amount Amount.
	 */
	public static function cents( float $amount ): int {
		return (int) round( $amount * 100 );
	}

	/**
	 * API request.
	 *
	 * @param string               $method      GET|POST.
	 * @param string               $path        Path.
	 * @param array<string, mixed> $body        Form fields.
	 * @param string               $idempotency Idempotency key (POST).
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, string $path, array $body = array(), string $idempotency = '' ) {
		$headers = array( 'Authorization' => 'Bearer ' . $this->secret );
		if ( '' !== $idempotency ) {
			$headers['Idempotency-Key'] = $idempotency;
		}
		$response = wp_remote_request(
			self::API . $path,
			array(
				'method'  => $method,
				'timeout' => 20,
				'headers' => $headers,
				'body'    => 'POST' === $method ? $body : null,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'bookora_pro_stripe_http', __( 'Stripe could not be reached. Please try again in a moment.', 'bookora-pro' ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';
			/* translators: %s: error message from Stripe. */
			return new \WP_Error( 'bookora_pro_stripe', sprintf( __( 'Stripe: %s', 'bookora-pro' ), '' !== $msg ? $msg : (string) $code ) );
		}
		return $data;
	}
}
