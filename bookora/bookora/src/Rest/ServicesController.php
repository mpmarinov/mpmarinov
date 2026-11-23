<?php
/**
 * REST: /bookora/v1/services
 *
 * @package Bookora
 */

namespace Bookora\Rest;

use Bookora\Repository\ServiceRepository;
use Bookora\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Services CRUD including resource assignment (`resource_ids`).
 */
final class ServicesController extends CrudController {

	/**
	 * Constructor (promotes a typed repository property).
	 *
	 * phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found
	 *
	 * @param ServiceRepository $services Services.
	 */
	public function __construct( private ServiceRepository $services ) {
		parent::__construct( $services );
	}
	// phpcs:enable Generic.CodeAnalysis.UselessOverridingMethod.Found

	/**
	 * Base.
	 */
	protected function base(): string {
		return 'services';
	}

	/**
	 * Capability.
	 */
	protected function capability(): string {
		return Capabilities::MANAGE_SETTINGS;
	}

	/**
	 * Save resource assignment.
	 *
	 * @param int                  $id   Service id.
	 * @param array<string, mixed> $data Input.
	 */
	protected function after_save( int $id, array $data ): void {
		if ( isset( $data['resource_ids'] ) && is_array( $data['resource_ids'] ) ) {
			$this->services->set_resource_ids( $id, $data['resource_ids'] );
		}
	}

	/**
	 * Add resource ids to output.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	public function prepare( array $row ): array {
		$row['resource_ids'] = $this->services->resource_ids( (int) $row['id'] );
		return $row;
	}
}
