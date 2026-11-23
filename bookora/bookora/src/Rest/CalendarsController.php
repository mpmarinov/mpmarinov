<?php
/**
 * REST: calendar subscriptions (feeds) and imported calendars.
 *
 * @package Bookora
 */

namespace Bookora\Rest;

use Bookora\Calendar\ConnectionRepository;
use Bookora\Calendar\Feed;
use Bookora\Calendar\IcsImporter;
use Bookora\Notifications\Mailer;
use Bookora\Repository\ResourceRepository;
use Bookora\Security\Capabilities;
use Bookora\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /calendars
 * POST /calendars/feeds/{scope}/reset
 * POST /calendars/connections, PUT|DELETE /calendars/connections/{id}, POST /calendars/connections/{id}/sync
 */
final class CalendarsController {

	public const MAX_CONNECTIONS = 50;

	/**
	 * Constructor.
	 *
	 * @param Feed                 $feed        Feeds.
	 * @param ConnectionRepository $connections Connections.
	 * @param IcsImporter          $importer    Importer.
	 * @param ResourceRepository   $resources   Staff.
	 * @param Settings             $settings    Settings.
	 * @param Mailer               $mailer      Mailer.
	 */
	public function __construct(
		private Feed $feed,
		private ConnectionRepository $connections,
		private IcsImporter $importer,
		private ResourceRepository $resources,
		private Settings $settings,
		private Mailer $mailer,
	) {}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$ns     = SettingsController::NAMESPACE;
		$manage = static fn(): bool => current_user_can( Capabilities::MANAGE_SETTINGS );

		register_rest_route(
			$ns,
			'/calendars',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			$ns,
			'/calendars/feeds/(?P<scope>all|r[1-9]\d*)/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function ( WP_REST_Request $r ) {
					$scope = (string) $r['scope'];
					if ( 'all' !== $scope && ! $this->resources->find( (int) substr( $scope, 1 ) ) ) {
						return new WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
					}
					$this->feed->regenerate( $scope );
					return new WP_REST_Response( $this->feed->urls( $scope ) );
				},
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			$ns,
			'/calendars/feeds/(?P<scope>all|r[1-9]\d*)/send',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			$ns,
			'/calendars/connections',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			$ns,
			'/calendars/connections/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => function ( WP_REST_Request $r ) {
						return $this->connections->delete( (int) $r['id'] )
							? new WP_REST_Response( array( 'deleted' => true ) )
							: new WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
					},
					'permission_callback' => $manage,
				),
			)
		);
		register_rest_route(
			$ns,
			'/calendars/connections/(?P<id>\d+)/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => function ( WP_REST_Request $r ) {
					$id = (int) $r['id'];
					if ( ! $this->connections->find( $id ) ) {
						return new WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
					}
					return new WP_REST_Response(
						array(
							'result'     => $this->importer->sync( $id, true ),
							'connection' => $this->connections->get( $id ),
						)
					);
				},
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * POST /calendars/feeds/{scope}/send {email?} – default: staff member's or owner's address.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send( WP_REST_Request $r ) {
		$scope    = (string) $r['scope'];
		$resource = 'all' === $scope ? null : $this->resources->find( (int) substr( $scope, 1 ) );
		if ( 'all' !== $scope && ! $resource ) {
			return new WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
		}
		$raw = trim( (string) $r->get_param( 'email' ) );
		$to  = sanitize_email( $raw );
		if ( '' !== $raw && ( ! is_email( $to ) || $to !== $raw ) ) {
			return new WP_Error( 'bookora_invalid_email', __( 'Please enter a valid email address.', 'bookora' ), array( 'status' => 400 ) );
		}
		if ( '' === $to ) {
			$to = $resource ? sanitize_email( (string) $resource['email'] ) : $this->mailer->owner_address();
		}
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'bookora_invalid_email', __( 'Please enter a valid email address.', 'bookora' ), array( 'status' => 400 ) );
		}
		if ( ! $this->feed->send_link( $scope, $to, $this->mailer ) ) {
			return new WP_Error( 'bookora_mail_failed', __( 'The email could not be sent. Please check the mail configuration (e.g. an SMTP plugin).', 'bookora' ), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'sent_to' => $to ) );
	}

	/**
	 * GET /calendars
	 */
	public function index(): WP_REST_Response {
		$feeds     = array(
			array(
				'scope' => 'all',
				'label' => __( 'All appointments', 'bookora' ),
				'email' => $this->mailer->owner_address(),
				'color' => '',
				'urls'  => $this->feed->urls( 'all' ),
			),
		);
		$resources = $this->resources->all( array( 'status' => 'active' ) );
		foreach ( $resources as $r ) {
			$feeds[] = array(
				'scope' => 'r' . (int) $r['id'],
				'label' => (string) $r['name'],
				'email' => (string) ( $r['email'] ?? '' ),
				'color' => (string) ( $r['color'] ?? '' ),
				'urls'  => $this->feed->urls( 'r' . (int) $r['id'] ),
			);
		}

		$next = wp_next_scheduled( IcsImporter::CRON );
		return new WP_REST_Response(
			array(
				'feeds'       => $feeds,
				'connections' => $this->connections->all(),
				'resources'   => array_map(
					static fn( $r ) => array(
						'id'   => (int) $r['id'],
						'name' => (string) $r['name'],
					),
					$resources
				),
				'settings'    => array(
					'feed_include_pending'  => (bool) $this->settings->get( 'feed_include_pending', true ),
					'feed_customer_details' => (bool) $this->settings->get( 'feed_customer_details', true ),
				),
				'next_sync'   => $next ? gmdate( 'c', (int) $next ) : null,
				'cron_ok'     => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			)
		);
	}

	/**
	 * Validate shared fields.
	 *
	 * @param WP_REST_Request $r       Request.
	 * @param bool            $require_url Whether the address is required.
	 * @return array<string, mixed>|WP_Error
	 */
	private function input( WP_REST_Request $r, bool $require_url ) {
		$data = array();
		if ( $r->has_param( 'label' ) ) {
			$data['label'] = mb_substr( sanitize_text_field( (string) $r->get_param( 'label' ) ), 0, 100 );
		}
		if ( $r->has_param( 'resource_id' ) ) {
			$resource = (int) $r->get_param( 'resource_id' );
			if ( $resource && ! $this->resources->find( $resource ) ) {
				return new WP_Error( 'bookora_unknown_resource', __( 'Unknown staff member.', 'bookora' ), array( 'status' => 400 ) );
			}
			$data['resource_id'] = $resource;
		}
		$url = trim( (string) $r->get_param( 'url' ) );
		if ( '' !== $url ) {
			$url = IcsImporter::normalize_url( $url );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			$data['url'] = $url;
		} elseif ( $require_url ) {
			return new WP_Error( 'bookora_invalid_calendar_url', __( 'Please enter the calendar address.', 'bookora' ), array( 'status' => 400 ) );
		}
		if ( $r->has_param( 'block_all_day' ) ) {
			$data['block_all_day'] = rest_sanitize_boolean( $r->get_param( 'block_all_day' ) );
		}
		if ( $r->has_param( 'status' ) ) {
			$data['status'] = sanitize_key( (string) $r->get_param( 'status' ) );
			if ( ! in_array( $data['status'], array( 'active', 'paused' ), true ) ) {
				return new WP_Error( 'bookora_invalid_status', __( 'Invalid status.', 'bookora' ), array( 'status' => 400 ) );
			}
		}
		return $data;
	}

	/**
	 * POST /calendars/connections – create and import right away.
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $r ) {
		if ( count( $this->connections->all() ) >= self::MAX_CONNECTIONS ) {
			return new WP_Error( 'bookora_limit', __( 'Too many calendars connected.', 'bookora' ), array( 'status' => 400 ) );
		}
		$data = $this->input( $r, true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['label'] ) ) {
			$data['label'] = ConnectionRepository::host( (string) $data['url'] );
		}
		$id     = $this->connections->create( $data );
		$result = $this->importer->sync( $id, true );
		return new WP_REST_Response(
			array(
				'result'     => $result,
				'connection' => $this->connections->get( $id ),
			),
			201
		);
	}

	/**
	 * PUT /calendars/connections/{id}
	 *
	 * @param WP_REST_Request $r Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! $this->connections->find( $id ) ) {
			return new WP_Error( 'bookora_not_found', __( 'Not found.', 'bookora' ), array( 'status' => 404 ) );
		}
		$data = $this->input( $r, false );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$this->connections->update( $id, $data );
		$result = null;
		$row    = $this->connections->find( $id );
		$reload = null === ( $row['sync_token'] ?? null ); // e.g. after changing the staff member (other time zone).
		if ( $row && 'paused' !== $row['status'] && ( $reload || isset( $data['url'] ) || ( isset( $data['status'] ) && 'active' === $data['status'] ) ) ) {
			$result = $this->importer->sync( $id, true );
		}
		return new WP_REST_Response(
			array(
				'result'     => $result,
				'connection' => $this->connections->get( $id ),
			)
		);
	}
}
