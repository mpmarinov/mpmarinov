<?php
/**
 * Plugin Name:       Kalendora – Appointments & Booking Forms
 * Plugin URI:        https://mpmarinov.com/kalendora
 * Description:       Booking forms, calendar, timeline and two-way calendar sync for WordPress. By Marinov Solutions.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Marinov Solutions
 * Author URI:        https://mpmarinov.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kalendora
 * Domain Path:       /languages
 *
 * @package Kalendora
 */

defined( 'ABSPATH' ) || exit;

define( 'KALENDORA_VERSION', '1.0.0' );
define( 'KALENDORA_FILE', __FILE__ );
define( 'KALENDORA_DIR', plugin_dir_path( __FILE__ ) );
define( 'KALENDORA_URL', plugin_dir_url( __FILE__ ) );
define( 'KALENDORA_MIN_PHP', '8.1' );
define( 'KALENDORA_MIN_WP', '6.6' );

if ( version_compare( PHP_VERSION, KALENDORA_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'Kalendora requires PHP %1$s or higher. Your server runs PHP %2$s.', 'kalendora' ),
						KALENDORA_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

require_once KALENDORA_DIR . 'src/autoload.php';

register_activation_hook( __FILE__, array( \Kalendora\Install\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Kalendora\Install\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Kalendora\Core\Plugin::class, 'boot' ) );
