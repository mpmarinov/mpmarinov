<?php
/**
 * Plugin Name:       Marinov Lizenzserver
 * Description:       License keys and updates for Kalendora Pro: keys after WooCommerce orders, activations per website, signed update downloads.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Marinov Solutions
 * Author URI:        https://mpmarinov.com
 * License:           GPL-2.0-or-later
 * Text Domain:       marinov-license-server
 *
 * @package MarinovLicenseServer
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'MarinovLicenseServer\\' ) ) {
			$file = __DIR__ . '/src/' . substr( $class_name, strlen( 'MarinovLicenseServer\\' ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		\MarinovLicenseServer\Licenses::install();
		\MarinovLicenseServer\Releases::dir();
		add_rewrite_endpoint( \MarinovLicenseServer\WooCommerce::ENDPOINT, EP_ROOT | EP_PAGES );
		flush_rewrite_rules();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( \MarinovLicenseServer\Licenses::DB_VERSION !== get_option( 'mls_db_version' ) ) {
			\MarinovLicenseServer\Licenses::install();
		}
		$licenses = new \MarinovLicenseServer\Licenses();
		( new \MarinovLicenseServer\Api( $licenses ) )->register();
		( new \MarinovLicenseServer\WooCommerce( $licenses ) )->register();
		( new \MarinovLicenseServer\Admin( $licenses ) )->register();
		load_plugin_textdomain( 'marinov-license-server', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);
