<?php
/**
 * Cancellation rules: deadlines, fees, agreed snapshot.
 *
 * @package Bookora
 */

// phpcs:ignoreFile

namespace {
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! function_exists( '__' ) ) {
		function __( $text, $domain = '' ) {
			return $text;
		}
	}
	if ( ! function_exists( '_n' ) ) {
		function _n( $single, $plural, $number, $domain = '' ) {
			return 1 === (int) $number ? $single : $plural;
		}
	}
	if ( ! function_exists( 'number_format_i18n' ) ) {
		function number_format_i18n( $number, $decimals = 0 ) {
			return number_format( (float) $number, $decimals, ',', '.' );
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value ) {
			return $value;
		}
	}
}

namespace Bookora\Tests\Unit {

	use Bookora\Booking\CancellationPolicy;
	use PHPUnit\Framework\TestCase;

	final class CancellationPolicyTest extends TestCase {

		private const SITE = array(
			'cancel_limit_hours' => 24,
			'cancel_fee_enabled' => true,
			'cancel_fee_type'    => 'percent',
			'cancel_fee_value'   => 50,
			'cancel_fee_no_show' => true,
			'cancel_late_online' => 'fee',
			'self_cancel'        => true,
			'self_reschedule'    => true,
		);

		private function booking( string $status, int $start, array $policy ): array {
			return array(
				'status' => $status,
				'start'  => gmdate( 'c', $start ),
				'policy' => $policy,
			);
		}

		public function test_global_rule_and_amount(): void {
			$rule = CancellationPolicy::for_service( array( 'settings' => '' ), self::SITE );
			$this->assertTrue( $rule['fee'] );
			$this->assertSame( 24, $rule['hours'] );
			$this->assertSame( 24.0, CancellationPolicy::fee_amount( $rule, 48 ) );
			$this->assertSame( 12.35, CancellationPolicy::fee_amount( $rule, 24.70 ) );
		}

		public function test_service_overrides(): void {
			$off = CancellationPolicy::for_service( array( 'settings' => array( 'cancel_fee' => 'off' ) ), self::SITE );
			$this->assertFalse( $off['fee'] );

			$own = CancellationPolicy::for_service(
				array( 'settings' => '{"cancel_fee":"custom","cancel_fee_type":"fixed","cancel_fee_value":30,"cancel_limit_hours":48}' ),
				self::SITE
			);
			$this->assertSame( 48, $own['hours'] );
			$this->assertSame( 30.0, CancellationPolicy::fee_amount( $own, 99 ) );

			$zero = CancellationPolicy::for_service( array( 'settings' => array( 'cancel_limit_hours' => 0 ) ), self::SITE );
			$this->assertSame( 0, $zero['hours'], '0 is a valid override (until the start)' );

			$empty = CancellationPolicy::for_service( array( 'settings' => array( 'cancel_limit_hours' => '' ) ), self::SITE );
			$this->assertSame( 24, $empty['hours'], 'empty = inherit' );
		}

		public function test_zero_fee_means_no_fee_rule(): void {
			$rule = CancellationPolicy::for_service( null, array( 'cancel_fee_value' => 0 ) + self::SITE );
			$this->assertFalse( $rule['fee'] );
		}

		public function test_fee_capped_and_never_negative(): void {
			$rule = array(
				'fee'       => true,
				'fee_type'  => 'percent',
				'fee_value' => 500,
			);
			$this->assertSame( 40.0, CancellationPolicy::fee_amount( $rule, 40 ) );
			$this->assertSame( 0.0, CancellationPolicy::fee_amount( array( 'fee_value' => -5, 'fee_type' => 'fixed' ) + $rule, 40 ) );
			$this->assertSame( 0.0, CancellationPolicy::fee_amount( array( 'fee' => false ) + $rule, 40 ) );
		}

		public function test_text(): void {
			$rule = CancellationPolicy::for_service( null, self::SITE );
			$text = CancellationPolicy::text( $rule, 48 );
			$this->assertStringContainsString( '24 hours', $text );
			$this->assertStringContainsString( '50 % of the price (24,00 €)', $text );
			$this->assertStringContainsString( 'do not attend', $text );

			$own = CancellationPolicy::text( array( 'custom_text' => 'Bis {hours} h frei, danach {fee}.' ) + $rule, 48 );
			$this->assertSame( 'Bis 24 h frei, danach 50 % of the price (24,00 €).', $own );

			$this->assertSame( '', CancellationPolicy::text( array( 'fee' => false, 'hours' => 0 ) + $rule, 48 ) );
		}

		public function test_options_timeline(): void {
			$rule   = CancellationPolicy::for_service( null, self::SITE );
			$now    = 1_800_000_000;
			$agreed = CancellationPolicy::snapshot( $rule, 48 );

			$early = CancellationPolicy::options( $this->booking( 'confirmed', $now + 3 * 86400, $agreed ), $rule, $now );
			$this->assertSame( array( 'free', 'free' ), array( $early['cancel'], $early['reschedule'] ) );

			$late = CancellationPolicy::options( $this->booking( 'confirmed', $now + 3600, $agreed ), $rule, $now );
			$this->assertSame( 'fee', $late['cancel'] );
			$this->assertSame( 24.0, $late['fee'] );
			$this->assertSame( 'blocked', $late['reschedule'] );
			$this->assertSame( $now + 3600 - 24 * 3600, $late['deadline'] );

			// Exactly at the deadline it is still free.
			$edge = CancellationPolicy::options( $this->booking( 'confirmed', $now + 24 * 3600, $agreed ), $rule, $now );
			$this->assertSame( 'free', $edge['cancel'] );

			$block = CancellationPolicy::options( $this->booking( 'confirmed', $now + 3600, array( 'late_online' => 'block' ) + $agreed ), $rule, $now );
			$this->assertSame( 'blocked', $block['cancel'] );

			$pending = CancellationPolicy::options( $this->booking( 'pending', $now + 3600, $agreed ), $rule, $now );
			$this->assertSame( 'free', $pending['cancel'] );

			$past = CancellationPolicy::options( $this->booking( 'confirmed', $now - 60, $agreed ), $rule, $now );
			$this->assertSame( 'closed', $past['cancel'] );

			$off = CancellationPolicy::options( $this->booking( 'confirmed', $now + 3 * 86400, $agreed ), array( 'self_cancel' => false, 'self_reschedule' => false ) + $rule, $now );
			$this->assertSame( array( 'off', 'off' ), array( $off['cancel'], $off['reschedule'] ) );
		}

		public function test_no_fee_without_agreement(): void {
			$rule    = CancellationPolicy::for_service( null, self::SITE );
			$no_fee  = CancellationPolicy::snapshot( array( 'fee' => false ) + $rule, 48 );
			$now     = 1_800_000_000;
			$options = CancellationPolicy::options( $this->booking( 'confirmed', $now + 3600, $no_fee ), $rule, $now );
			$this->assertSame( 'blocked', $options['cancel'], 'a fee the customer never accepted is never charged' );
			$this->assertNull( $no_fee['accepted_at'] );

			// Old bookings from before 1.5 have no snapshot: never a fee.
			$legacy = CancellationPolicy::options( $this->booking( 'confirmed', $now + 3600, array() ), $rule, $now );
			$this->assertSame( 'blocked', $legacy['cancel'] );
			$this->assertSame( 0.0, $legacy['fee'] );
		}
	}
}
