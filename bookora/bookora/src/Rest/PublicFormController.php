<?php
/**
 * Public REST endpoint for submitting forms.
 *
 * @package Bookora
 */

namespace Bookora\Rest;

use Bookora\Forms\Submission;
use Bookora\Repository\FormRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /bookora/v1/public/forms/{id}/submit
 *
 * No nonce on purpose: pages with forms are often cached, and a stale nonce would block real visitors.
 * Protection: honeypot, signed render timestamp, rate limiting and strict server-side validation.
 */
final class PublicFormController {

	/**
	 * Constructor.
	 *
	 * @param FormRepository $forms      Forms.
	 * @param Submission     $submission Submission handler.
	 */
	public function __construct(
		private FormRepository $forms,
		private Submission $submission,
	) {}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			SettingsController::NAMESPACE,
			'/public/forms/(?P<id>\d+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle a submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( WP_REST_Request $request ) {
		$form = $this->forms->find( (int) $request['id'] );

		$preview = current_user_can( \Bookora\Security\Capabilities::MANAGE_FORMS );
		if ( ! $form || ( 'published' !== $form['status'] && ! $preview ) ) {
			return new WP_Error( 'bookora_not_found', __( 'This form is not available.', 'bookora' ), array( 'status' => 404 ) );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}

		$result = $this->submission->handle(
			$form,
			is_array( $body ) ? $body : array(),
			array(
				'ip'         => self::client_ip(),
				'user_agent' => (string) $request->get_header( 'user_agent' ),
				'source_url' => is_string( $body['_source'] ?? null ) ? $body['_source'] : '',
				'locale'     => determine_locale(),
				'timezone'   => is_string( $body['_timezone'] ?? null ) ? $body['_timezone'] : '',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = new WP_REST_Response(
			array(
				'success'  => true,
				'message'  => wp_kses_post( wpautop( $result['message'] ) ),
				'redirect' => esc_url_raw( $result['redirect'] ),
			),
			201
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Visitor IP. Proxy headers are only trusted when explicitly enabled.
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Header to read the client IP from when the site runs behind a trusted proxy (e.g. "HTTP_CF_CONNECTING_IP").
		 *
		 * @param string $header Server variable name or empty string.
		 */
		$header = (string) apply_filters( 'bookora_trusted_ip_header', '' );
		if ( '' !== $header && ! empty( $_SERVER[ $header ] ) ) {
			// X-Forwarded-For style lists: the right-most entry was added by the trusted proxy,
			// entries further left come from the client and can be faked.
			$forwarded = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
			$ip        = trim( (string) end( $forwarded ) );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
