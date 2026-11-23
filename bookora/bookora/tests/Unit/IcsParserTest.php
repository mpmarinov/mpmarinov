<?php
/**
 * Tests for the iCalendar reader.
 *
 * @package Bookora
 */

namespace Bookora\Tests\Unit;

use Bookora\Calendar\IcsParser;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class IcsParserTest extends TestCase {

	private DateTimeZone $berlin;

	protected function setUp(): void {
		$this->berlin = new DateTimeZone( 'Europe/Berlin' );
	}

	private function ts( string $local, string $tz = 'Europe/Berlin' ): int {
		return ( new DateTimeImmutable( $local, new DateTimeZone( $tz ) ) )->getTimestamp();
	}

	private function local( int $ts ): string {
		return ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $this->berlin )->format( 'Y-m-d H:i' );
	}

	/**
	 * @return list<string> "start – end" in Berlin time.
	 */
	private function busy( string $ics, string $from = '2026-01-01', string $to = '2027-01-01' ): array {
		$out = array();
		foreach ( ( new IcsParser( $this->berlin ) )->busy( $ics, $this->ts( $from ), $this->ts( $to ) ) as $b ) {
			$out[] = $this->local( $b['start'] ) . ' – ' . $this->local( $b['end'] );
		}
		return $out;
	}

	private function cal( string ...$events ): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n" . implode( "\r\n", $events ) . "\r\nEND:VCALENDAR\r\n";
	}

	public function test_google_export_with_utc_times_and_folded_lines(): void {
		$ics = $this->cal(
			"BEGIN:VEVENT\r\nDTSTART:20261124T080000Z\r\nDTEND:20261124T093000Z\r\nUID:abc123@google.com\r\nSUMMARY:Zahnarzt mit einer sehr langen Beschreibung die umgebrochen\r\n  wird\r\nEND:VEVENT"
		);
		$this->assertSame( array( '2026-11-24 09:00 – 2026-11-24 10:30' ), $this->busy( $ics ) );
	}

	public function test_tzid_and_duration(): void {
		$ics = $this->cal( "BEGIN:VEVENT\nUID:1\nDTSTART;TZID=America/New_York:20260710T090000\nDURATION:PT1H30M\nEND:VEVENT" );
		$this->assertSame( array( '2026-07-10 15:00 – 2026-07-10 16:30' ), $this->busy( $ics ) );
	}

	public function test_outlook_windows_timezone_and_quoted_tzid(): void {
		$ics = $this->cal(
			"BEGIN:VEVENT\nUID:o1\nDTSTART;TZID=\"W. Europe Standard Time\":20261201T140000\nDTEND;TZID=\"W. Europe Standard Time\":20261201T150000\nX-MICROSOFT-CDO-BUSYSTATUS:BUSY\nEND:VEVENT",
			"BEGIN:VEVENT\nUID:o2\nDTSTART;TZID=/mozilla.org/20070129_1/Europe/Sofia:20261201T100000\nDTEND;TZID=/mozilla.org/20070129_1/Europe/Sofia:20261201T110000\nEND:VEVENT"
		);
		$this->assertSame( array( '2026-12-01 09:00 – 2026-12-01 10:00', '2026-12-01 14:00 – 2026-12-01 15:00' ), $this->busy( $ics ) );
	}

	public function test_all_day_and_multi_day_events(): void {
		$ics = $this->cal(
			"BEGIN:VEVENT\nUID:a\nDTSTART;VALUE=DATE:20261224\nDTEND;VALUE=DATE:20261227\nSUMMARY:Urlaub\nEND:VEVENT",
			"BEGIN:VEVENT\nUID:b\nDTSTART;VALUE=DATE:20260329\nSUMMARY:Ohne Ende (Zeitumstellung)\nEND:VEVENT"
		);
		$busy = ( new IcsParser( $this->berlin ) )->busy( $ics, $this->ts( '2026-01-01' ), $this->ts( '2027-01-01' ) );
		$this->assertCount( 2, $busy );
		$this->assertTrue( $busy[0]['all_day'] );
		$this->assertSame( '2026-03-29 00:00', $this->local( $busy[0]['start'] ) );
		$this->assertSame( '2026-03-30 00:00', $this->local( $busy[0]['end'] ), 'all-day event on the DST day still ends at midnight' );
		$this->assertSame( '2026-12-24 00:00', $this->local( $busy[1]['start'] ) );
		$this->assertSame( '2026-12-27 00:00', $this->local( $busy[1]['end'] ) );
	}

	public function test_free_cancelled_and_zero_length_events_do_not_block(): void {
		$ics = $this->cal(
			"BEGIN:VEVENT\nUID:t\nDTSTART:20261124T080000Z\nDTEND:20261124T090000Z\nTRANSP:TRANSPARENT\nEND:VEVENT",
			"BEGIN:VEVENT\nUID:c\nDTSTART:20261124T080000Z\nDTEND:20261124T090000Z\nSTATUS:CANCELLED\nEND:VEVENT",
			"BEGIN:VEVENT\nUID:f\nDTSTART:20261124T080000Z\nDTEND:20261124T090000Z\nX-MICROSOFT-CDO-BUSYSTATUS:FREE\nEND:VEVENT",
			"BEGIN:VEVENT\nUID:z\nDTSTART:20261124T080000Z\nEND:VEVENT",
			"BEGIN:VEVENT\nUID:ok\nDTSTART:20261124T100000Z\nDTEND:20261124T110000Z\nTRANSP:OPAQUE\nSTATUS:TENTATIVE\nEND:VEVENT"
		);
		$this->assertSame( array( '2026-11-24 11:00 – 2026-11-24 12:00' ), $this->busy( $ics ) );
	}

	public function test_weekly_meeting_keeps_local_time_across_dst(): void {
		$ics  = $this->cal( "BEGIN:VEVENT\nUID:w\nDTSTART;TZID=Europe/Berlin:20260316T090000\nDTEND;TZID=Europe/Berlin:20260316T100000\nRRULE:FREQ=WEEKLY;COUNT=4\nEND:VEVENT" );
		$busy = $this->busy( $ics );
		$this->assertSame(
			array( '2026-03-16 09:00 – 2026-03-16 10:00', '2026-03-23 09:00 – 2026-03-23 10:00', '2026-03-30 09:00 – 2026-03-30 10:00', '2026-04-06 09:00 – 2026-04-06 10:00' ),
			$busy
		);
	}

	public function test_exdate_recurrence_override_and_cancelled_instance(): void {
		$ics = $this->cal(
			"BEGIN:VEVENT\nUID:r1\nDTSTART;TZID=Europe/Berlin:20261102T080000\nDTEND;TZID=Europe/Berlin:20261102T090000\nRRULE:FREQ=DAILY;UNTIL=20261106T235959Z\nEXDATE;TZID=Europe/Berlin:20261103T080000\nEND:VEVENT",
			"BEGIN:VEVENT\nUID:r1\nRECURRENCE-ID;TZID=Europe/Berlin:20261104T080000\nDTSTART;TZID=Europe/Berlin:20261104T120000\nDTEND;TZID=Europe/Berlin:20261104T130000\nEND:VEVENT",
			"BEGIN:VEVENT\nUID:r1\nRECURRENCE-ID;TZID=Europe/Berlin:20261105T080000\nDTSTART;TZID=Europe/Berlin:20261105T080000\nDTEND;TZID=Europe/Berlin:20261105T090000\nSTATUS:CANCELLED\nEND:VEVENT"
		);
		$this->assertSame(
			array( '2026-11-02 08:00 – 2026-11-02 09:00', '2026-11-04 12:00 – 2026-11-04 13:00', '2026-11-06 08:00 – 2026-11-06 09:00' ),
			$this->busy( $ics )
		);
	}

	public function test_exdate_as_date_list_and_utc(): void {
		$ics = $this->cal(
			"BEGIN:VEVENT\nUID:x\nDTSTART;TZID=Europe/Berlin:20261102T080000\nDTEND;TZID=Europe/Berlin:20261102T083000\nRRULE:FREQ=DAILY;COUNT=5\nEXDATE;VALUE=DATE:20261103,20261105\nEXDATE:20261106T070000Z\nEND:VEVENT"
		);
		$this->assertSame( array( '2026-11-02 08:00 – 2026-11-02 08:30', '2026-11-04 08:00 – 2026-11-04 08:30' ), $this->busy( $ics ) );
	}

	public function test_window_only_returns_overlapping_instances_of_old_series(): void {
		$ics  = $this->cal( "BEGIN:VEVENT\nUID:old\nDTSTART;TZID=Europe/Berlin:20100104T183000\nDTEND;TZID=Europe/Berlin:20100104T200000\nRRULE:FREQ=WEEKLY;BYDAY=MO,TH\nEND:VEVENT" );
		$busy = $this->busy( $ics, '2026-11-23', '2026-11-30' );
		$this->assertSame( array( '2026-11-23 18:30 – 2026-11-23 20:00', '2026-11-26 18:30 – 2026-11-26 20:00' ), $busy );
	}

	public function test_instance_started_before_window_is_included(): void {
		$ics = $this->cal( "BEGIN:VEVENT\nUID:n\nDTSTART;TZID=Europe/Berlin:20261101T220000\nDTEND;TZID=Europe/Berlin:20261102T020000\nRRULE:FREQ=DAILY\nEND:VEVENT" );
		$this->assertContains( '2026-11-09 22:00 – 2026-11-10 02:00', $this->busy( $ics, '2026-11-10', '2026-11-11' ) );
	}

	public function test_floating_time_and_calendar_timezone(): void {
		$floating = $this->cal( "BEGIN:VEVENT\nUID:f\nDTSTART:20261124T090000\nDTEND:20261124T100000\nEND:VEVENT" );
		$this->assertSame( array( '2026-11-24 09:00 – 2026-11-24 10:00' ), $this->busy( $floating ), 'floating = owner time zone' );

		$sofia = "BEGIN:VCALENDAR\nX-WR-TIMEZONE:Europe/Sofia\nBEGIN:VEVENT\nUID:s\nDTSTART:20261124T090000\nDTEND:20261124T100000\nEND:VEVENT\nEND:VCALENDAR";
		$this->assertSame( array( '2026-11-24 08:00 – 2026-11-24 09:00' ), $this->busy( $sofia ) );
	}

	public function test_valarm_inside_event_is_ignored(): void {
		$ics = $this->cal( "BEGIN:VEVENT\nUID:al\nDTSTART:20261124T080000Z\nDTEND:20261124T090000Z\nBEGIN:VALARM\nTRIGGER:-PT15M\nACTION:DISPLAY\nDTSTART:20000101T000000Z\nEND:VALARM\nEND:VEVENT" );
		$this->assertSame( array( '2026-11-24 09:00 – 2026-11-24 10:00' ), $this->busy( $ics ) );
	}

	public function test_garbage_and_invalid_values_are_skipped(): void {
		$ics = "hello\nBEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART:20261340T250000Z\nEND:VEVENT\nBEGIN:VEVENT\nDTSTART:nonsense\nEND:VEVENT\nBEGIN:VEVENT\nUID:ok\nDTSTART:20261124T080000Z\nDTEND:20261124T090000Z\nRRULE:FREQ=SECONDLY\nEND:VEVENT\n<script>alert(1)</script>";
		$this->assertSame( array( '2026-11-24 09:00 – 2026-11-24 10:00' ), $this->busy( $ics ), 'unsupported FREQ only keeps the first instance' );
		$this->assertSame( array(), $this->busy( '' ) );
		$this->assertSame( array(), $this->busy( '<html>Login required</html>' ) );
	}

	public function test_unbounded_daily_series_is_limited(): void {
		$ics   = $this->cal( "BEGIN:VEVENT\nUID:d\nDTSTART:19700101T080000Z\nDTEND:19700101T081500Z\nRRULE:FREQ=DAILY\nEND:VEVENT" );
		$start = microtime( true );
		$busy  = ( new IcsParser( $this->berlin ) )->busy( $ics, $this->ts( '2026-01-01' ), $this->ts( '2027-01-01' ) );
		$this->assertCount( 365, $busy );
		$this->assertLessThan( 2.0, microtime( true ) - $start );
	}

	public function test_monthly_last_friday_and_yearly_birthday(): void {
		$ics  = $this->cal(
			"BEGIN:VEVENT\nUID:m\nDTSTART;TZID=Europe/Berlin:20260130T150000\nDTEND;TZID=Europe/Berlin:20260130T160000\nRRULE:FREQ=MONTHLY;BYDAY=-1FR;COUNT=3\nEND:VEVENT",
			"BEGIN:VEVENT\nUID:y\nDTSTART;VALUE=DATE:19811210\nDTEND;VALUE=DATE:19811211\nRRULE:FREQ=YEARLY\nEND:VEVENT"
		);
		$this->assertSame(
			array( '2026-01-30 15:00 – 2026-01-30 16:00', '2026-02-27 15:00 – 2026-02-27 16:00', '2026-03-27 15:00 – 2026-03-27 16:00', '2026-12-10 00:00 – 2026-12-11 00:00' ),
			$this->busy( $ics )
		);
	}

	public function test_duration_parser(): void {
		$this->assertSame( 5400, IcsParser::duration( 'PT1H30M' ) );
		$this->assertSame( 694800, IcsParser::duration( 'P1W1DT1H' ) );
		$this->assertSame( -900, IcsParser::duration( '-PT15M' ) );
		$this->assertSame( 0, IcsParser::duration( 'garbage' ) );
	}

	/* ---- Regression tests from the code review ---- */

	public function test_utc_anchored_series_stays_fixed_in_utc(): void {
		$ics = $this->cal( "BEGIN:VEVENT\nUID:u\nDTSTART:20260320T090000Z\nDTEND:20260320T100000Z\nRRULE:FREQ=WEEKLY;COUNT=3\nEXDATE:20260327T090000Z\nEND:VEVENT" );
		// 09:00Z = 10:00 CET before, 11:00 CEST after the switch on 29.03.
		$this->assertSame( array( '2026-03-20 10:00 – 2026-03-20 11:00', '2026-04-03 11:00 – 2026-04-03 12:00' ), $this->busy( $ics ) );
	}

	public function test_custom_vtimezone_without_dst_uses_its_offset(): void {
		$ics = $this->cal( "BEGIN:VTIMEZONE\nTZID:Customized Time Zone\nBEGIN:STANDARD\nDTSTART:16010101T000000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0500\nEND:STANDARD\nEND:VTIMEZONE\nBEGIN:VEVENT\nUID:c\nDTSTART;TZID=Customized Time Zone:20261124T090000\nDTEND;TZID=Customized Time Zone:20261124T100000\nEND:VEVENT" );
		$this->assertSame( array( '2026-11-24 15:00 – 2026-11-24 16:00' ), $this->busy( $ics ) );
	}

	public function test_custom_vtimezone_with_dst_is_matched_to_a_real_zone(): void {
		$vtz = "BEGIN:VTIMEZONE\nTZID:Customized Time Zone 2\nBEGIN:STANDARD\nDTSTART:16010101T020000\nTZOFFSETFROM:-0400\nTZOFFSETTO:-0500\nRRULE:FREQ=YEARLY;BYDAY=1SU;BYMONTH=11\nEND:STANDARD\nBEGIN:DAYLIGHT\nDTSTART:16010101T020000\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0400\nRRULE:FREQ=YEARLY;BYDAY=2SU;BYMONTH=3\nEND:DAYLIGHT\nEND:VTIMEZONE";
		$ics = $this->cal( $vtz, "BEGIN:VEVENT\nUID:s\nDTSTART;TZID=Customized Time Zone 2:20260715T090000\nDTEND;TZID=Customized Time Zone 2:20260715T100000\nEND:VEVENT", "BEGIN:VEVENT\nUID:w\nDTSTART;TZID=Customized Time Zone 2:20261215T090000\nDTEND;TZID=Customized Time Zone 2:20261215T100000\nEND:VEVENT" );
		// New York: 09:00 EDT = 13:00Z = 15:00 Berlin (summer); 09:00 EST = 14:00Z = 15:00 Berlin (winter).
		$this->assertSame( array( '2026-07-15 15:00 – 2026-07-15 16:00', '2026-12-15 15:00 – 2026-12-15 16:00' ), $this->busy( $ics ) );
	}

	public function test_unquoted_outlook_tzid_with_colon_and_commas(): void {
		$name = '(UTC+01:00) Amsterdam, Berlin, Bern, Rom, Stockholm, Wien';
		$vtz  = "BEGIN:VTIMEZONE\nTZID:{$name}\nBEGIN:STANDARD\nDTSTART:16010101T030000\nTZOFFSETFROM:+0200\nTZOFFSETTO:+0100\nRRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10\nEND:STANDARD\nBEGIN:DAYLIGHT\nDTSTART:16010101T020000\nTZOFFSETFROM:+0100\nTZOFFSETTO:+0200\nRRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3\nEND:DAYLIGHT\nEND:VTIMEZONE";
		$ics  = $this->cal( $vtz, "BEGIN:VEVENT\nUID:o\nDTSTART;TZID={$name}:20260715T090000\nDTEND;TZID={$name}:20260715T100000\nEND:VEVENT" );
		$this->assertSame( array( '2026-07-15 09:00 – 2026-07-15 10:00' ), $this->busy( $ics ) );
	}

	public function test_this_and_future_moves_all_later_instances(): void {
		$ics = $this->cal(
			"BEGIN:VEVENT\nUID:t\nDTSTART;TZID=Europe/Berlin:20261102T080000\nDTEND;TZID=Europe/Berlin:20261102T090000\nRRULE:FREQ=DAILY;COUNT=4\nEND:VEVENT",
			"BEGIN:VEVENT\nUID:t\nRECURRENCE-ID;RANGE=THISANDFUTURE;TZID=Europe/Berlin:20261103T080000\nDTSTART;TZID=Europe/Berlin:20261103T140000\nDTEND;TZID=Europe/Berlin:20261103T143000\nEND:VEVENT"
		);
		$this->assertSame(
			array( '2026-11-02 08:00 – 2026-11-02 09:00', '2026-11-03 14:00 – 2026-11-03 14:30', '2026-11-04 14:00 – 2026-11-04 14:30', '2026-11-05 14:00 – 2026-11-05 14:30' ),
			$this->busy( $ics )
		);
	}

	public function test_calendar_timezone_does_not_shift_all_day_events_and_may_be_folded(): void {
		$ics = "BEGIN:VCALENDAR\r\nX-WR-TIMEZONE:America/Los_\r\n Angeles\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART;VALUE=DATE:20261124\r\nDTEND;VALUE=DATE:20261125\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:f\r\nDTSTART:20261125T090000\r\nDTEND:20261125T100000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		$this->assertSame( array( '2026-11-24 00:00 – 2026-11-25 00:00', '2026-11-25 18:00 – 2026-11-25 19:00' ), $this->busy( $ics ) );
	}

	public function test_old_series_with_huge_count_still_produces_current_instances(): void {
		$ics = $this->cal( "BEGIN:VEVENT\nUID:h\nDTSTART;TZID=Europe/Berlin:19000101T070000\nDTEND;TZID=Europe/Berlin:19000101T073000\nRRULE:FREQ=DAILY;COUNT=99999999\nEND:VEVENT" );
		$this->assertCount( 7, $this->busy( $ics, '2026-11-23', '2026-11-30' ) );
	}

	public function test_malicious_rule_is_stopped_by_the_time_budget(): void {
		$evil  = '';
		for ( $i = 0; $i < 20; $i++ ) {
			$evil .= "BEGIN:VEVENT\nUID:e{$i}\nDTSTART:00010101T090000Z\nDTEND:00010101T100000Z\nRRULE:FREQ=YEARLY;BYDAY=MO,TU,WE,TH,FR,SA,SU;COUNT=99999999\nEND:VEVENT\n";
		}
		$ics   = $this->cal( rtrim( $evil ), "BEGIN:VEVENT\nUID:ok\nDTSTART:20261124T080000Z\nDTEND:20261124T090000Z\nEND:VEVENT" );
		$start = microtime( true );
		$busy  = ( new IcsParser( $this->berlin, 1.0 ) )->busy( $ics, $this->ts( '2026-11-01' ), $this->ts( '2026-12-01' ) );
		$this->assertLessThan( 3.0, microtime( true ) - $start );
		$this->assertContains( 'ok', array_column( $busy, 'uid' ), 'normal events are still imported' );
	}

	public function test_exdate_date_on_utc_series(): void {
		$ics = $this->cal( "BEGIN:VEVENT\nUID:x\nDTSTART:20261102T070000Z\nDTEND:20261102T073000Z\nRRULE:FREQ=DAILY;COUNT=3\nEXDATE;VALUE=DATE:20261103\nEND:VEVENT" );
		$this->assertSame( array( '2026-11-02 08:00 – 2026-11-02 08:30', '2026-11-04 08:00 – 2026-11-04 08:30' ), $this->busy( $ics ) );
	}
}
