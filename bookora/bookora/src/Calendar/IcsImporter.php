<?php
/**
 * Imports busy times from private calendar addresses (Google, iCloud, Outlook, Nextcloud …).
 *
 * @package Bookora
 */

namespace Bookora\Calendar;

use Bookora\Availability\AvailabilityService;
use Bookora\Install\Schema;
use Bookora\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Runs every 15 minutes via WP-Cron. Only start/end are stored – titles never leave the external calendar.
 */
final class IcsImporter {

	public const CRON     = 'bookora_sync_calendars';
	public const SCHEDULE = 'bookora_quarter_hour';
	public const INTERVAL = 15 * MINUTE_IN_SECONDS;
	public const MAX_SIZE = 15 * MB_IN_BYTES;

	/**
	 * Constructor.
	 *
	 * @param ConnectionRepository $connections  Connections.
	 * @param AvailabilityService  $availability Availability (time zones).
	 * @param Settings             $settings     Settings.
	 */
	public function __construct(
		private ConnectionRepository $connections,
		private AvailabilityService $availability,
		private Settings $settings,
	) {}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval -- 15 minutes is intended.
		add_action( self::CRON, array( $this, 'run' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON );
		}
	}

	/**
	 * Add the 15-minute schedule.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function schedules( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::INTERVAL,
			'display'  => 'Bookora: every 15 minutes',
		);
		return $schedules;
	}

	/**
	 * Cron: sync all connections that are due (max. ~25 s per run).
	 *
	 * @return int Number of synced connections.
	 */
	public function run(): int {
		$started = time();
		$done    = 0;
		foreach ( $this->connections->due( self::INTERVAL - 60 ) as $id ) {
			if ( time() - $started > 25 ) {
				break;
			}
			$this->sync( $id );
			++$done;
		}
		return $done;
	}

	/**
	 * Normalize and validate a calendar address entered by the user.
	 *
	 * @param string $url Address (https://, http:// or webcal://).
	 * @return string|WP_Error
	 */
	public static function normalize_url( string $url ) {
		$url   = trim( $url );
		$url   = preg_replace( '#^webcals?://#i', 'https://', $url ) ?? $url;
		$parts = wp_parse_url( $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		// Syntax check only; the request itself goes through wp_safe_remote_get() (blocks internal addresses).
		// Only https: the address contains a secret key that must not travel unencrypted.
		$bad = ! preg_match( '#^https://#i', $url ) || '' === $host || isset( $parts['user'] ) || isset( $parts['pass'] )
			|| 'localhost' === $host || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' )
			|| ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) )
			|| strlen( $url ) > 2000;
		if ( $bad ) {
			return new WP_Error( 'bookora_invalid_calendar_url', __( 'Please enter a valid, encrypted calendar address (starting with https:// or webcal://).', 'bookora' ), array( 'status' => 400 ) );
		}
		$own = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( str_contains( $url, 'bookora_feed=' ) && ConnectionRepository::host( $url ) === $own ) {
			return new WP_Error( 'bookora_own_feed', __( 'This is a Bookora feed of this website. Importing it would block your own appointments twice.', 'bookora' ), array( 'status' => 400 ) );
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Sync one connection.
	 *
	 * @param int  $id    Connection id.
	 * @param bool $force Ignore HTTP caching.
	 * @return array{ok:bool, error:?string, events:int, unchanged:bool}
	 */
	public function sync( int $id, bool $force = false ): array {
		$row = $this->connections->find( $id );
		if ( ! $row ) {
			return array(
				'ok'        => false,
				'error'     => __( 'Not found.', 'bookora' ),
				'events'    => 0,
				'unchanged' => false,
			);
		}

		if ( ! $this->connections->lock( $id ) ) {
			return array(
				'ok'        => false,
				'error'     => __( 'A synchronization of this calendar is already running. Please try again in a minute.', 'bookora' ),
				'events'    => 0,
				'unchanged' => false,
			);
		}
		try {
			return $this->run_sync( $row, $force );
		} finally {
			$this->connections->unlock( $id );
		}
	}

	/**
	 * Sync body (runs under the per-connection lock).
	 *
	 * @param array<string, mixed> $row   Connection row.
	 * @param bool                 $force Ignore HTTP caching.
	 * @return array{ok:bool, error:?string, events:int, unchanged:bool}
	 */
	private function run_sync( array $row, bool $force ): array {
		$id            = (int) $row['id'];
		$url           = $this->connections->url( $row );
		$state         = json_decode( (string) $row['sync_token'], true );
		$state         = is_array( $state ) ? $state : array();
		[ $from, $to ] = $this->window();

		// Record the attempt first: a run that dies (timeout) must not block the other calendars.
		$this->connections->touch( $id );

		// A 304 keeps the stored busy times – but only while they still cover the booking horizon.
		$covered = isset( $state['to'] ) && (int) $state['to'] >= $to - DAY_IN_SECONDS;
		$headers = array( 'Accept' => 'text/calendar, */*;q=0.5' );
		if ( ! $force && $covered && ! empty( $state['etag'] ) ) {
			$headers['If-None-Match'] = (string) $state['etag'];
		}
		if ( ! $force && $covered && ! empty( $state['last_modified'] ) ) {
			$headers['If-Modified-Since'] = (string) $state['last_modified'];
		}

		$response = '' === $url ? new WP_Error( 'bookora_no_url', '' ) : wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_SIZE,
				'headers'             => $headers,
				'user-agent'          => 'Bookora/' . ( defined( 'BOOKORA_VERSION' ) ? BOOKORA_VERSION : '1' ) . '; ' . home_url( '/' ),
			)
		);

		$error = $this->http_error( $response );
		if ( null !== $error ) {
			$this->connections->mark( $id, $error );
			return array(
				'ok'        => false,
				'error'     => $error,
				'events'    => 0,
				'unchanged' => false,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code ) {
			$this->connections->mark( $id, null, $state );
			return array(
				'ok'        => true,
				'error'     => null,
				'events'    => (int) ( $state['count'] ?? 0 ),
				'unchanged' => true,
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) >= self::MAX_SIZE || ( str_contains( strtoupper( substr( $body, 0, 2000 ) ), 'BEGIN:VCALENDAR' ) && ! str_contains( strtoupper( substr( $body, -2000 ) ), 'END:VCALENDAR' ) ) ) {
			// Truncated download: keep the previous busy times instead of replacing them with a partial set.
			$error = __( 'The calendar file was incomplete (too large or interrupted). The previous busy times are kept.', 'bookora' );
			$this->connections->mark( $id, $error );
			return array(
				'ok'        => false,
				'error'     => $error,
				'events'    => 0,
				'unchanged' => false,
			);
		}
		if ( ! str_contains( strtoupper( substr( $body, 0, 2000 ) ), 'BEGIN:VCALENDAR' ) ) {
			$error = __( 'The address does not return a calendar file. Please copy the private iCal/ICS address of your calendar.', 'bookora' );
			$this->connections->mark( $id, $error );
			return array(
				'ok'        => false,
				'error'     => $error,
				'events'    => 0,
				'unchanged' => false,
			);
		}

		$etag          = (string) wp_remote_retrieve_header( $response, 'etag' );
		$last_modified = (string) wp_remote_retrieve_header( $response, 'last-modified' );
		unset( $response );

		wp_raise_memory_limit( 'bookora_calendar' ); // Large calendars (years of history) need some headroom.
		$events = $this->parse( $row, $body, $from, $to );
		unset( $body );
		if ( ! $this->connections->replace_events( $id, $events ) ) {
			$error = __( 'The busy times could not be saved (database error). The previous busy times are kept.', 'bookora' );
			$this->connections->mark( $id, $error );
			return array(
				'ok'        => false,
				'error'     => $error,
				'events'    => 0,
				'unchanged' => false,
			);
		}
		$this->connections->mark(
			$id,
			null,
			array(
				'etag'          => $etag,
				'last_modified' => $last_modified,
				'count'         => count( $events ),
				'to'            => $to,
			)
		);

		/**
		 * Fires after busy times of an external calendar were imported.
		 *
		 * @param int $id     Connection id.
		 * @param int $events Number of busy intervals stored.
		 */
		do_action( 'bookora_calendar_synced', $id, count( $events ) );

		return array(
			'ok'        => true,
			'error'     => null,
			'events'    => count( $events ),
			'unchanged' => false,
		);
	}

	/**
	 * Busy intervals worth storing (bookable window, without Bookora's own appointments).
	 *
	 * @param array<string, mixed> $row  Connection row.
	 * @param string               $body Calendar text.
	 * @param int                  $from Window start.
	 * @param int                  $to   Window end.
	 * @return list<array{uid:string, start:int, end:int, all_day:bool}>
	 */
	public function parse( array $row, string $body, int $from, int $to ): array {
		$resource = $row['resource_id'] ? (int) $row['resource_id'] : null;
		$options  = ConnectionRepository::options( $row );
		$own      = '@' . strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		$out = array();
		foreach ( ( new IcsParser( $this->availability->timezone( $resource ) ) )->busy( $body, $from, $to ) as $event ) {
			// Bookora appointments that came back via a subscribed feed are already blocked.
			$uid = strtolower( $event['uid'] );
			if ( str_starts_with( $uid, 'bookora-' ) && str_ends_with( $uid, $own ) ) {
				continue;
			}
			if ( $event['all_day'] && ! $options['block_all_day'] ) {
				continue;
			}
			$out[] = $event;
		}
		return $out;
	}

	/**
	 * Import window: from yesterday until the end of the booking horizon.
	 *
	 * @return array{0:int, 1:int}
	 */
	private function window(): array {
		$now = time();
		return array( $now - DAY_IN_SECONDS, $now + ( $this->horizon_days() + 2 ) * DAY_IN_SECONDS );
	}

	/**
	 * How far ahead customers can book (global setting or longest service setting).
	 */
	private function horizon_days(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
		$service = (int) $wpdb->get_var( 'SELECT MAX(max_advance) FROM ' . Schema::table( $wpdb->prefix, 'services' ) );
		$days    = max( (int) $this->settings->get( 'max_advance_days', 90 ), $service );
		return max( 30, min( 1100, $days ) );
	}

	/**
	 * Human readable error for a failed request.
	 *
	 * @param array<string, mixed>|WP_Error $response Response.
	 */
	private function http_error( $response ): ?string {
		if ( is_wp_error( $response ) ) {
			if ( 'bookora_no_url' === $response->get_error_code() ) {
				return __( 'The calendar address could not be decrypted. Please enter it again.', 'bookora' );
			}
			return sprintf(
				/* translators: %s: technical error message. */
				__( 'The calendar could not be loaded: %s', 'bookora' ),
				$response->get_error_message()
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code || 304 === $code ) {
			return null;
		}
		if ( 401 === $code || 403 === $code ) {
			return __( 'Access denied. Please use the private (secret) iCal address of the calendar.', 'bookora' );
		}
		if ( 404 === $code || 410 === $code ) {
			return __( 'The calendar no longer exists at this address. Was the address reset?', 'bookora' );
		}
		return sprintf(
			/* translators: %d: HTTP status code. */
			__( 'The calendar server answered with error %d. Bookora will try again automatically.', 'bookora' ),
			$code
		);
	}
}
