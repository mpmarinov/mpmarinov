<?php
/**
 * GDPR integration with the WordPress privacy tools.
 *
 * @package Bookora
 */

namespace Bookora\Privacy;

use Bookora\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Registers personal-data exporter and eraser plus a privacy policy suggestion.
 */
final class Privacy {

	private const PER_PAGE = 50;

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_content' ) );
	}

	/**
	 * Add exporter.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['bookora'] = array(
			'exporter_friendly_name' => __( 'Bookora entries and bookings', 'bookora' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Add eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['bookora'] = array(
			'eraser_friendly_name' => __( 'Bookora entries and bookings', 'bookora' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export all entries (with or without appointment) of a customer.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number (1-based).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		global $wpdb;

		$customers = Schema::table( $wpdb->prefix, 'customers' );
		$entries   = Schema::table( $wpdb->prefix, 'entries' );
		$values    = Schema::table( $wpdb->prefix, 'entry_values' );
		$bookings  = Schema::table( $wpdb->prefix, 'bookings' );
		$offset    = ( max( 1, $page ) - 1 ) * self::PER_PAGE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.uid, e.created_at, c.name, c.phone, b.start_utc, b.end_utc, b.status AS booking_status
				FROM {$entries} e
				INNER JOIN {$customers} c ON c.id = e.customer_id
				LEFT JOIN {$bookings} b ON b.entry_id = e.id
				WHERE c.email = %s ORDER BY e.id LIMIT %d OFFSET %d",
				$email,
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);

		$data = array();
		foreach ( $rows as $row ) {
			$items = array(
				array(
					'name'  => __( 'Submitted (UTC)', 'bookora' ),
					'value' => $row['created_at'],
				),
				array(
					'name'  => __( 'Name', 'bookora' ),
					'value' => $row['name'],
				),
				array(
					'name'  => __( 'Phone', 'bookora' ),
					'value' => $row['phone'],
				),
			);

			if ( ! empty( $row['start_utc'] ) ) {
				$items[] = array(
					'name'  => __( 'Appointment (UTC)', 'bookora' ),
					'value' => $row['start_utc'] . ' – ' . $row['end_utc'] . ' (' . $row['booking_status'] . ')',
				);
			}

			$fields = (array) $wpdb->get_results( $wpdb->prepare( "SELECT field_key, value FROM {$values} WHERE entry_id = %d", $row['id'] ), ARRAY_A );
			foreach ( $fields as $field ) {
				$items[] = array(
					'name'  => $field['field_key'],
					'value' => (string) $field['value'],
				);
			}

			$data[] = array(
				'group_id'    => 'bookora-entries',
				'group_label' => __( 'Form entries and bookings', 'bookora' ),
				'item_id'     => 'bookora-' . $row['uid'],
				'data'        => $items,
			);
		}
		// phpcs:enable

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Anonymize a customer and delete their form values. Booking time slots stay for statistics.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email ): array {
		global $wpdb;

		$customers = Schema::table( $wpdb->prefix, 'customers' );
		$entries   = Schema::table( $wpdb->prefix, 'entries' );
		$values    = Schema::table( $wpdb->prefix, 'entry_values' );
		$bookings  = Schema::table( $wpdb->prefix, 'bookings' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$customer_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$customers} WHERE email = %s", $email ) );

		if ( ! $customer_id ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$wpdb->query( $wpdb->prepare( "DELETE v FROM {$values} v INNER JOIN {$entries} e ON e.id = v.entry_id WHERE e.customer_id = %d", $customer_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$entries} SET ip_hash = '', user_agent = '', source_url = '' WHERE customer_id = %d", $customer_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$bookings} SET notes = NULL WHERE customer_id = %d", $customer_id ) );
		$wpdb->update(
			$customers,
			array(
				'email'   => 'deleted-' . $customer_id . '@invalid.invalid',
				'name'    => '',
				'phone'   => '',
				'user_id' => null,
			),
			array( 'id' => $customer_id )
		);
		// phpcs:enable

		return array(
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested text for the site's privacy policy.
	 */
	public function policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			'Bookora',
			wp_kses_post(
				'<p>' . __( 'When you submit a form or book an appointment, we store the data you enter in the form (such as name, email address, phone number and your message) together with the date and time of a booked appointment. We use this data to process your request, manage your appointment and send you confirmations and reminders.', 'bookora' ) . '</p>'
			)
		);
	}
}
