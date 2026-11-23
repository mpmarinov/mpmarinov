<?php
/**
 * One-click confirm/reject links in admin emails.
 *
 * @package Bookora
 */

namespace Bookora\Notifications;

use Bookora\Install\Schema;
use Bookora\Repository\EntryRepository;
use Bookora\Security\Crypto;
use Bookora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Flow: email link (GET) → confirmation page with booking details → button (POST) → action.
 * GET never changes anything, so email security scanners that open links cannot confirm bookings.
 * Tokens are random, stored only as SHA-256 hash, single use and expire.
 */
final class ActionLinks {

	public const QUERY_VAR = 'bookora_action';
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
		add_action( 'bookora_booking_status_changed', array( $this, 'invalidate' ) );
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

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$link  = $this->find( $token );

		if ( ! $link ) {
			$this->page( __( 'Link no longer valid', 'bookora' ), '<p>' . esc_html__( 'This link has already been used, has expired or the booking was changed in the meantime. Please open the booking in WordPress.', 'bookora' ) . '</p>' . $this->admin_button() );
		}

		$booking = $this->entries->booking( $link['booking_id'] );
		if ( ! $booking ) {
			$this->page( __( 'Booking not found', 'bookora' ), '' );
		}

		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];

		if ( $is_post ) {
			if ( ! isset( $_POST['_bookora_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_bookora_nonce'] ) ), 'bookora_action_' . $link['id'] ) ) {
				$this->page( __( 'Please try again', 'bookora' ), '<p>' . esc_html__( 'The page has expired. Please open the link from the email again.', 'bookora' ) . '</p>' );
			}

			$result = $this->entries->set_booking_status( $link['booking_id'], $link['action'] );
			$this->invalidate( $link['booking_id'] );

			if ( is_wp_error( $result ) ) {
				$this->page( __( 'Not possible', 'bookora' ), '<p>' . esc_html( $result->get_error_message() ) . '</p>' . $this->summary( $booking ) . $this->admin_button() );
			}

			$title = 'confirmed' === $link['action'] ? __( 'Booking confirmed', 'bookora' ) : __( 'Booking rejected', 'bookora' );
			$text  = 'confirmed' === $link['action']
				? __( 'The customer has been informed by email.', 'bookora' )
				: __( 'The customer has been informed by email. The time is free again.', 'bookora' );
			$this->page( $title, '<p class="ok">✓ ' . esc_html( $text ) . '</p>' . $this->summary( (array) $result ) . $this->admin_button(), 'confirmed' === $link['action'] ? 'success' : 'neutral' );
		}

		if ( 'pending' !== $booking['status'] ) {
			$this->page( __( 'Already processed', 'bookora' ), '<p>' . esc_html__( 'This booking has already been processed.', 'bookora' ) . '</p>' . $this->summary( $booking ) . $this->admin_button() );
		}

		$confirm = 'confirmed' === $link['action'];
		$form    = sprintf(
			'<form method="post">%1$s<button type="submit" class="btn %2$s">%3$s</button></form>',
			wp_nonce_field( 'bookora_action_' . $link['id'], '_bookora_nonce', false, false ),
			$confirm ? 'btn-confirm' : 'btn-reject',
			esc_html( $confirm ? __( 'Confirm booking', 'bookora' ) : __( 'Reject booking', 'bookora' ) )
		);

		$this->page(
			$confirm ? __( 'Confirm booking?', 'bookora' ) : __( 'Reject booking?', 'bookora' ),
			$this->summary( $booking ) . $form . '<p class="hint">' . esc_html__( 'The customer will be informed automatically by email.', 'bookora' ) . '</p>'
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
			__( 'Customer', 'bookora' ) => trim( $b['name'] . ' ' . ( $b['email'] ? '(' . $b['email'] . ')' : '' ) ),
			__( 'Service', 'bookora' )  => $b['service_name'],
			__( 'Date', 'bookora' )     => wp_date( 'l, ' . get_option( 'date_format' ), $ts ),
			__( 'Time', 'bookora' )     => $b['time'] . ' – ' . $b['end_time'],
		);
		if ( '' !== $b['resource_name'] ) {
			$rows[ __( 'Staff member', 'bookora' ) ] = $b['resource_name'];
		}
		if ( '' !== $b['phone'] ) {
			$rows[ __( 'Phone', 'bookora' ) ] = $b['phone'];
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
		return '<p><a class="link" href="' . esc_url( admin_url( 'admin.php?page=bookora-bookings' ) ) . '">' . esc_html__( 'Open bookings in WordPress', 'bookora' ) . '</a></p>';
	}

	/**
	 * Output a minimal standalone page and stop.
	 *
	 * @param string $title Title.
	 * @param string $body  Escaped HTML.
	 * @param string $tone  success|neutral.
	 */
	private function page( string $title, string $body, string $tone = 'neutral' ): void {
		$color = (string) $this->settings->get( 'primary_color', '#2563eb' );
		$name  = (string) $this->settings->get( 'business_name', '' );
		$name  = '' !== $name ? $name : get_bloginfo( 'name' );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html( $title . ' – ' . $name ); ?></title>
<style>
body{margin:0;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f3f4f6;color:#1f2937}
.card{max-width:480px;margin:40px auto;background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08);overflow:hidden}
.head{background:<?php echo esc_attr( $color ); ?>;color:#fff;padding:18px 24px;font-weight:600}
.body{padding:24px}h1{font-size:22px;margin:0 0 16px}
dl{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin:0 0 20px;padding:16px;background:#f9fafb;border-radius:10px}
dt{color:#6b7280}dd{margin:0;font-weight:500}
.btn{display:block;width:100%;padding:14px;border:0;border-radius:10px;font:inherit;font-weight:600;color:#fff;cursor:pointer}
.btn-confirm{background:#15803d}.btn-reject{background:#b91c1c}
.hint{color:#6b7280;font-size:14px;margin-top:12px}.ok{color:<?php echo 'success' === $tone ? '#15803d' : '#374151'; ?>;font-weight:600}
.link{color:<?php echo esc_attr( $color ); ?>}
@media (max-width:520px){.card{margin:0;border-radius:0;min-height:100vh}}
</style>
</head>
<body>
<div class="card">
	<div class="head"><?php echo esc_html( $name ); ?></div>
	<div class="body">
		<h1><?php echo esc_html( $title ); ?></h1>
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
	</div>
</div>
</body>
</html>
		<?php
		exit;
	}
}
