<?php
/**
 * Uninstall routine. Data is only removed when the user opted in via settings.
 *
 * @package Bookora
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/autoload.php';

/**
 * Remove Bookora data for the current site if requested.
 */
function bookora_uninstall_site(): void {
	global $wpdb;

	// Scheduled tasks are always removed – the plugin code is gone.
	wp_clear_scheduled_hook( 'bookora_daily_maintenance' );
	wp_clear_scheduled_hook( 'bookora_send_reminders' );
	wp_clear_scheduled_hook( 'bookora_sync_calendars' );

	$settings = get_option( 'bookora_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	foreach ( \Bookora\Install\Schema::TABLES as $name ) {
		$table = \Bookora\Install\Schema::table( $wpdb->prefix, $name );
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
	}

	delete_option( 'bookora_settings' );
	delete_option( 'bookora_db_version' );
	delete_option( 'bookora_feed_tokens' );
	\Bookora\Security\Capabilities::uninstall();
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $bookora_site_id ) {
		switch_to_blog( (int) $bookora_site_id );
		bookora_uninstall_site();
		restore_current_blog();
	}
} else {
	bookora_uninstall_site();
}
