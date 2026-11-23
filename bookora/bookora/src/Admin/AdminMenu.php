<?php
/**
 * Admin menu and React app mount points.
 *
 * @package Bookora
 */

namespace Bookora\Admin;

use Bookora\Security\Capabilities;
use Bookora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Bookora admin screens. Each screen renders a mount point for the React admin app.
 */
final class AdminMenu {

	public const SLUG = 'bookora';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Registered hook suffixes.
	 *
	 * @var string[]
	 */
	private array $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BOOKORA_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Screen definitions: slug suffix => [title, capability].
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	private function screens(): array {
		return array(
			'dashboard' => array( __( 'Dashboard', 'bookora' ), Capabilities::VIEW_BOOKINGS ),
			'timeline'  => array( __( 'Timeline', 'bookora' ), Capabilities::VIEW_BOOKINGS ),
			'bookings'  => array( __( 'Bookings', 'bookora' ), Capabilities::VIEW_BOOKINGS ),
			'forms'     => array( __( 'Forms', 'bookora' ), Capabilities::MANAGE_FORMS ),
			'services'  => array( __( 'Services', 'bookora' ), Capabilities::MANAGE_SETTINGS ),
			'resources' => array( __( 'Staff & Resources', 'bookora' ), Capabilities::MANAGE_SETTINGS ),
			'emails'    => array( __( 'Emails', 'bookora' ), Capabilities::MANAGE_SETTINGS ),
			'calendars' => array( __( 'Calendar Sync', 'bookora' ), Capabilities::MANAGE_SETTINGS ),
			'settings'  => array( __( 'Settings', 'bookora' ), Capabilities::MANAGE_SETTINGS ),
		);
	}

	/**
	 * Add the top-level menu and sub pages.
	 */
	public function add_menu(): void {
		$this->hooks[] = (string) add_menu_page(
			__( 'Bookora', 'bookora' ),
			__( 'Bookora', 'bookora' ),
			Capabilities::VIEW_BOOKINGS,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-calendar-alt',
			26
		);

		foreach ( $this->screens() as $key => [ $title, $cap ] ) {
			$slug          = 'dashboard' === $key ? self::SLUG : self::SLUG . '-' . $key;
			$this->hooks[] = (string) add_submenu_page( self::SLUG, $title, $title, $cap, $slug, array( $this, 'render' ) );
		}
	}

	/**
	 * Render the React mount point.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::SLUG;
		$screen = self::SLUG === $page ? 'dashboard' : substr( $page, strlen( self::SLUG ) + 1 );

		printf(
			'<div class="wrap bookora-admin"><div id="bookora-admin-root" data-screen="%s"><p>%s</p></div></div>',
			esc_attr( $screen ),
			esc_html__( 'Loading…', 'bookora' )
		);
	}

	/**
	 * Enqueue the compiled admin bundle only on Bookora screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hooks, true ) ) {
			return;
		}

		$asset_file = BOOKORA_DIR . 'build/admin/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script( 'bookora-admin', BOOKORA_URL . 'build/admin/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'bookora-admin', 'bookora', BOOKORA_DIR . 'languages' );

		if ( is_readable( BOOKORA_DIR . 'build/admin/index.css' ) ) {
			wp_enqueue_style( 'bookora-admin', BOOKORA_URL . 'build/admin/index.css', array( 'wp-components' ), $asset['version'] );
		}

		wp_localize_script(
			'bookora-admin',
			'bookoraAdmin',
			array(
				'version'  => BOOKORA_VERSION,
				'restPath' => '/bookora/v1',
				'caps'     => array(
					'manageSettings' => current_user_can( Capabilities::MANAGE_SETTINGS ),
					'manageForms'    => current_user_can( Capabilities::MANAGE_FORMS ),
					'manageBookings' => current_user_can( Capabilities::MANAGE_BOOKINGS ),
					'unfilteredHtml' => current_user_can( 'unfiltered_html' ),
				),
				'isPro'    => (bool) apply_filters( 'bookora_is_pro', false ),
			)
		);
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ),
				esc_html__( 'Settings', 'bookora' )
			)
		);
		return $links;
	}
}
