<?php
/**
 * Uninstall routine. Data is only removed when the user opted in via settings.
 *
 * @package Kalendora
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/autoload.php';

/**
 * Remove Kalendora data for the current site if requested.
 */
function kalendora_uninstall_site(): void {
	global $wpdb;

	// Scheduled tasks are always removed – the plugin code is gone.
	wp_clear_scheduled_hook( 'kalendora_daily_maintenance' );
	wp_clear_scheduled_hook( 'kalendora_send_reminders' );
	wp_clear_scheduled_hook( 'kalendora_send_reminders_more' );
	wp_clear_scheduled_hook( 'kalendora_mail_queue' );
	delete_option( 'kalendora_mail_queue_lock' );
	wp_clear_scheduled_hook( 'kalendora_sync_calendars' );

	$settings = get_option( 'kalendora_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}

	// Private uploads (files and signatures): only Kalendora's own folder, only regular files.
	$kalendora_uploads = wp_upload_dir( null, false );
	$kalendora_dir     = trailingslashit( (string) $kalendora_uploads['basedir'] ) . 'kalendora-private';
	if ( is_dir( $kalendora_dir ) ) {
		$kalendora_items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $kalendora_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $kalendora_items as $kalendora_item ) {
			if ( $kalendora_item->isLink() ) {
				continue;
			}
			$kalendora_item->isDir() ? @rmdir( $kalendora_item->getPathname() ) : wp_delete_file( $kalendora_item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		@rmdir( $kalendora_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	foreach ( \Kalendora\Install\Schema::TABLES as $name ) {
		$table = \Kalendora\Install\Schema::table( $wpdb->prefix, $name );
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
	}

	delete_option( 'kalendora_settings' );
	delete_option( 'kalendora_db_version' );
	delete_option( 'kalendora_feed_tokens' );
	delete_option( 'kalendora_integrations' );
	// Remaining options and short-lived caches (rate limits, tokens) of the plugin.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", 'kalendora\_%', '\_transient\_kalendora\_%', '\_transient\_timeout\_kalendora\_%' ) );
	\Kalendora\Security\Capabilities::uninstall();
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $kalendora_site_id ) {
		switch_to_blog( (int) $kalendora_site_id );
		kalendora_uninstall_site();
		restore_current_blog();
	}
} else {
	kalendora_uninstall_site();
}
