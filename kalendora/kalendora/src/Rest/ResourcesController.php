<?php
/**
 * REST: /kalendora/v1/resources
 *
 * @package Kalendora
 */

namespace Kalendora\Rest;

use Kalendora\Repository\ResourceRepository;
use Kalendora\Security\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Staff, rooms and equipment. Enforces the free-version limit on creation.
 */
final class ResourcesController extends CrudController {

	/**
	 * Constructor (promotes a typed repository property).
	 *
	 * phpcs:disable Generic.CodeAnalysis.UselessOverridingMethod.Found
	 *
	 * @param ResourceRepository $resources Resources.
	 */
	public function __construct( private ResourceRepository $resources ) {
		parent::__construct( $resources );
	}
	// phpcs:enable Generic.CodeAnalysis.UselessOverridingMethod.Found

	/**
	 * Base.
	 */
	protected function base(): string {
		return 'resources';
	}

	/**
	 * Capability.
	 */
	protected function capability(): string {
		return Capabilities::MANAGE_SETTINGS;
	}

	/**
	 * Validate, including the resource limit.
	 *
	 * @param array<string, mixed> $data     Input.
	 * @param bool                 $creating Creating.
	 */
	protected function validate( array $data, bool $creating ): ?WP_Error {
		if ( $creating && ! $this->resources->can_create() ) {
			return new WP_Error(
				'kalendora_resource_limit',
				sprintf(
					/* translators: %d: maximum number of staff members/resources. */
					__( 'The free version supports up to %d staff members or resources. Upgrade to Kalendora Pro for unlimited resources.', 'kalendora' ),
					$this->resources->limit()
				),
				array( 'status' => 403 )
			);
		}

		if ( ! empty( $data['email'] ) && ! is_email( $data['email'] ) ) {
			return new WP_Error( 'kalendora_invalid_email', __( 'Please enter a valid email address.', 'kalendora' ), array( 'status' => 400 ) );
		}

		return parent::validate( $data, $creating );
	}
}
