<?php
/**
 * Daily housekeeping (data minimisation).
 *
 * @package Bookora
 */

namespace Bookora\Core;

use Bookora\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on the daily cron event "bookora_daily_maintenance".
 */
final class Maintenance {

	public const CRON = 'bookora_daily_maintenance';

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( self::CRON, array( $this, 'run' ) );
	}

	/**
	 * Delete what is no longer needed.
	 *
	 * @return array{tokens:int, log:int} Deleted rows.
	 */
	public function run(): array {
		global $wpdb;
		$tokens = Schema::table( $wpdb->prefix, 'action_tokens' );
		$log    = Schema::table( $wpdb->prefix, 'notification_log' );

		/**
		 * Days the email send log is kept.
		 *
		 * @param int $days Days (default 365).
		 */
		$keep = max( 30, (int) apply_filters( 'bookora_log_retention_days', 365 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$deleted_tokens = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$tokens} WHERE expires_at < %s OR used_at < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$deleted_log    = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $keep * DAY_IN_SECONDS ) ) );
		// phpcs:enable

		return array(
			'tokens' => $deleted_tokens,
			'log'    => $deleted_log,
			'files'  => \Bookora\Forms\FileStore::purge_orphans(),
		);
	}
}
