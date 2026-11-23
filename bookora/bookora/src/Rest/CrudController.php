<?php
/**
 * Generic REST CRUD controller backed by a TableRepository.
 *
 * @package Bookora
 */

namespace Bookora\Rest;

use Bookora\Repository\TableRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers /{base} (list, create) and /{base}/{id} (read, update, delete).
 */
abstract class CrudController {

	/**
	 * Constructor.
	 *
	 * @param TableRepository $repository Repository.
	 */
	public function __construct( protected TableRepository $repository ) {}

	/**
	 * Route base, e.g. "services".
	 */
	abstract protected function base(): string;

	/**
	 * Capability required for all operations.
	 */
	abstract protected function capability(): string;

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$permission = fn(): bool => current_user_can( $this->capability() );

		register_rest_route(
			SettingsController::NAMESPACE,
			'/' . $this->base(),
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'store' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			SettingsController::NAMESPACE,
			'/' . $this->base() . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * List.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$rows   = $this->repository->all( $status ? array( 'status' => $status ) : array() );
		return new WP_REST_Response( array_map( array( $this, 'prepare' ), $rows ) );
	}

	/**
	 * Read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		$row = $this->repository->find( (int) $request['id'] );
		return $row ? new WP_REST_Response( $this->prepare( $row ) ) : $this->not_found();
	}

	/**
	 * Create.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function store( WP_REST_Request $request ) {
		$data  = (array) $request->get_json_params();
		$error = $this->validate( $data, true );
		if ( $error ) {
			return $error;
		}

		$id = $this->repository->create( $data );
		if ( ! $id ) {
			return new WP_Error( 'bookora_db_error', __( 'Could not save.', 'bookora' ), array( 'status' => 500 ) );
		}

		$this->after_save( $id, $data );
		return new WP_REST_Response( $this->prepare( (array) $this->repository->find( $id ) ), 201 );
	}

	/**
	 * Update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $this->repository->find( $id ) ) {
			return $this->not_found();
		}

		$data  = (array) $request->get_json_params();
		$error = $this->validate( $data, false );
		if ( $error ) {
			return $error;
		}

		$this->repository->update( $id, $data );
		$this->after_save( $id, $data );
		return new WP_REST_Response( $this->prepare( (array) $this->repository->find( $id ) ) );
	}

	/**
	 * Delete.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $this->repository->find( $id ) ) {
			return $this->not_found();
		}
		$this->repository->delete( $id );
		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Validate input; return an error or null.
	 *
	 * @param array<string, mixed> $data     Input.
	 * @param bool                 $creating Whether a new row is created.
	 */
	protected function validate( array $data, bool $creating ): ?WP_Error {
		if ( ( $creating || array_key_exists( 'name', $data ) ) && '' === trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) ) ) {
			return new WP_Error( 'bookora_missing_name', __( 'Please enter a name.', 'bookora' ), array( 'status' => 400 ) );
		}
		return null;
	}

	/**
	 * Hook after create/update.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Input.
	 */
	protected function after_save( int $id, array $data ): void {}

	/**
	 * Shape a row for output.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	public function prepare( array $row ): array {
		return $row;
	}

	/**
	 * 404 error.
	 */
	protected function not_found(): WP_Error {
		return new WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
	}
}
