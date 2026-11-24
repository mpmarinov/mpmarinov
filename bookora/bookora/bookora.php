<?php
/**
 * Plugin Name:       Bookora – Appointments & Booking Forms
 * Plugin URI:        https://mpmarinov.com/bookora
 * Description:       Booking forms, calendar, timeline and two-way calendar sync for WordPress. By Marinov Solutions.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Marinov Solutions
 * Author URI:        https://mpmarinov.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bookora
 * Domain Path:       /languages
 *
 * @package Bookora
 */

defined( 'ABSPATH' ) || exit;

define( 'BOOKORA_VERSION', '1.0.0' );
define( 'BOOKORA_FILE', __FILE__ );
define( 'BOOKORA_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOOKORA_URL', plugin_dir_url( __FILE__ ) );
define( 'BOOKORA_MIN_PHP', '8.1' );
define( 'BOOKORA_MIN_WP', '6.6' );

if ( version_compare( PHP_VERSION, BOOKORA_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'Bookora requires PHP %1$s or higher. Your server runs PHP %2$s.', 'bookora' ),
						BOOKORA_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

require_once BOOKORA_DIR . 'src/autoload.php';

register_activation_hook( __FILE__, array( \Bookora\Install\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Bookora\Install\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Bookora\Core\Plugin::class, 'boot' ) );
