<?php
/**
 * PayPal Checkout (Orders API v2): the customer approves on paypal.com, the website captures.
 *
 * @package KalendoraPro
 */

namespace KalendoraPro;

defined( 'ABSPATH' ) || exit;

/**
 * PayPal API calls.
 */
final class PayPal {

	/**
	 * Constructor.
	 *
	 * @param string $client  Client ID.
	 * @param string $secret  Secret.
	 * @param bool   $sandbox Sandbox (test) mode.
	 */
	public function __construct( private string $client, private string $secret, private bool $sandbox ) {}

	/**
	 * API base.
	 */
	public function base(): string {
		return $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
	}

	/**
	 * Create an order.
	 *
	 * @param array<string, mixed> $p amount, currency, name, payment_id, booking_id, return_url, cancel_url, brand.
	 * @return array{id:string, url:string}|\WP_Error
	 */
	public function create_order( array $p ) {
		$data = $this->request(
			'POST',
			'/v2/checkout/orders',
			array(
				'intent'              => 'CAPTURE',
				'purchase_units'      => array(
					array(
						'reference_id' => (string) $p['payment_id'],
						'custom_id'    => (string) $p['booking_id'],
						'description'  => mb_substr( (string) $p['name'], 0, 127 ),
						'amount'       => array(
							'currency_code' => strtoupper( (string) $p['currency'] ),
							'value'         => number_format( (float) $p['amount'], 2, '.', '' ),
						),
					),
				),
				'application_context' => array(
					'brand_name'          => mb_substr( (string) $p['brand'], 0, 127 ),
					'user_action'         => 'PAY_NOW',
					'shipping_preference' => 'NO_SHIPPING',
					'return_url'          => $p['return_url'],
					'cancel_url'          => $p['cancel_url'],
				),
			),
			'kalendora-pay-' . $p['payment_id'] . '-' . $p['attempt']
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		foreach ( (array) ( $data['links'] ?? array() ) as $link ) {
			if ( in_array( $link['rel'] ?? '', array( 'approve', 'payer-action' ), true ) ) {
				return array(
					'id'  => (string) $data['id'],
					'url' => (string) $link['href'],
				);
			}
		}
		return new \WP_Error( 'kalendora_pro_paypal', __( 'PayPal did not return a payment page.', 'kalendora-pro' ) );
	}

	/**
	 * Capture an approved order.
	 *
	 * @param string $order_id Order id.
	 * @return array{paid:bool, reference:string, amount:float}|\WP_Error
	 */
	public function capture( string $order_id ) {
		if ( ! preg_match( '/^[A-Z0-9]{10,40}$/', $order_id ) ) {
			return new \WP_Error( 'kalendora_pro_paypal', __( 'Invalid payment reference.', 'kalendora-pro' ) );
		}
		$data = $this->request( 'POST', '/v2/checkout/orders/' . $order_id . '/capture', null, 'kalendora-capture-' . $order_id );
		if ( is_wp_error( $data ) ) {
			// Already captured (e.g. page reloaded): read the order instead.
			if ( str_contains( $data->get_error_message(), 'ORDER_ALREADY_CAPTURED' ) ) {
				$data = $this->request( 'GET', '/v2/checkout/orders/' . $order_id );
			}
			if ( is_wp_error( $data ) ) {
				return $data;
			}
		}
		$capture = $data['purchase_units'][0]['payments']['captures'][0] ?? array();
		return array(
			'paid'      => 'COMPLETED' === ( $data['status'] ?? '' ) && 'COMPLETED' === ( $capture['status'] ?? '' ),
			'reference' => (string) ( $capture['id'] ?? '' ),
			'amount'    => (float) ( $capture['amount']['value'] ?? 0 ),
		);
	}

	/**
	 * Refund a capture.
	 *
	 * @param string $capture_id Capture id.
	 * @param float  $amount     Amount.
	 * @param string $currency   Currency.
	 * @return true|\WP_Error
	 */
	public function refund( string $capture_id, float $amount, string $currency ) {
		$data = $this->request(
			'POST',
			'/v2/payments/captures/' . rawurlencode( $capture_id ) . '/refund',
			array(
				'amount' => array(
					'value'         => number_format( $amount, 2, '.', '' ),
					'currency_code' => strtoupper( $currency ),
				),
			),
			'kalendora-refund-' . $capture_id . '-' . number_format( $amount, 2, '.', '' )
		);
		return is_wp_error( $data ) ? $data : true;
	}

	/**
	 * OAuth access token (cached).
	 *
	 * @return string|\WP_Error
	 */
	private function token() {
		$key    = 'kalendora_pro_pp_' . md5( $this->client . $this->sandbox );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$response = wp_remote_post(
			$this->base() . '/v1/oauth2/token',
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $this->client . ':' . $this->secret ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth.
				'body'    => array( 'grant_type' => 'client_credentials' ),
			)
		);
		$data     = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
			return new \WP_Error( 'kalendora_pro_paypal', __( 'PayPal rejected the access data. Please check client ID and secret (and live/sandbox).', 'kalendora-pro' ) );
		}
		set_transient( $key, (string) $data['access_token'], max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 120 ) );
		return (string) $data['access_token'];
	}

	/**
	 * JSON request.
	 *
	 * @param string                    $method      GET|POST.
	 * @param string                    $path        Path.
	 * @param array<string, mixed>|null $body        Body.
	 * @param string                    $request_id  PayPal-Request-Id (idempotency).
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, string $path, ?array $body, string $request_id = '' ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return=representation',
		);
		if ( '' !== $request_id ) {
			$headers['PayPal-Request-Id'] = $request_id;
		}
		$response = wp_remote_request(
			$this->base() . $path,
			array(
				'method'  => $method,
				'timeout' => 20,
				'headers' => $headers,
				'body'    => null === $body ? ( 'POST' === $method ? '{}' : null ) : (string) wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'kalendora_pro_paypal_http', __( 'PayPal could not be reached. Please try again in a moment.', 'kalendora-pro' ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$issue = is_array( $data ) ? (string) ( $data['details'][0]['issue'] ?? $data['name'] ?? '' ) : '';
			/* translators: %s: error code from PayPal. */
			return new \WP_Error( 'kalendora_pro_paypal', sprintf( __( 'PayPal: %s', 'kalendora-pro' ), '' !== $issue ? $issue : (string) $code ) );
		}
		return $data;
	}
}
