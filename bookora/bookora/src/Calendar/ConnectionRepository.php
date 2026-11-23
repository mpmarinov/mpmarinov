<?php
/**
 * External calendar connections and their imported busy times.
 *
 * @package Bookora
 */

namespace Bookora\Calendar;

use Bookora\Install\Schema;
use Bookora\Security\Crypto;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal table names; values are prepared.

/**
 * Secrets (calendar addresses contain a private token) are stored encrypted and never returned in full.
 */
final class ConnectionRepository {

	public const PROVIDERS = array( 'ics' );

	/**
	 * Constructor.
	 *
	 * @param Crypto $crypto Encryption.
	 */
	public function __construct( private Crypto $crypto ) {}

	/**
	 * Table name.
	 *
	 * @param string $name Short name.
	 */
	private static function t( string $name ): string {
		global $wpdb;
		return Schema::table( $wpdb->prefix, $name );
	}

	/**
	 * All connections (safe for output).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$rows   = (array) $wpdb->get_results( 'SELECT * FROM ' . self::t( 'calendar_connections' ) . ' ORDER BY id', ARRAY_A );
		$out    = array();
		$counts = $this->counts();
		foreach ( $rows as $row ) {
			$out[] = $this->format( $row, $counts[ (int) $row['id'] ] ?? 0 );
		}
		return $out;
	}

	/**
	 * Raw row.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'calendar_connections' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Formatted connection.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->find( $id );
		return $row ? $this->format( $row, $this->counts()[ $id ] ?? 0 ) : null;
	}

	/**
	 * Decrypted calendar address.
	 *
	 * @param array<string, mixed> $row Raw row.
	 */
	public function url( array $row ): string {
		return (string) $this->crypto->decrypt( (string) $row['credentials'] );
	}

	/**
	 * Options of a connection.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array{block_all_day:bool}
	 */
	public static function options( array $row ): array {
		$opt = json_decode( (string) ( $row['options'] ?? '' ), true );
		$opt = is_array( $opt ) ? $opt : array();
		return array(
			'block_all_day' => ! array_key_exists( 'block_all_day', $opt ) || (bool) $opt['block_all_day'],
		);
	}

	/**
	 * Create a connection.
	 *
	 * @param array<string, mixed> $data resource_id, label, url, block_all_day.
	 */
	public function create( array $data ): int {
		global $wpdb;
		$wpdb->insert(
			self::t( 'calendar_connections' ),
			array(
				'resource_id' => empty( $data['resource_id'] ) ? null : (int) $data['resource_id'],
				'provider'    => 'ics',
				'label'       => (string) $data['label'],
				'account'     => self::host( (string) $data['url'] ),
				'credentials' => $this->crypto->encrypt( (string) $data['url'] ),
				'options'     => wp_json_encode( array( 'block_all_day' => (bool) ( $data['block_all_day'] ?? true ) ) ),
				'status'      => 'active',
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update label, owner, address or options.
	 *
	 * @param int                  $id   Id.
	 * @param array<string, mixed> $data Changes.
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		$row = $this->find( $id );
		if ( ! $row ) {
			return;
		}
		$set = array();
		if ( array_key_exists( 'label', $data ) ) {
			$set['label'] = (string) $data['label'];
		}
		if ( array_key_exists( 'resource_id', $data ) ) {
			$set['resource_id'] = empty( $data['resource_id'] ) ? null : (int) $data['resource_id'];
			if ( (int) $set['resource_id'] !== (int) $row['resource_id'] ) {
				// Floating and all-day times depend on the owner's time zone: reload completely.
				$set['sync_token']     = null;
				$set['last_synced_at'] = null;
			}
		}
		if ( ! empty( $data['url'] ) ) {
			$set['credentials']    = $this->crypto->encrypt( (string) $data['url'] );
			$set['account']        = self::host( (string) $data['url'] );
			$set['sync_token']     = null;
			$set['last_synced_at'] = null;
		}
		if ( array_key_exists( 'block_all_day', $data ) ) {
			$set['options']        = wp_json_encode( array( 'block_all_day' => (bool) $data['block_all_day'] ) );
			$set['sync_token']     = null; // Force a full reload with the new rule.
			$set['last_synced_at'] = null;
		}
		if ( array_key_exists( 'status', $data ) && in_array( $data['status'], array( 'active', 'paused' ), true ) ) {
			$set['status'] = $data['status'];
		}
		if ( $set ) {
			$wpdb->update( self::t( 'calendar_connections' ), $set, array( 'id' => $id ) );
		}
	}

	/**
	 * Delete a connection with its busy times.
	 *
	 * @param int $id Id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		$wpdb->delete( self::t( 'external_events' ), array( 'connection_id' => $id ) );
		return (bool) $wpdb->delete( self::t( 'calendar_connections' ), array( 'id' => $id ) );
	}

	/**
	 * Replace all imported busy times of a connection (all or nothing).
	 *
	 * @param int                                                       $id     Connection.
	 * @param list<array{uid:string, start:int, end:int, all_day:bool}> $events Busy intervals.
	 * @return bool Success.
	 */
	public function replace_events( int $id, array $events ): bool {
		global $wpdb;
		$table = self::t( 'external_events' );
		$ok    = true;

		$wpdb->query( 'START TRANSACTION' );
		$ok = false !== $wpdb->delete( $table, array( 'connection_id' => $id ) );
		foreach ( array_chunk( $events, 200 ) as $chunk ) {
			if ( ! $ok ) {
				break;
			}
			$values = array();
			$params = array();
			foreach ( $chunk as $e ) {
				$values[] = '(%d, %s, %s, %s, %d)';
				// mb_strcut keeps multibyte characters intact (invalid UTF-8 would make wpdb reject the query).
				array_push( $params, $id, mb_strcut( (string) $e['uid'], 0, 255, 'UTF-8' ), gmdate( 'Y-m-d H:i:s', $e['start'] ), gmdate( 'Y-m-d H:i:s', $e['end'] ), $e['all_day'] ? 1 : 0 );
			}
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders are built above.
			$ok = false !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (connection_id, external_id, start_utc, end_utc, all_day) VALUES " . implode( ',', $values ), $params ) );
		}
		$wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
		return $ok;
	}

	/**
	 * Per-connection lock (works on MySQL and SQLite: option names are unique).
	 *
	 * @param int $id Connection.
	 */
	public function lock( int $id ): bool {
		$key = 'bookora_sync_lock_' . $id;
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}
		// Stale lock (crashed run) after 5 minutes.
		if ( (int) get_option( $key, 0 ) < time() - 5 * MINUTE_IN_SECONDS ) {
			delete_option( $key );
			return (bool) add_option( $key, time(), '', false );
		}
		return false;
	}

	/**
	 * Release the lock.
	 *
	 * @param int $id Connection.
	 */
	public function unlock( int $id ): void {
		delete_option( 'bookora_sync_lock_' . $id );
	}

	/**
	 * Note a sync attempt (keeps the cron queue fair even if the run dies).
	 *
	 * @param int $id Connection.
	 */
	public function touch( int $id ): void {
		global $wpdb;
		$wpdb->update( self::t( 'calendar_connections' ), array( 'last_synced_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
	}

	/**
	 * Record the outcome of a sync.
	 *
	 * @param int                  $id    Connection.
	 * @param string|null          $error Error message or null on success.
	 * @param array<string, mixed> $state Sync state (etag, last_modified, count).
	 */
	public function mark( int $id, ?string $error, array $state = array() ): void {
		global $wpdb;
		$row = $this->find( $id );
		if ( ! $row ) {
			return;
		}
		$set = array(
			'last_error'     => $error,
			'last_synced_at' => current_time( 'mysql', true ),
		);
		if ( 'paused' !== $row['status'] ) {
			$set['status'] = null === $error ? 'active' : 'error';
		}
		if ( null === $error ) {
			$set['sync_token'] = wp_json_encode( $state );
		}
		$wpdb->update( self::t( 'calendar_connections' ), $set, array( 'id' => $id ) );
	}

	/**
	 * Connections whose last sync is older than the interval.
	 *
	 * @param int $interval Seconds.
	 * @return list<int>
	 */
	public function due( int $interval ): array {
		global $wpdb;
		$before = gmdate( 'Y-m-d H:i:s', time() - $interval );
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM ' . self::t( 'calendar_connections' ) . " WHERE status <> 'paused' AND provider = 'ics' AND ( last_synced_at IS NULL OR last_synced_at < %s ) ORDER BY last_synced_at IS NOT NULL, last_synced_at",
					$before
				)
			)
		);
	}

	/**
	 * Imported busy times for the timeline.
	 *
	 * @param int $from UTC timestamp.
	 * @param int $to   UTC timestamp.
	 * @return list<array{connection_id:int, resource_id:int|null, label:string, start:int, end:int, all_day:bool}>
	 */
	public function events_between( int $from, int $to ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.connection_id, e.start_utc, e.end_utc, e.all_day, c.resource_id, c.label FROM ' . self::t( 'external_events' ) . ' e INNER JOIN ' . self::t( 'calendar_connections' ) . " c ON c.id = e.connection_id
				WHERE c.status <> 'paused' AND e.start_utc < %s AND e.end_utc > %s ORDER BY e.start_utc",
				gmdate( 'Y-m-d H:i:s', $to ),
				gmdate( 'Y-m-d H:i:s', $from )
			),
			ARRAY_A
		);
		return array_map(
			static fn( $r ) => array(
				'connection_id' => (int) $r['connection_id'],
				'resource_id'   => $r['resource_id'] ? (int) $r['resource_id'] : null,
				'label'         => (string) $r['label'],
				'start'         => (int) strtotime( $r['start_utc'] . ' UTC' ),
				'end'           => (int) strtotime( $r['end_utc'] . ' UTC' ),
				'all_day'       => (bool) $r['all_day'],
			),
			$rows
		);
	}

	/**
	 * Number of imported events per connection.
	 *
	 * @return array<int, int>
	 */
	private function counts(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( 'SELECT connection_id, COUNT(*) AS n FROM ' . self::t( 'external_events' ) . ' GROUP BY connection_id', ARRAY_A ) as $r ) {
			$out[ (int) $r['connection_id'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Host of a calendar address (shown instead of the secret URL).
	 *
	 * @param string $url URL.
	 */
	public static function host( string $url ): string {
		return (string) wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Output format – never contains the secret address.
	 *
	 * @param array<string, mixed> $row    Raw row.
	 * @param int                  $events Stored busy times.
	 * @return array<string, mixed>
	 */
	private function format( array $row, int $events ): array {
		$state = json_decode( (string) $row['sync_token'], true );
		return array(
			'id'             => (int) $row['id'],
			'provider'       => $row['provider'],
			'label'          => $row['label'],
			'host'           => $row['account'],
			'resource_id'    => $row['resource_id'] ? (int) $row['resource_id'] : null,
			'status'         => $row['status'],
			'last_error'     => $row['last_error'] ? (string) $row['last_error'] : null,
			'last_synced_at' => $row['last_synced_at'] ? gmdate( 'c', (int) strtotime( $row['last_synced_at'] . ' UTC' ) ) : null,
			'events'         => $events,
			'found'          => is_array( $state ) ? (int) ( $state['count'] ?? 0 ) : 0,
			'block_all_day'  => self::options( $row )['block_all_day'],
		);
	}
}
