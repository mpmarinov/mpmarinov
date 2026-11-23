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
			'exporter_friendly_name' => __( 'Bookora bookings', 'bookora' ),
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
			'eraser_friendly_name' => __( 'Bookora bookings', 'bookora' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export bookings of a customer.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number (1-based).
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		global $wpdb;

		$customers = Schema::table( $wpdb->prefix, 'customers' );
		$bookings  = Schema::table( $wpdb->prefix, 'bookings' );
		$offset    = ( max( 1, $page ) - 1 ) * self::PER_PAGE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.uid, b.start_utc, b.end_utc, b.status, b.notes, c.name, c.phone
				FROM {$bookings} b INNER JOIN {$customers} c ON c.id = b.customer_id
				WHERE c.email = %s ORDER BY b.id LIMIT %d OFFSET %d",
				$email,
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		$data = array();
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => 'bookora-bookings',
				'group_label' => __( 'Bookings', 'bookora' ),
				'item_id'     => 'bookora-' . $row['uid'],
				'data'        => array(
					array(
						'name'  => __( 'Name', 'bookora' ),
						'value' => $row['name'],
					),
					array(
						'name'  => __( 'Phone', 'bookora' ),
						'value' => $row['phone'],
					),
					array(
						'name'  => __( 'Start (UTC)', 'bookora' ),
						'value' => $row['start_utc'],
					),
					array(
						'name'  => __( 'End (UTC)', 'bookora' ),
						'value' => $row['end_utc'],
					),
					array(
						'name'  => __( 'Status', 'bookora' ),
						'value' => $row['status'],
					),
					array(
						'name'  => __( 'Notes', 'bookora' ),
						'value' => (string) $row['notes'],
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $data ) < self::PER_PAGE,
		);
	}

	/**
	 * Anonymize a customer and delete their form values. Booking rows stay for statistics.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email ): array {
		global $wpdb;

		$customers = Schema::table( $wpdb->prefix, 'customers' );
		$bookings  = Schema::table( $wpdb->prefix, 'bookings' );
		$values    = Schema::table( $wpdb->prefix, 'booking_values' );

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

		$wpdb->query( $wpdb->prepare( "DELETE v FROM {$values} v INNER JOIN {$bookings} b ON b.id = v.booking_id WHERE b.customer_id = %d", $customer_id ) );
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
				'<p>' . __( 'When you book an appointment, we store the data you enter in the booking form (such as name, email address, phone number and your message) together with the date and time of the appointment. We use this data to manage your appointment and send you confirmations and reminders.', 'bookora' ) . '</p>'
			)
		);
	}
}
