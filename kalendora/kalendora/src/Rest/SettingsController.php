<?php
/**
 * REST endpoint for global settings.
 *
 * @package Kalendora
 */

namespace Kalendora\Rest;

use Kalendora\Security\Capabilities;
use Kalendora\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /wp-json/kalendora/v1/settings
 */
final class SettingsController {

	public const NAMESPACE = 'kalendora/v1';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	/**
	 * Permission check. Cookie auth requires the wp_rest nonce, which core validates.
	 */
	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE_SETTINGS );
	}

	/**
	 * GET handler.
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->settings->all() );
	}

	/**
	 * POST/PUT/PATCH handler.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		// Unknown keys are dropped by Settings::sanitize().
		return new WP_REST_Response( $this->settings->update( $params ) );
	}
}
