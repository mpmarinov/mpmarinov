<?php
/**
 * Plugin Name:       Bookora Pro
 * Plugin URI:        https://mpmarinov.com/bookora
 * Description:       Pro add-on for Bookora: unlimited staff and resources, webhooks for Zapier, Make, n8n and Google Sheets, automatic updates.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Requires Plugins:  bookora
 * Author:            Marinov Solutions
 * Author URI:        https://mpmarinov.com
 * License:           GPL-2.0-or-later
 * Text Domain:       bookora-pro
 *
 * @package BookoraPro
 */

defined( 'ABSPATH' ) || exit;

define( 'BOOKORA_PRO_VERSION', '1.0.0' );
define( 'BOOKORA_PRO_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'BookoraPro\\' ) ) {
			$file = __DIR__ . '/src/' . substr( $class_name, strlen( 'BookoraPro\\' ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( \BookoraPro\License::CRON );
		wp_clear_scheduled_hook( \BookoraPro\Webhooks::RETRY );
	}
);

add_action(
	'bookora_loaded',
	static function (): void {
		// Pro features. They keep working when the license expires – only updates stop.
		add_filter( 'bookora_is_pro', '__return_true' );
		add_filter( 'bookora_resource_limit', static fn() => PHP_INT_MAX );

		$license  = new \BookoraPro\License();
		$webhooks = new \BookoraPro\Webhooks();
		$license->register();
		$webhooks->register();
		( new \BookoraPro\AdminPage( $license, $webhooks ) )->register();
	}
);

add_action(
	'admin_notices',
	static function (): void {
		if ( ! did_action( 'bookora_loaded' ) && current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Bookora Pro needs the free Bookora plugin. Please install and activate Bookora.', 'bookora-pro' ) . '</p></div>';
		}
	}
);

add_action( 'init', static fn() => load_plugin_textdomain( 'bookora-pro', false, dirname( BOOKORA_PRO_BASENAME ) . '/languages' ) );
