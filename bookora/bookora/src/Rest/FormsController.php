<?php
/**
 * REST: /bookora/v1/forms
 *
 * @package Bookora
 */

namespace Bookora\Rest;

use Bookora\Forms\FieldRegistry;
use Bookora\Repository\FormRepository;
use Bookora\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Forms CRUD plus duplicate. List output is slim (no field definitions).
 */
final class FormsController extends CrudController {

	/**
	 * Constructor (promotes a typed repository property).
	 *
	 * phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found
	 *
	 * @param FormRepository $forms Forms.
	 */
	public function __construct( private FormRepository $forms ) {
		parent::__construct( $forms );
	}
	// phpcs:enable Generic.CodeAnalysis.UselessOverridingMethod.Found

	/**
	 * Base.
	 */
	protected function base(): string {
		return 'forms';
	}

	/**
	 * Capability.
	 */
	protected function capability(): string {
		return Capabilities::MANAGE_FORMS;
	}

	/**
	 * Register CRUD routes plus /forms/{id}/duplicate and /forms/field-types.
	 */
	public function register_routes(): void {
		parent::register_routes();

		register_rest_route(
			SettingsController::NAMESPACE,
			'/forms/(?P<id>\d+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate' ),
				'permission_callback' => fn(): bool => current_user_can( $this->capability() ),
			)
		);

		register_rest_route(
			SettingsController::NAMESPACE,
			'/forms/field-types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static fn() => new WP_REST_Response( FieldRegistry::types() ),
				'permission_callback' => fn(): bool => current_user_can( $this->capability() ),
			)
		);
	}

	/**
	 * Slim list with entry counts.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$counts = $this->forms->entry_counts();
		$rows   = array_map(
			static fn( array $form ): array => array(
				'id'          => $form['id'],
				'title'       => $form['title'],
				'type'        => $form['type'],
				'status'      => $form['status'],
				'field_count' => count( $form['fields'] ),
				'entries'     => $counts[ $form['id'] ] ?? 0,
				'updated_at'  => $form['updated_at'],
			),
			$this->forms->all()
		);
		return new WP_REST_Response( $rows );
	}

	/**
	 * Validate.
	 *
	 * @param array<string, mixed> $data     Input.
	 * @param bool                 $creating Creating.
	 */
	protected function validate( array $data, bool $creating ): ?WP_Error {
		if ( ( $creating || array_key_exists( 'title', $data ) ) && '' === trim( sanitize_text_field( (string) ( $data['title'] ?? '' ) ) ) ) {
			return new WP_Error( 'bookora_missing_title', __( 'Please enter a form title.', 'bookora' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['fields'] ) && ! is_array( $data['fields'] ) ) {
			return new WP_Error( 'bookora_invalid_fields', __( 'Invalid form fields.', 'bookora' ), array( 'status' => 400 ) );
		}
		return null;
	}

	/**
	 * Add shortcode and capability info to the single-form output.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	public function prepare( array $row ): array {
		$row['settings']  = FormRepository::sanitize_settings( $row['settings'] ?? array() );
		$row['shortcode'] = sprintf( '[bookora id="%d"]', $row['id'] );
		return $row;
	}

	/**
	 * POST /forms/{id}/duplicate
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate( WP_REST_Request $request ) {
		$id = $this->forms->duplicate( (int) $request['id'] );
		return $id ? new WP_REST_Response( $this->prepare( (array) $this->forms->find( $id ) ), 201 ) : $this->not_found();
	}
}
