<?php
/**
 * Services (bookable offers).
 *
 * @package Bookora
 */

namespace Bookora\Repository;

use Bookora\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for services plus the resource assignment.
 */
final class ServiceRepository extends TableRepository {

	/**
	 * Table.
	 */
	protected function table_name(): string {
		return 'services';
	}

	/**
	 * Fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'name'          => array(
				'type'    => 'string',
				'default' => '',
			),
			'description'   => array(
				'type'    => 'text',
				'default' => '',
			),
			'duration'      => array(
				'type'    => 'uint',
				'default' => 60,
				'max'     => 1440,
			),
			'buffer_before' => array(
				'type'    => 'uint',
				'default' => 0,
				'max'     => 1440,
			),
			'buffer_after'  => array(
				'type'    => 'uint',
				'default' => 0,
				'max'     => 1440,
			),
			'slot_interval' => array(
				'type'    => 'uint',
				'default' => 0,
				'max'     => 720,
			),
			'capacity'      => array(
				'type'    => 'uint',
				'default' => 1,
				'max'     => 10000,
			),
			'price'         => array(
				'type'    => 'decimal',
				'default' => 0,
			),
			'color'         => array(
				'type'    => 'color',
				'default' => '#2563eb',
			),
			'min_notice'    => array(
				'type'    => 'uint',
				'default' => 0,
				'max'     => 525600,
			),
			'max_advance'   => array(
				'type'    => 'uint',
				'default' => 0,
				'max'     => 1095,
			),
			'status'        => array(
				'type'    => 'enum',
				'default' => 'active',
				'options' => array( 'active', 'inactive' ),
			),
			'settings'      => array(
				'type'    => 'json',
				'default' => array(),
			),
		);
	}

	/**
	 * Resource ids assigned to a service.
	 *
	 * @param int $service_id Service id.
	 * @return int[]
	 */
	public function resource_ids( int $service_id ): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'resource_services' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT resource_id FROM {$table} WHERE service_id = %d ORDER BY resource_id", $service_id ) ) );
	}

	/**
	 * Replace the resources assigned to a service.
	 *
	 * @param int   $service_id   Service id.
	 * @param int[] $resource_ids Resource ids.
	 */
	public function set_resource_ids( int $service_id, array $resource_ids ): void {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'resource_services' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'service_id' => $service_id ), array( '%d' ) );
		foreach ( array_unique( array_filter( array_map( 'intval', $resource_ids ) ) ) as $resource_id ) {
			$wpdb->insert(
				$table,
				array(
					'resource_id' => $resource_id,
					'service_id'  => $service_id,
				),
				array( '%d', '%d' )
			);
		}
		// phpcs:enable
	}

	/**
	 * Delete a service and its assignments.
	 *
	 * @param int $id Service id.
	 */
	public function delete( int $id ): bool {
		$this->set_resource_ids( $id, array() );
		return parent::delete( $id );
	}
}
