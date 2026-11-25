<?php
/**
 * Resources: staff members, rooms, equipment.
 *
 * @package Kalendora
 */

namespace Kalendora\Repository;

use Kalendora\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for resources.
 */
final class ResourceRepository extends TableRepository {

	/**
	 * Free version limit. The Pro add-on lifts it via the `kalendora_resource_limit` filter.
	 */
	public const FREE_LIMIT = 3;

	/**
	 * Table.
	 */
	protected function table_name(): string {
		return 'resources';
	}

	/**
	 * Fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'type'     => array(
				'type'    => 'enum',
				'default' => 'staff',
				'options' => array( 'staff', 'room', 'equipment' ),
			),
			'name'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'email'    => array(
				'type'    => 'email',
				'default' => '',
			),
			'user_id'  => array(
				'type'    => 'uint',
				'default' => null,
			),
			'timezone' => array(
				'type'    => 'timezone',
				'default' => '',
			),
			'color'    => array(
				'type'    => 'color',
				'default' => '#2563eb',
			),
			'status'   => array(
				'type'    => 'enum',
				'default' => 'active',
				'options' => array( 'active', 'inactive' ),
			),
			'settings' => array(
				'type'    => 'json',
				'default' => array(),
			),
		);
	}

	/**
	 * Maximum number of active resources.
	 */
	public function limit(): int {
		return (int) apply_filters( 'kalendora_resource_limit', self::FREE_LIMIT );
	}

	/**
	 * Whether another resource may be created.
	 */
	public function can_create(): bool {
		return count( $this->all() ) < $this->limit();
	}

	/**
	 * Delete a resource with its schedules and assignments.
	 *
	 * @param int $id Resource id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( $wpdb->prefix, 'resource_services' ), array( 'resource_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table( $wpdb->prefix, 'schedules' ), array( 'resource_id' => $id ), array( '%d' ) );
		$wpdb->delete( Schema::table( $wpdb->prefix, 'schedule_exceptions' ), array( 'resource_id' => $id ), array( '%d' ) );
		// phpcs:enable

		return parent::delete( $id );
	}
}
