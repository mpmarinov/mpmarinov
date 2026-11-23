<?php
/**
 * REST: timeline data and moving bookings.
 *
 * @package Bookora
 */

namespace Bookora\Rest;

use Bookora\Availability\AvailabilityService;
use Bookora\Calendar\ConnectionRepository;
use Bookora\Booking\BookingService;
use Bookora\Repository\EntryRepository;
use Bookora\Repository\ResourceRepository;
use Bookora\Security\Capabilities;
use Bookora\Settings\Settings;
use DateTimeImmutable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /timeline?view=day|week&date=Y-m-d
 * POST /bookings/{id}/move {date: Y-m-d, time: H:i, resource_id?: int, notify?: bool}
 *
 * All times in the response are local minutes since midnight, so the UI needs no timezone logic.
 */
final class TimelineController {

	/**
	 * Constructor.
	 *
	 * @param EntryRepository      $entries      Entries/bookings.
	 * @param ResourceRepository   $resources    Resources.
	 * @param AvailabilityService  $availability Availability.
	 * @param BookingService       $bookings     Booking service.
	 * @param Settings             $settings     Settings.
	 * @param ConnectionRepository $connections Imported calendars.
	 */
	public function __construct(
		private EntryRepository $entries,
		private ResourceRepository $resources,
		private AvailabilityService $availability,
		private BookingService $bookings,
		private Settings $settings,
		private ConnectionRepository $connections,
	) {}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			SettingsController::NAMESPACE,
			'/timeline',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'timeline' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::VIEW_BOOKINGS ),
				'args'                => array(
					'view' => array(
						'type'    => 'string',
						'enum'    => array( 'day', 'week' ),
						'default' => 'day',
					),
					'date' => array(
						'type'    => 'string',
						'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
					),
				),
			)
		);

		register_rest_route(
			SettingsController::NAMESPACE,
			'/bookings/(?P<id>\d+)/move',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'move' ),
				'permission_callback' => static fn(): bool => current_user_can( Capabilities::MANAGE_BOOKINGS ),
				'args'                => array(
					'date' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\\d{4}-\\d{2}-\\d{2}$',
					),
					'time' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^([01]\\d|2[0-3]):[0-5]\\d$',
					),
				),
			)
		);
	}

	/**
	 * GET /timeline
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function timeline( WP_REST_Request $request ): WP_REST_Response {
		$tz    = wp_timezone();
		$view  = (string) $request['view'];
		$date  = (string) ( $request['date'] ?? '' );
		$day   = DateTimeImmutable::createFromFormat( '!Y-m-d', '' !== $date ? $date : wp_date( 'Y-m-d' ), $tz );
		$day   = $day && $day->format( 'Y-m-d' ) === $date ? $day : new DateTimeImmutable( 'today', $tz );
		$start = $day;

		if ( 'week' === $view ) {
			$week_start = (int) $this->settings->get( 'week_start', 1 );
			$offset     = ( (int) $day->format( 'w' ) - $week_start + 7 ) % 7;
			$start      = $day->modify( "-{$offset} days" );
		}
		$days  = 'week' === $view ? 7 : 1;
		$dates = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$dates[] = $start->modify( "+{$i} days" )->format( 'Y-m-d' );
		}

		$resources = $this->resources->all( array( 'status' => 'active' ) );
		$bookings  = $this->entries->bookings(
			array(
				'view'     => 'all',
				'from'     => $start->modify( '-1 day' )->format( 'Y-m-d' ),
				'to'       => $start->modify( '+' . ( $days + 1 ) . ' days' )->format( 'Y-m-d' ),
				'per_page' => 1000,
			)
		)['items'];
		$bookings  = array_values( array_filter( $bookings, static fn( $b ) => in_array( $b['date'], $dates, true ) && ! in_array( $b['status'], array( 'rejected', 'cancelled' ), true ) ) );

		$rows = array();
		if ( 'day' === $view ) {
			$has_unassigned = ! $resources || array_filter( $bookings, static fn( $b ) => null === $b['resource_id'] );
			if ( $has_unassigned ) {
				$rows[] = array(
					'id'    => 0,
					'label' => $resources ? __( 'Not assigned', 'bookora' ) : (string) $this->settings->get( 'business_name', get_bloginfo( 'name' ) ),
					'color' => '#64748b',
					'date'  => $dates[0],
					'open'  => $this->open_minutes( null, $dates[0] ),
				);
			}
			foreach ( $resources as $r ) {
				$rows[] = array(
					'id'    => (int) $r['id'],
					'label' => $r['name'],
					'color' => $r['color'],
					'date'  => $dates[0],
					'open'  => $this->open_minutes( (int) $r['id'], $dates[0] ),
				);
			}
		} else {
			foreach ( $dates as $d ) {
				$open = $this->open_minutes( null, $d );
				foreach ( $resources as $r ) {
					$open = array_merge( $open, $this->open_minutes( (int) $r['id'], $d ) );
				}
				$rows[] = array(
					'id'    => $d,
					'label' => wp_date( 'D j.n.', (int) strtotime( $d . ' 12:00' ) ),
					'color' => '#64748b',
					'date'  => $d,
					'open'  => self::merge( $open ),
				);
			}
		}

		// Visible hours: union of opening hours and bookings, at least 08–18.
		$min = 8 * 60;
		$max = 18 * 60;
		foreach ( $rows as $row ) {
			foreach ( $row['open'] as [ $a, $b ] ) {
				$min = min( $min, $a );
				$max = max( $max, $b );
			}
		}
		foreach ( $bookings as &$b ) {
			$b['start_min'] = self::minutes( $b['time'] );
			$b['end_min']   = '00:00' === $b['end_time'] ? 1440 : self::minutes( $b['end_time'] );
			$min            = min( $min, $b['start_min'] );
			$max            = max( $max, $b['end_min'] );
		}
		unset( $b );

		$now    = new DateTimeImmutable( 'now', $tz );
		$blocks = $this->blocks( $rows, $dates, $view, $resources );

		return new WP_REST_Response(
			array(
				'view'      => $view,
				'dates'     => $dates,
				'from'      => (int) floor( $min / 60 ) * 60,
				'to'        => (int) min( 1440, ceil( $max / 60 ) * 60 ),
				'rows'      => $rows,
				'bookings'  => $bookings,
				'blocks'    => $blocks,
				'today'     => $now->format( 'Y-m-d' ),
				'now_min'   => (int) $now->format( 'G' ) * 60 + (int) $now->format( 'i' ),
				'resources' => array_map(
					static fn( $r ) => array(
						'id'   => (int) $r['id'],
						'name' => $r['name'],
					),
					$resources
				),
			)
		);
	}

	/**
	 * Busy times imported from external calendars, clipped to each visible day.
	 *
	 * @param array<int, array<string, mixed>> $rows      Rows.
	 * @param array<int, string>               $dates     Visible dates.
	 * @param string                           $view      day|week.
	 * @param array<int, array<string, mixed>> $resources Active staff.
	 * @return list<array{row:int|string, start_min:int, end_min:int, label:string, all_day:bool}>
	 */
	private function blocks( array $rows, array $dates, string $view, array $resources ): array {
		$tz    = wp_timezone();
		$first = new DateTimeImmutable( $dates[0], $tz );
		$last  = ( new DateTimeImmutable( end( $dates ), $tz ) )->modify( '+1 day' );
		$names = array();
		foreach ( $resources as $r ) {
			$names[ (int) $r['id'] ] = (string) $r['name'];
		}

		$out = array();
		foreach ( $this->connections->events_between( $first->getTimestamp(), $last->getTimestamp() ) as $e ) {
			foreach ( $dates as $d ) {
				$day_start = ( new DateTimeImmutable( $d, $tz ) )->getTimestamp();
				$day_end   = ( new DateTimeImmutable( $d, $tz ) )->modify( '+1 day' )->getTimestamp();
				$a         = max( $e['start'], $day_start );
				$b         = min( $e['end'], $day_end );
				if ( $a >= $b ) {
					continue;
				}
				$start_min = (int) round( ( $a - $day_start ) / 60 );
				$end_min   = $b >= $day_end ? 1440 : (int) round( ( $b - $day_start ) / 60 );
				$label     = $e['label'];
				if ( 'week' === $view && null !== $e['resource_id'] && isset( $names[ $e['resource_id'] ] ) ) {
					$label = $names[ $e['resource_id'] ] . ': ' . $label;
				}

				$targets = array();
				if ( 'week' === $view ) {
					$targets[] = $d;
				} else {
					foreach ( $rows as $row ) {
						// A calendar of the whole business blocks every row.
						if ( null === $e['resource_id'] || $row['id'] === $e['resource_id'] ) {
							$targets[] = $row['id'];
						}
					}
				}
				foreach ( $targets as $row_id ) {
					$out[] = array(
						'row'       => $row_id,
						'start_min' => $start_min,
						'end_min'   => $end_min,
						'label'     => $label,
						'all_day'   => $e['all_day'],
					);
				}
			}
		}
		return $out;
	}

	/**
	 * POST /bookings/{id}/move
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function move( WP_REST_Request $request ) {
		$input = (string) $request['date'] . ' ' . (string) $request['time'];
		$local = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $input, wp_timezone() );
		// Reject overflowing values such as 2026-02-30 or 25:00 instead of silently rolling them over.
		if ( ! $local || $local->format( 'Y-m-d H:i' ) !== $input ) {
			return new WP_Error( 'bookora_invalid_date', __( 'Please choose a valid date.', 'bookora' ), array( 'status' => 400 ) );
		}

		$resource = $request->has_param( 'resource_id' ) ? (int) $request->get_param( 'resource_id' ) : null;
		$notify   = ! $request->has_param( 'notify' ) || rest_sanitize_boolean( $request->get_param( 'notify' ) );
		$silence  = static fn() => false;

		if ( ! $notify ) {
			add_filter( 'bookora_send_reschedule_email', $silence );
		}
		$result = $this->bookings->move( (int) $request['id'], $local->getTimestamp(), $resource );
		if ( ! $notify ) {
			remove_filter( 'bookora_send_reschedule_email', $silence );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'booking'       => $this->entries->booking( (int) $request['id'] ),
				'outside_hours' => $result['outside_hours'],
				'notified'      => $notify,
			)
		);
	}

	/**
	 * Opening windows of a row as local minutes.
	 *
	 * @param int|null $resource_id Resource (null = business).
	 * @param string   $date        Y-m-d.
	 * @return array<int, array{0:int,1:int}>
	 */
	private function open_minutes( ?int $resource_id, string $date ): array {
		$tz   = $this->availability->timezone( $resource_id );
		$calc = new \Bookora\Availability\SlotCalculator( $tz, ( new \Bookora\Repository\ScheduleRepository() )->weekly( $resource_id ), ( new \Bookora\Repository\ScheduleRepository() )->exceptions( $resource_id, $date, $date ) );
		$out  = array();
		foreach ( $calc->windows( $date ) as [ $a, $b ] ) {
			$sa    = ( new DateTimeImmutable( '@' . $a ) )->setTimezone( wp_timezone() );
			$sb    = ( new DateTimeImmutable( '@' . $b ) )->setTimezone( wp_timezone() );
			$end   = $sb->format( 'Y-m-d' ) > $date ? 1440 : (int) $sb->format( 'G' ) * 60 + (int) $sb->format( 'i' );
			$out[] = array( (int) $sa->format( 'G' ) * 60 + (int) $sa->format( 'i' ), $end );
		}
		return $out;
	}

	/**
	 * Merge overlapping minute ranges.
	 *
	 * @param array<int, array{0:int,1:int}> $ranges Ranges.
	 * @return array<int, array{0:int,1:int}>
	 */
	private static function merge( array $ranges ): array {
		usort( $ranges, static fn( $a, $b ) => $a[0] <=> $b[0] );
		$out = array();
		foreach ( $ranges as $r ) {
			$last = count( $out ) - 1;
			if ( $last >= 0 && $r[0] <= $out[ $last ][1] ) {
				$out[ $last ][1] = max( $out[ $last ][1], $r[1] );
			} else {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * "H:i" → minutes.
	 *
	 * @param string $time Time.
	 */
	private static function minutes( string $time ): int {
		[ $h, $m ] = array_map( 'intval', explode( ':', $time ) );
		return $h * 60 + $m;
	}
}
