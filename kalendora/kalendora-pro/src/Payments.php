<?php
/**
 * Online payment for bookings (Stripe Checkout, PayPal).
 *
 * Flow: the booking is created unconfirmed and reserved → the visitor is sent to a payment page
 * → pays on the provider's page → the booking is confirmed (emails go out only now).
 * Not paid within the time limit → the booking is cancelled and the time is free again.
 * Free cancellations are refunded automatically (minus a cancellation fee, if any).
 * Optional: the visitor chooses "pay on site" (cash / card at the location) instead – the booking
 * then follows the normal confirmation rules and the business marks it as paid later.
 *
 * @package KalendoraPro
 */

namespace KalendoraPro;

use Kalendora\Core\Plugin as Kalendora;
use Kalendora\Frontend\StandalonePage;
use Kalendora\Notifications\Mailer;
use Kalendora\Repository\EntryRepository;
use Kalendora\Repository\ServiceRepository;
use Kalendora\Security\Crypto;
use Kalendora\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Payments.
 */
final class Payments {

	public const OPTION     = 'kalendora_pro_payments';
	public const DB_VERSION = '1.0.0';
	public const QUERY_VAR  = 'kalendora_pay';
	public const CRON       = 'kalendora_pro_payment_expire';
	private const SECRETS   = array( 'stripe_secret', 'stripe_webhook', 'paypal_secret' );

	/**
	 * Booking ids whose emails wait for the payment.
	 *
	 * @var array<int, bool>
	 */
	private array $hold = array();

	/**
	 * Badge cache per booking.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private static array $cache = array();

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'kalendora_pro_payments';
	}

	/**
	 * Create/upgrade the table.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  amount decimal(12,2) NOT NULL DEFAULT 0.00,
  total decimal(12,2) NOT NULL DEFAULT 0.00,
  currency char(3) NOT NULL DEFAULT 'EUR',
  kind varchar(10) NOT NULL DEFAULT 'full',
  status varchar(20) NOT NULL DEFAULT 'open',
  provider varchar(20) NOT NULL DEFAULT '',
  session_id varchar(191) NOT NULL DEFAULT '',
  reference varchar(191) NOT NULL DEFAULT '',
  refunded decimal(12,2) NOT NULL DEFAULT 0.00,
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  expires_at datetime NOT NULL,
  paid_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY status (status)
) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'kalendora_pro_payments_db', self::DB_VERSION, false );
	}

	/**
	 * Hooks.
	 */
	public function register(): void {
		if ( self::DB_VERSION !== get_option( 'kalendora_pro_payments_db' ) ) {
			self::install();
		}
		add_filter( 'kalendora_booking_input', array( $this, 'booking_input' ), 10, 2 );
		add_action( 'kalendora_booking_created', array( $this, 'booking_created' ), 5, 3 );
		add_filter( 'kalendora_notify_booking_created', array( $this, 'notify' ), 10, 2 );
		add_filter( 'kalendora_booking_response', array( $this, 'response' ), 10, 2 );
		add_filter( 'kalendora_booking_data', array( $this, 'badges' ) );
		add_action( 'kalendora_customer_cancelled', array( $this, 'customer_cancelled' ), 20, 2 );
		add_action( 'kalendora_booking_status_changed', array( $this, 'status_changed' ), 20, 3 );
		add_action( self::CRON, array( $this, 'expire' ) );
		add_action( 'template_redirect', array( $this, 'page' ), 1 );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/* ------------------------------------------------------------------ settings */

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'mode'           => 'off',
			'deposit_type'   => 'percent',
			'deposit_value'  => 30,
			'services'       => array(),
			'minutes'        => 30,
			'refund_cancel'  => true,
			'onsite'         => true,
			'stripe_secret'  => '',
			'stripe_webhook' => '',
			'paypal_client'  => '',
			'paypal_secret'  => '',
			'paypal_sandbox' => false,
		);
	}

	/**
	 * Settings with decrypted secrets.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$s      = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		$crypto = Kalendora::instance()->container()->get( Crypto::class );
		foreach ( self::SECRETS as $key ) {
			$s[ $key ] = '' !== (string) $s[ $key ] ? (string) $crypto->decrypt( (string) $s[ $key ] ) : '';
		}
		return $s;
	}

	/**
	 * Save from the admin form. Empty secret fields keep the stored value.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return true|\WP_Error
	 */
	public static function save( array $input ) {
		$current = self::settings();
		$out     = array(
			'mode'           => in_array( $input['mode'] ?? '', array( 'off', 'full', 'deposit' ), true ) ? $input['mode'] : 'off',
			'deposit_type'   => 'fixed' === ( $input['deposit_type'] ?? '' ) ? 'fixed' : 'percent',
			'deposit_value'  => max( 0, round( (float) str_replace( ',', '.', (string) ( $input['deposit_value'] ?? 0 ) ), 2 ) ),
			'services'       => array_values( array_filter( array_map( 'absint', (array) ( $input['services'] ?? array() ) ) ) ),
			'minutes'        => max( 30, min( 240, absint( $input['minutes'] ?? 30 ) ) ),
			'refund_cancel'  => ! empty( $input['refund_cancel'] ),
			'onsite'         => ! empty( $input['onsite'] ),
			'paypal_client'  => mb_substr( sanitize_text_field( (string) ( $input['paypal_client'] ?? '' ) ), 0, 200 ),
			'paypal_sandbox' => ! empty( $input['paypal_sandbox'] ),
		);
		if ( 'percent' === $out['deposit_type'] ) {
			$out['deposit_value'] = min( 100, $out['deposit_value'] );
		}
		foreach ( self::SECRETS as $key ) {
			$value       = trim( sanitize_text_field( (string) ( $input[ $key ] ?? '' ) ) );
			$out[ $key ] = '' === $value ? (string) $current[ $key ] : $value;
			if ( ! empty( $input[ $key . '_remove' ] ) ) {
				$out[ $key ] = '';
			}
		}
		if ( '' !== $out['stripe_secret'] && ! preg_match( '/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,}$/', $out['stripe_secret'] ) ) {
			return new \WP_Error( 'kalendora_pro_stripe_key', __( 'The Stripe secret key starts with sk_live_ or sk_test_ (Developers → API keys).', 'kalendora-pro' ) );
		}
		if ( '' !== $out['stripe_webhook'] && ! str_starts_with( $out['stripe_webhook'], 'whsec_' ) ) {
			return new \WP_Error( 'kalendora_pro_stripe_webhook', __( 'The Stripe webhook signing secret starts with whsec_.', 'kalendora-pro' ) );
		}
		if ( 'off' !== $out['mode'] && '' === $out['stripe_secret'] && ( '' === $out['paypal_client'] || '' === $out['paypal_secret'] ) ) {
			return new \WP_Error( 'kalendora_pro_no_provider', __( 'Please enter the access data of Stripe or PayPal first.', 'kalendora-pro' ) );
		}
		$crypto = Kalendora::instance()->container()->get( Crypto::class );
		foreach ( self::SECRETS as $key ) {
			$out[ $key ] = '' === $out[ $key ] ? '' : $crypto->encrypt( $out[ $key ] );
		}
		update_option( self::OPTION, $out, false );
		return true;
	}

	/**
	 * Configured providers.
	 *
	 * @param array<string, mixed>|null $s Settings.
	 * @return string[] stripe|paypal.
	 */
	public static function providers( ?array $s = null ): array {
		$s   = $s ?? self::settings();
		$out = array();
		if ( '' !== $s['stripe_secret'] ) {
			$out[] = 'stripe';
		}
		if ( '' !== $s['paypal_client'] && '' !== $s['paypal_secret'] ) {
			$out[] = 'paypal';
		}
		return $out;
	}

	/**
	 * Amount to pay online for a service, or 0.
	 *
	 * @param array<string, mixed>|null $service Service.
	 * @param int                       $seats   Places.
	 * @param array<string, mixed>|null $s       Settings.
	 * @param float                     $extra   Travel costs of this booking.
	 * @return array{amount:float, total:float, kind:string}
	 */
	public static function amount( ?array $service, int $seats = 1, ?array $s = null, float $extra = 0.0 ): array {
		$s     = $s ?? self::settings();
		$total = $service ? round( (float) $service['price'] * max( 1, $seats ) + max( 0.0, $extra ), 2 ) : 0.0;
		$none  = array(
			'amount' => 0.0,
			'total'  => $total,
			'kind'   => 'none',
		);
		if ( 'off' === $s['mode'] || $total <= 0 || ! self::providers( $s ) ) {
			return $none;
		}
		if ( $s['services'] && ! in_array( (int) $service['id'], array_map( 'intval', $s['services'] ), true ) ) {
			return $none;
		}
		$amount = $total;
		if ( 'deposit' === $s['mode'] ) {
			$amount = 'fixed' === $s['deposit_type'] ? min( $total, (float) $s['deposit_value'] ) : round( $total * (float) $s['deposit_value'] / 100, 2 );
		}
		/**
		 * Amount paid online (0 = no online payment).
		 *
		 * @param float                $amount  Amount.
		 * @param array<string, mixed> $service Service.
		 * @param int                  $seats   Places.
		 */
		$amount = round( (float) apply_filters( 'kalendora_pro_payment_amount', $amount, $service, $seats ), 2 );
		return $amount > 0 ? array(
			'amount' => $amount,
			'total'  => $total,
			'kind'   => $amount < $total ? 'deposit' : 'full',
		) : $none;
	}

	/* ------------------------------------------------------------------ booking flow */

	/**
	 * Before the booking is stored: keep it unconfirmed if it has to be paid.
	 *
	 * @param array<string, mixed> $input Booking input.
	 * @return array<string, mixed>
	 */
	public function booking_input( array $input ): array {
		$service = Kalendora::instance()->container()->get( ServiceRepository::class )->find( (int) ( $input['service_id'] ?? 0 ) );
		$amount  = self::amount( $service, (int) ( $input['seats'] ?? 1 ), null, (float) ( $input['location']['cost'] ?? 0 ) );
		if ( $amount['amount'] > 0 ) {
			$input['status']                 = 'pending';
			$input['_kalendora_pro_payment'] = $amount;
		}
		return $input;
	}

	/**
	 * Booking stored: create the open payment.
	 *
	 * @param int                  $booking_id Booking.
	 * @param int                  $entry_id   Entry.
	 * @param array<string, mixed> $input      Input.
	 */
	public function booking_created( int $booking_id, int $entry_id, array $input = array() ): void {
		unset( $entry_id );
		$amount = $input['_kalendora_pro_payment'] ?? null;
		if ( ! is_array( $amount ) ) {
			return;
		}
		global $wpdb;
		$s   = self::settings();
		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'booking_id' => $booking_id,
				'amount'     => $amount['amount'],
				'total'      => $amount['total'],
				'currency'   => strtoupper( (string) apply_filters( 'kalendora_currency', 'EUR' ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filter of the Kalendora core.
				'kind'       => $amount['kind'],
				'status'     => 'open',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + (int) $s['minutes'] * MINUTE_IN_SECONDS ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$id                        = (int) $wpdb->insert_id;
		$this->hold[ $booking_id ] = true;
		wp_schedule_single_event( time() + (int) $s['minutes'] * MINUTE_IN_SECONDS + MINUTE_IN_SECONDS, self::CRON, array( $id ) );
	}

	/**
	 * Emails of new bookings wait until they are paid.
	 *
	 * @param bool $notify     Notify.
	 * @param int  $booking_id Booking.
	 */
	public function notify( bool $notify, int $booking_id ): bool {
		return isset( $this->hold[ $booking_id ] ) ? false : $notify;
	}

	/**
	 * Send the visitor to the payment page.
	 *
	 * @param array<string, mixed> $response   Response.
	 * @param int                  $booking_id Booking.
	 * @return array<string, mixed>
	 */
	public function response( array $response, int $booking_id ): array {
		$payment = self::for_booking( $booking_id );
		if ( $payment && 'open' === $payment['status'] ) {
			$response['redirect'] = self::url( $payment );
			$response['message']  = '<p>' . esc_html__( 'Almost done – you are being forwarded to the payment.', 'kalendora-pro' ) . '</p>';
		}
		return $response;
	}

	/* ------------------------------------------------------------------ payment page */

	/**
	 * Address of the payment page of a payment.
	 *
	 * @param array<string, mixed>  $payment Payment.
	 * @param array<string, string> $extra  Extra query args.
	 */
	public static function url( array $payment, array $extra = array() ): string {
		return add_query_arg(
			array(
				self::QUERY_VAR => (int) $payment['id'],
				't'             => self::token( $payment ),
			) + $extra,
			home_url( '/' )
		);
	}

	/**
	 * Secret token for the payment page (nothing stored, HMAC).
	 *
	 * @param array<string, mixed> $payment Payment.
	 */
	public static function token( array $payment ): string {
		return substr( hash_hmac( 'sha256', 'pay|' . (int) $payment['id'] . '|' . (int) $payment['booking_id'] . '|' . $payment['created_at'], wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Payment page, provider redirect and return.
	 */
	public function page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated by the HMAC token in the link.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$payment = self::find( absint( $_GET[ self::QUERY_VAR ] ) );
		$token   = sanitize_text_field( wp_unslash( $_GET['t'] ?? '' ) );
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		if ( ! $payment || ! hash_equals( self::token( $payment ), $token ) ) {
			$this->output( __( 'Payment', 'kalendora-pro' ), '<p>' . esc_html__( 'This payment link is not valid.', 'kalendora-pro' ) . '</p>', 'error', 404 );
		}

		$notice = '';
		if ( 'open' === $payment['status'] ) {
			$s = self::settings();
			if ( isset( $_POST['onsite'] ) && self::onsite_allowed( $payment, $s ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authenticated by the HMAC token.
				$this->choose_onsite( (int) $payment['id'] );
				$payment = self::find( (int) $payment['id'] );
			} elseif ( isset( $_GET['go'] ) ) {
				$this->start( $payment, sanitize_key( wp_unslash( $_GET['go'] ) ), $s );
				$payment = self::find( (int) $payment['id'] );
				$notice  = __( 'The payment page could not be opened. Please try again or choose another payment method.', 'kalendora-pro' );
			} elseif ( isset( $_GET['return'] ) ) {
				$result = $this->verify( $payment, sanitize_key( wp_unslash( $_GET['return'] ) ), sanitize_text_field( wp_unslash( $_GET['session'] ?? $_GET['token'] ?? '' ) ), $s );
				if ( is_wp_error( $result ) ) {
					$notice = $result->get_error_message();
				}
				$payment = self::find( (int) $payment['id'] );
			} elseif ( isset( $_GET['cancelled'] ) ) {
				$notice = __( 'The payment was cancelled. You can try again.', 'kalendora-pro' );
			}
			if ( 'open' === $payment['status'] && strtotime( $payment['expires_at'] . ' UTC' ) < time() ) {
				$this->expire( (int) $payment['id'] );
				$payment = self::find( (int) $payment['id'] );
			}
		}
		// phpcs:enable
		$this->render( $payment, $notice );
	}

	/**
	 * Create the provider session and redirect.
	 *
	 * @param array<string, mixed> $payment  Payment.
	 * @param string               $provider stripe|paypal.
	 * @param array<string, mixed> $s        Settings.
	 * @throws \RuntimeException In tests instead of redirecting.
	 */
	private function start( array $payment, string $provider, array $s ): void {
		if ( ! in_array( $provider, self::providers( $s ), true ) ) {
			return;
		}
		$booking = Kalendora::instance()->container()->get( EntryRepository::class )->booking( (int) $payment['booking_id'] );
		if ( ! $booking ) {
			return;
		}
		global $wpdb;
		$attempt = (int) $payment['attempts'] + 1;
		$wpdb->update( self::table(), array( 'attempts' => $attempt ), array( 'id' => (int) $payment['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$name = sprintf(
			/* translators: 1: service, 2: date and time. */
			__( '%1$s on %2$s', 'kalendora-pro' ),
			$booking['service_name'],
			wp_date( get_option( 'date_format' ) . ' H:i', (int) strtotime( $booking['start'] ) )
		);
		if ( 'deposit' === $payment['kind'] ) {
			/* translators: %s: booking description. */
			$name = sprintf( __( 'Deposit: %s', 'kalendora-pro' ), $name );
		}
		$minutes = max( 1, (int) ceil( ( strtotime( $payment['expires_at'] . ' UTC' ) - time() ) / 60 ) );
		$common  = array(
			'amount'     => (float) $payment['amount'],
			'currency'   => $payment['currency'],
			'name'       => $name,
			'email'      => $booking['email'],
			'payment_id' => (int) $payment['id'],
			'booking_id' => (int) $payment['booking_id'],
			'attempt'    => $attempt,
			'minutes'    => $minutes,
			'brand'      => (string) Kalendora::instance()->container()->get( Settings::class )->get( 'business_name', get_bloginfo( 'name' ) ),
		);
		if ( 'stripe' === $provider ) {
			$result = ( new Stripe( $s['stripe_secret'] ) )->create_session(
				$common + array(
					'success_url' => self::url( $payment, array( 'return' => 'stripe' ) ) . '&session={CHECKOUT_SESSION_ID}',
					'cancel_url'  => self::url( $payment, array( 'cancelled' => '1' ) ),
				)
			);
		} else {
			$result = ( new PayPal( $s['paypal_client'], $s['paypal_secret'], (bool) $s['paypal_sandbox'] ) )->create_order(
				$common + array(
					'return_url' => self::url( $payment, array( 'return' => 'paypal' ) ),
					'cancel_url' => self::url( $payment, array( 'cancelled' => '1' ) ),
				)
			);
		}
		if ( is_wp_error( $result ) ) {
			return;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'provider'   => $provider,
				'session_id' => $result['id'],
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $payment['id'] )
		);
		if ( defined( 'KALENDORA_PRO_TESTING' ) ) {
			throw new \RuntimeException( 'redirect:' . $result['url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		wp_redirect( $result['url'] ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- provider payment page.
		exit;
	}

	/**
	 * Back from the provider: check the payment there (never trust the URL alone).
	 *
	 * @param array<string, mixed> $payment  Payment.
	 * @param string               $provider stripe|paypal.
	 * @param string               $ref      Session id (Stripe) or order id (PayPal).
	 * @param array<string, mixed> $s        Settings.
	 * @return true|\WP_Error
	 */
	public function verify( array $payment, string $provider, string $ref, array $s ) {
		if ( $provider !== $payment['provider'] || '' === $ref || ! hash_equals( (string) $payment['session_id'], $ref ) ) {
			return new \WP_Error( 'kalendora_pro_ref', __( 'The payment could not be assigned. If you have paid, please contact us.', 'kalendora-pro' ) );
		}
		$result = 'stripe' === $provider
			? ( new Stripe( $s['stripe_secret'] ) )->session_status( $ref )
			: ( new PayPal( $s['paypal_client'], $s['paypal_secret'], (bool) $s['paypal_sandbox'] ) )->capture( $ref );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result['paid'] ) {
			return new \WP_Error( 'kalendora_pro_unpaid', __( 'The payment has not been completed yet.', 'kalendora-pro' ) );
		}
		if ( abs( (float) $result['amount'] - (float) $payment['amount'] ) > 0.009 ) {
			return new \WP_Error( 'kalendora_pro_amount', __( 'The paid amount does not match. Please contact us.', 'kalendora-pro' ) );
		}
		$this->mark_paid( (int) $payment['id'], (string) $result['reference'] );
		return true;
	}

	/**
	 * Paid: confirm the booking and send the emails (once).
	 *
	 * @param int    $id        Payment id.
	 * @param string $reference Provider reference (payment intent / capture id).
	 */
	public function mark_paid( int $id, string $reference ): void {
		global $wpdb;
		$before = self::find( $id );
		// Atomic: only the first caller (return page or webhook) continues. "onsite": the visitor chose
		// to pay on site but completed an online payment started before – the money counts.
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'paid', reference = %s, paid_at = %s, updated_at = %s WHERE id = %d AND status IN ('open','expired','onsite')", $reference, current_time( 'mysql', true ), current_time( 'mysql', true ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $changed ) {
			return;
		}
		self::$cache = array();
		$payment     = self::find( $id );
		if ( $before && 'onsite' === $before['status'] ) {
			do_action( 'kalendora_pro_payment_paid', $payment );
			return; // Booking and emails were already handled when "on site" was chosen.
		}
		$entries = Kalendora::instance()->container()->get( EntryRepository::class );
		$booking = $entries->booking( (int) $payment['booking_id'] );
		if ( ! $booking ) {
			return;
		}
		/**
		 * Confirm bookings automatically after payment (default true).
		 *
		 * @param bool                 $confirm Confirm.
		 * @param array<string, mixed> $booking Booking.
		 */
		if ( 'pending' === $booking['status'] && apply_filters( 'kalendora_pro_confirm_paid', true, $booking ) ) {
			$entries->set_booking_status( (int) $booking['id'], 'confirmed' );
		} elseif ( 'pending' === $booking['status'] ) {
			$mailer = Kalendora::instance()->container()->get( Mailer::class );
			$ctx    = $mailer->booking_context( (int) $booking['id'] );
			if ( $ctx ) {
				$mailer->send( 'booking_received', $ctx );
			}
		}
		$mailer = Kalendora::instance()->container()->get( Mailer::class );
		$ctx    = $mailer->booking_context( (int) $booking['id'] );
		if ( $ctx ) {
			$mailer->send( 'admin_new_booking', $ctx );
		}
		/**
		 * After an online payment.
		 *
		 * @param array<string, mixed> $payment Payment.
		 */
		do_action( 'kalendora_pro_payment_paid', $payment );
	}

	/**
	 * May this payment be settled on site instead?
	 *
	 * @param array<string, mixed>      $payment Payment.
	 * @param array<string, mixed>|null $s       Settings.
	 */
	public static function onsite_allowed( array $payment, ?array $s = null ): bool {
		$s = $s ?? self::settings();
		/**
		 * Offer "pay on site" for this payment (setting "onsite" by default).
		 *
		 * @param bool                 $allowed Allowed.
		 * @param array<string, mixed> $payment Payment.
		 */
		return (bool) apply_filters( 'kalendora_pro_onsite_allowed', ! empty( $s['onsite'] ), $payment );
	}

	/**
	 * Visitor pays on site: the booking continues like one without online payment.
	 *
	 * @param int $id Payment id.
	 */
	public function choose_onsite( int $id ): void {
		global $wpdb;
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'onsite', updated_at = %s WHERE id = %d AND status = 'open'", current_time( 'mysql', true ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $changed ) {
			return;
		}
		wp_clear_scheduled_hook( self::CRON, array( $id ) );
		self::$cache = array();
		$payment     = self::find( $id );
		$entries     = Kalendora::instance()->container()->get( EntryRepository::class );
		$booking     = $entries->booking( (int) $payment['booking_id'] );
		if ( ! $booking ) {
			return;
		}
		$mailer = Kalendora::instance()->container()->get( Mailer::class );
		$target = (string) Kalendora::instance()->container()->get( Settings::class )->get( 'default_status', 'pending' );
		/**
		 * Status of a booking paid on site (default: the normal status of new bookings).
		 *
		 * @param string               $status  pending|confirmed.
		 * @param array<string, mixed> $booking Booking.
		 */
		$target = (string) apply_filters( 'kalendora_pro_onsite_status', $target, $booking );
		if ( 'pending' === $booking['status'] && 'confirmed' === $target ) {
			$entries->set_booking_status( (int) $booking['id'], 'confirmed' ); // Sends the confirmation.
		} elseif ( 'pending' === $booking['status'] ) {
			$ctx = $mailer->booking_context( (int) $booking['id'] );
			if ( $ctx ) {
				$mailer->send( 'booking_received', $ctx );
			}
		}
		$ctx = $mailer->booking_context( (int) $booking['id'] );
		if ( $ctx ) {
			$mailer->send( 'admin_new_booking', $ctx );
		}
		/**
		 * The visitor chose to pay on site.
		 *
		 * @param array<string, mixed> $payment Payment.
		 */
		do_action( 'kalendora_pro_payment_onsite', $payment );
	}

	/**
	 * The business received the money on site (cash, card terminal).
	 *
	 * @param int $id Payment id.
	 */
	public function mark_received( int $id ): bool {
		global $wpdb;
		$changed     = (bool) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'paid', provider = 'onsite', reference = 'onsite', amount = total, kind = 'full', paid_at = %s, updated_at = %s WHERE id = %d AND status = 'onsite'", current_time( 'mysql', true ), current_time( 'mysql', true ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		self::$cache = array();
		if ( $changed ) {
			do_action( 'kalendora_pro_payment_paid', self::find( $id ) );
		}
		return $changed;
	}

	/**
	 * Not paid in time: free the slot.
	 *
	 * @param int $id Payment id.
	 */
	public function expire( int $id ): void {
		$payment = self::find( $id );
		if ( ! $payment || 'open' !== $payment['status'] || strtotime( $payment['expires_at'] . ' UTC' ) > time() ) {
			return;
		}
		// A late webhook may be missing: ask Stripe once before giving up.
		if ( 'stripe' === $payment['provider'] && '' !== $payment['session_id'] ) {
			$s      = self::settings();
			$status = ( new Stripe( $s['stripe_secret'] ) )->session_status( (string) $payment['session_id'] );
			if ( ! is_wp_error( $status ) && $status['paid'] ) {
				$this->mark_paid( $id, (string) $status['reference'] );
				return;
			}
		}
		global $wpdb;
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'expired', updated_at = %s WHERE id = %d AND status = 'open'", current_time( 'mysql', true ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		if ( $changed ) {
			self::$cache = array();
			$entries     = Kalendora::instance()->container()->get( EntryRepository::class );
			$booking     = $entries->booking( (int) $payment['booking_id'] );
			if ( $booking && 'pending' === $booking['status'] ) {
				$entries->set_cancelled_by( (int) $booking['id'], 'payment' );
				$entries->set_booking_status( (int) $booking['id'], 'cancelled' );
			}
		}
	}

	/* ------------------------------------------------------------------ refunds */

	/**
	 * Customer cancelled online: refund what was paid minus the fee.
	 *
	 * @param int   $booking_id Booking.
	 * @param float $fee        Cancellation fee.
	 */
	public function customer_cancelled( int $booking_id, $fee = 0.0 ): void {
		if ( ! self::settings()['refund_cancel'] ) {
			return;
		}
		$payment = self::for_booking( $booking_id );
		if ( $payment && 'paid' === $payment['status'] && 'onsite' !== $payment['provider'] ) {
			$this->refund( $payment, max( 0.0, (float) $payment['amount'] - (float) $fee ) );
		}
	}

	/**
	 * Cancelled or rejected by the business: full refund.
	 *
	 * @param int    $booking_id Booking.
	 * @param string $status     New status.
	 * @param string $previous   Previous status.
	 */
	public function status_changed( int $booking_id, string $status, string $previous ): void {
		unset( $previous );
		if ( ! in_array( $status, array( 'cancelled', 'rejected' ), true ) || ! self::settings()['refund_cancel'] ) {
			return;
		}
		$booking = Kalendora::instance()->container()->get( EntryRepository::class )->booking( $booking_id );
		if ( ! $booking || in_array( $booking['cancelled_by'], array( 'customer', 'payment' ), true ) ) {
			return; // Customer cancellations are handled with the fee in customer_cancelled().
		}
		$payment = self::for_booking( $booking_id );
		if ( $payment && 'paid' === $payment['status'] && 'onsite' !== $payment['provider'] ) {
			$this->refund( $payment, (float) $payment['amount'] );
		}
	}

	/**
	 * Refund at the provider.
	 *
	 * @param array<string, mixed> $payment Payment.
	 * @param float                $amount  Amount.
	 * @return true|\WP_Error
	 */
	public function refund( array $payment, float $amount ) {
		$amount = round( min( $amount, (float) $payment['amount'] - (float) $payment['refunded'] ), 2 );
		if ( $amount <= 0 ) {
			return true;
		}
		if ( 'onsite' === $payment['provider'] ) {
			return new \WP_Error( 'kalendora_pro_onsite_refund', __( 'This amount was paid on site. Please refund it directly to the customer.', 'kalendora-pro' ) );
		}
		$s      = self::settings();
		$result = 'stripe' === $payment['provider']
			? ( new Stripe( $s['stripe_secret'] ) )->refund( (string) $payment['reference'], $amount )
			: ( new PayPal( $s['paypal_client'], $s['paypal_secret'], (bool) $s['paypal_sandbox'] ) )->refund( (string) $payment['reference'], $amount, (string) $payment['currency'] );
		if ( is_wp_error( $result ) ) {
			do_action( 'kalendora_pro_refund_failed', $payment, $amount, $result );
			return $result;
		}
		global $wpdb;
		$refunded = round( (float) $payment['refunded'] + $amount, 2 );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'refunded'   => $refunded,
				'status'     => $refunded >= (float) $payment['amount'] ? 'refunded' : 'partially_refunded',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $payment['id'] )
		);
		self::$cache = array();
		return true;
	}

	/* ------------------------------------------------------------------ Stripe webhook */

	/**
	 * Routes.
	 */
	public function routes(): void {
		register_rest_route(
			'kalendora/v1',
			'/pro/stripe-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'stripe_webhook' ),
				'permission_callback' => '__return_true', // Authenticated by the Stripe signature.
			)
		);
	}

	/**
	 * Stripe webhook: checkout.session.completed / async_payment_succeeded.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function stripe_webhook( WP_REST_Request $request ): WP_REST_Response {
		$s       = self::settings();
		$payload = (string) $request->get_body();
		if ( ! Stripe::verify_signature( $payload, (string) $request->get_header( 'stripe_signature' ), (string) $s['stripe_webhook'] ) ) {
			return new WP_REST_Response( array( 'error' => 'signature' ), 400 );
		}
		$event   = json_decode( $payload, true );
		$session = $event['data']['object'] ?? array();
		if ( in_array( $event['type'] ?? '', array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true ) && 'paid' === ( $session['payment_status'] ?? '' ) ) {
			$payment = self::find( (int) ( $session['metadata']['payment_id'] ?? $session['client_reference_id'] ?? 0 ) );
			if ( $payment && hash_equals( (string) $payment['session_id'], (string) ( $session['id'] ?? '' ) ) && Stripe::cents( (float) $payment['amount'] ) === (int) ( $session['amount_total'] ?? -1 ) ) {
				$this->mark_paid( (int) $payment['id'], (string) ( $session['payment_intent'] ?? '' ) );
			}
		}
		return new WP_REST_Response( array( 'received' => true ) );
	}

	/* ------------------------------------------------------------------ data */

	/**
	 * Payment by id.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * Latest payment of a booking.
	 *
	 * @param int $booking_id Booking.
	 * @return array<string, mixed>|null
	 */
	public static function for_booking( int $booking_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE booking_id = %d ORDER BY id DESC LIMIT 1', $booking_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * Latest payments (admin list).
	 *
	 * @param int $limit Limit.
	 * @return list<array<string, mixed>>
	 */
	public static function latest( int $limit = 100 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * "49,00 €".
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 */
	public static function money( float $amount, string $currency = 'EUR' ): string {
		return number_format_i18n( $amount, 2 ) . ' ' . ( 'EUR' === $currency ? '€' : $currency );
	}

	/**
	 * Badge in the admin booking list.
	 *
	 * @param array<string, mixed> $booking Booking.
	 * @return array<string, mixed>
	 */
	public function badges( array $booking ): array {
		$id = (int) ( $booking['id'] ?? 0 );
		if ( ! array_key_exists( $id, self::$cache ) ) {
			self::$cache[ $id ] = self::for_booking( $id );
		}
		$p = self::$cache[ $id ];
		if ( ! $p ) {
			return $booking;
		}
		$money = self::money( (float) $p['amount'], (string) $p['currency'] );
		$total = self::money( (float) $p['total'], (string) $p['currency'] );
		$map   = array(
			/* translators: %s: amount. */
			'onsite'             => array( sprintf( __( 'Pays on site: %s', 'kalendora-pro' ), $total ), 'warning' ),
			/* translators: %s: amount. */
			'open'               => array( sprintf( __( 'Payment open: %s', 'kalendora-pro' ), $money ), 'warning' ),
			/* translators: %s: amount. */
			'paid'               => array( sprintf( 'onsite' === $p['provider'] ? __( 'Paid on site: %s', 'kalendora-pro' ) : ( 'deposit' === $p['kind'] ? __( 'Deposit paid: %s', 'kalendora-pro' ) : __( 'Paid online: %s', 'kalendora-pro' ) ), $money ), 'success' ),
			'expired'            => array( __( 'Not paid in time', 'kalendora-pro' ), 'neutral' ),
			/* translators: %s: amount. */
			'refunded'           => array( sprintf( __( 'Refunded: %s', 'kalendora-pro' ), self::money( (float) $p['refunded'], (string) $p['currency'] ) ), 'neutral' ),
			/* translators: %s: amount. */
			'partially_refunded' => array( sprintf( __( 'Partly refunded: %s', 'kalendora-pro' ), self::money( (float) $p['refunded'], (string) $p['currency'] ) ), 'neutral' ),
		);
		if ( isset( $map[ $p['status'] ] ) ) {
			$booking['badges'][] = array(
				'label' => $map[ $p['status'] ][0],
				'tone'  => $map[ $p['status'] ][1],
			);
		}
		return $booking;
	}

	/* ------------------------------------------------------------------ output */

	/**
	 * Page for the customer.
	 *
	 * @param array<string, mixed> $payment Payment.
	 * @param string               $notice  Message.
	 */
	private function render( array $payment, string $notice ): void {
		$booking = Kalendora::instance()->container()->get( EntryRepository::class )->booking( (int) $payment['booking_id'] );
		$when    = $booking ? wp_date( 'l, ' . get_option( 'date_format' ) . ', H:i', (int) strtotime( $booking['start'] ) ) : '';
		$money   = self::money( (float) $payment['amount'], (string) $payment['currency'] );
		$summary = '<dl><dt>' . esc_html__( 'Service', 'kalendora-pro' ) . '</dt><dd>' . esc_html( $booking['service_name'] ?? '' ) . '</dd>'
			. '<dt>' . esc_html__( 'Appointment', 'kalendora-pro' ) . '</dt><dd>' . esc_html( $when ) . '</dd>'
			. '<dt>' . esc_html( 'deposit' === $payment['kind'] ? __( 'Deposit', 'kalendora-pro' ) : __( 'Amount', 'kalendora-pro' ) ) . '</dt><dd><strong>' . esc_html( $money ) . '</strong>'
			. ( 'deposit' === $payment['kind'] ? ' <small>(' . esc_html( sprintf( /* translators: %s: total price. */ __( 'of %s', 'kalendora-pro' ), self::money( (float) $payment['total'], (string) $payment['currency'] ) ) ) . ')</small>' : '' ) . '</dd></dl>';
		$note    = '' !== $notice ? '<p class="notice">' . esc_html( $notice ) . '</p>' : '';

		switch ( $payment['status'] ) {
			case 'onsite':
				$total = self::money( (float) $payment['total'], (string) $payment['currency'] );
				$this->output(
					__( 'Thank you for your booking', 'kalendora-pro' ),
					'<dl><dt>' . esc_html__( 'Service', 'kalendora-pro' ) . '</dt><dd>' . esc_html( $booking['service_name'] ?? '' ) . '</dd><dt>' . esc_html__( 'Appointment', 'kalendora-pro' ) . '</dt><dd>' . esc_html( $when ) . '</dd><dt>' . esc_html__( 'To pay on site', 'kalendora-pro' ) . '</dt><dd><strong>' . esc_html( $total ) . '</strong></dd></dl>'
						. '<p class="ok">' . esc_html( $booking && 'confirmed' === $booking['status'] ? __( 'Your appointment is booked. You pay on site, in cash or as offered there. You will receive a confirmation by email.', 'kalendora-pro' ) : __( 'Your booking request has been received. You pay on site, in cash or as offered there. You will receive an email from us.', 'kalendora-pro' ) ) . '</p>',
					'success'
				);
				break;
			case 'paid':
				$this->output( __( 'Thank you for your payment', 'kalendora-pro' ), $summary . '<p class="ok">' . esc_html__( 'Your payment has been received and your appointment is confirmed. You will receive a confirmation by email.', 'kalendora-pro' ) . '</p>', 'success' );
				break;
			case 'expired':
				$this->output( __( 'Time expired', 'kalendora-pro' ), '<p>' . esc_html__( 'The payment was not completed in time, so the appointment has been released. You are welcome to book again.', 'kalendora-pro' ) . '</p><p><a class="btn" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to the website', 'kalendora-pro' ) . '</a></p>', 'neutral' );
				break;
			case 'refunded':
			case 'partially_refunded':
				$this->output( __( 'Payment refunded', 'kalendora-pro' ), $summary . '<p>' . esc_html( sprintf( /* translators: %s: amount. */ __( 'We have refunded %s.', 'kalendora-pro' ), self::money( (float) $payment['refunded'], (string) $payment['currency'] ) ) ) . '</p>', 'neutral' );
				break;
			default:
				$left    = max( 1, (int) ceil( ( strtotime( $payment['expires_at'] . ' UTC' ) - time() ) / 60 ) );
				$buttons = '';
				foreach ( self::providers() as $provider ) {
					$label    = 'stripe' === $provider ? __( 'Pay by card, Apple Pay or Google Pay', 'kalendora-pro' ) : __( 'Pay with PayPal', 'kalendora-pro' );
					$buttons .= '<p><a class="btn' . ( 'paypal' === $provider ? ' btn-secondary' : '' ) . '" href="' . esc_url( self::url( $payment, array( 'go' => $provider ) ) ) . '">' . esc_html( $label ) . '</a></p>';
				}
				if ( self::onsite_allowed( $payment ) ) {
					$buttons .= '<form method="post" action="' . esc_url( self::url( $payment ) ) . '"><p><button type="submit" name="onsite" value="1" class="btn btn-secondary">'
						/* translators: %s: total price. */
						. esc_html( sprintf( __( 'Pay on site instead (%s)', 'kalendora-pro' ), self::money( (float) $payment['total'], (string) $payment['currency'] ) ) ) . '</button></p></form>';
				}
				$this->output(
					__( 'Pay to confirm your appointment', 'kalendora-pro' ),
					$note . $summary . $buttons
						/* translators: %d: minutes. */
						. '<p class="hint">' . esc_html( sprintf( _n( 'The appointment is reserved for you for %d more minute. You pay on the secure page of the payment provider.', 'The appointment is reserved for you for %d more minutes. You pay on the secure page of the payment provider.', $left, 'kalendora-pro' ), $left ) ) . '</p>',
					'neutral'
				);
		}
	}

	/**
	 * Standalone page (same look as Kalendora's pages).
	 *
	 * @param string $title  Title.
	 * @param string $body   HTML.
	 * @param string $tone   success|error|neutral.
	 * @param int    $status HTTP status.
	 * @throws \RuntimeException In tests instead of printing.
	 */
	private function output( string $title, string $body, string $tone, int $status = 200 ): void {
		if ( defined( 'KALENDORA_PRO_TESTING' ) ) {
			throw new \RuntimeException( 'page:' . $title . '|' . wp_strip_all_tags( $body ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		StandalonePage::output( Kalendora::instance()->container()->get( Settings::class ), $title, $body, $tone, $status );
		exit;
	}
}
