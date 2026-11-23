<?php
/**
 * Creates bookings safely.
 *
 * @package Bookora
 */

namespace Bookora\Booking;

use Bookora\Availability\AvailabilityService;
use Bookora\Availability\SlotCalculator;
use Bookora\Availability\SlotRules;
use Bookora\Install\Schema;
use Bookora\Repository\ServiceRepository;
use Bookora\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents double bookings: every write for a resource happens while holding a named
 * database lock (GET_LOCK), and the slot is re-validated inside that lock.
 */
final class BookingService {

	private const LOCK_TIMEOUT = 10;

	/**
	 * Constructor.
	 *
	 * @param AvailabilityService $availability Availability.
	 * @param ServiceRepository   $services     Services.
	 * @param Settings            $settings     Settings.
	 */
	public function __construct(
		private AvailabilityService $availability,
		private ServiceRepository $services,
		private Settings $settings,
	) {}

	/**
	 * Create a booking.
	 *
	 * Keys: service_id, start (UTC timestamp), resource_id (optional, empty = any), seats, form_id,
	 * customer {email, name, phone}, values (field_key => value), timezone, notes, locale,
	 * source_url, ip, user_agent, status (pending|confirmed).
	 *
	 * @param array<string, mixed> $input Booking data.
	 * @return array{booking_id:int, entry_id:int, uid:string, resource_id:int|null, status:string}|WP_Error
	 */
	public function create( array $input ) {
		global $wpdb;

		$service_id = (int) ( $input['service_id'] ?? 0 );
		$start      = (int) ( $input['start'] ?? 0 );
		$seats      = max( 1, (int) ( $input['seats'] ?? 1 ) );
		$email      = sanitize_email( (string) ( $input['customer']['email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'bookora_invalid_email', __( 'Please enter a valid email address.', 'bookora' ), array( 'status' => 400 ) );
		}

		$service = $this->services->find( $service_id );
		if ( ! $service || 'active' !== $service['status'] ) {
			return new WP_Error( 'bookora_unknown_service', __( 'This service is not available.', 'bookora' ), array( 'status' => 404 ) );
		}

		$requested  = empty( $input['resource_id'] ) ? null : (int) $input['resource_id'];
		$candidates = $this->availability->candidate_resources( $service_id, $requested );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		/**
		 * Filter the order in which resources are tried for "any available" bookings.
		 *
		 * @param array $candidates Resource ids (null = no resource).
		 * @param array $service    Service row.
		 * @param int   $start      Start timestamp.
		 */
		$candidates = (array) apply_filters( 'bookora_resource_candidates', $candidates, $service, $start );

		$rules = SlotRules::from_service( $service, $this->settings->all(), $seats );
		$end   = $start + $rules->duration * 60;

		foreach ( $candidates as $resource_id ) {
			$lock = $this->lock_name( $resource_id );

			if ( ! $this->acquire_lock( $lock ) ) {
				continue;
			}

			try {
				$calc = $this->availability->calculator( $service_id, $resource_id, $start - DAY_IN_SECONDS, $end + DAY_IN_SECONDS );
				if ( ! $calc->is_available( $start, $rules, time() ) ) {
					continue;
				}

				$result = $this->insert( $input, $service, $resource_id, $start, $end, $seats, $email );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				/**
				 * Fires after a booking was stored.
				 *
				 * @param int   $booking_id Booking id.
				 * @param int   $entry_id   Entry id.
				 * @param array $input      Original input.
				 */
				do_action( 'bookora_booking_created', $result['booking_id'], $result['entry_id'], $input );

				return $result;
			} finally {
				$this->release_lock( $lock );
			}
		}

		return new WP_Error( 'bookora_slot_taken', __( 'Sorry, this time has just been booked. Please choose another time.', 'bookora' ), array( 'status' => 409 ) );
	}

	/**
	 * Move a booking to a new start time and/or resource (admin). Keeps the duration.
	 * Overlaps (incl. breaks) are refused; outside opening hours is allowed but reported.
	 *
	 * @param int      $booking_id  Booking id.
	 * @param int      $start       New UTC start.
	 * @param int|null $resource_id New resource (null = keep for bookings with resource, or business-wide).
	 * @return array{outside_hours:bool, previous_start:string, previous_resource:int|null}|WP_Error
	 */
	public function move( int $booking_id, int $start, ?int $resource_id ) {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'bookings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $booking_id ), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $row['status'], array( 'pending', 'confirmed' ), true ) ) {
			return new WP_Error( 'bookora_not_movable', __( 'Only pending or confirmed appointments can be moved.', 'bookora' ), array( 'status' => 409 ) );
		}

		$service_id = (int) $row['service_id'];
		$service    = $service_id ? $this->services->find( $service_id ) : null;
		$current    = $row['resource_id'] ? (int) $row['resource_id'] : null;
		$target     = null === $resource_id ? $current : ( $resource_id > 0 ? $resource_id : null );

		$assigned = $service_id ? $this->services->resource_ids( $service_id ) : array();
		if ( $assigned && ( null === $target || ! in_array( $target, $assigned, true ) ) ) {
			return new WP_Error( 'bookora_unknown_resource', __( 'This staff member does not offer the service.', 'bookora' ), array( 'status' => 400 ) );
		}

		$duration = strtotime( $row['end_utc'] . ' UTC' ) - strtotime( $row['start_utc'] . ' UTC' );
		$end      = $start + $duration;
		$before   = (int) ( $service['buffer_before'] ?? 0 ) * 60;
		$after    = (int) ( $service['buffer_after'] ?? 0 ) * 60;
		$capacity = max( 1, (int) ( $service['capacity'] ?? 1 ) );
		$lock     = $this->lock_name( $target );

		if ( ! $this->acquire_lock( $lock ) ) {
			return new WP_Error( 'bookora_busy', __( 'Please try again in a moment.', 'bookora' ), array( 'status' => 409 ) );
		}

		try {
			$calc = $this->availability->calculator( $service_id, $target, $start - DAY_IN_SECONDS, $end + DAY_IN_SECONDS, $booking_id );
			$used = (int) $row['seats'];

			foreach ( $this->bookings_busy( $target, $service_id, $start - $before, $end + $after, $booking_id ) as $busy ) {
				if ( $busy['start'] < $end + $after && $busy['end'] > $start - $before ) {
					$used = SlotCalculator::FULL_BLOCK === $busy['seats'] ? PHP_INT_MAX : $used + $busy['seats'];
				}
				if ( $used > $capacity ) {
					return new WP_Error( 'bookora_conflict', __( 'This time overlaps with another appointment (including breaks).', 'bookora' ), array( 'status' => 409 ) );
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				$table,
				array(
					'start_utc'   => gmdate( 'Y-m-d H:i:s', $start ),
					'end_utc'     => gmdate( 'Y-m-d H:i:s', $end ),
					'resource_id' => $target,
					'updated_at'  => current_time( 'mysql', true ),
				),
				array( 'id' => $booking_id )
			);
			$outside = ! $calc->within_hours( $start, $end );
		} finally {
			$this->release_lock( $lock );
		}

		/**
		 * Fires after a booking was moved.
		 *
		 * @param int      $booking_id        Booking id.
		 * @param string   $previous_start    Previous UTC start (Y-m-d H:i:s).
		 * @param int|null $previous_resource Previous resource.
		 */
		do_action( 'bookora_booking_moved', $booking_id, $row['start_utc'], $current );

		return array(
			'outside_hours'     => $outside,
			'previous_start'    => $row['start_utc'],
			'previous_resource' => $current,
		);
	}

	/**
	 * Busy intervals for a resource without one booking.
	 *
	 * @param int|null $resource_id Resource.
	 * @param int      $service_id  Service.
	 * @param int      $from        From.
	 * @param int      $to          To.
	 * @param int      $exclude_id  Booking to ignore.
	 * @return array<int, array{start:int,end:int,seats:int}>
	 */
	private function bookings_busy( ?int $resource_id, int $service_id, int $from, int $to, int $exclude_id ): array {
		return ( new \Bookora\Repository\BookingRepository() )->busy( $resource_id, $service_id, $from, $to, $exclude_id );
	}

	/**
	 * Store customer, entry, values and booking in one transaction.
	 *
	 * @param array<string, mixed> $input       Input.
	 * @param array<string, mixed> $service     Service row.
	 * @param int|null             $resource_id Resource.
	 * @param int                  $start       Start.
	 * @param int                  $end         End.
	 * @param int                  $seats       Seats.
	 * @param string               $email       Validated email.
	 * @return array{booking_id:int, entry_id:int, uid:string, resource_id:int|null, status:string}|WP_Error
	 */
	private function insert( array $input, array $service, ?int $resource_id, int $start, int $end, int $seats, string $email ) {
		global $wpdb;

		$now     = current_time( 'mysql', true );
		$form_id = empty( $input['form_id'] ) ? null : (int) $input['form_id'];
		$status  = in_array( $input['status'] ?? '', array( 'pending', 'confirmed' ), true ) ? $input['status'] : (string) $this->settings->get( 'default_status', 'pending' );
		$tz      = (string) ( $input['timezone'] ?? '' );
		$tz      = in_array( $tz, timezone_identifiers_list(), true ) ? $tz : wp_timezone_string();
		$uid     = wp_generate_uuid4();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );

		$customer_id = $this->upsert_customer( $email, $input, $now );
		$entry_id    = 0;

		if ( $customer_id ) {
			$ok       = $wpdb->insert(
				Schema::table( $wpdb->prefix, 'entries' ),
				array(
					'uid'         => wp_generate_uuid4(),
					'form_id'     => (int) $form_id,
					'customer_id' => $customer_id,
					'status'      => 'new',
					'ip_hash'     => empty( $input['ip'] ) ? '' : hash( 'sha256', $input['ip'] . wp_salt( 'nonce' ) ),
					'user_agent'  => mb_substr( sanitize_text_field( (string) ( $input['user_agent'] ?? '' ) ), 0, 255 ),
					'source_url'  => mb_substr( esc_url_raw( (string) ( $input['source_url'] ?? '' ) ), 0, 2048 ),
					'locale'      => mb_substr( sanitize_text_field( (string) ( $input['locale'] ?? get_locale() ) ), 0, 20 ),
					'created_at'  => $now,
					'updated_at'  => $now,
				)
			);
			$entry_id = $ok ? (int) $wpdb->insert_id : 0;
		}

		if ( $entry_id ) {
			foreach ( (array) ( $input['values'] ?? array() ) as $key => $value ) {
				$wpdb->insert(
					Schema::table( $wpdb->prefix, 'entry_values' ),
					array(
						'entry_id'  => $entry_id,
						'field_key' => mb_substr( sanitize_key( (string) $key ), 0, 64 ),
						'value'     => is_array( $value ) ? wp_json_encode( $value ) : (string) $value,
					)
				);
			}
		}

		$booking_id = 0;
		if ( $entry_id ) {
			$ok         = $wpdb->insert(
				Schema::table( $wpdb->prefix, 'bookings' ),
				array(
					'uid'         => $uid,
					'entry_id'    => $entry_id,
					'form_id'     => $form_id,
					'service_id'  => (int) $service['id'],
					'resource_id' => $resource_id,
					'customer_id' => $customer_id,
					'start_utc'   => gmdate( 'Y-m-d H:i:s', $start ),
					'end_utc'     => gmdate( 'Y-m-d H:i:s', $end ),
					'timezone'    => $tz,
					'status'      => $status,
					'seats'       => $seats,
					'notes'       => sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ),
					'source'      => 'web',
					'created_at'  => $now,
					'updated_at'  => $now,
				)
			);
			$booking_id = $ok ? (int) $wpdb->insert_id : 0;
		}

		if ( ! $booking_id ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'bookora_db_error', __( 'The booking could not be saved. Please try again.', 'bookora' ), array( 'status' => 500 ) );
		}

		$wpdb->query( 'COMMIT' );
		// phpcs:enable

		return array(
			'booking_id'  => $booking_id,
			'entry_id'    => $entry_id,
			'uid'         => $uid,
			'resource_id' => $resource_id,
			'status'      => $status,
		);
	}

	/**
	 * Find or create the customer; update name/phone when provided.
	 *
	 * @param string               $email Email.
	 * @param array<string, mixed> $input Input.
	 * @param string               $now   UTC datetime.
	 */
	private function upsert_customer( string $email, array $input, string $now ): int {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'customers' );
		$name  = mb_substr( sanitize_text_field( (string) ( $input['customer']['name'] ?? '' ) ), 0, 191 );
		$phone = mb_substr( sanitize_text_field( (string) ( $input['customer']['phone'] ?? '' ) ), 0, 50 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $email ) );

		if ( $id ) {
			$update = array_filter(
				array(
					'name'  => $name,
					'phone' => $phone,
				)
			);
			if ( $update ) {
				$wpdb->update( $table, $update + array( 'updated_at' => $now ), array( 'id' => $id ) );
			}
			return $id;
		}

		$user = get_user_by( 'email', $email );
		$ok   = $wpdb->insert(
			$table,
			array(
				'email'      => $email,
				'name'       => $name,
				'phone'      => $phone,
				'user_id'    => $user ? (int) $user->ID : null,
				'locale'     => mb_substr( sanitize_text_field( (string) ( $input['locale'] ?? '' ) ), 0, 20 ),
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		// phpcs:enable

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Acquire a named lock. SQLite (e.g. WordPress Playground) allows only one writer at a time,
	 * so no named lock is needed there.
	 *
	 * @param string $lock Lock name.
	 */
	private function acquire_lock( string $lock ): bool {
		global $wpdb;

		if ( self::is_sqlite() ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) );
	}

	/**
	 * Release a named lock.
	 *
	 * @param string $lock Lock name.
	 */
	private function release_lock( string $lock ): void {
		global $wpdb;

		if ( self::is_sqlite() ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}

	/**
	 * Whether WordPress runs on SQLite.
	 */
	private static function is_sqlite(): bool {
		return defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE;
	}

	/**
	 * Lock name per site and resource (max 64 chars for MySQL).
	 *
	 * @param int|null $resource_id Resource id.
	 */
	private function lock_name( ?int $resource_id ): string {
		global $wpdb;
		return substr( 'bookora_' . md5( DB_NAME . $wpdb->prefix ) . '_' . (int) $resource_id, 0, 64 );
	}
}
