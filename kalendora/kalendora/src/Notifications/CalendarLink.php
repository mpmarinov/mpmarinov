<?php
/**
 * "Add to calendar" link that serves the .ics file from the website.
 *
 * @package Kalendora
 */

namespace Kalendora\Notifications;

use Kalendora\Repository\EntryRepository;
use Kalendora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * URL: /?kalendora_ics=<booking uid>.<signature>
 * iPhone (Safari) shows the native "Add to Calendar" dialog, Android opens the calendar app.
 * The signature prevents guessing other bookings; the file only reveals what the customer already knows.
 */
final class CalendarLink {

	public const QUERY_VAR = 'kalendora_ics';

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
		add_action( 'template_redirect', array( $this, 'serve' ), 0 );
	}

	/**
	 * Signed URL for a booking.
	 *
	 * @param string $uid Booking uid.
	 */
	public static function url( string $uid ): string {
		return add_query_arg( self::QUERY_VAR, $uid . '.' . self::sign( $uid ), home_url( '/' ) );
	}

	/**
	 * Signature (not stored; derived from the site salts).
	 *
	 * @param string $uid Booking uid.
	 */
	private static function sign( string $uid ): string {
		return substr( hash_hmac( 'sha256', 'kalendora_ics|' . $uid, wp_salt( 'auth' ) ), 0, 20 );
	}

	/**
	 * Output the calendar file.
	 */
	public function serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed, read-only download.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw   = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$parts = explode( '.', $raw );

		if ( 2 !== count( $parts ) || ! preg_match( '/^[a-f0-9-]{36}$/', $parts[0] ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$booking = $this->entries->booking_by_uid( $parts[0] );
		if ( ! $booking ) {
			status_header( 404 );
			exit;
		}

		$name     = trim( (string) $this->settings->get( 'business_name', '' ) );
		$name     = '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$status   = in_array( $booking['status'], array( 'rejected', 'cancelled' ), true ) ? 'CANCELLED' : 'CONFIRMED';
		$calendar = Ics::event( $booking, $booking['service_name'] . ' – ' . $name, (string) $this->settings->get( 'business_address', '' ), '', $status );

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="termin.ics"' );
		header( 'Content-Length: ' . strlen( $calendar ) );
		echo $calendar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar, values escaped by Ics.
		exit;
	}
}
