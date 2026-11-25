<?php
/**
 * REST: email templates.
 *
 * @package Kalendora
 */

namespace Kalendora\Rest;

use Kalendora\Notifications\Mailer;
use Kalendora\Notifications\Templates;
use Kalendora\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /emails, PUT /emails/{event}, POST /emails/{event}/reset|preview|test, GET /emails/log
 */
final class EmailsController {

	/**
	 * Constructor.
	 *
	 * @param Templates $templates Templates.
	 * @param Mailer    $mailer    Mailer.
	 */
	public function __construct(
		private Templates $templates,
		private Mailer $mailer,
	) {}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$perm  = static fn(): bool => current_user_can( Capabilities::MANAGE_SETTINGS );
		$ns    = SettingsController::NAMESPACE;
		$event = '(?P<event>[a-z_]+)';

		register_rest_route(
			$ns,
			'/emails',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/emails/' . $event,
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'save' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/emails/' . $event . '/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/emails/' . $event . '/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/emails/' . $event . '/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			$ns,
			'/emails/log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'log' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * All events with current templates.
	 */
	public function index(): WP_REST_Response {
		$labels = Templates::labels();
		$out    = array();
		foreach ( Templates::events() as $event => $def ) {
			$out[] = $this->templates->get( $event ) + array(
				'group'       => $def['group'],
				'title'       => $labels[ $event ]['title'] ?? $event,
				'description' => $labels[ $event ]['description'] ?? '',
			);
		}
		return new WP_REST_Response(
			array(
				'templates'    => $out,
				'placeholders' => Templates::placeholders(),
			)
		);
	}

	/**
	 * PUT /emails/{event}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$event = $this->event( $request );
		if ( is_wp_error( $event ) ) {
			return $event;
		}
		$subject = trim( (string) $request->get_param( 'subject' ) );
		$body    = trim( (string) $request->get_param( 'body' ) );
		if ( '' === $subject || '' === $body ) {
			return new WP_Error( 'kalendora_empty_template', __( 'Subject and text must not be empty.', 'kalendora' ), array( 'status' => 400 ) );
		}
		$this->templates->save( $event, (bool) $request->get_param( 'enabled' ), $subject, $body );
		return new WP_REST_Response( $this->templates->get( $event ) );
	}

	/**
	 * POST /emails/{event}/reset
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset( WP_REST_Request $request ) {
		$event = $this->event( $request );
		if ( is_wp_error( $event ) ) {
			return $event;
		}
		$this->templates->reset( $event );
		return new WP_REST_Response( $this->templates->get( $event ) );
	}

	/**
	 * POST /emails/{event}/preview {subject, body} → rendered HTML with sample data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ) {
		$event = $this->event( $request );
		if ( is_wp_error( $event ) ) {
			return $event;
		}
		$tpl                = array_merge(
			$this->templates->get( $event ),
			array_filter(
				array(
					'subject' => (string) $request->get_param( 'subject' ),
					'body'    => (string) $request->get_param( 'body' ),
				)
			)
		);
		[ $subject, $html ] = $this->mailer->render( $event, $tpl, $this->mailer->sample_context() );
		return new WP_REST_Response(
			array(
				'subject' => $subject,
				'html'    => $html,
			)
		);
	}

	/**
	 * POST /emails/{event}/test {to} → sends the saved template with sample data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( WP_REST_Request $request ) {
		$event = $this->event( $request );
		if ( is_wp_error( $event ) ) {
			return $event;
		}
		$to = sanitize_email( (string) $request->get_param( 'to' ) );
		if ( ! is_email( $to ) ) {
			$to = wp_get_current_user()->user_email;
		}
		$ok = $this->mailer->send( $event, $this->mailer->sample_context(), '', $to );
		if ( ! $ok ) {
			return new WP_Error( 'kalendora_mail_failed', __( 'The test email could not be sent. Please check the mail configuration of your website (e.g. with an SMTP plugin).', 'kalendora' ), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'sent' => $to ) );
	}

	/**
	 * GET /emails/log – last 50 sends.
	 */
	public function log(): WP_REST_Response {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
		$rows = (array) $wpdb->get_results( 'SELECT id, booking_id, entry_id, event, recipient, status, error, created_at FROM ' . \Kalendora\Install\Schema::table( $wpdb->prefix, 'notification_log' ) . ' ORDER BY id DESC LIMIT 50', ARRAY_A );
		return new WP_REST_Response( $rows );
	}

	/**
	 * Validate the event parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error
	 */
	private function event( WP_REST_Request $request ) {
		$event = (string) $request['event'];
		return isset( Templates::events()[ $event ] ) ? $event : new WP_Error( 'kalendora_not_found', __( 'Not found.', 'kalendora' ), array( 'status' => 404 ) );
	}
}
