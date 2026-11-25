<?php
/**
 * Plugin Name:       Kalendora Pro
 * Plugin URI:        https://mpmarinov.com/kalendora
 * Description:       Pro add-on for Kalendora: online payment and deposits (Stripe, PayPal), unlimited staff and resources, webhooks for Zapier, Make, n8n and Google Sheets, automatic updates.
 * Version:           1.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Requires Plugins:  kalendora
 * Author:            Marinov Solutions
 * Author URI:        https://mpmarinov.com
 * License:           GPL-2.0-or-later
 * Text Domain:       kalendora-pro
 *
 * @package KalendoraPro
 */

defined( 'ABSPATH' ) || exit;

define( 'KALENDORA_PRO_VERSION', '1.1.0' );
define( 'KALENDORA_PRO_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'KalendoraPro\\' ) ) {
			$file = __DIR__ . '/src/' . substr( $class_name, strlen( 'KalendoraPro\\' ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( \KalendoraPro\License::CRON );
		wp_clear_scheduled_hook( \KalendoraPro\Webhooks::RETRY );
		wp_clear_scheduled_hook( \KalendoraPro\Payments::CRON );
	}
);

add_action(
	'kalendora_loaded',
	static function (): void {
		// Pro features. They keep working when the license expires – only updates stop.
		add_filter( 'kalendora_is_pro', '__return_true' );
		add_filter( 'kalendora_resource_limit', static fn() => PHP_INT_MAX );

		$license  = new \KalendoraPro\License();
		$webhooks = new \KalendoraPro\Webhooks();
		$payments = new \KalendoraPro\Payments();
		$license->register();
		$webhooks->register();
		$payments->register();
		( new \KalendoraPro\PaymentsAdmin( $payments ) )->register();
		( new \KalendoraPro\AdminPage( $license, $webhooks ) )->register();
	}
);

add_action(
	'admin_notices',
	static function (): void {
		if ( ! did_action( 'kalendora_loaded' ) && current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Kalendora Pro needs the free Kalendora plugin. Please install and activate Kalendora.', 'kalendora-pro' ) . '</p></div>';
		}
	}
);

add_action( 'init', static fn() => load_plugin_textdomain( 'kalendora-pro', false, dirname( KALENDORA_PRO_BASENAME ) . '/languages' ) );
