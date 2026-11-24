<?php
/**
 * Customer self-service: view, move or cancel an appointment via the link in the email.
 *
 * @package Bookora
 */

namespace Bookora\Booking;

use Bookora\Frontend\StandalonePage;
use Bookora\Repository\EntryRepository;
use Bookora\Repository\ServiceRepository;
use Bookora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The link carries the booking uid plus an HMAC signature (128 bit, derived from the site salts,
 * nothing stored). GET only shows information; every change is a POST with a nonce, so link
 * scanners in mail programs cannot cancel appointments.
 */
final class ManagePage {

	public const QUERY_VAR = 'bookora_manage';

	/**
	 * Days searched for free times when moving.
	 */
	private const SEARCH_DAYS = 60;

	/**
	 * Constructor.
	 *
	 * @param EntryRepository   $entries  Bookings.
	 * @param ServiceRepository $services Services.
	 * @param BookingService    $bookings Booking writes.
	 * @param Settings          $settings Settings.
	 */
	public function __construct(
		private EntryRepository $entries,
		private ServiceRepository $services,
		private BookingService $bookings,
		private Settings $settings,
	) {}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 0 );
		add_action( 'bookora_booking_status_changed', array( $this, 'status_fees' ), 5, 3 );
	}

	/**
	 * Signed manage URL of a booking.
	 *
	 * @param string $uid Booking uid.
	 */
	public static function url( string $uid ): string {
		return add_query_arg( self::QUERY_VAR, $uid . '.' . self::sign( $uid ), home_url( '/' ) );
	}

	/**
	 * Signature.
	 *
	 * @param string $uid Booking uid.
	 */
	private static function sign( string $uid ): string {
		return substr( hash_hmac( 'sha256', 'bookora_manage|' . $uid, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Whether customers can change appointments online at all (email placeholder is empty otherwise).
	 */
	public function enabled(): bool {
		return (bool) $this->settings->get( 'self_cancel', true ) || (bool) $this->settings->get( 'self_reschedule', true );
	}

	/**
	 * No-show and re-opening: record or clear the fee automatically.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $status     New status.
	 * @param string $previous   Previous status.
	 */
	public function status_fees( int $booking_id, string $status, string $previous ): void {
		$booking = $this->entries->booking( $booking_id );
		if ( ! $booking ) {
			return;
		}
		$policy = (array) ( $booking['policy'] ?? array() );

		if ( 'no_show' === $status && ! empty( $policy['fee'] ) && ! empty( $policy['no_show'] ) && '' === $booking['fee_status'] ) {
			$this->entries->set_fee( $booking_id, (float) ( $policy['fee_amount'] ?? 0 ), 'open', 'no_show' );
			return;
		}

		$auto = array(
			'no_show'   => 'no_show',
			'cancelled' => 'late_cancel',
		);
		if ( isset( $auto[ $previous ] ) && $auto[ $previous ] === $booking['fee_reason'] && 'open' === $booking['fee_status'] ) {
			$this->entries->set_fee( $booking_id, 0.0, '' );
		}
	}

	/**
	 * Route the request.
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the signed token is the credential; POSTs are nonce-checked.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw   = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$parts = explode( '.', $raw );
		if ( 2 !== count( $parts ) || ! preg_match( '/^[a-f0-9-]{36}$/', $parts[0] ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			$this->page( __( 'Link not valid', 'bookora' ), '<p>' . esc_html__( 'This link is not valid. Please use the link from your most recent email.', 'bookora' ) . '</p>', 'neutral', 404 );
		}

		$booking = $this->entries->booking_by_uid( $parts[0] );
		if ( ! $booking ) {
			$this->page( __( 'Appointment not found', 'bookora' ), '<p>' . esc_html__( 'This appointment no longer exists.', 'bookora' ) . '</p>', 'neutral', 404 );
		}

		$base = self::url( $parts[0] );
		$rule = $this->rule( $booking );
		$opt  = CancellationPolicy::options( $booking, $rule, time() );

		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		if ( $is_post ) {
			if ( ! isset( $_POST['_bookora_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_bookora_nonce'] ) ), 'bookora_manage_' . $booking['uid'] ) ) {
				$this->page( __( 'Please try again', 'bookora' ), '<p>' . esc_html__( 'The page has expired. Please open the link from the email again.', 'bookora' ) . '</p>' . $this->back( $base ) );
			}
			$do = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
			if ( 'cancel' === $do ) {
				$reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) );
				$this->do_cancel( $booking, $opt, mb_substr( $reason, 0, 500 ), $base );
			}
			if ( 'move' === $do ) {
				$this->do_move( $booking, $opt, absint( wp_unslash( $_POST['start'] ?? 0 ) ), $base );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( 'cancel' === $view && in_array( $opt['cancel'], array( 'free', 'fee' ), true ) ) {
			$this->view_cancel( $booking, $opt, $base );
		}
		if ( 'move' === $view && 'free' === $opt['reschedule'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
			$this->view_move( $booking, $date, $base );
		}
		$this->view_overview( $booking, $rule, $opt, $base );
	}

	/**
	 * Current rule of the booking's service.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @return array<string, mixed>
	 */
	private function rule( array $booking ): array {
		$service = $booking['service_id'] ? $this->services->find( (int) $booking['service_id'] ) : null;
		return CancellationPolicy::for_service( $service, $this->settings->all() );
	}

	/**
	 * Overview with the appointment and the possible actions.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param array<string, mixed> $rule    Rule.
	 * @param array<string, mixed> $opt     Options.
	 * @param string               $base    Manage URL.
	 */
	private function view_overview( array $booking, array $rule, array $opt, string $base ): void {
		$body = $this->summary( $booking );

		if ( 'closed' === $opt['cancel'] ) {
			$body .= '<p>' . esc_html( $this->closed_text( $booking ) ) . '</p>';
			$this->page( __( 'Your appointment', 'bookora' ), $body );
		}

		$text = (string) ( $booking['policy']['text'] ?? '' );
		if ( '' === $text ) {
			$text = CancellationPolicy::text( array( 'fee' => false ) + $rule, 0.0 );
		}
		if ( '' !== $text ) {
			$body .= '<p class="policy">' . esc_html( $text ) . '</p>';
		}

		$buttons = '';
		if ( 'free' === $opt['reschedule'] ) {
			$buttons .= '<a class="btn" href="' . esc_url( add_query_arg( 'view', 'move', $base ) ) . '">' . esc_html__( 'Move appointment', 'bookora' ) . '</a>';
		}
		if ( in_array( $opt['cancel'], array( 'free', 'fee' ), true ) ) {
			$label    = 'pending' === $booking['status'] ? __( 'Withdraw request', 'bookora' ) : __( 'Cancel appointment', 'bookora' );
			$buttons .= '<a class="btn btn-secondary" href="' . esc_url( add_query_arg( 'view', 'cancel', $base ) ) . '">' . esc_html( $label ) . '</a>';
		}

		if ( 'free' !== $opt['reschedule'] || ! in_array( $opt['cancel'], array( 'free', 'fee' ), true ) ) {
			$blocked = 'blocked' === $opt['cancel'] || ( 'blocked' === $opt['reschedule'] && 'fee' !== $opt['cancel'] );
			if ( $blocked ) {
				$body .= '<p class="notice">' . esc_html(
					sprintf(
						/* translators: %s: date and time. */
						__( 'Online changes were possible until %s. For short-notice changes please contact us directly.', 'bookora' ),
						$this->format_time( (int) $opt['deadline'] )
					)
				) . '</p>' . $this->contact();
			} elseif ( '' === $buttons ) {
				$body .= '<p>' . esc_html__( 'To change this appointment, please contact us directly.', 'bookora' ) . '</p>' . $this->contact();
			}
		}
		if ( 'fee' === $opt['cancel'] ) {
			$body .= '<p class="notice">' . esc_html(
				sprintf(
					/* translators: 1: date and time, 2: fee. */
					__( 'The free cancellation period ended on %1$s. If you cancel now, a fee of %2$s applies.', 'bookora' ),
					$this->format_time( (int) $opt['deadline'] ),
					CancellationPolicy::money( (float) $opt['fee'] )
				)
			) . '</p>';
		}

		$this->page( __( 'Your appointment', 'bookora' ), $body . $buttons );
	}

	/**
	 * Cancel confirmation.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param array<string, mixed> $opt     Options.
	 * @param string               $base    Manage URL.
	 */
	private function view_cancel( array $booking, array $opt, string $base ): void {
		$body = $this->summary( $booking );
		if ( 'fee' === $opt['cancel'] ) {
			$body .= '<p class="notice notice-error"><strong>' . esc_html(
				sprintf(
					/* translators: %s: fee amount. */
					__( 'A cancellation fee of %s applies.', 'bookora' ),
					CancellationPolicy::money( (float) $opt['fee'] )
				)
			) . '</strong> ' . esc_html__( 'The free cancellation period has ended.', 'bookora' ) . '</p>';
		}

		$label = 'pending' === $booking['status'] ? __( 'Withdraw request', 'bookora' ) : __( 'Cancel appointment', 'bookora' );
		if ( 'fee' === $opt['cancel'] ) {
			/* translators: %s: fee amount. */
			$label = sprintf( __( 'Cancel with fee (%s)', 'bookora' ), CancellationPolicy::money( (float) $opt['fee'] ) );
		}

		$body .= '<form method="post">'
			. wp_nonce_field( 'bookora_manage_' . $booking['uid'], '_bookora_nonce', false, false )
			. '<input type="hidden" name="do" value="cancel">'
			. '<label for="bookora-reason">' . esc_html__( 'Reason (optional)', 'bookora' ) . '</label>'
			. '<textarea id="bookora-reason" name="reason" maxlength="500"></textarea>'
			. '<button type="submit" class="btn btn-reject">' . esc_html( $label ) . '</button>'
			. '</form>'
			. $this->back( $base, __( 'Keep appointment', 'bookora' ) );

		$this->page( 'pending' === $booking['status'] ? __( 'Withdraw request?', 'bookora' ) : __( 'Cancel appointment?', 'bookora' ), $body );
	}

	/**
	 * Choose a new time.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param string               $date    Chosen date (Y-m-d) or ''.
	 * @param string               $base    Manage URL.
	 */
	private function view_move( array $booking, string $date, string $base ): void {
		$tz    = wp_timezone();
		$today = wp_date( 'Y-m-d', time(), $tz );
		$days  = min( self::SEARCH_DAYS, max( 1, (int) $this->settings->get( 'max_advance_days', 90 ) ) );
		$last  = wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS, $tz );
		$slots = $this->bookings->customer_slots( (int) $booking['id'], $today, $last );
		$avail = array_keys( $slots );
		$date  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && isset( $slots[ $date ] ) ? $date : ( $avail[0] ?? '' );
		$tf    = '12h' === $this->settings->get( 'time_format', '24h' ) ? 'g:i A' : 'H:i';
		$body  = '<p>' . esc_html(
			sprintf(
				/* translators: 1: service, 2: current date and time. */
				__( 'Current appointment: %1$s, %2$s', 'bookora' ),
				$booking['service_name'],
				$this->format_time( (int) strtotime( (string) $booking['start'] ) )
			)
		) . '</p>';

		if ( ! $avail ) {
			$body .= '<p class="notice">' . esc_html__( 'Unfortunately there are no free times in the coming weeks. Please contact us directly.', 'bookora' ) . '</p>' . $this->contact() . $this->back( $base );
			$this->page( __( 'Move appointment', 'bookora' ), $body );
		}

		$body .= '<h2>' . esc_html__( 'Choose a day', 'bookora' ) . '</h2><ul class="chips">';
		foreach ( array_slice( $avail, 0, 21 ) as $day ) {
			$ts    = (int) strtotime( $day . ' 12:00 UTC' );
			$body .= '<li><a class="chip" href="' . esc_url(
				add_query_arg(
					array(
						'view' => 'move',
						'date' => $day,
					),
					$base
				)
			) . '"' . ( $day === $date ? ' aria-current="date"' : '' ) . '>' . esc_html( wp_date( 'D, j. M', $ts, new \DateTimeZone( 'UTC' ) ) ) . '</a></li>';
		}
		$body .= '</ul>';

		$body .= '<h2>' . esc_html(
			sprintf(
				/* translators: %s: date. */
				__( 'Free times on %s', 'bookora' ),
				wp_date( 'l, ' . get_option( 'date_format' ), (int) strtotime( $date . ' 12:00 UTC' ), new \DateTimeZone( 'UTC' ) )
			)
		) . '</h2>';
		$body .= '<form method="post">' . wp_nonce_field( 'bookora_manage_' . $booking['uid'], '_bookora_nonce', false, false ) . '<input type="hidden" name="do" value="move"><div class="slots">';
		foreach ( $slots[ $date ] as $start ) {
			$body .= '<button type="submit" class="slot" name="start" value="' . (int) $start . '">' . esc_html( wp_date( $tf, $start, $tz ) ) . '</button>';
		}
		$body .= '</div></form><p class="hint">' . esc_html__( 'Tap a time to move your appointment. You will receive a confirmation by email.', 'bookora' ) . '</p>' . $this->back( $base );

		$this->page( __( 'Move appointment', 'bookora' ), $body );
	}

	/**
	 * Execute a cancellation.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param array<string, mixed> $opt     Options.
	 * @param string               $reason  Reason.
	 * @param string               $base    Manage URL.
	 */
	private function do_cancel( array $booking, array $opt, string $reason, string $base ): void {
		if ( ! in_array( $opt['cancel'], array( 'free', 'fee' ), true ) ) {
			$this->page( __( 'Not possible', 'bookora' ), '<p>' . esc_html__( 'This appointment can no longer be cancelled online. Please contact us directly.', 'bookora' ) . '</p>' . $this->contact() . $this->back( $base ) );
		}

		$id  = (int) $booking['id'];
		$fee = 'fee' === $opt['cancel'] ? (float) $opt['fee'] : 0.0;
		if ( $fee > 0 ) {
			$this->entries->set_fee( $id, $fee, 'open', 'late_cancel' );
		}
		$this->entries->set_cancelled_by( $id, 'customer' );
		if ( '' !== $reason ) {
			$notes = trim( $booking['notes'] . "\n" . __( 'Reason given by the customer:', 'bookora' ) . ' ' . $reason );
			$this->entries->set_booking_notes( $id, $notes );
		}

		$result = $this->entries->set_booking_status( $id, 'cancelled' );
		if ( is_wp_error( $result ) ) {
			if ( $fee > 0 ) {
				$this->entries->set_fee( $id, 0.0, '' );
			}
			$this->entries->set_cancelled_by( $id, '' );
			$this->page( __( 'Not possible', 'bookora' ), '<p>' . esc_html( $result->get_error_message() ) . '</p>' . $this->back( $base ) );
		}

		/**
		 * Fires after the customer cancelled via the manage link.
		 *
		 * @param int    $booking_id Booking id.
		 * @param float  $fee        Fee charged (0 = free).
		 * @param string $reason     Reason given.
		 */
		do_action( 'bookora_customer_cancelled', $id, $fee, $reason );

		$text = $fee > 0
			/* translators: %s: fee amount. */
			? sprintf( __( 'Your appointment has been cancelled. A cancellation fee of %s has been recorded.', 'bookora' ), CancellationPolicy::money( $fee ) )
			: __( 'Your appointment has been cancelled. You will receive a confirmation by email.', 'bookora' );
		$this->page( __( 'Appointment cancelled', 'bookora' ), '<p class="ok">✓ ' . esc_html( $text ) . '</p>' . $this->summary( (array) $result ) . $this->rebook(), 'success' );
	}

	/**
	 * Execute a move.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @param array<string, mixed> $opt     Options.
	 * @param int                  $start   New start.
	 * @param string               $base    Manage URL.
	 */
	private function do_move( array $booking, array $opt, int $start, string $base ): void {
		if ( 'free' !== $opt['reschedule'] ) {
			$this->page( __( 'Not possible', 'bookora' ), '<p>' . esc_html__( 'This appointment can no longer be moved online. Please contact us directly.', 'bookora' ) . '</p>' . $this->contact() . $this->back( $base ) );
		}
		$result = $this->bookings->reschedule_by_customer( (int) $booking['id'], $start );
		if ( is_wp_error( $result ) ) {
			$this->page( __( 'Not possible', 'bookora' ), '<p class="notice notice-error">' . esc_html( $result->get_error_message() ) . '</p><a class="btn" href="' . esc_url( add_query_arg( 'view', 'move', $base ) ) . '">' . esc_html__( 'Choose another time', 'bookora' ) . '</a>' . $this->back( $base ) );
		}
		$updated = (array) $this->entries->booking( (int) $booking['id'] );
		$this->page( __( 'Appointment moved', 'bookora' ), '<p class="ok">✓ ' . esc_html__( 'Your appointment has been moved. You will receive a confirmation with the new calendar file by email.', 'bookora' ) . '</p>' . $this->summary( $updated ) . $this->back( $base ), 'success' );
	}

	/**
	 * Appointment summary.
	 *
	 * @param array<string, mixed> $b Booking.
	 */
	private function summary( array $b ): string {
		$labels = array(
			'pending'   => __( 'Requested – not yet confirmed', 'bookora' ),
			'confirmed' => __( 'Confirmed', 'bookora' ),
			'cancelled' => __( 'Cancelled', 'bookora' ),
			'rejected'  => __( 'Rejected', 'bookora' ),
			'completed' => __( 'Completed', 'bookora' ),
			'no_show'   => __( 'Not attended', 'bookora' ),
		);
		$tf     = '12h' === $this->settings->get( 'time_format', '24h' ) ? 'g:i A' : 'H:i';
		$ts     = (int) strtotime( (string) $b['start'] );
		$end    = (int) strtotime( (string) $b['end'] );
		$rows   = array(
			__( 'Service', 'bookora' ) => esc_html( (string) $b['service_name'] ),
			__( 'Date', 'bookora' )    => esc_html( wp_date( 'l, ' . get_option( 'date_format' ), $ts ) ),
			__( 'Time', 'bookora' )    => esc_html( wp_date( $tf, $ts ) . ' – ' . wp_date( $tf, $end ) ),
		);
		if ( '' !== (string) $b['resource_name'] ) {
			$rows[ __( 'Staff member', 'bookora' ) ] = esc_html( (string) $b['resource_name'] );
		}
		$rows[ __( 'Status', 'bookora' ) ] = '<span class="status status-' . esc_attr( (string) $b['status'] ) . '">' . esc_html( $labels[ $b['status'] ] ?? (string) $b['status'] ) . '</span>';
		if ( (float) ( $b['fee_amount'] ?? 0 ) > 0 ) {
			$rows[ __( 'Cancellation fee', 'bookora' ) ] = esc_html( CancellationPolicy::money( (float) $b['fee_amount'] ) );
		}

		$html = '<dl>';
		foreach ( $rows as $k => $v ) {
			$html .= '<dt>' . esc_html( $k ) . '</dt><dd>' . $v . '</dd>';
		}
		return $html . '</dl>';
	}

	/**
	 * Why nothing can be changed any more.
	 *
	 * @param array<string, mixed> $b Booking.
	 */
	private function closed_text( array $b ): string {
		if ( 'cancelled' === $b['status'] ) {
			return __( 'This appointment has been cancelled.', 'bookora' );
		}
		if ( 'rejected' === $b['status'] ) {
			return __( 'Unfortunately this request could not be accepted.', 'bookora' );
		}
		return __( 'This appointment is in the past and can no longer be changed.', 'bookora' );
	}

	/**
	 * Contact details of the business.
	 */
	private function contact(): string {
		$email = (string) $this->settings->get( 'email_from_address', '' );
		$email = is_email( $email ) ? $email : (string) $this->settings->get( 'admin_email', '' );
		$email = is_email( $email ) ? $email : (string) get_option( 'admin_email' );
		$phone = trim( (string) $this->settings->get( 'business_phone', '' ) );

		$html = '<p>';
		if ( '' !== $phone ) {
			$html .= esc_html__( 'Phone:', 'bookora' ) . ' <a class="link" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a><br>';
		}
		return $html . esc_html__( 'Email:', 'bookora' ) . ' <a class="link" href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></p>';
	}

	/**
	 * Back link.
	 *
	 * @param string $base  Manage URL.
	 * @param string $label Label.
	 */
	private function back( string $base, string $label = '' ): string {
		return '<p><a class="link" href="' . esc_url( $base ) . '">' . esc_html( '' !== $label ? $label : __( 'Back to the appointment', 'bookora' ) ) . '</a></p>';
	}

	/**
	 * Link to book again.
	 */
	private function rebook(): string {
		return '<p><a class="link" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the website', 'bookora' ) . '</a></p>';
	}

	/**
	 * Local date and time.
	 *
	 * @param int $ts Timestamp.
	 */
	private function format_time( int $ts ): string {
		$tf = '12h' === $this->settings->get( 'time_format', '24h' ) ? 'g:i A' : 'H:i';
		return wp_date( get_option( 'date_format' ) . ', ' . $tf, $ts );
	}

	/**
	 * Output.
	 *
	 * @param string $title  Title.
	 * @param string $body   Escaped HTML.
	 * @param string $tone   Tone.
	 * @param int    $status HTTP status.
	 */
	private function page( string $title, string $body, string $tone = 'neutral', int $status = 200 ): void {
		StandalonePage::output( $this->settings, $title, $body, $tone, $status );
	}
}
