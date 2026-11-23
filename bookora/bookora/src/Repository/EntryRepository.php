<?php
/**
 * Read and manage form entries and bookings for the admin.
 *
 * @package Bookora
 */

namespace Bookora\Repository;

use Bookora\Install\Schema;

defined( 'ABSPATH' ) || exit;

// Table names come from Schema::table() (never from input); every user value goes through
// $wpdb->prepare() placeholders, which the sniffs cannot follow through built-up WHERE strings.
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

/**
 * Queries for the "Entries" and "Bookings" admin screens.
 */
final class EntryRepository {

	public const ENTRY_STATUSES = array( 'new', 'read', 'starred', 'spam', 'trash' );

	/**
	 * Allowed booking status transitions.
	 */
	public const TRANSITIONS = array(
		'pending'   => array( 'confirmed', 'rejected', 'cancelled' ),
		'confirmed' => array( 'cancelled', 'completed', 'no_show' ),
		'completed' => array( 'no_show', 'confirmed' ),
		'no_show'   => array( 'completed', 'confirmed' ),
		'rejected'  => array( 'pending' ),
		'cancelled' => array( 'pending' ),
	);

	/**
	 * Table helper.
	 *
	 * @param string $name Table.
	 */
	private static function t( string $name ): string {
		global $wpdb;
		return Schema::table( $wpdb->prefix, $name );
	}

	/**
	 * List entries of standard forms (and optionally booking forms).
	 *
	 * @param array{form_id?:int, status?:string, search?:string, page?:int, per_page?:int, with_bookings?:bool} $args Filters.
	 * @return array{items: array<int, array<string, mixed>>, total: int, counts: array<string, int>}
	 */
	public function entries( array $args ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( empty( $args['with_bookings'] ) ) {
			$where[] = 'b.id IS NULL';
		}
		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'e.form_id = %d';
			$params[] = (int) $args['form_id'];
		}

		$base_where  = implode( ' AND ', $where );
		$base_params = $params;

		$status = (string) ( $args['status'] ?? '' );
		if ( in_array( $status, self::ENTRY_STATUSES, true ) ) {
			$where[]  = 'e.status = %s';
			$params[] = $status;
		} else {
			$where[] = "e.status NOT IN ('spam','trash')";
		}

		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( c.email LIKE %s OR c.name LIKE %s OR EXISTS ( SELECT 1 FROM ' . self::t( 'entry_values' ) . ' sv WHERE sv.entry_id = e.id AND sv.value LIKE %s ) )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$from     = self::t( 'entries' ) . ' e LEFT JOIN ' . self::t( 'customers' ) . ' c ON c.id = e.customer_id LEFT JOIN ' . self::t( 'bookings' ) . ' b ON b.entry_id = e.id LEFT JOIN ' . self::t( 'forms' ) . ' f ON f.id = e.form_id';
		$sql      = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$sql}", $params ) : "SELECT COUNT(*) FROM {$from} WHERE {$sql}" );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.uid, e.form_id, e.status, e.created_at, c.name, c.email, c.phone, f.title AS form_title, b.id AS booking_id
				FROM {$from} WHERE {$sql} ORDER BY e.id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		$count_rows = (array) $wpdb->get_results(
			$base_params ? $wpdb->prepare( "SELECT e.status, COUNT(*) AS n FROM {$from} WHERE {$base_where} GROUP BY e.status", $base_params ) : "SELECT e.status, COUNT(*) AS n FROM {$from} WHERE {$base_where} GROUP BY e.status",
			ARRAY_A
		);

		$counts = array_fill_keys( self::ENTRY_STATUSES, 0 );
		foreach ( $count_rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['n'];
		}

		$previews = $this->previews( array_map( 'intval', array_column( $rows, 'id' ) ) );

		$items = array_map(
			static fn( array $r ): array => array(
				'id'         => (int) $r['id'],
				'form_id'    => (int) $r['form_id'],
				'form_title' => (string) $r['form_title'],
				'status'     => $r['status'],
				'created_at' => $r['created_at'],
				'name'       => (string) $r['name'],
				'email'      => (string) $r['email'],
				'phone'      => (string) $r['phone'],
				'preview'    => $previews[ (int) $r['id'] ] ?? '',
				'booking_id' => $r['booking_id'] ? (int) $r['booking_id'] : null,
			),
			$rows
		);

		return array(
			'items'  => $items,
			'total'  => $total,
			'counts' => $counts,
		);
	}

	/**
	 * Bookings for the admin list.
	 *
	 * @param array{view?:string, from?:string, to?:string, service_id?:int, resource_id?:int, search?:string, page?:int, per_page?:int} $args Filters.
	 * @return array{items: array<int, array<string, mixed>>, total: int, counts: array<string, int>}
	 */
	public function bookings( array $args ): array {
		global $wpdb;

		$now    = current_time( 'mysql', true );
		$views  = array(
			'pending'  => "b.status = 'pending'",
			'upcoming' => "b.status = 'confirmed' AND b.end_utc >= '" . esc_sql( $now ) . "'",
			'past'     => "b.status IN ('confirmed','completed','no_show') AND b.end_utc < '" . esc_sql( $now ) . "'",
			'closed'   => "b.status IN ('rejected','cancelled')",
			'all'      => '1=1',
		);
		$view   = isset( $views[ $args['view'] ?? '' ] ) ? $args['view'] : 'pending';
		$where  = array( $views[ $view ] );
		$params = array();

		if ( ! empty( $args['service_id'] ) ) {
			$where[]  = 'b.service_id = %d';
			$params[] = (int) $args['service_id'];
		}
		if ( ! empty( $args['resource_id'] ) ) {
			$where[]  = 'b.resource_id = %d';
			$params[] = (int) $args['resource_id'];
		}
		foreach ( array(
			'from' => '>=',
			'to'   => '<',
		) as $key => $op ) {
			if ( ! empty( $args[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args[ $key ] ) ) {
				$where[]  = "b.start_utc {$op} %s";
				$params[] = $args[ $key ] . ' 00:00:00';
			}
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( c.email LIKE %s OR c.name LIKE %s OR c.phone LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$order    = in_array( $view, array( 'past', 'closed', 'all' ), true ) ? 'DESC' : 'ASC';
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$from     = self::t( 'bookings' ) . ' b LEFT JOIN ' . self::t( 'customers' ) . ' c ON c.id = b.customer_id LEFT JOIN ' . self::t( 'services' ) . ' s ON s.id = b.service_id LEFT JOIN ' . self::t( 'resources' ) . ' r ON r.id = b.resource_id';
		$sql      = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$sql}", $params ) : "SELECT COUNT(*) FROM {$from} WHERE {$sql}" );
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, c.name, c.email, c.phone, s.name AS service_name, s.color AS service_color, r.name AS resource_name
				FROM {$from} WHERE {$sql} ORDER BY b.start_utc {$order}, b.id {$order} LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( $views as $key => $condition ) {
			$counts[ $key ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from} WHERE {$condition}" );
		}

		return array(
			'items'  => array_map( array( self::class, 'format_booking' ), $rows ),
			'total'  => $total,
			'counts' => $counts,
		);
	}

	/**
	 * Full detail of one entry, with labeled values and the booking (if any).
	 *
	 * @param int $entry_id Entry id.
	 * @return array<string, mixed>|null
	 */
	public function entry( int $entry_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT e.*, c.name, c.email, c.phone, f.title AS form_title, f.fields AS form_fields FROM ' . self::t( 'entries' ) . ' e
				LEFT JOIN ' . self::t( 'customers' ) . ' c ON c.id = e.customer_id LEFT JOIN ' . self::t( 'forms' ) . ' f ON f.id = e.form_id WHERE e.id = %d',
				$entry_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		$values  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT field_key, value FROM ' . self::t( 'entry_values' ) . ' WHERE entry_id = %d ORDER BY id', $entry_id ), ARRAY_A );
		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT b.*, c.name, c.email, c.phone, s.name AS service_name, s.color AS service_color, r.name AS resource_name FROM ' . self::t( 'bookings' ) . ' b
				LEFT JOIN ' . self::t( 'customers' ) . ' c ON c.id = b.customer_id LEFT JOIN ' . self::t( 'services' ) . ' s ON s.id = b.service_id LEFT JOIN ' . self::t( 'resources' ) . ' r ON r.id = b.resource_id WHERE b.entry_id = %d',
				$entry_id
			),
			ARRAY_A
		);

		$fields = json_decode( (string) $row['form_fields'], true );
		$labels = array();
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			if ( ! empty( $field['key'] ) ) {
				$labels[ $field['key'] ] = array(
					'label'   => (string) ( $field['label'] ?? $field['key'] ),
					'type'    => (string) $field['type'],
					'options' => array_column( (array) ( $field['options'] ?? array() ), 'label', 'value' ),
				);
			}
		}

		$out = array();
		foreach ( $values as $v ) {
			$meta  = $labels[ $v['field_key'] ] ?? array(
				'label'   => $v['field_key'],
				'type'    => 'text',
				'options' => array(),
			);
			$out[] = array(
				'key'   => $v['field_key'],
				'label' => $meta['label'],
				'type'  => $meta['type'],
				'value' => self::display_value( (string) $v['value'], $meta ),
			);
		}

		return array(
			'id'         => (int) $row['id'],
			'uid'        => $row['uid'],
			'form_id'    => (int) $row['form_id'],
			'form_title' => (string) $row['form_title'],
			'status'     => $row['status'],
			'created_at' => $row['created_at'],
			'name'       => (string) $row['name'],
			'email'      => (string) $row['email'],
			'phone'      => (string) $row['phone'],
			'source_url' => (string) $row['source_url'],
			'values'     => $out,
			'booking'    => $booking ? self::format_booking( $booking ) : null,
		);
	}

	/**
	 * Change entry status.
	 *
	 * @param int[]  $ids    Entry ids.
	 * @param string $status New status.
	 */
	public function set_entry_status( array $ids, string $status ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! in_array( $status, self::ENTRY_STATUSES, true ) ) {
			return 0;
		}
		$in = implode( ',', $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'entries' ) . " SET status = %s, updated_at = %s WHERE id IN ({$in})", $status, current_time( 'mysql', true ) ) );
	}

	/**
	 * Permanently delete entries that are in the trash (values included). Bookings are kept.
	 *
	 * @param int[] $ids Entry ids.
	 */
	public function delete_entries( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$in      = implode( ',', $ids );
		$trashed = array_map( 'intval', (array) $wpdb->get_col( 'SELECT e.id FROM ' . self::t( 'entries' ) . ' e LEFT JOIN ' . self::t( 'bookings' ) . " b ON b.entry_id = e.id WHERE e.id IN ({$in}) AND e.status = 'trash' AND b.id IS NULL" ) );
		if ( ! $trashed ) {
			return 0;
		}
		$in = implode( ',', $trashed );
		$wpdb->query( 'DELETE FROM ' . self::t( 'entry_values' ) . " WHERE entry_id IN ({$in})" );
		$deleted = (int) $wpdb->query( 'DELETE FROM ' . self::t( 'entries' ) . " WHERE id IN ({$in})" );
		return $deleted;
	}

	/**
	 * Change booking status if the transition is allowed.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $status     New status.
	 * @return array<string, mixed>|\WP_Error Updated booking.
	 */
	public function set_booking_status( int $booking_id, string $status ) {
		global $wpdb;

		$current = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . self::t( 'bookings' ) . ' WHERE id = %d', $booking_id ) );
		if ( '' === $current ) {
			return new \WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
		}
		if ( $current === $status ) {
			return $this->booking( $booking_id );
		}
		if ( ! in_array( $status, self::TRANSITIONS[ $current ] ?? array(), true ) ) {
			return new \WP_Error( 'bookora_invalid_transition', __( 'This status change is not possible.', 'bookora' ), array( 'status' => 409 ) );
		}

		$wpdb->update(
			self::t( 'bookings' ),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $booking_id )
		);

		/**
		 * Fires after a booking status changed (emails, calendar sync, webhooks hook in here).
		 *
		 * @param int    $booking_id Booking id.
		 * @param string $status     New status.
		 * @param string $current    Previous status.
		 */
		do_action( 'bookora_booking_status_changed', $booking_id, $status, $current );

		return $this->booking( $booking_id );
	}

	/**
	 * Update internal notes of a booking.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $notes      Notes.
	 */
	public function set_booking_notes( int $booking_id, string $notes ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false !== $wpdb->update(
			self::t( 'bookings' ),
			array(
				'notes'      => sanitize_textarea_field( $notes ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $booking_id )
		);
	}

	/**
	 * One booking, formatted.
	 *
	 * @param int $booking_id Booking id.
	 * @return array<string, mixed>|null
	 */
	public function booking( int $booking_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal tables.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT b.*, c.name, c.email, c.phone, s.name AS service_name, s.color AS service_color, r.name AS resource_name FROM ' . self::t( 'bookings' ) . ' b LEFT JOIN ' . self::t( 'customers' ) . ' c ON c.id = b.customer_id LEFT JOIN ' . self::t( 'services' ) . ' s ON s.id = b.service_id LEFT JOIN ' . self::t( 'resources' ) . ' r ON r.id = b.resource_id WHERE b.id = %d', $booking_id ), ARRAY_A );
		return $row ? self::format_booking( $row ) : null;
	}

	/**
	 * One booking by its public uid.
	 *
	 * @param string $uid Booking uid.
	 * @return array<string, mixed>|null
	 */
	public function booking_by_uid( string $uid ): ?array {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'bookings' ) . ' WHERE uid = %s', $uid ) );
		return $id ? $this->booking( $id ) : null;
	}

	/**
	 * Counters for the admin menu.
	 *
	 * @return array{pending:int, new_entries:int}
	 */
	public function badge_counts(): array {
		global $wpdb;
		$pending = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'bookings' ) . " WHERE status = 'pending'" );
		$new     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t( 'entries' ) . ' e LEFT JOIN ' . self::t( 'bookings' ) . " b ON b.entry_id = e.id WHERE e.status = 'new' AND b.id IS NULL" );
		return array(
			'pending'     => $pending,
			'new_entries' => $new,
		);
	}

	/**
	 * Rows for CSV export of a form.
	 *
	 * @param int $form_id Form id.
	 * @return array{0: string[], 1: array<int, array<int, string>>} Header and rows.
	 */
	public function export( int $form_id ): array {
		global $wpdb;

		$form    = $wpdb->get_row( $wpdb->prepare( 'SELECT fields FROM ' . self::t( 'forms' ) . ' WHERE id = %d', $form_id ), ARRAY_A );
		$entries = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT e.id, e.created_at, e.status, b.start_utc, b.status AS booking_status FROM ' . self::t( 'entries' ) . ' e LEFT JOIN ' . self::t( 'bookings' ) . " b ON b.entry_id = e.id WHERE e.form_id = %d AND e.status <> 'trash' ORDER BY e.id", $form_id ), ARRAY_A );
		$values  = array();
		if ( $entries ) {
			$in = implode( ',', array_map( 'intval', array_column( $entries, 'id' ) ) );
			foreach ( (array) $wpdb->get_results( 'SELECT entry_id, field_key, value FROM ' . self::t( 'entry_values' ) . " WHERE entry_id IN ({$in})", ARRAY_A ) as $v ) {
				$values[ (int) $v['entry_id'] ][ $v['field_key'] ] = $v['value'];
			}
		}

		$fields = array_filter( (array) json_decode( (string) ( $form['fields'] ?? '' ), true ), static fn( $f ) => ! empty( $f['key'] ) && ! in_array( $f['type'], array( 'service', 'resource', 'appointment' ), true ) );
		$tz     = wp_timezone();
		$header = array_merge( array( __( 'ID', 'bookora' ), __( 'Submitted', 'bookora' ), __( 'Status', 'bookora' ), __( 'Appointment', 'bookora' ), __( 'Booking status', 'bookora' ) ), array_map( static fn( $f ) => (string) ( $f['label'] ?? $f['key'] ), $fields ) );
		$rows   = array();

		foreach ( $entries as $e ) {
			$row = array(
				(string) $e['id'],
				self::local( $e['created_at'], $tz ),
				$e['status'],
				$e['start_utc'] ? self::local( $e['start_utc'], $tz ) : '',
				(string) $e['booking_status'],
			);
			foreach ( $fields as $f ) {
				$row[] = self::display_value(
					(string) ( $values[ (int) $e['id'] ][ $f['key'] ] ?? '' ),
					array(
						'type'    => $f['type'],
						'options' => array_column( (array) ( $f['options'] ?? array() ), 'label', 'value' ),
					)
				);
			}
			$rows[] = array_map( array( self::class, 'csv_safe' ), $row );
		}

		return array( array_map( array( self::class, 'csv_safe' ), $header ), $rows );
	}

	/**
	 * Prevent spreadsheet formula injection.
	 *
	 * @param string $value Cell value.
	 */
	public static function csv_safe( string $value ): string {
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Booking row for the API (times in UTC ISO and local).
	 *
	 * @param array<string, mixed> $b Row.
	 * @return array<string, mixed>
	 */
	private static function format_booking( array $b ): array {
		$tz    = wp_timezone();
		$start = new \DateTimeImmutable( $b['start_utc'], new \DateTimeZone( 'UTC' ) );
		$end   = new \DateTimeImmutable( $b['end_utc'], new \DateTimeZone( 'UTC' ) );

		return array(
			'id'            => (int) $b['id'],
			'uid'           => (string) ( $b['uid'] ?? '' ),
			'entry_id'      => $b['entry_id'] ? (int) $b['entry_id'] : null,
			'status'        => $b['status'],
			'start'         => $start->format( 'c' ),
			'end'           => $end->format( 'c' ),
			'date'          => $start->setTimezone( $tz )->format( 'Y-m-d' ),
			'time'          => $start->setTimezone( $tz )->format( 'H:i' ),
			'end_time'      => $end->setTimezone( $tz )->format( 'H:i' ),
			'seats'         => (int) $b['seats'],
			'notes'         => (string) $b['notes'],
			'service_id'    => $b['service_id'] ? (int) $b['service_id'] : null,
			'service_name'  => (string) ( $b['service_name'] ?? '' ),
			'service_color' => (string) ( $b['service_color'] ?? '#2563eb' ),
			'resource_name' => (string) ( $b['resource_name'] ?? '' ),
			'name'          => (string) ( $b['name'] ?? '' ),
			'email'         => (string) ( $b['email'] ?? '' ),
			'phone'         => (string) ( $b['phone'] ?? '' ),
			'created_at'    => $b['created_at'],
			'transitions'   => self::TRANSITIONS[ $b['status'] ] ?? array(),
		);
	}

	/**
	 * Short preview text per entry (first two non-empty values).
	 *
	 * @param int[] $ids Entry ids.
	 * @return array<int, string>
	 */
	private function previews( array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', $ids );
		$out = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		foreach ( (array) $wpdb->get_results( 'SELECT entry_id, value FROM ' . self::t( 'entry_values' ) . " WHERE entry_id IN ({$in}) ORDER BY id", ARRAY_A ) as $v ) {
			$id   = (int) $v['entry_id'];
			$text = self::display_value(
				(string) $v['value'],
				array(
					'type'    => 'text',
					'options' => array(),
				)
			);
			if ( '' === $text || '1' === $text || mb_strlen( $out[ $id ] ?? '' ) > 120 ) {
				continue;
			}
			$out[ $id ] = trim( ( $out[ $id ] ?? '' ) . ' · ' . $text, ' ·' );
		}
		return array_map( static fn( $t ) => mb_substr( $t, 0, 160 ), $out );
	}

	/**
	 * Human readable value (JSON arrays, option labels).
	 *
	 * @param string               $value Stored value.
	 * @param array<string, mixed> $meta  Field meta (type, options).
	 */
	private static function display_value( string $value, array $meta ): string {
		$decoded = '' !== $value && in_array( $value[0], array( '[', '{' ), true ) ? json_decode( $value, true ) : null;
		$options = (array) ( $meta['options'] ?? array() );

		if ( is_array( $decoded ) ) {
			$parts = array_map( static fn( $v ) => (string) ( $options[ $v ] ?? $v ), array_values( array_filter( $decoded, 'is_scalar' ) ) );
			return implode( 'name' === ( $meta['type'] ?? '' ) ? ' ' : ', ', $parts );
		}
		if ( 'consent' === ( $meta['type'] ?? '' ) ) {
			return '1' === $value ? __( 'Yes', 'bookora' ) : '';
		}
		return (string) ( $options[ $value ] ?? $value );
	}

	/**
	 * UTC datetime → local "Y-m-d H:i".
	 *
	 * @param string        $utc UTC datetime.
	 * @param \DateTimeZone $tz  Timezone.
	 */
	private static function local( string $utc, \DateTimeZone $tz ): string {
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d H:i' );
	}
}
