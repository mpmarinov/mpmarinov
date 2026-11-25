<?php
/**
 * Cancellation rules: free cancellation deadline and optional late-cancellation fee.
 *
 * @package Kalendora
 */

namespace Kalendora\Booking;

defined( 'ABSPATH' ) || exit;

/**
 * One deadline ("free up to X hours before") applies to online cancelling and moving.
 * After the deadline:
 *  - without fee rule: online changes are no longer possible (the customer is asked to get in touch),
 *  - with fee rule:    cancelling online is still possible but costs the fee (or is blocked, if set so),
 *                      moving online is not possible (a late move would bypass the fee).
 *
 * The rule a customer agreed to is stored with the booking (snapshot) – later changes to the
 * settings or the price do not change what was agreed.
 */
final class CancellationPolicy {

	public const FEE_TYPES = array( 'percent', 'fixed' );

	/**
	 * Effective rule for a service (global settings, overridable in the service).
	 *
	 * @param array<string, mixed>|null $service Service row (settings may be a JSON string or array).
	 * @param array<string, mixed>      $site    Global settings.
	 * @return array{hours:int, fee:bool, fee_type:string, fee_value:float, no_show:bool, late_online:string, self_cancel:bool, self_reschedule:bool, custom_text:string}
	 */
	public static function for_service( ?array $service, array $site ): array {
		$own = array();
		if ( $service ) {
			$own = is_array( $service['settings'] ?? null ) ? $service['settings'] : json_decode( (string) ( $service['settings'] ?? '' ), true );
			$own = is_array( $own ) ? $own : array();
		}

		$rule = array(
			'hours'           => max( 0, (int) ( $site['cancel_limit_hours'] ?? 24 ) ),
			'fee'             => ! empty( $site['cancel_fee_enabled'] ),
			'fee_type'        => in_array( $site['cancel_fee_type'] ?? '', self::FEE_TYPES, true ) ? (string) $site['cancel_fee_type'] : 'percent',
			'fee_value'       => max( 0.0, (float) ( $site['cancel_fee_value'] ?? 0 ) ),
			'no_show'         => ! array_key_exists( 'cancel_fee_no_show', $site ) || ! empty( $site['cancel_fee_no_show'] ),
			'late_online'     => 'block' === ( $site['cancel_late_online'] ?? '' ) ? 'block' : 'fee',
			'self_cancel'     => ! array_key_exists( 'self_cancel', $site ) || ! empty( $site['self_cancel'] ),
			'self_reschedule' => ! array_key_exists( 'self_reschedule', $site ) || ! empty( $site['self_reschedule'] ),
			'custom_text'     => trim( (string) ( $site['cancel_policy_text'] ?? '' ) ),
		);

		if ( isset( $own['cancel_limit_hours'] ) && '' !== $own['cancel_limit_hours'] && (int) $own['cancel_limit_hours'] >= 0 ) {
			$rule['hours'] = (int) $own['cancel_limit_hours'];
		}
		$mode = (string) ( $own['cancel_fee'] ?? 'inherit' );
		if ( 'off' === $mode ) {
			$rule['fee'] = false;
		} elseif ( 'custom' === $mode ) {
			$rule['fee']       = true;
			$rule['fee_type']  = in_array( $own['cancel_fee_type'] ?? '', self::FEE_TYPES, true ) ? (string) $own['cancel_fee_type'] : 'percent';
			$rule['fee_value'] = max( 0.0, (float) ( $own['cancel_fee_value'] ?? 0 ) );
		}
		if ( $rule['fee'] && $rule['fee_value'] <= 0 ) {
			$rule['fee'] = false;
		}

		/**
		 * Filter the cancellation rule of a service.
		 *
		 * @param array      $rule    Rule.
		 * @param array|null $service Service.
		 */
		return (array) apply_filters( 'kalendora_cancellation_policy', $rule, $service );
	}

	/**
	 * Fee amount for a rule and a price.
	 *
	 * @param array<string, mixed> $rule  Rule.
	 * @param float                $price Service price.
	 */
	public static function fee_amount( array $rule, float $price ): float {
		if ( empty( $rule['fee'] ) ) {
			return 0.0;
		}
		$amount = 'fixed' === $rule['fee_type'] ? (float) $rule['fee_value'] : $price * min( 100.0, (float) $rule['fee_value'] ) / 100;
		return round( max( 0.0, $amount ), 2 );
	}

	/**
	 * Snapshot stored with a booking (what the customer agreed to).
	 *
	 * @param array<string, mixed> $rule  Rule.
	 * @param float                $price Price at booking time.
	 * @return array<string, mixed>
	 */
	public static function snapshot( array $rule, float $price ): array {
		return array(
			'hours'       => (int) $rule['hours'],
			'fee'         => ! empty( $rule['fee'] ),
			'fee_type'    => (string) $rule['fee_type'],
			'fee_value'   => (float) $rule['fee_value'],
			'fee_amount'  => self::fee_amount( $rule, $price ),
			'no_show'     => ! empty( $rule['no_show'] ),
			'late_online' => (string) $rule['late_online'],
			'text'        => self::text( $rule, $price ),
			'accepted_at' => ! empty( $rule['fee'] ) ? gmdate( 'Y-m-d H:i:s' ) : null,
		);
	}

	/**
	 * Human-readable rule (shown in the form, on the manage page and in emails).
	 *
	 * @param array<string, mixed> $rule  Rule.
	 * @param float                $price Price.
	 */
	public static function text( array $rule, float $price ): string {
		$hours = (int) $rule['hours'];
		$fee   = self::fee_label( $rule, $price );

		if ( '' !== (string) ( $rule['custom_text'] ?? '' ) ) {
			return str_replace( array( '{hours}', '{fee}' ), array( (string) $hours, $fee ), (string) $rule['custom_text'] );
		}

		if ( empty( $rule['fee'] ) ) {
			return $hours > 0
				/* translators: %d: hours. */
				? sprintf( _n( 'Free cancellation up to %d hour before the appointment.', 'Free cancellation up to %d hours before the appointment.', $hours, 'kalendora' ), $hours )
				: '';
		}

		$text = $hours > 0
			/* translators: %d: hours. */
			? sprintf( _n( 'Free cancellation up to %d hour before the appointment.', 'Free cancellation up to %d hours before the appointment.', $hours, 'kalendora' ), $hours ) . ' '
			: '';

		return $text . sprintf(
			/* translators: %s: fee, e.g. "50 % of the price (24.00 €)" or "20.00 €". */
			! empty( $rule['no_show'] ) ? __( 'For later cancellations or if you do not attend, we charge a fee of %s.', 'kalendora' ) : __( 'For later cancellations we charge a fee of %s.', 'kalendora' ),
			$fee
		);
	}

	/**
	 * "50 % of the price (24.00 €)" or "20.00 €".
	 *
	 * @param array<string, mixed> $rule  Rule.
	 * @param float                $price Price.
	 */
	public static function fee_label( array $rule, float $price ): string {
		if ( 'fixed' === ( $rule['fee_type'] ?? '' ) ) {
			return self::money( (float) $rule['fee_value'] );
		}
		$percent = rtrim( rtrim( number_format_i18n( (float) $rule['fee_value'], 2 ), '0' ), '.,' );
		if ( $price > 0 ) {
			/* translators: 1: percent, 2: amount. */
			return sprintf( __( '%1$s %% of the price (%2$s)', 'kalendora' ), $percent, self::money( self::fee_amount( $rule, $price ) ) );
		}
		/* translators: %s: percent. */
		return sprintf( __( '%s %% of the price', 'kalendora' ), $percent );
	}

	/**
	 * Format money.
	 *
	 * @param float $amount Amount.
	 */
	public static function money( float $amount ): string {
		/** This filter is documented in src/Notifications/Mailer.php */
		return number_format_i18n( $amount, 2 ) . ' ' . apply_filters( 'kalendora_currency_symbol', '€' );
	}

	/**
	 * What the customer may do now with a booking.
	 *
	 * @param array<string, mixed> $booking Formatted booking (needs status, start, policy).
	 * @param array<string, mixed> $rule    Current rule of the service (self-service switches).
	 * @param int                  $now     Now.
	 * @return array{cancel:string, reschedule:string, late:bool, deadline:int, fee:float}
	 *         cancel/reschedule: 'free' | 'fee' | 'blocked' | 'off' | 'closed'.
	 */
	public static function options( array $booking, array $rule, int $now ): array {
		$start  = (int) strtotime( (string) $booking['start'] );
		$agreed = is_array( $booking['policy'] ?? null ) ? $booking['policy'] : array();
		$hours  = isset( $agreed['hours'] ) ? (int) $agreed['hours'] : (int) $rule['hours'];
		$fee    = isset( $agreed['fee'] ) ? ( ! empty( $agreed['fee'] ) ? (float) $agreed['fee_amount'] : 0.0 ) : 0.0;
		$has    = isset( $agreed['fee'] ) ? ! empty( $agreed['fee'] ) : false;
		$late_o = (string) ( $agreed['late_online'] ?? $rule['late_online'] );

		$deadline = $start - $hours * HOUR_IN_SECONDS;
		$late     = $now > $deadline;
		$open     = in_array( $booking['status'], array( 'pending', 'confirmed' ), true ) && $start > $now;

		if ( ! $open ) {
			$cancel     = 'closed';
			$reschedule = 'closed';
		} else {
			$cancel     = ! $rule['self_cancel'] ? 'off' : ( ! $late ? 'free' : ( $has && 'fee' === $late_o && 'confirmed' === $booking['status'] ? 'fee' : 'blocked' ) );
			$reschedule = ! $rule['self_reschedule'] ? 'off' : ( ! $late ? 'free' : 'blocked' );
			// A request that was never confirmed can always be withdrawn free of charge.
			if ( 'pending' === $booking['status'] && $rule['self_cancel'] ) {
				$cancel = 'free';
			}
		}

		return array(
			'cancel'     => $cancel,
			'reschedule' => $reschedule,
			'late'       => $late,
			'deadline'   => $deadline,
			'fee'        => 'fee' === $cancel ? $fee : 0.0,
		);
	}
}
