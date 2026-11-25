<?php
/**
 * Webhooks for Zapier, Make, n8n or Google Sheets (Apps Script): signed JSON per event,
 * delivered after the visitor's response, retried after 5 and 30 minutes.
 *
 * @package KalendoraPro
 */

namespace KalendoraPro;

use Kalendora\Core\Plugin as Kalendora;
use Kalendora\Repository\EntryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Webhooks.
 */
final class Webhooks {

	public const OPTION = 'kalendora_pro_webhooks';
	public const LOG    = 'kalendora_pro_webhook_log';
	public const RETRY  = 'kalendora_pro_webhook_retry';

	public const EVENTS = array(
		'booking.created',
		'booking.status_changed',
		'booking.moved',
		'booking.cancelled_by_customer',
		'booking.rescheduled_by_customer',
		'entry.created',
	);

	/** Minutes before attempt 2 and 3. */
	private const RETRY_MINUTES = array( 5, 30 );

	/**
	 * Deliveries waiting for shutdown.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $pending = array();

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'kalendora_booking_created', fn( $id ) => $this->booking_event( 'booking.created', (int) $id ), 30 );
		add_action( 'kalendora_booking_status_changed', fn( $id, $status, $previous ) => $this->booking_event( 'booking.status_changed', (int) $id, array( 'previous_status' => (string) $previous ) ), 30, 3 );
		add_action( 'kalendora_booking_moved', fn( $id, $previous_start ) => $this->booking_event( 'booking.moved', (int) $id, array( 'previous_start' => gmdate( 'c', (int) strtotime( $previous_start . ' UTC' ) ) ) ), 30, 2 );
		add_action(
			'kalendora_customer_cancelled',
			fn( $id, $fee, $reason ) => $this->booking_event(
				'booking.cancelled_by_customer',
				(int) $id,
				array(
					'fee'    => (float) $fee,
					'reason' => (string) $reason,
				)
			),
			30,
			3
		);
		add_action( 'kalendora_customer_rescheduled', fn( $id ) => $this->booking_event( 'booking.rescheduled_by_customer', (int) $id ), 30 );
		add_action( 'kalendora_entry_created', array( $this, 'entry_event' ), 30, 3 );
		add_action( self::RETRY, array( $this, 'retry' ) );
	}

	/**
	 * Configured hooks.
	 *
	 * @return list<array{id:string, url:string, events:string[], secret:string, active:bool}>
	 */
	public static function all(): array {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Add a webhook.
	 *
	 * @param string   $url    HTTPS address.
	 * @param string[] $events Events.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function add( string $url, array $events ) {
		$url    = esc_url_raw( trim( $url ), array( 'https' ) );
		$events = array_values( array_intersect( $events, self::EVENTS ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		// Syntax and internal targets here; the full check (DNS → private IP) runs on every delivery (wp_safe_remote_post).
		$internal = '' === $host || ! str_contains( $host, '.' ) || 'localhost' === $host || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' )
			|| ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) )
			|| str_starts_with( $host, '[' );
		if ( '' === $url || $internal ) {
			return new \WP_Error( 'kalendora_pro_url', __( 'Please enter a public https:// address.', 'kalendora-pro' ) );
		}
		if ( ! $events ) {
			return new \WP_Error( 'kalendora_pro_events', __( 'Please choose at least one event.', 'kalendora-pro' ) );
		}
		$hook   = array(
			'id'     => wp_generate_password( 12, false ),
			'url'    => $url,
			'events' => $events,
			'secret' => wp_generate_password( 32, false ),
			'active' => true,
		);
		$list   = self::all();
		$list[] = $hook;
		update_option( self::OPTION, $list, false );
		return $hook;
	}

	/**
	 * Remove a webhook.
	 *
	 * @param string $id Id.
	 */
	public static function remove( string $id ): void {
		update_option( self::OPTION, array_values( array_filter( self::all(), static fn( $h ) => $h['id'] !== $id ) ), false );
	}

	/* ------------------------------------------------------------------ payloads */

	/**
	 * Booking events.
	 *
	 * @param string               $event      Event.
	 * @param int                  $booking_id Booking.
	 * @param array<string, mixed> $extra      Extra data.
	 */
	public function booking_event( string $event, int $booking_id, array $extra = array() ): void {
		if ( ! $this->listening( $event ) ) {
			return;
		}
		$b = Kalendora::instance()->container()->get( EntryRepository::class )->booking( $booking_id );
		if ( ! $b ) {
			return;
		}
		$data = array(
			'id'         => (int) $b['id'],
			'uid'        => (string) $b['uid'],
			'status'     => (string) $b['status'],
			'start'      => (string) $b['start'],
			'end'        => (string) $b['end'],
			'date'       => (string) $b['date'],
			'time'       => (string) $b['time'],
			'end_time'   => (string) $b['end_time'],
			'service'    => (string) $b['service_name'],
			'service_id' => $b['service_id'],
			'staff'      => (string) $b['resource_name'],
			'staff_id'   => $b['resource_id'],
			'seats'      => (int) $b['seats'],
			'customer'   => array(
				'name'     => (string) $b['name'],
				'email'    => (string) $b['email'],
				'phone'    => (string) $b['phone'],
				'timezone' => (string) ( $b['timezone'] ?? '' ),
			),
			'fee'        => array(
				'amount' => (float) $b['fee_amount'],
				'status' => (string) $b['fee_status'],
			),
			'admin_url'  => admin_url( 'admin.php?page=kalendora-bookings' ),
		);
		if ( ! empty( $b['entry_id'] ) ) {
			$entry          = Kalendora::instance()->container()->get( EntryRepository::class )->entry( (int) $b['entry_id'] );
			$data['fields'] = $entry ? self::fields( (array) $entry['values'] ) : array();
		}
		$this->queue( $event, $data + $extra );
	}

	/**
	 * New entry.
	 *
	 * @param int                  $entry_id Entry.
	 * @param array<string, mixed> $values   Values.
	 * @param array<string, mixed> $form     Form.
	 */
	public function entry_event( int $entry_id, array $values, array $form ): void {
		unset( $values );
		if ( ! $this->listening( 'entry.created' ) ) {
			return;
		}
		$entry = Kalendora::instance()->container()->get( EntryRepository::class )->entry( $entry_id );
		if ( ! $entry ) {
			return;
		}
		$this->queue(
			'entry.created',
			array(
				'id'         => $entry_id,
				'form_id'    => (int) $form['id'],
				'form'       => (string) $form['title'],
				'form_type'  => (string) $form['type'],
				'created_at' => gmdate( 'c', (int) strtotime( (string) $entry['created_at'] . ' UTC' ) ),
				'fields'     => self::fields( (array) $entry['values'] ),
				'admin_url'  => admin_url( 'admin.php?page=kalendora-entries' ),
			)
		);
	}

	/**
	 * Field key => readable value (files only as names).
	 *
	 * @param array<int, array<string, mixed>> $values Entry values (key, label, value).
	 * @return array<string, string>
	 */
	private static function fields( array $values ): array {
		$out = array();
		foreach ( $values as $v ) {
			if ( empty( $v['key'] ) || in_array( $v['type'] ?? '', array( 'signature' ), true ) ) {
				continue;
			}
			$out[ (string) $v['key'] ] = is_array( $v['value'] ?? null ) ? implode( ', ', array_map( 'strval', array_filter( $v['value'], 'is_scalar' ) ) ) : (string) ( $v['display'] ?? $v['value'] ?? '' );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ delivery */

	/**
	 * Any active hook for this event?
	 *
	 * @param string $event Event.
	 */
	private function listening( string $event ): bool {
		foreach ( self::all() as $hook ) {
			if ( $hook['active'] && in_array( $event, $hook['events'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Queue for all matching hooks.
	 *
	 * @param string               $event Event.
	 * @param array<string, mixed> $data  Data.
	 */
	private function queue( string $event, array $data ): void {
		foreach ( self::all() as $hook ) {
			if ( $hook['active'] && in_array( $event, $hook['events'], true ) ) {
				$delivery = array(
					'id'      => wp_generate_uuid4(),
					'hook'    => $hook['id'],
					'event'   => $event,
					'attempt' => 1,
					'body'    => (string) wp_json_encode(
						array(
							'event'      => $event,
							'created_at' => gmdate( 'c' ),
							'site'       => home_url( '/' ),
							'data'       => $data,
						)
					),
				);
				if ( 'cli' === PHP_SAPI ) {
					$this->deliver( $delivery );
				} else {
					if ( ! $this->pending ) {
						add_action( 'shutdown', array( $this, 'flush' ), 120 );
					}
					$this->pending[] = $delivery;
				}
			}
		}
	}

	/**
	 * Shutdown: respond to the visitor first.
	 */
	public function flush(): void {
		if ( function_exists( 'fastcgi_finish_request' ) && ! headers_sent() ) {
			fastcgi_finish_request();
		}
		$list          = $this->pending;
		$this->pending = array();
		foreach ( $list as $delivery ) {
			$this->deliver( $delivery );
		}
	}

	/**
	 * Retry from cron.
	 *
	 * @param string $delivery_id Delivery id.
	 */
	public function retry( string $delivery_id ): void {
		$delivery = get_transient( 'kalendora_pro_wh_' . $delivery_id );
		delete_transient( 'kalendora_pro_wh_' . $delivery_id );
		if ( is_array( $delivery ) ) {
			$this->deliver( $delivery );
		}
	}

	/**
	 * Send one delivery.
	 *
	 * @param array<string, mixed> $delivery Delivery.
	 * @return bool Success.
	 */
	public function deliver( array $delivery ): bool {
		$hook = null;
		foreach ( self::all() as $h ) {
			if ( $h['id'] === $delivery['hook'] ) {
				$hook = $h;
			}
		}
		if ( ! $hook ) {
			return false;
		}
		$response = wp_safe_remote_post(
			$hook['url'],
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'          => 'application/json',
					'User-Agent'            => 'Kalendora/' . ( defined( 'KALENDORA_VERSION' ) ? KALENDORA_VERSION : '1' ) . '; ' . home_url(),
					'X-Kalendora-Event'     => $delivery['event'],
					'X-Kalendora-Delivery'  => $delivery['id'],
					'X-Kalendora-Signature' => 'sha256=' . hash_hmac( 'sha256', $delivery['body'], $hook['secret'] ),
				),
				'body'        => $delivery['body'],
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$ok       = $code >= 200 && $code < 300;
		self::log( $hook['url'], (string) $delivery['event'], $code, (int) $delivery['attempt'] );

		if ( ! $ok && $delivery['attempt'] <= count( self::RETRY_MINUTES ) ) {
			$wait = self::RETRY_MINUTES[ $delivery['attempt'] - 1 ];
			++$delivery['attempt'];
			set_transient( 'kalendora_pro_wh_' . $delivery['id'], $delivery, DAY_IN_SECONDS );
			wp_schedule_single_event( time() + $wait * MINUTE_IN_SECONDS, self::RETRY, array( $delivery['id'] ) );
		}
		return $ok;
	}

	/**
	 * Keep the last 30 deliveries.
	 *
	 * @param string $url     URL.
	 * @param string $event   Event.
	 * @param int    $code    HTTP code (0 = no connection).
	 * @param int    $attempt Attempt.
	 */
	private static function log( string $url, string $event, int $code, int $attempt ): void {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'host'    => (string) wp_parse_url( $url, PHP_URL_HOST ),
				'event'   => $event,
				'code'    => $code,
				'attempt' => $attempt,
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 30 ), false );
	}

	/**
	 * Send a test delivery to one hook.
	 *
	 * @param string $id Hook id.
	 */
	public function test( string $id ): bool {
		return $this->deliver(
			array(
				'id'      => wp_generate_uuid4(),
				'hook'    => $id,
				'event'   => 'test',
				'attempt' => 99,
				'body'    => (string) wp_json_encode(
					array(
						'event'      => 'test',
						'created_at' => gmdate( 'c' ),
						'site'       => home_url( '/' ),
						'data'       => array( 'message' => 'Kalendora webhook test' ),
					)
				),
			)
		);
	}
}
