<?php
/**
 * Activation, upgrades and deactivation.
 *
 * @package Bookora
 */

namespace Bookora\Install;

use Bookora\Security\Capabilities;
use Bookora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables, roles and default data; runs versioned migrations.
 */
final class Installer {

	public const DB_VERSION_OPTION = 'bookora_db_version';

	/**
	 * Activation hook. Handles network activation on multisite.
	 *
	 * @param bool $network_wide Whether the plugin is network activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}

		self::install();
	}

	/**
	 * Deactivation hook. Keeps all data; only clears scheduled events.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'bookora_daily_maintenance' );
		wp_clear_scheduled_hook( \Bookora\Notifications\Mailer::CRON_REMINDERS );
		wp_clear_scheduled_hook( \Bookora\Notifications\Mailer::CRON_REMINDERS_MORE );
		wp_clear_scheduled_hook( \Bookora\Notifications\Mailer::CRON_QUEUE );
		wp_clear_scheduled_hook( \Bookora\Calendar\IcsImporter::CRON );
	}

	/**
	 * Run the installer when the stored schema version is outdated (e.g. after a plugin update).
	 */
	public static function maybe_upgrade(): void {
		if ( version_compare( (string) get_option( self::DB_VERSION_OPTION, '0' ), Schema::VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Install or upgrade the current site.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::statements( $wpdb->prefix, $wpdb->get_charset_collate() ) as $sql ) {
			dbDelta( $sql );
		}

		Capabilities::install();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		self::seed_default_schedule();

		if ( ! wp_next_scheduled( 'bookora_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'bookora_daily_maintenance' );
		}

		update_option( self::DB_VERSION_OPTION, Schema::VERSION, false );

		/**
		 * Fires after Bookora installed or upgraded its schema.
		 */
		do_action( 'bookora_installed' );
	}

	/**
	 * Create Mon–Fri 09:00–17:00 global opening hours on first install.
	 */
	private static function seed_default_schedule(): void {
		global $wpdb;

		$table = Schema::table( $wpdb->prefix, 'schedules' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		if ( $count > 0 ) {
			return;
		}

		for ( $weekday = 1; $weekday <= 5; $weekday++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'resource_id' => null,
					'weekday'     => $weekday,
					'start_time'  => '09:00:00',
					'end_time'    => '17:00:00',
				),
				array( '%d', '%d', '%s', '%s' )
			);
		}
	}
}
