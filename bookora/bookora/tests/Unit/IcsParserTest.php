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
}
