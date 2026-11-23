<?php
/**
 * Main plugin bootstrap.
 *
 * @package Bookora
 */

namespace Bookora\Core;

use Bookora\Admin\AdminMenu;
use Bookora\Install\Installer;
use Bookora\Privacy\Privacy;
use Bookora\Rest\SettingsController;
use Bookora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires all services together. Extension point for the Pro add-on: `bookora_loaded`.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Boot the plugin (hooked to `plugins_loaded`).
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self( new Container() );
		self::$instance->register();
	}

	/**
	 * Access the running instance.
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	private function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Service container accessor.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Register services and hooks.
	 */
	private function register(): void {
		$c = $this->container;

		$c->set( Settings::class, static fn() => new Settings() );
		$c->set( AdminMenu::class, static fn( Container $c ) => new AdminMenu( $c->get( Settings::class ) ) );
		$c->set( SettingsController::class, static fn( Container $c ) => new SettingsController( $c->get( Settings::class ) ) );
		$c->set( Privacy::class, static fn() => new Privacy() );

		Installer::maybe_upgrade();

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$c->get( Settings::class )->register();
		$c->get( Privacy::class )->register();

		add_action( 'rest_api_init', array( $c->get( SettingsController::class ), 'register_routes' ) );

		if ( is_admin() ) {
			$c->get( AdminMenu::class )->register();
		}

		/**
		 * Fires after Bookora has registered its core services.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'bookora_loaded', $this );
	}

	/**
	 * Load bundled translations (de_DE, bg_BG). WordPress.org language packs take precedence.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'bookora', false, dirname( plugin_basename( BOOKORA_FILE ) ) . '/languages' );
	}
}
