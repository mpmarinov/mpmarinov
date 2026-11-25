<?php
/**
 * Public REST endpoint for submitting forms.
 *
 * @package Kalendora
 */

namespace Kalendora\Rest;

use Kalendora\Forms\FileStore;
use Kalendora\Forms\Submission;
use Kalendora\Repository\FormRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /kalendora/v1/public/forms/{id}/submit
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
	 * @param FileStore|null $files      File storage.
	 */
	public function __construct(
		private FormRepository $forms,
		private Submission $submission,
		private ?FileStore $files = null,
	) {}

	/**
	 * Uploads per IP (or IPv6 /64) per hour.
	 */
	public const UPLOAD_LIMIT = 30;

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
		register_rest_route(
			SettingsController::NAMESPACE,
			'/public/forms/(?P<id>\d+)/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Upload one file for a file field (multipart: file, field, _bk_t). Returns a token that the
	 * form sends with the submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		$form    = $this->forms->find( (int) $request['id'] );
		$preview = current_user_can( \Kalendora\Security\Capabilities::MANAGE_FORMS );
		if ( ! $form || ( 'published' !== $form['status'] && ! $preview ) ) {
			return new WP_Error( 'kalendora_not_found', __( 'This form is not available.', 'kalendora' ), array( 'status' => 404 ) );
		}
		if ( ! Submission::valid_render_token( $request->get_param( '_bk_t' ), (int) $form['id'] ) ) {
			return new WP_Error( 'kalendora_spam', __( 'The form has expired. Please reload the page and try again.', 'kalendora' ), array( 'status' => 400 ) );
		}

		$key   = sanitize_key( (string) $request->get_param( 'field' ) );
		$field = null;
		foreach ( (array) $form['fields'] as $f ) {
			if ( 'file' === ( $f['type'] ?? '' ) && ( $f['key'] ?? '' ) === $key ) {
				$field = $f;
				break;
			}
		}
		if ( ! $field ) {
			return new WP_Error( 'kalendora_invalid_field', __( 'Invalid field.', 'kalendora' ), array( 'status' => 400 ) );
		}

		$ip     = self::client_ip();
		$bucket = Submission::ip_bucket( $ip );
		if ( '' !== $bucket ) {
			$rl    = 'kalendora_rl_up_' . substr( hash( 'sha256', $bucket . wp_salt( 'nonce' ) ), 0, 32 );
			$count = (int) get_transient( $rl );
			/**
			 * Max. uploads per IP per hour.
			 *
			 * @param int $limit Limit.
			 */
			if ( $count >= (int) apply_filters( 'kalendora_upload_limit', self::UPLOAD_LIMIT ) ) {
				return new WP_Error( 'kalendora_rate_limited', __( 'Too many uploads. Please try again later.', 'kalendora' ), array( 'status' => 429 ) );
			}
			set_transient( $rl, $count + 1, HOUR_IN_SECONDS );
		}

		$file = $request->get_file_params()['file'] ?? null;
		if ( ! is_array( $file ) || is_array( $file['name'] ?? null ) ) {
			return new WP_Error( 'kalendora_upload_failed', __( 'The file could not be uploaded. Please try again.', 'kalendora' ), array( 'status' => 400 ) );
		}

		$result = ( $this->files ?? new FileStore() )->store_upload( $form, $field, $file, $ip );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		unset( $result['id'] );
		$response = new WP_REST_Response( $result, 201 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Handle a submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit( WP_REST_Request $request ) {
		$form = $this->forms->find( (int) $request['id'] );

		$preview = current_user_can( \Kalendora\Security\Capabilities::MANAGE_FORMS );
		if ( ! $form || ( 'published' !== $form['status'] && ! $preview ) ) {
			return new WP_Error( 'kalendora_not_found', __( 'This form is not available.', 'kalendora' ), array( 'status' => 404 ) );
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
		$header = (string) apply_filters( 'kalendora_trusted_ip_header', '' );
		if ( '' !== $header && ! empty( $_SERVER[ $header ] ) ) {
			// X-Forwarded-For style lists: the right-most entry was added by the trusted proxy,
			// entries further left come from the client and can be faked.
			$forwarded = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
			$ip        = trim( (string) end( $forwarded ) );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
