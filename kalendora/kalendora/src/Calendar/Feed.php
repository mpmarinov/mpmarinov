<?php
/**
 * Subscribable calendar feeds (iPhone, Android/Google, Outlook, Thunderbird …).
 *
 * @package Kalendora
 */

namespace Kalendora\Calendar;

use Kalendora\Notifications\Ics;
use Kalendora\Repository\EntryRepository;
use Kalendora\Repository\ResourceRepository;
use Kalendora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * URL: /?kalendora_feed=<secret token>. One feed for the whole business ("all") and one per staff member ("r<ID>").
 * The token is the only protection (like Google's "secret address"), so it can be reset at any time.
 */
final class Feed {

	public const QUERY_VAR = 'kalendora_feed';
	public const OPTION    = 'kalendora_feed_tokens';
	public const PAST_DAYS = 60;
	public const NEXT_DAYS = 400;
	public const SUBSCRIBE = 'subscribe';

	/**
	 * Constructor.
	 *
	 * @param EntryRepository    $entries   Bookings.
	 * @param ResourceRepository $resources Staff.
	 * @param Settings           $settings  Settings.
	 */
	public function __construct(
		private EntryRepository $entries,
		private ResourceRepository $resources,
		private Settings $settings,
	) {}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'serve' ), 0 );
	}

	/**
	 * Valid scope names.
	 *
	 * @param string $scope Scope.
	 */
	public static function valid_scope( string $scope ): bool {
		return 'all' === $scope || (bool) preg_match( '/^r[1-9]\d*$/', $scope );
	}

	/**
	 * Token of a scope (created on first use).
	 *
	 * @param string $scope all | r<ID>.
	 */
	public function token( string $scope ): string {
		$tokens = (array) get_option( self::OPTION, array() );
		if ( empty( $tokens[ $scope ] ) ) {
			$tokens[ $scope ] = bin2hex( random_bytes( 20 ) );
			update_option( self::OPTION, $tokens, false );
		}
		return (string) $tokens[ $scope ];
	}

	/**
	 * Reset a token – old subscriptions stop working immediately.
	 *
	 * @param string $scope Scope.
	 */
	public function regenerate( string $scope ): string {
		$tokens           = (array) get_option( self::OPTION, array() );
		$tokens[ $scope ] = bin2hex( random_bytes( 20 ) );
		update_option( self::OPTION, $tokens, false );
		return $tokens[ $scope ];
	}

	/**
	 * Feed addresses of a scope.
	 *
	 * @param string $scope Scope.
	 * @return array{https:string, webcal:string, google:string, outlook:string, subscribe:string}
	 */
	public function urls( string $scope ): array {
		$https  = add_query_arg( self::QUERY_VAR, $this->token( $scope ), home_url( '/' ) );
		$webcal = (string) preg_replace( '#^https?://#', 'webcal://', $https );
		return array(
			'https'     => $https,
			'webcal'    => $webcal,
			'google'    => 'https://calendar.google.com/calendar/r?cid=' . rawurlencode( $webcal ),
			'outlook'   => add_query_arg(
				array(
					'url'  => rawurlencode( $https ),
					'name' => rawurlencode( $this->calendar_name( $scope ) ),
				),
				'https://outlook.live.com/calendar/0/addfromweb'
			),
			// Page that picks the right calendar app on the phone (used for the QR code).
			'subscribe' => $this->subscribe_url( $scope ),
		);
	}

	/**
	 * Device-independent link (for emails): opens a small page that starts the right calendar app.
	 *
	 * @param string $scope Scope.
	 */
	public function subscribe_url( string $scope ): string {
		return add_query_arg(
			array(
				self::QUERY_VAR => $this->token( $scope ),
				self::SUBSCRIBE => '1',
			),
			home_url( '/' )
		);
	}

	/**
	 * Email the subscription link (e.g. to a staff member's phone).
	 *
	 * @param string                          $scope  Scope.
	 * @param string                          $to     Recipient.
	 * @param \Kalendora\Notifications\Mailer $mailer Mailer.
	 */
	public function send_link( string $scope, string $to, \Kalendora\Notifications\Mailer $mailer ): bool {
		$name  = $this->calendar_name( $scope );
		$color = sanitize_hex_color( (string) $this->settings->get( 'primary_color', '#2563eb' ) );
		$color = $color ? $color : '#2563eb';
		$body  = '<p>' . esc_html__( 'Hello,', 'kalendora' ) . '</p>'
			. '<p>' . esc_html(
				sprintf(
					/* translators: %s: calendar name. */
					__( 'with this link you can subscribe to the calendar "%s" on your phone or computer. New, moved and cancelled appointments then appear automatically.', 'kalendora' ),
					$name
				)
			) . '</p>'
			. '<p style="margin:24px 0;"><a href="' . esc_url( $this->subscribe_url( $scope ) ) . '" style="display:inline-block;padding:12px 22px;background:' . $color . ';color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;">&#128197; ' . esc_html__( 'Subscribe to calendar', 'kalendora' ) . '</a></p>'
			. '<p style="font-size:13px;color:#6b7280;">' . esc_html__( 'Open this email on the device where the calendar should appear. Keep the link private – anyone who has it can see the appointments.', 'kalendora' ) . '</p>';

		return $mailer->send_message(
			$to,
			sprintf(
				/* translators: %s: calendar name. */
				__( 'Your calendar subscription: %s', 'kalendora' ),
				$name
			),
			$body,
			'feed_link'
		);
	}

	/**
	 * Scope for a token.
	 *
	 * @param string $token Token.
	 */
	private function scope_for( string $token ): ?string {
		foreach ( (array) get_option( self::OPTION, array() ) as $scope => $known ) {
			if ( is_string( $known ) && '' !== $known && hash_equals( $known, $token ) ) {
				return (string) $scope;
			}
		}
		return null;
	}

	/**
	 * Calendar name shown in the calendar app.
	 *
	 * @param string $scope Scope.
	 */
	public function calendar_name( string $scope ): string {
		$name = trim( (string) $this->settings->get( 'business_name', '' ) );
		$name = '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		if ( 'all' !== $scope ) {
			$resource = $this->resources->find( (int) substr( $scope, 1 ) );
			if ( $resource ) {
				$name .= ' – ' . $resource['name'];
			}
		}
		return 'Kalendora · ' . $name;
	}

	/**
	 * Output the feed.
	 */
	public function serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- secret token, read-only.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$scope = preg_match( '/^[a-f0-9]{40}$/', $token ) ? $this->scope_for( $token ) : null;

		if ( null === $scope || ( 'all' !== $scope && ! $this->resources->find( (int) substr( $scope, 1 ) ) ) ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found';
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page.
		if ( isset( $_GET[ self::SUBSCRIBE ] ) ) {
			$this->subscribe_page( $scope );
			exit;
		}

		$body = $this->build( $scope );
		$etag = '"' . md5( $body ) . '"';

		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Cache-Control: private, max-age=300' );
		header( 'ETag: ' . $etag );

		$match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
		if ( $match === $etag ) {
			status_header( 304 );
			exit;
		}

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="kalendora.ics"' );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar, values escaped by Ics.
		exit;
	}

	/**
	 * Small page with one button per calendar app; Apple devices are forwarded automatically.
	 *
	 * @param string $scope Scope.
	 */
	private function subscribe_page( string $scope ): void {
		$urls  = $this->urls( $scope );
		$name  = $this->calendar_name( $scope );
		$color = sanitize_hex_color( (string) $this->settings->get( 'primary_color', '#2563eb' ) );
		$color = $color ? $color : '#2563eb';
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$apple = (bool) preg_match( '/iPhone|iPad|iPod|Macintosh/i', $agent );
		$btn   = 'display:block;margin:12px 0;padding:14px 18px;border-radius:10px;text-decoration:none;font-weight:600;text-align:center;';

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Content-Type: text/html; charset=utf-8' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- all dynamic values are escaped inline.
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex,nofollow"><title>' . esc_html( $name ) . '</title>'
			. ( $apple ? '<meta http-equiv="refresh" content="0;url=' . esc_attr( $urls['webcal'] ) . '">' : '' )
			. '</head><body style="margin:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;color:#1f2937;">'
			. '<main style="max-width:440px;margin:0 auto;padding:32px 16px;">'
			. '<h1 style="font-size:22px;margin:0 0 6px;">&#128197; ' . esc_html( $name ) . '</h1>'
			. '<p style="margin:0 0 20px;color:#4b5563;">' . esc_html__( 'Choose your calendar app. New appointments will then appear automatically.', 'kalendora' ) . '</p>'
			. '<a style="' . $btn . 'background:' . esc_attr( $color ) . ';color:#fff;" href="' . esc_url( $urls['webcal'], array( 'webcal' ) ) . '">' . esc_html__( 'iPhone, iPad, Mac (Apple Calendar)', 'kalendora' ) . '</a>'
			. '<a style="' . $btn . 'background:#fff;color:#1f2937;border:1px solid #d1d5db;" href="' . esc_url( $urls['google'] ) . '">' . esc_html__( 'Android / Google Calendar', 'kalendora' ) . '</a>'
			. '<a style="' . $btn . 'background:#fff;color:#1f2937;border:1px solid #d1d5db;" href="' . esc_url( $urls['outlook'] ) . '">' . esc_html__( 'Outlook', 'kalendora' ) . '</a>'
			. '<p style="margin:24px 0 6px;font-size:14px;color:#4b5563;">' . esc_html__( 'Other calendar apps: subscribe to this address', 'kalendora' ) . '</p>'
			. '<input readonly onclick="this.select()" value="' . esc_attr( $urls['https'] ) . '" style="width:100%;box-sizing:border-box;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;">'
			. '<p style="margin:20px 0 0;font-size:13px;color:#6b7280;">' . esc_html__( 'Google updates subscribed calendars every few hours, Apple and Outlook usually more often.', 'kalendora' ) . '</p>'
			. '</main></body></html>';
		// phpcs:enable
	}

	/**
	 * Feed content.
	 *
	 * @param string $scope Scope.
	 */
	public function build( string $scope ): string {
		$details  = (bool) $this->settings->get( 'feed_customer_details', true );
		$pending  = (bool) $this->settings->get( 'feed_include_pending', true );
		$location = (string) $this->settings->get( 'business_address', '' );
		$admin    = admin_url( 'admin.php?page=kalendora-bookings' );
		$events   = array();

		foreach ( $this->bookings( $scope ) as $b ) {
			if ( 'pending' === $b['status'] && ! $pending ) {
				continue;
			}
			$who     = '' !== $b['name'] ? $b['name'] : $b['email'];
			$summary = $details && '' !== $who ? $b['service_name'] . ' – ' . $who : $b['service_name'];
			if ( 'pending' === $b['status'] ) {
				/* translators: %s: appointment title. Prefix for appointments not yet confirmed. */
				$summary = sprintf( __( '[Unconfirmed] %s', 'kalendora' ), $summary );
			}
			if ( 'all' === $scope && '' !== $b['resource_name'] ) {
				$summary .= ' (' . $b['resource_name'] . ')';
			}

			$lines = array();
			if ( $details ) {
				foreach (
					array(
						__( 'Customer', 'kalendora' ) => $b['name'],
						__( 'Phone', 'kalendora' )    => $b['phone'],
						__( 'Email', 'kalendora' )    => $b['email'],
						__( 'Places', 'kalendora' )   => $b['seats'] > 1 ? (string) $b['seats'] : '',
						__( 'Notes', 'kalendora' )    => $b['notes'],
					) as $label => $value
				) {
					if ( '' !== (string) $value ) {
						$lines[] = $label . ': ' . $value;
					}
				}
			}
			$lines[] = $admin;

			/**
			 * Filter one appointment of the calendar subscription.
			 * Return null to leave it out.
			 *
			 * @param array<string, mixed>|null $event   summary, location, description, status, booking.
			 * @param array<string, mixed>      $booking Booking.
			 * @param string                    $scope   "all" or "r<staff id>".
			 */
			$event = apply_filters(
				'kalendora_feed_event',
				array(
					'booking'     => $b,
					'summary'     => $summary,
					'location'    => $location,
					'description' => implode( "\n", $lines ),
					'status'      => 'pending' === $b['status'] ? 'TENTATIVE' : 'CONFIRMED',
				),
				$b,
				$scope
			);
			if ( is_array( $event ) && isset( $event['booking'], $event['summary'] ) ) {
				$events[] = $event;
			}
		}

		return Ics::calendar( $events, $this->calendar_name( $scope ), wp_timezone_string() );
	}

	/**
	 * Active bookings of a scope in the feed window.
	 *
	 * @param string $scope Scope.
	 * @return list<array<string, mixed>>
	 */
	private function bookings( string $scope ): array {
		$args = array(
			'view'        => 'active',
			'from'        => gmdate( 'Y-m-d', time() - self::PAST_DAYS * DAY_IN_SECONDS ),
			'to'          => gmdate( 'Y-m-d', time() + self::NEXT_DAYS * DAY_IN_SECONDS ),
			'resource_id' => 'all' === $scope ? 0 : (int) substr( $scope, 1 ),
		);
		return $this->entries->all_bookings( $args );
	}
}
