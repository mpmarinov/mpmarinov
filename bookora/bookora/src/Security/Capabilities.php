<?php
/**
 * Roles and capabilities.
 *
 * @package Bookora
 */

namespace Bookora\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Fine-grained capabilities so site owners can delegate booking management safely.
 */
final class Capabilities {

	public const MANAGE_SETTINGS = 'bookora_manage_settings';
	public const MANAGE_FORMS    = 'bookora_manage_forms';
	public const MANAGE_BOOKINGS = 'bookora_manage_bookings';
	public const VIEW_BOOKINGS   = 'bookora_view_bookings';

	public const ROLE_MANAGER = 'bookora_manager';

	/**
	 * All Bookora capabilities.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::MANAGE_SETTINGS,
			self::MANAGE_FORMS,
			self::MANAGE_BOOKINGS,
			self::VIEW_BOOKINGS,
		);
	}

	/**
	 * Grant all caps to administrators and create the "Booking Manager" role.
	 */
	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		if ( ! get_role( self::ROLE_MANAGER ) ) {
			add_role(
				self::ROLE_MANAGER,
				'Booking Manager',
				array(
					'read'                => true,
					self::MANAGE_BOOKINGS => true,
					self::VIEW_BOOKINGS   => true,
				)
			);
		}
	}

	/**
	 * Remove all caps and the custom role (used on uninstall).
	 */
	public static function uninstall(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		remove_role( self::ROLE_MANAGER );
	}
}
