<?php
/**
 * Generic CRUD repository for simple Bookora tables.
 *
 * @package Bookora
 */

namespace Bookora\Repository;

use Bookora\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions drive sanitizing, so every write goes through the same whitelist.
 *
 * Field types: string, text, email, color, int, uint, decimal, bool, json, enum, timezone.
 */
abstract class TableRepository {

	/**
	 * Unprefixed table name.
	 */
	abstract protected function table_name(): string;

	/**
	 * Field definitions: name => [type, default, options].
	 *
	 * @return array<string, array{type:string, default:mixed, options?:array<int, string>, max?:int}>
	 */
	abstract public function fields(): array;

	/**
	 * Whether the table has created_at/updated_at columns.
	 */
	protected function has_timestamps(): bool {
		return true;
	}

	/**
	 * Full table name.
	 */
	public function table(): string {
		global $wpdb;
		return Schema::table( $wpdb->prefix, $this->table_name() );
	}

	/**
	 * Find one row.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * List rows, optionally filtered by status.
	 *
	 * @param array{status?:string, ids?:int[]} $args Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( array $args = array() ): array {
		global $wpdb;
		$table  = $this->table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) && isset( $this->fields()['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}

		if ( isset( $args['ids'] ) ) {
			$ids = array_filter( array_map( 'intval', (array) $args['ids'] ) );
			if ( ! $ids ) {
				return array();
			}
			$where[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Insert a row.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return int New id (0 on failure).
	 */
	public function create( array $data ): int {
		global $wpdb;

		$row = $this->sanitize( $data + $this->defaults() );

		if ( $this->has_timestamps() ) {
			$row['created_at'] = current_time( 'mysql', true );
			$row['updated_at'] = $row['created_at'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $this->table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a row (partial).
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Raw data.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$row = $this->sanitize( array_intersect_key( $data, $this->fields() ) );
		if ( $this->has_timestamps() ) {
			$row['updated_at'] = current_time( 'mysql', true );
		}
		if ( ! $row ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update( $this->table(), $row, array( 'id' => $id ) );
	}

	/**
	 * Delete a row.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Default values of all fields.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array_map( static fn( array $f ) => $f['default'], $this->fields() );
	}

	/**
	 * Sanitize known fields; unknown keys are dropped.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @return array<string, mixed> Database-ready values.
	 */
	public function sanitize( array $data ): array {
		$out = array();

		foreach ( $this->fields() as $name => $def ) {
			if ( ! array_key_exists( $name, $data ) ) {
				continue;
			}
			$out[ $name ] = self::sanitize_value( $data[ $name ], $def );
		}

		return $out;
	}

	/**
	 * Convert database strings into proper PHP types for API output.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	public function hydrate( array $row ): array {
		$row['id'] = (int) $row['id'];

		foreach ( $this->fields() as $name => $def ) {
			if ( ! array_key_exists( $name, $row ) ) {
				continue;
			}
			switch ( $def['type'] ) {
				case 'int':
				case 'uint':
					$row[ $name ] = null === $row[ $name ] ? null : (int) $row[ $name ];
					break;
				case 'decimal':
					$row[ $name ] = (float) $row[ $name ];
					break;
				case 'bool':
					$row[ $name ] = (bool) $row[ $name ];
					break;
				case 'json':
					$decoded      = is_string( $row[ $name ] ) ? json_decode( $row[ $name ], true ) : null;
					$row[ $name ] = is_array( $decoded ) ? $decoded : $def['default'];
					break;
			}
		}

		return $row;
	}

	/**
	 * Sanitize a single value according to its definition.
	 *
	 * @param mixed                $value Raw value.
	 * @param array<string, mixed> $def   Field definition.
	 * @return mixed
	 */
	private static function sanitize_value( $value, array $def ) {
		$max = (int) ( $def['max'] ?? 191 );

		switch ( $def['type'] ) {
			case 'text':
				return sanitize_textarea_field( (string) $value );
			case 'email':
				return sanitize_email( (string) $value );
			case 'color':
				$color = sanitize_hex_color( (string) $value );
				return $color ? $color : $def['default'];
			case 'int':
				return null === $value || '' === $value ? $def['default'] : (int) $value;
			case 'uint':
				if ( null === $value || '' === $value ) {
					return $def['default'];
				}
				return min( (int) ( $def['max'] ?? PHP_INT_MAX ), max( 0, (int) $value ) );
			case 'decimal':
				return round( max( 0, (float) $value ), 2 );
			case 'bool':
				return empty( $value ) ? 0 : 1;
			case 'json':
				return wp_json_encode( is_array( $value ) ? $value : array() );
			case 'enum':
				return in_array( $value, $def['options'], true ) ? $value : $def['default'];
			case 'timezone':
				return '' === $value || in_array( $value, timezone_identifiers_list(), true ) ? (string) $value : $def['default'];
			case 'string':
			default:
				return mb_substr( sanitize_text_field( (string) $value ), 0, $max );
		}
	}
}
