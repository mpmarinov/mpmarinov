<?php
/**
 * Main plugin bootstrap.
 *
 * @package Kalendora
 */

namespace Kalendora\Core;

use Kalendora\Admin\AdminMenu;
use Kalendora\Admin\Export;
use Kalendora\Availability\AvailabilityService;
use Kalendora\Calendar\ConnectionRepository;
use Kalendora\Calendar\Feed;
use Kalendora\Calendar\IcsImporter;
use Kalendora\Booking\BookingService;
use Kalendora\Forms\FileStore;
use Kalendora\Forms\Submission;
use Kalendora\Frontend\Renderer;
use Kalendora\Install\Installer;
use Kalendora\Booking\ManagePage;
use Kalendora\Notifications\ActionLinks;
use Kalendora\Notifications\CalendarLink;
use Kalendora\Notifications\Mailer;
use Kalendora\Notifications\Templates;
use Kalendora\Privacy\Privacy;
use Kalendora\Repository\BookingRepository;
use Kalendora\Repository\EntryRepository;
use Kalendora\Repository\FormRepository;
use Kalendora\Repository\ResourceRepository;
use Kalendora\Repository\ScheduleRepository;
use Kalendora\Repository\ServiceRepository;
use Kalendora\Rest\AvailabilityController;
use Kalendora\Rest\CalendarsController;
use Kalendora\Rest\TimelineController;
use Kalendora\Rest\EmailsController;
use Kalendora\Rest\EntriesController;
use Kalendora\Rest\FormsController;
use Kalendora\Rest\PublicFormController;
use Kalendora\Rest\ResourcesController;
use Kalendora\Rest\SchedulesController;
use Kalendora\Rest\ServicesController;
use Kalendora\Rest\SettingsController;
use Kalendora\Security\Crypto;
use Kalendora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Wires all services together. Extension point for the Pro add-on: `kalendora_loaded`.
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
		$c->set( AdminMenu::class, static fn( Container $c ) => new AdminMenu( $c->get( Settings::class ), $c->get( EntryRepository::class ) ) );
		$c->set( SettingsController::class, static fn( Container $c ) => new SettingsController( $c->get( Settings::class ) ) );
		$c->set( Privacy::class, static fn() => new Privacy() );

		$c->set( ServiceRepository::class, static fn() => new ServiceRepository() );
		$c->set( ResourceRepository::class, static fn() => new ResourceRepository() );
		$c->set( ScheduleRepository::class, static fn() => new ScheduleRepository() );
		$c->set( BookingRepository::class, static fn() => new BookingRepository() );
		$c->set( FormRepository::class, static fn() => new FormRepository() );
		$c->set( EntryRepository::class, static fn() => new EntryRepository() );
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
				$c->get( ServiceRepository::class ),
				$c->get( Settings::class ),
				$c->get( FileStore::class )
			)
		);
		$c->set( FileStore::class, static fn() => new FileStore() );
		$c->set( PublicFormController::class, static fn( Container $c ) => new PublicFormController( $c->get( FormRepository::class ), $c->get( Submission::class ), $c->get( FileStore::class ) ) );
		$c->set(
			Renderer::class,
			static fn( Container $c ) => new Renderer(
				$c->get( FormRepository::class ),
				$c->get( ServiceRepository::class ),
				$c->get( ResourceRepository::class ),
				$c->get( Settings::class )
			)
		);
		$c->set( Templates::class, static fn() => new Templates() );
		$c->set( CalendarLink::class, static fn( Container $c ) => new CalendarLink( $c->get( EntryRepository::class ), $c->get( Settings::class ) ) );
		$c->set( ActionLinks::class, static fn( Container $c ) => new ActionLinks( $c->get( EntryRepository::class ), $c->get( Settings::class ) ) );
		$c->set( ManagePage::class, static fn( Container $c ) => new ManagePage( $c->get( EntryRepository::class ), $c->get( ServiceRepository::class ), $c->get( BookingService::class ), $c->get( Settings::class ) ) );
		$c->set(
			Mailer::class,
			static fn( Container $c ) => new Mailer(
				$c->get( Templates::class ),
				$c->get( EntryRepository::class ),
				$c->get( ServiceRepository::class ),
				$c->get( ResourceRepository::class ),
				$c->get( FormRepository::class ),
				$c->get( ActionLinks::class ),
				$c->get( Settings::class )
			)
		);
		$c->set(
			TimelineController::class,
			static fn( Container $c ) => new TimelineController(
				$c->get( EntryRepository::class ),
				$c->get( ResourceRepository::class ),
				$c->get( AvailabilityService::class ),
				$c->get( BookingService::class ),
				$c->get( Settings::class ),
				$c->get( ConnectionRepository::class )
			)
		);
		$c->set( Crypto::class, static fn() => new Crypto() );
		$c->set( \Kalendora\Integrations\Integrations::class, static fn( Container $c ) => new \Kalendora\Integrations\Integrations( $c->get( Crypto::class ), $c->get( EntryRepository::class ) ) );
		$c->set( ConnectionRepository::class, static fn( Container $c ) => new ConnectionRepository( $c->get( Crypto::class ) ) );
		$c->set( IcsImporter::class, static fn( Container $c ) => new IcsImporter( $c->get( ConnectionRepository::class ), $c->get( AvailabilityService::class ), $c->get( Settings::class ) ) );
		$c->set( Feed::class, static fn( Container $c ) => new Feed( $c->get( EntryRepository::class ), $c->get( ResourceRepository::class ), $c->get( Settings::class ) ) );
		$c->set(
			CalendarsController::class,
			static fn( Container $c ) => new CalendarsController(
				$c->get( Feed::class ),
				$c->get( ConnectionRepository::class ),
				$c->get( IcsImporter::class ),
				$c->get( ResourceRepository::class ),
				$c->get( Settings::class ),
				$c->get( Mailer::class )
			)
		);
		$c->set( EmailsController::class, static fn( Container $c ) => new EmailsController( $c->get( Templates::class ), $c->get( Mailer::class ) ) );
		$c->set( Export::class, static fn( Container $c ) => new Export( $c->get( EntryRepository::class ), $c->get( FormRepository::class ) ) );
		$c->set( EntriesController::class, static fn( Container $c ) => new EntriesController( $c->get( EntryRepository::class ), $c->get( BookingRepository::class ), $c->get( ServiceRepository::class ) ) );
		$c->set( FormsController::class, static fn( Container $c ) => new FormsController( $c->get( FormRepository::class ) ) );
		$c->set( AvailabilityController::class, static fn( Container $c ) => new AvailabilityController( $c->get( AvailabilityService::class ), $c->get( ServiceRepository::class ) ) );

		Installer::maybe_upgrade();

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$c->get( Settings::class )->register();
		$c->get( Privacy::class )->register();
		$c->get( Renderer::class )->register();
		$c->get( ActionLinks::class )->register();
		$c->get( CalendarLink::class )->register();
		$c->get( ManagePage::class )->register();
		$c->get( FileStore::class )->register();
		( new Maintenance() )->register();
		$c->get( Feed::class )->register();
		$c->get( IcsImporter::class )->register();
		$c->get( Mailer::class )->register();
		$c->get( \Kalendora\Integrations\Integrations::class )->register();

		add_action(
			'rest_api_init',
			static function () use ( $c ) {
				foreach ( array( SettingsController::class, ServicesController::class, ResourcesController::class, SchedulesController::class, AvailabilityController::class, FormsController::class, PublicFormController::class, EntriesController::class, EmailsController::class, TimelineController::class, CalendarsController::class ) as $controller ) {
					$c->get( $controller )->register_routes();
				}
			}
		);

		if ( is_admin() ) {
			$c->get( AdminMenu::class )->register();
			$c->get( Export::class )->register();
		}

		/**
		 * Fires after Kalendora has registered its core services.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'kalendora_loaded', $this );
	}

	/**
	 * Load bundled translations (de_DE, bg_BG). WordPress.org language packs take precedence.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'kalendora', false, dirname( plugin_basename( KALENDORA_FILE ) ) . '/languages' );
	}
}
