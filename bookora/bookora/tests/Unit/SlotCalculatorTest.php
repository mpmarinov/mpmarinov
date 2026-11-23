<?php
namespace Bookora\Tests\Unit;

use Bookora\Availability\SlotCalculator;
use Bookora\Availability\SlotRules;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SlotCalculatorTest extends TestCase {

	private DateTimeZone $tz;

	/** Monday–Friday 09:00–12:00 and 13:00–17:00. */
	private array $weekly;

	/** A point in time long before the tested dates. */
	private int $past;

	protected function setUp(): void {
		$this->tz     = new DateTimeZone( 'Europe/Berlin' );
		$day          = array( array( '09:00', '12:00' ), array( '13:00', '17:00' ) );
		$this->weekly = array( 1 => $day, 2 => $day, 3 => $day, 4 => $day, 5 => $day );
		$this->past   = $this->ts( '2026-01-01 00:00' );
	}

	private function ts( string $local ): int {
		return ( new DateTimeImmutable( $local, $this->tz ) )->getTimestamp();
	}

	private function starts( array $slots ): array {
		return array_map( fn( $s ) => ( new DateTimeImmutable( '@' . $s['start'] ) )->setTimezone( $this->tz )->format( 'H:i' ), $slots );
	}

	public function test_weekday_slots_respect_breaks(): void {
		$calc  = new SlotCalculator( $this->tz, $this->weekly );
		$slots = $calc->slots( '2026-12-07', '2026-12-07', new SlotRules( duration: 60, interval: 60 ), $this->past );

		$this->assertSame( array( '09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00' ), $this->starts( $slots ) );
		$this->assertSame( 3600, $slots[0]['end'] - $slots[0]['start'] );
	}

	public function test_weekend_is_closed(): void {
		$calc = new SlotCalculator( $this->tz, $this->weekly );
		$this->assertSame( array(), $calc->slots( '2026-12-05', '2026-12-06', new SlotRules(), $this->past ) );
	}

	public function test_duration_must_fit_into_window(): void {
		$calc  = new SlotCalculator( $this->tz, array( 1 => array( array( '09:00', '10:30' ) ) ) );
		$slots = $calc->slots( '2026-12-07', '2026-12-07', new SlotRules( duration: 60, interval: 15 ), $this->past );

		$this->assertSame( array( '09:00', '09:15', '09:30' ), $this->starts( $slots ) );
	}

	public function test_existing_booking_blocks_overlapping_slots(): void {
		$busy  = array( array( 'start' => $this->ts( '2026-12-07 10:00' ), 'end' => $this->ts( '2026-12-07 11:00' ) ) );
		$calc  = new SlotCalculator( $this->tz, $this->weekly, array(), $busy );
		$slots = $calc->slots( '2026-12-07', '2026-12-07', new SlotRules( duration: 60, interval: 30 ), $this->past );

		$this->assertNotContains( '09:30', $this->starts( $slots ) );
		$this->assertNotContains( '10:00', $this->starts( $slots ) );
		$this->assertNotContains( '10:30', $this->starts( $slots ) );
		$this->assertContains( '09:00', $this->starts( $slots ) );
		$this->assertContains( '11:00', $this->starts( $slots ) );
	}

	public function test_buffers_keep_distance_to_other_bookings(): void {
		$busy  = array( array( 'start' => $this->ts( '2026-12-07 10:00' ), 'end' => $this->ts( '2026-12-07 11:00' ) ) );
		$calc  = new SlotCalculator( $this->tz, $this->weekly, array(), $busy );
		$slots = $calc->slots( '2026-12-07', '2026-12-07', new SlotRules( duration: 30, interval: 30, buffer_before: 15, buffer_after: 15 ), $this->past );

		// 09:30–10:00 + 15 min after-buffer overlaps 10:00; 11:00 needs 15 min before-buffer.
		$this->assertSame( array( '09:00', '11:30' ), array_slice( $this->starts( $slots ), 0, 2 ) );
	}

	public function test_group_capacity_counts_seats(): void {
		$start = $this->ts( '2026-12-07 09:00' );
		$busy  = array(
			array( 'start' => $start, 'end' => $start + 3600, 'seats' => 6 ),
			array( 'start' => $start, 'end' => $start + 3600, 'seats' => 3 ),
		);
		$calc  = new SlotCalculator( $this->tz, $this->weekly, array(), $busy );

		$one = $calc->slots( '2026-12-07', '2026-12-07', new SlotRules( duration: 60, interval: 60, capacity: 10 ), $this->past );
		$this->assertSame( '09:00', $this->starts( $one )[0] );
		$this->assertSame( 1, $one[0]['available'] );

		$two = $calc->slots( '2026-12-07', '2026-12-07', new SlotRules( duration: 60, interval: 60, capacity: 10, seats: 2 ), $this->past );
		$this->assertSame( '10:00', $this->starts( $two )[0] );
	}

	public function test_external_event_blocks_group_completely(): void {
		$start = $this->ts( '2026-12-07 09:00' );
		$busy  = array( array( 'start' => $start, 'end' => $start + 3600, 'seats' => SlotCalculator::FULL_BLOCK ) );
		$calc  = new SlotCalculator( $this->tz, $this->weekly, array(), $busy );
		$slots = $calc->slots( '2026-12-07', '2026-12-07', new SlotRules( duration: 60, interval: 60, capacity: 10 ), $this->past );

		$this->assertSame( '10:00', $this->starts( $slots )[0] );
	}

	public function test_closed_day_exception(): void {
		$ex   = array( array( 'date_start' => '2026-12-24', 'date_end' => '2026-12-26', 'type' => 'closed' ) );
		$calc = new SlotCalculator( $this->tz, $this->weekly, $ex );

		$this->assertSame( array(), $calc->slots( '2026-12-24', '2026-12-25', new SlotRules(), $this->past ) );
		$this->assertNotEmpty( $calc->slots( '2026-12-28', '2026-12-28', new SlotRules(), $this->past ) );
	}

	public function test_closed_time_window_exception(): void {
		$ex    = array( array( 'date_start' => '2026-12-07', 'date_end' => '2026-12-07', 'type' => 'closed', 'start_time' => '14:00', 'end_time' => '16:00' ) );
		$calc  = new SlotCalculator( $this->tz, $this->weekly, $ex );
		$slots = $calc->slots( '2026-12-07', '2026-12-07', new SlotRules( duration: 60, interval: 60 ), $this->past );

		$this->assertSame( array( '09:00', '10:00', '11:00', '13:00', '16:00' ), $this->starts( $slots ) );
	}

	public function test_special_opening_on_saturday(): void {
		$ex    = array( array( 'date_start' => '2026-12-12', 'date_end' => '2026-12-12', 'type' => 'open', 'start_time' => '10:00', 'end_time' => '12:00' ) );
		$calc  = new SlotCalculator( $this->tz, $this->weekly, $ex );
		$slots = $calc->slots( '2026-12-12', '2026-12-12', new SlotRules( duration: 60, interval: 60 ), $this->past );

		$this->assertSame( array( '10:00', '11:00' ), $this->starts( $slots ) );
	}

	public function test_min_notice_and_max_advance(): void {
		$calc = new SlotCalculator( $this->tz, $this->weekly );
		$now  = $this->ts( '2026-12-07 09:10' );

		$slots = $calc->slots( '2026-12-07', '2026-12-07', new SlotRules( duration: 60, interval: 60, min_notice: 120 ), $now );
		$this->assertSame( '13:00', $this->starts( $slots )[0] );

		$far = $calc->slots( '2026-12-07', '2026-12-31', new SlotRules( duration: 60, interval: 60, max_advance: 2 ), $now );
		$last = end( $far );
		$this->assertLessThanOrEqual( $now + 2 * 86400, $last['start'] );
	}

	public function test_daylight_saving_switch_keeps_local_hours(): void {
		// 29 March 2026: clocks go forward in Europe; 25 October 2026: back.
		$weekly = array( 0 => array( array( '09:00', '10:00' ) ) );
		$calc   = new SlotCalculator( $this->tz, $weekly );

		foreach ( array( '2026-03-22', '2026-03-29', '2026-10-25' ) as $sunday ) {
			$slots = $calc->slots( $sunday, $sunday, new SlotRules( duration: 60, interval: 60 ), 0 );
			$this->assertSame( array( '09:00' ), $this->starts( $slots ), $sunday );
		}
	}

	public function test_is_available(): void {
		$busy = array( array( 'start' => $this->ts( '2026-12-07 10:00' ), 'end' => $this->ts( '2026-12-07 11:00' ) ) );
		$calc = new SlotCalculator( $this->tz, $this->weekly, array(), $busy );
		$r    = new SlotRules( duration: 60, interval: 60 );

		$this->assertTrue( $calc->is_available( $this->ts( '2026-12-07 09:00' ), $r, $this->past ) );
		$this->assertFalse( $calc->is_available( $this->ts( '2026-12-07 10:00' ), $r, $this->past ) );
		$this->assertFalse( $calc->is_available( $this->ts( '2026-12-07 09:20' ), $r, $this->past ), 'off-grid start' );
		$this->assertFalse( $calc->is_available( $this->ts( '2026-12-06 10:00' ), $r, $this->past ), 'closed day' );
	}

	public function test_invalid_range_returns_nothing(): void {
		$calc = new SlotCalculator( $this->tz, $this->weekly );
		$this->assertSame( array(), $calc->slots( '2026-12-10', '2026-12-01', new SlotRules(), 0 ) );
		$this->assertSame( array(), $calc->slots( 'nonsense', '2026-12-01', new SlotRules(), 0 ) );
	}

	public function test_rules_from_service_fall_back_to_settings(): void {
		$r = SlotRules::from_service( array( 'duration' => 45, 'capacity' => 0 ), array( 'slot_interval' => 15, 'min_notice_minutes' => 60, 'max_advance_days' => 30 ) );
		$this->assertSame( 45, $r->duration );
		$this->assertSame( 15, $r->interval );
		$this->assertSame( 1, $r->capacity );
		$this->assertSame( 60, $r->min_notice );
		$this->assertSame( 30, $r->max_advance );
	}
}
