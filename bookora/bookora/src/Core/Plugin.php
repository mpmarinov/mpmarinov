<?php
/**
 * Main plugin bootstrap.
 *
 * @package Bookora
 */

namespace Bookora\Core;

use Bookora\Admin\AdminMenu;
use Bookora\Availability\AvailabilityService;
use Bookora\Booking\BookingService;
use Bookora\Forms\Submission;
use Bookora\Frontend\Renderer;
use Bookora\Install\Installer;
use Bookora\Privacy\Privacy;
use Bookora\Repository\BookingRepository;
use Bookora\Repository\FormRepository;
use Bookora\Repository\ResourceRepository;
use Bookora\Repository\ScheduleRepository;
use Bookora\Repository\ServiceRepository;
use Bookora\Rest\AvailabilityController;
use Bookora\Rest\FormsController;
use Bookora\Rest\PublicFormController;
use Bookora\Rest\ResourcesController;
use Bookora\Rest\SchedulesController;
use Bookora\Rest\ServicesController;
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

		$c->set( ServiceRepository::class, static fn() => new ServiceRepository() );
		$c->set( ResourceRepository::class, static fn() => new ResourceRepository() );
		$c->set( ScheduleRepository::class, static fn() => new ScheduleRepository() );
		$c->set( BookingRepository::class, static fn() => new BookingRepository() );
		$c->set( FormRepository::class, static fn() => new FormRepository() );
		$c->set(
			AvailabilityService::class,
			static fn( Container $c ) => new AvailabilityService(
				$c->get( ServiceRepository::class ),
				$c->get( ResourceRepository::class ),
				$c->get( ScheduleRepository::class ),
				$c->get( BookingRepository::class ),
				$c->get( Settings::class )
			)
		);
		$c->set(
			BookingService::class,
			static fn( Container $c ) => new BookingService(
				$c->get( AvailabilityService::class ),
				$c->get( ServiceRepository::class ),
				$c->get( Settings::class )
			)
		);

		$c->set( ServicesController::class, static fn( Container $c ) => new ServicesController( $c->get( ServiceRepository::class ) ) );
		$c->set( ResourcesController::class, static fn( Container $c ) => new ResourcesController( $c->get( ResourceRepository::class ) ) );
		$c->set( SchedulesController::class, static fn( Container $c ) => new SchedulesController( $c->get( ScheduleRepository::class ), $c->get( ResourceRepository::class ) ) );
		$c->set(
			Submission::class,
			static fn( Container $c ) => new Submission(
				$c->get( BookingService::class ),
				$c->get( AvailabilityService::class ),
				$c->get( ServiceRepository::class )
			)
		);
		$c->set( PublicFormController::class, static fn( Container $c ) => new PublicFormController( $c->get( FormRepository::class ), $c->get( Submission::class ) ) );
		$c->set(
			Renderer::class,
			static fn( Container $c ) => new Renderer(
				$c->get( FormRepository::class ),
				$c->get( ServiceRepository::class ),
				$c->get( ResourceRepository::class ),
				$c->get( Settings::class )
			)
		);
		$c->set( FormsController::class, static fn( Container $c ) => new FormsController( $c->get( FormRepository::class ) ) );
		$c->set( AvailabilityController::class, static fn( Container $c ) => new AvailabilityController( $c->get( AvailabilityService::class ), $c->get( ServiceRepository::class ) ) );

		Installer::maybe_upgrade();

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$c->get( Settings::class )->register();
		$c->get( Privacy::class )->register();
		$c->get( Renderer::class )->register();

		add_action(
			'rest_api_init',
			static function () use ( $c ) {
				foreach ( array( SettingsController::class, ServicesController::class, ResourcesController::class, SchedulesController::class, AvailabilityController::class, FormsController::class, PublicFormController::class ) as $controller ) {
					$c->get( $controller )->register_routes();
				}
			}
		);

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
