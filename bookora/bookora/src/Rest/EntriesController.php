<?php
/**
 * REST: entries and bookings for the admin.
 *
 * @package Bookora
 */

namespace Bookora\Rest;

use Bookora\Availability\SlotCalculator;
use Bookora\Repository\BookingRepository;
use Bookora\Repository\EntryRepository;
use Bookora\Repository\ServiceRepository;
use Bookora\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /entries, /entries/{id}; POST /entries/bulk
 * GET  /bookings, /bookings/{id}; POST /bookings/{id}/status, /bookings/{id}/notes
 * GET  /badges
 */
final class EntriesController {

	/**
	 * Constructor.
	 *
	 * @param EntryRepository   $entries  Entries.
	 * @param BookingRepository $bookings Bookings.
	 * @param ServiceRepository $services Services.
	 */
	public function __construct(
		private EntryRepository $entries,
		private BookingRepository $bookings,
		private ServiceRepository $services,
	) {}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$view   = static fn(): bool => current_user_can( Capabilities::VIEW_BOOKINGS );
		$manage = static fn(): bool => current_user_can( Capabilities::MANAGE_BOOKINGS );
		$ns     = SettingsController::NAMESPACE;

		register_rest_route(
			$ns,
			'/entries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => new WP_REST_Response(
					$this->entries->entries(
						array(
							'form_id'  => (int) $r->get_param( 'form_id' ),
							'status'   => sanitize_key( (string) $r->get_param( 'status' ) ),
							'search'   => sanitize_text_field( (string) $r->get_param( 'search' ) ),
							'page'     => (int) $r->get_param( 'page' ),
							'per_page' => (int) $r->get_param( 'per_page' ),
						)
					)
				),
				'permission_callback' => $view,
			)
		);

		register_rest_route(
			$ns,
			'/entries/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'entry' ),
				'permission_callback' => $view,
			)
		);

		register_rest_route(
			$ns,
			'/entries/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			$ns,
			'/bookings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn( WP_REST_Request $r ) => new WP_REST_Response(
					$this->entries->bookings(
						array(
							'view'        => sanitize_key( (string) $r->get_param( 'view' ) ),
							'from'        => (string) $r->get_param( 'from' ),
							'to'          => (string) $r->get_param( 'to' ),
							'service_id'  => (int) $r->get_param( 'service_id' ),
							'resource_id' => (int) $r->get_param( 'resource_id' ),
							'search'      => sanitize_text_field( (string) $r->get_param( 'search' ) ),
							'page'        => (int) $r->get_param( 'page' ),
							'per_page'    => min( 100, (int) $r->get_param( 'per_page' ) ),
						)
					)
				),
				'permission_callback' => $view,
			)
		);

		register_rest_route(
			$ns,
			'/bookings/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $manage,
				'args'                => array(
					'status' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'pending', 'confirmed', 'rejected', 'cancelled', 'completed', 'no_show' ),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/bookings/(?P<id>\d+)/notes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function ( WP_REST_Request $r ) {
					if ( ! $this->entries->booking( (int) $r['id'] ) ) {
						return new WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
					}
					$this->entries->set_booking_notes( (int) $r['id'], (string) $r->get_param( 'notes' ) );
					return new WP_REST_Response( $this->entries->booking( (int) $r['id'] ) );
				},
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			$ns,
			'/bookings/(?P<id>\d+)/fee',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function ( WP_REST_Request $r ) {
					$booking = $this->entries->booking( (int) $r['id'] );
					if ( ! $booking ) {
						return new WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
					}
					$amount = null === $r->get_param( 'amount' ) ? (float) $booking['fee_amount'] : (float) $r->get_param( 'amount' );
					$reason = '' !== $booking['fee_reason'] ? $booking['fee_reason'] : 'manual';
					$result = $this->entries->set_fee( (int) $r['id'], $amount, (string) $r->get_param( 'status' ), $reason );
					return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
				},
				'permission_callback' => $manage,
				'args'                => array(
					'status' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( '', 'open', 'paid', 'waived' ),
					),
					'amount' => array(
						'type'    => 'number',
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/badges',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => new WP_REST_Response( $this->entries->badge_counts() ),
				'permission_callback' => $view,
			)
		);
	}

	/**
	 * GET /entries/{id} – opening a new entry marks it as read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function entry( WP_REST_Request $request ) {
		$entry = $this->entries->entry( (int) $request['id'] );
		if ( ! $entry ) {
			return new WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
		}
		if ( 'new' === $entry['status'] && current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			$this->entries->set_entry_status( array( $entry['id'] ), 'read' );
			$entry['status'] = 'read';
		}
		return new WP_REST_Response( $entry );
	}

	/**
	 * POST /entries/bulk {ids: [], action: read|new|starred|spam|trash|restore|delete}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk( WP_REST_Request $request ) {
		$ids    = array_slice( array_map( 'intval', (array) $request->get_param( 'ids' ) ), 0, 500 );
		$action = sanitize_key( (string) $request->get_param( 'action' ) );

		if ( 'delete' === $action ) {
			return new WP_REST_Response( array( 'affected' => $this->entries->delete_entries( $ids ) ) );
		}
		$status = 'restore' === $action ? 'read' : $action;
		if ( ! in_array( $status, EntryRepository::ENTRY_STATUSES, true ) ) {
			return new WP_Error( 'bookora_invalid_action', __( 'Invalid action.', 'bookora' ), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( array( 'affected' => $this->entries->set_entry_status( $ids, $status ) ) );
	}

	/**
	 * POST /bookings/{id}/status
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$status  = (string) $request->get_param( 'status' );
		$booking = $this->entries->booking( $id );

		if ( ! $booking ) {
			return new WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
		}

		// Re-opening a rejected/cancelled booking must not create a double booking.
		$blocking = BookingRepository::BLOCKING_STATUSES;
		if ( in_array( $status, $blocking, true ) && ! in_array( $booking['status'], $blocking, true ) && $this->conflicts( $id ) ) {
			return new WP_Error( 'bookora_slot_taken', __( 'This time is no longer free. The booking cannot be reopened.', 'bookora' ), array( 'status' => 409 ) );
		}

		$result = $this->entries->set_booking_status( $id, $status );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/**
	 * Whether a (currently non-blocking) booking would overlap other active bookings.
	 *
	 * @param int $booking_id Booking id.
	 */
	private function conflicts( int $booking_id ): bool {
		$row = $this->bookings->find( $booking_id );
		if ( ! $row ) {
			return true;
		}

		$service = $row['service_id'] ? $this->services->find( (int) $row['service_id'] ) : null;
		$before  = (int) ( $service['buffer_before'] ?? 0 ) * 60;
		$after   = (int) ( $service['buffer_after'] ?? 0 ) * 60;
		$cap     = max( 1, (int) ( $service['capacity'] ?? 1 ) );
		$start   = BookingRepository::ts( $row['start_utc'] ) - $before;
		$end     = BookingRepository::ts( $row['end_utc'] ) + $after;
		$used    = (int) $row['seats'];

		foreach ( $this->bookings->busy( $row['resource_id'] ? (int) $row['resource_id'] : null, (int) $row['service_id'], $start, $end ) as $busy ) {
			if ( $busy['start'] < $end && $busy['end'] > $start ) {
				if ( SlotCalculator::FULL_BLOCK === $busy['seats'] ) {
					return true;
				}
				$used += $busy['seats'];
			}
		}

		return $used > $cap;
	}
}
