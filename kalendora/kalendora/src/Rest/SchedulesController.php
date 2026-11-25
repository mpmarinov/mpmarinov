<?php
/**
 * REST: opening hours and exceptions.
 *
 * @package Kalendora
 */

namespace Kalendora\Rest;

use Kalendora\Repository\ResourceRepository;
use Kalendora\Repository\ScheduleRepository;
use Kalendora\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET/PUT /schedules/weekly?resource_id=   (omit resource_id for the global schedule)
 * GET/POST /schedules/exceptions, DELETE /schedules/exceptions/{id}
 */
final class SchedulesController {

	/**
	 * Constructor.
	 *
	 * @param ScheduleRepository $schedules Schedules.
	 * @param ResourceRepository $resources Resources.
	 */
	public function __construct(
		private ScheduleRepository $schedules,
		private ResourceRepository $resources,
	) {}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$permission = static fn(): bool => current_user_can( Capabilities::MANAGE_SETTINGS );

		register_rest_route(
			SettingsController::NAMESPACE,
			'/schedules/weekly',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_weekly' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save_weekly' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			SettingsController::NAMESPACE,
			'/schedules/exceptions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_exceptions' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_exception' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			SettingsController::NAMESPACE,
			'/schedules/exceptions/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_exception' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * GET weekly hours.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_weekly( WP_REST_Request $request ) {
		$resource_id = $this->resource_id( $request );
		if ( is_wp_error( $resource_id ) ) {
			return $resource_id;
		}

		$own = $this->schedules->weekly( $resource_id, false );

		return new WP_REST_Response(
			array(
				'resource_id' => $resource_id,
				'inherits'    => null !== $resource_id && ! $own,
				'weekly'      => (object) $this->schedules->weekly( $resource_id ),
			)
		);
	}

	/**
	 * PUT weekly hours. An empty object for a resource means "use the global schedule".
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_weekly( WP_REST_Request $request ) {
		$resource_id = $this->resource_id( $request );
		if ( is_wp_error( $resource_id ) ) {
			return $resource_id;
		}

		$weekly = $request->get_json_params()['weekly'] ?? array();
		$this->schedules->save_weekly( $resource_id, is_array( $weekly ) ? $weekly : array() );

		return $this->get_weekly( $request );
	}

	/**
	 * GET exceptions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_exceptions( WP_REST_Request $request ) {
		$resource_id = $this->resource_id( $request );
		if ( is_wp_error( $resource_id ) ) {
			return $resource_id;
		}

		$from = (string) ( $request->get_param( 'from' ) ?? gmdate( 'Y-m-d', strtotime( '-30 days' ) ) );
		$to   = (string) ( $request->get_param( 'to' ) ?? '9999-12-31' );

		return new WP_REST_Response( $this->schedules->exceptions( $resource_id, $from, $to ) );
	}

	/**
	 * POST exception.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_exception( WP_REST_Request $request ) {
		$data = (array) $request->get_json_params();

		if ( ! empty( $data['resource_id'] ) && ! $this->resources->find( (int) $data['resource_id'] ) ) {
			return new WP_Error( 'kalendora_not_found', __( 'Not found.', 'kalendora' ), array( 'status' => 404 ) );
		}

		$id = $this->schedules->create_exception( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return new WP_REST_Response( array( 'id' => $id ), 201 );
	}

	/**
	 * DELETE exception.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_exception( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'deleted' => $this->schedules->delete_exception( (int) $request['id'] ) ) );
	}

	/**
	 * Read and validate the optional resource_id parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int|null|WP_Error
	 */
	private function resource_id( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'resource_id' );
		if ( ! $id ) {
			return null;
		}
		return $this->resources->find( $id ) ? $id : new WP_Error( 'kalendora_not_found', __( 'Not found.', 'kalendora' ), array( 'status' => 404 ) );
	}
}
