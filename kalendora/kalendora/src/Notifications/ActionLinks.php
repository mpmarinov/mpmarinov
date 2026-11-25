<?php
/**
 * One-click confirm/reject links in admin emails.
 *
 * @package Kalendora
 */

namespace Kalendora\Notifications;

use Kalendora\Frontend\StandalonePage;
use Kalendora\Install\Schema;
use Kalendora\Repository\EntryRepository;
use Kalendora\Security\Crypto;
use Kalendora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Flow: email link (GET) → confirmation page with booking details → button (POST) → action.
 * GET never changes anything, so email security scanners that open links cannot confirm bookings.
 * Tokens are random, stored only as SHA-256 hash, single use and expire.
 */
final class ActionLinks {

	public const QUERY_VAR = 'kalendora_action';
	public const ACTIONS   = array( 'confirmed', 'rejected' );

	/**
	 * Constructor.
	 *
	 * @param EntryRepository $entries  Entries/bookings.
	 * @param Settings        $settings Settings.
	 */
	public function __construct(
		private EntryRepository $entries,
		private Settings $settings,
	) {}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 0 );
		add_action( 'kalendora_booking_status_changed', array( $this, 'invalidate' ) );
	}

	/**
	 * Create a link for an action.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $action     confirmed|rejected.
	 */
	public function create( int $booking_id, string $action ): string {
		global $wpdb;
		$token = Crypto::random_token();
		$hours = max( 1, (int) $this->settings->get( 'token_ttl_hours', 72 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::table( $wpdb->prefix, 'action_tokens' ),
			array(
				'booking_id' => $booking_id,
				'action'     => $action,
				'token_hash' => Crypto::hash_token( $token ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS ),
				'created_at' => current_time( 'mysql', true ),
			)
		);

		return add_query_arg( self::QUERY_VAR, rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * Any status change (also from the admin) invalidates open links of that booking.
	 *
	 * @param int $booking_id Booking id.
	 */
	public function invalidate( int $booking_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( $wpdb->prefix, 'action_tokens' ) . ' SET used_at = %s WHERE booking_id = %d AND used_at IS NULL', current_time( 'mysql', true ), $booking_id ) );
	}

	/**
	 * Look up a valid token.
	 *
	 * @param string $token Plain token.
	 * @return array{id:int, booking_id:int, action:string}|null
	 */
	public function find( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9_-]{20,100}$/', $token ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, booking_id, action FROM ' . Schema::table( $wpdb->prefix, 'action_tokens' ) . ' WHERE token_hash = %s AND used_at IS NULL AND expires_at > %s', Crypto::hash_token( $token ), current_time( 'mysql', true ) ), ARRAY_A );

		return $row ? array(
			'id'         => (int) $row['id'],
			'booking_id' => (int) $row['booking_id'],
			'action'     => (string) $row['action'],
		) : null;
	}

	/**
	 * Show the confirmation page (GET) or execute the action (POST).
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential; POST is nonce-checked below.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$link  = $this->find( $token );

		if ( ! $link ) {
			$this->page( __( 'Link no longer valid', 'kalendora' ), '<p>' . esc_html__( 'This link has already been used, has expired or the booking was changed in the meantime. Please open the booking in WordPress.', 'kalendora' ) . '</p>' . $this->admin_button() );
		}

		$booking = $this->entries->booking( $link['booking_id'] );
		if ( ! $booking ) {
			$this->page( __( 'Booking not found', 'kalendora' ), '' );
		}

		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];

		if ( $is_post ) {
			if ( ! isset( $_POST['_kalendora_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_kalendora_nonce'] ) ), 'kalendora_action_' . $link['id'] ) ) {
				$this->page( __( 'Please try again', 'kalendora' ), '<p>' . esc_html__( 'The page has expired. Please open the link from the email again.', 'kalendora' ) . '</p>' );
			}

			$result = $this->entries->set_booking_status( $link['booking_id'], $link['action'] );
			$this->invalidate( $link['booking_id'] );

			if ( is_wp_error( $result ) ) {
				$this->page( __( 'Not possible', 'kalendora' ), '<p>' . esc_html( $result->get_error_message() ) . '</p>' . $this->summary( $booking ) . $this->admin_button() );
			}

			$title = 'confirmed' === $link['action'] ? __( 'Booking confirmed', 'kalendora' ) : __( 'Booking rejected', 'kalendora' );
			$text  = 'confirmed' === $link['action']
				? __( 'The customer has been informed by email.', 'kalendora' )
				: __( 'The customer has been informed by email. The time is free again.', 'kalendora' );
			$this->page( $title, '<p class="ok">✓ ' . esc_html( $text ) . '</p>' . $this->summary( (array) $result ) . $this->admin_button(), 'confirmed' === $link['action'] ? 'success' : 'neutral' );
		}

		if ( 'pending' !== $booking['status'] ) {
			$this->page( __( 'Already processed', 'kalendora' ), '<p>' . esc_html__( 'This booking has already been processed.', 'kalendora' ) . '</p>' . $this->summary( $booking ) . $this->admin_button() );
		}

		$confirm = 'confirmed' === $link['action'];
		$form    = sprintf(
			'<form method="post">%1$s<button type="submit" class="btn %2$s">%3$s</button></form>',
			wp_nonce_field( 'kalendora_action_' . $link['id'], '_kalendora_nonce', false, false ),
			$confirm ? 'btn-confirm' : 'btn-reject',
			esc_html( $confirm ? __( 'Confirm booking', 'kalendora' ) : __( 'Reject booking', 'kalendora' ) )
		);

		$this->page(
			$confirm ? __( 'Confirm booking?', 'kalendora' ) : __( 'Reject booking?', 'kalendora' ),
			$this->summary( $booking ) . $form . '<p class="hint">' . esc_html__( 'The customer will be informed automatically by email.', 'kalendora' ) . '</p>'
		);
	}

	/**
	 * Booking summary block.
	 *
	 * @param array<string, mixed> $b Booking.
	 */
	private function summary( array $b ): string {
		$ts   = strtotime( (string) $b['start'] );
		$rows = array(
			__( 'Customer', 'kalendora' ) => trim( $b['name'] . ' ' . ( $b['email'] ? '(' . $b['email'] . ')' : '' ) ),
			__( 'Service', 'kalendora' )  => $b['service_name'],
			__( 'Date', 'kalendora' )     => wp_date( 'l, ' . get_option( 'date_format' ), $ts ),
			__( 'Time', 'kalendora' )     => $b['time'] . ' – ' . $b['end_time'],
		);
		if ( '' !== $b['resource_name'] ) {
			$rows[ __( 'Staff member', 'kalendora' ) ] = $b['resource_name'];
		}
		if ( '' !== $b['phone'] ) {
			$rows[ __( 'Phone', 'kalendora' ) ] = $b['phone'];
		}

		$html = '<dl>';
		foreach ( $rows as $k => $v ) {
			$html .= '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( (string) $v ) . '</dd>';
		}
		return $html . '</dl>';
	}

	/**
	 * Link to the bookings screen.
	 */
	private function admin_button(): string {
		return '<p><a class="link" href="' . esc_url( admin_url( 'admin.php?page=kalendora-bookings' ) ) . '">' . esc_html__( 'Open bookings in WordPress', 'kalendora' ) . '</a></p>';
	}

	/**
	 * Output a minimal standalone page and stop.
	 *
	 * @param string $title Title.
	 * @param string $body  Escaped HTML.
	 * @param string $tone  success|neutral.
	 */
	private function page( string $title, string $body, string $tone = 'neutral' ): void {
		StandalonePage::output( $this->settings, $title, $body, $tone );
	}
}
