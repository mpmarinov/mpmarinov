<?php
/**
 * Value object with the rules of a bookable service.
 *
 * @package Bookora
 */

namespace Bookora\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * All durations in minutes, max_advance in days (0 = unlimited).
 */
final class SlotRules {

	/**
	 * Constructor.
	 *
	 * @param int $duration      Appointment length.
	 * @param int $interval      Distance between possible start times.
	 * @param int $buffer_before Free time required before.
	 * @param int $buffer_after  Free time required after.
	 * @param int $capacity      Seats per slot (1 = individual appointment).
	 * @param int $min_notice    Minimum notice.
	 * @param int $max_advance   Maximum days in advance (0 = unlimited).
	 * @param int $seats         Seats requested by this booking.
	 */
	public function __construct(
		public readonly int $duration = 60,
		public readonly int $interval = 30,
		public readonly int $buffer_before = 0,
		public readonly int $buffer_after = 0,
		public readonly int $capacity = 1,
		public readonly int $min_notice = 0,
		public readonly int $max_advance = 0,
		public readonly int $seats = 1,
	) {}

	/**
	 * Build from a service row and global settings, clamping invalid values.
	 *
	 * @param array<string, mixed> $service  Service row.
	 * @param array<string, mixed> $settings Global settings.
	 * @param int                  $seats    Requested seats.
	 */
	public static function from_service( array $service, array $settings, int $seats = 1 ): self {
		$min_notice  = (int) ( $service['min_notice'] ?? 0 );
		$max_advance = (int) ( $service['max_advance'] ?? 0 );

		return new self(
			max( 5, (int) ( $service['duration'] ?? 60 ) ),
			max( 5, (int) ( $settings['slot_interval'] ?? 30 ) ),
			max( 0, (int) ( $service['buffer_before'] ?? 0 ) ),
			max( 0, (int) ( $service['buffer_after'] ?? 0 ) ),
			max( 1, (int) ( $service['capacity'] ?? 1 ) ),
			$min_notice > 0 ? $min_notice : max( 0, (int) ( $settings['min_notice_minutes'] ?? 0 ) ),
			$max_advance > 0 ? $max_advance : max( 0, (int) ( $settings['max_advance_days'] ?? 0 ) ),
			max( 1, $seats )
		);
	}
}
