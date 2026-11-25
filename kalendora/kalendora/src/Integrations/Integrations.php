<?php
/**
 * Optional connections to external services: spam protection (Turnstile, hCaptcha, reCAPTCHA),
 * notifications (Slack, Telegram) and newsletter sign-up (Mailchimp, Brevo).
 *
 * Nothing is contacted unless the site owner enters credentials. Secrets are stored encrypted
 * and never sent back to the browser. Notifications and sign-ups run after the visitor's
 * response has been sent, so a slow service never delays a booking.
 *
 * @package Kalendora
 */

namespace Kalendora\Integrations;

use Kalendora\Repository\EntryRepository;
use Kalendora\Security\Capabilities;
use Kalendora\Security\Crypto;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Integrations.
 */
final class Integrations {

	public const OPTION = 'kalendora_integrations';

	/** Fields that hold secrets (stored encrypted, never returned). */
	private const SECRETS = array( 'captcha_secret', 'slack_webhook', 'telegram_token', 'mailchimp_key', 'brevo_key' );

	public const CAPTCHA = array(
		'turnstile' => array(
			'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
			'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			'class'  => 'cf-turnstile',
			'field'  => 'cf-turnstile-response',
		),
		'hcaptcha'  => array(
			'script' => 'https://js.hcaptcha.com/1/api.js',
			'verify' => 'https://api.hcaptcha.com/siteverify',
			'class'  => 'h-captcha',
			'field'  => 'h-captcha-response',
		),
		'recaptcha' => array(
			'script' => 'https://www.google.com/recaptcha/api.js',
			'verify' => 'https://www.google.com/recaptcha/api/siteverify',
			'class'  => 'g-recaptcha',
			'field'  => 'g-recaptcha-response',
		),
	);

	/**
	 * Work to do after the response (notifications, sign-ups).
	 *
	 * @var list<callable>
	 */
	private array $deferred = array();

	/**
	 * Constructor.
	 *
	 * @param Crypto          $crypto  Encryption for secrets.
	 * @param EntryRepository $entries Entries and bookings.
	 */
	public function __construct( private Crypto $crypto, private EntryRepository $entries ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'kalendora_spam_check', array( $this, 'verify_captcha' ), 10, 3 );
		add_action( 'kalendora_form_before_submit', array( $this, 'captcha_widget' ) );
		add_action( 'kalendora_booking_created', array( $this, 'on_booking' ), 20, 1 );
		add_action( 'kalendora_entry_created', array( $this, 'on_entry' ), 20, 3 );
		add_action( 'kalendora_customer_cancelled', array( $this, 'on_cancelled' ), 20, 1 );
		add_action( 'kalendora_customer_rescheduled', array( $this, 'on_rescheduled' ), 20, 1 );
	}

	/* ------------------------------------------------------------------ settings */

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'captcha'          => '',
			'captcha_site_key' => '',
			'captcha_secret'   => '',
			'slack_webhook'    => '',
			'telegram_token'   => '',
			'telegram_chat'    => '',
			'notify_events'    => array( 'booking', 'entry', 'cancelled', 'rescheduled' ),
			'mailchimp_key'    => '',
			'mailchimp_list'   => '',
			'brevo_key'        => '',
			'brevo_list'       => 0,
			'brevo_template'   => 0,
			'brevo_redirect'   => '',
		);
	}

	/**
	 * Raw settings with decrypted secrets (server only).
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		$all    = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		foreach ( self::SECRETS as $key ) {
			$all[ $key ] = '' !== (string) $all[ $key ] ? (string) $this->crypto->decrypt( (string) $all[ $key ] ) : '';
		}
		return $all;
	}

	/**
	 * Settings for the admin screen: secrets only as "set / last 4 characters".
	 *
	 * @return array<string, mixed>
	 */
	public function public_settings(): array {
		$all = $this->all();
		foreach ( self::SECRETS as $key ) {
			$value       = (string) $all[ $key ];
			$all[ $key ] = '' === $value ? '' : '••••' . mb_substr( $value, -4 );
		}
		return $all;
	}

	/**
	 * Save. Secrets are kept when the masked value comes back unchanged.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error Public settings.
	 */
	public function save( array $input ) {
		$current = $this->all();
		$out     = array();

		$out['captcha']          = isset( self::CAPTCHA[ $input['captcha'] ?? '' ] ) ? (string) $input['captcha'] : '';
		$out['captcha_site_key'] = mb_substr( sanitize_text_field( (string) ( $input['captcha_site_key'] ?? '' ) ), 0, 200 );
		$out['telegram_chat']    = mb_substr( preg_replace( '/[^0-9@a-zA-Z_\-]/', '', (string) ( $input['telegram_chat'] ?? '' ) ), 0, 64 );
		$out['notify_events']    = array_values( array_intersect( (array) ( $input['notify_events'] ?? array() ), array( 'booking', 'entry', 'cancelled', 'rescheduled' ) ) );
		$out['mailchimp_list']   = mb_substr( preg_replace( '/[^a-zA-Z0-9]/', '', (string) ( $input['mailchimp_list'] ?? '' ) ), 0, 40 );
		$out['brevo_list']       = absint( $input['brevo_list'] ?? 0 );
		$out['brevo_template']   = absint( $input['brevo_template'] ?? 0 );
		$out['brevo_redirect']   = esc_url_raw( (string) ( $input['brevo_redirect'] ?? '' ), array( 'https', 'http' ) );

		foreach ( self::SECRETS as $key ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );
			if ( str_starts_with( $value, '••••' ) ) {
				$value = (string) $current[ $key ];
			}
			$value       = mb_substr( sanitize_text_field( $value ), 0, 300 );
			$out[ $key ] = $value;
		}

		if ( '' !== $out['slack_webhook'] && ! preg_match( '#^https://hooks\.slack\.com/(services|workflows|triggers)/[A-Za-z0-9/_\-]+$#', $out['slack_webhook'] ) ) {
			return new WP_Error( 'kalendora_slack_url', __( 'Please enter a Slack webhook address (starts with https://hooks.slack.com/).', 'kalendora' ), array( 'status' => 400 ) );
		}
		if ( '' !== $out['telegram_token'] && ! preg_match( '/^\d{5,15}:[A-Za-z0-9_\-]{30,50}$/', $out['telegram_token'] ) ) {
			return new WP_Error( 'kalendora_telegram_token', __( 'The Telegram bot token looks wrong. It has the form 123456789:ABC… and is shown by @BotFather.', 'kalendora' ), array( 'status' => 400 ) );
		}
		if ( '' !== $out['mailchimp_key'] && ! preg_match( '/^[a-f0-9]{32}-[a-z]{2,4}\d{1,3}$/', $out['mailchimp_key'] ) ) {
			return new WP_Error( 'kalendora_mailchimp_key', __( 'The Mailchimp API key looks wrong. It ends with the data center, e.g. …-us21.', 'kalendora' ), array( 'status' => 400 ) );
		}
		if ( '' !== $out['captcha'] && ( '' === $out['captcha_site_key'] || '' === $out['captcha_secret'] ) ) {
			return new WP_Error( 'kalendora_captcha_keys', __( 'Spam protection needs both the site key and the secret key.', 'kalendora' ), array( 'status' => 400 ) );
		}

		foreach ( self::SECRETS as $key ) {
			$out[ $key ] = '' === $out[ $key ] ? '' : $this->crypto->encrypt( $out[ $key ] );
		}
		update_option( self::OPTION, $out, false );
		return $this->public_settings();
	}

	/* ------------------------------------------------------------------ REST */

	/**
	 * Routes.
	 */
	public function routes(): void {
		$can = static fn(): bool => current_user_can( Capabilities::MANAGE_SETTINGS );
		register_rest_route(
			'kalendora/v1',
			'/integrations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => fn() => new WP_REST_Response( $this->public_settings() ),
					'permission_callback' => $can,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => function ( WP_REST_Request $r ) {
						$result = $this->save( (array) $r->get_json_params() );
						return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
					},
					'permission_callback' => $can,
				),
			)
		);
		register_rest_route(
			'kalendora/v1',
			'/integrations/test/(?P<channel>slack|telegram|mailchimp|brevo)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $can,
			)
		);
	}

	/**
	 * POST /integrations/test/{channel}: send a test message or check the newsletter connection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( WP_REST_Request $request ) {
		$channel = (string) $request['channel'];
		/* translators: %s: site name. */
		$text = sprintf( __( 'Test message from Kalendora on %s – the connection works.', 'kalendora' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		switch ( $channel ) {
			case 'slack':
				$result = $this->slack( $text );
				break;
			case 'telegram':
				$result = $this->telegram( $text );
				break;
			case 'mailchimp':
				$result = $this->mailchimp_check();
				break;
			default:
				$result = $this->brevo_check();
		}
		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'ok' => true ) );
	}

	/* ------------------------------------------------------------------ CAPTCHA */

	/**
	 * Widget before the submit button (only when configured; loads the provider script).
	 */
	public function captcha_widget(): void {
		$s = $this->all();
		if ( '' === $s['captcha'] || '' === $s['captcha_site_key'] ) {
			return;
		}
		$p = self::CAPTCHA[ $s['captcha'] ];
		// The provider requires its own script from its own server (see readme: External services).
		wp_enqueue_script( 'kalendora-captcha', $p['script'], array(), null, array( 'strategy' => 'async' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- provider script, must not be versioned.
		printf(
			'<div class="kalendora-captcha %1$s" data-sitekey="%2$s" data-kalendora-captcha="%3$s"></div>',
			esc_attr( $p['class'] ),
			esc_attr( $s['captcha_site_key'] ),
			esc_attr( $s['captcha'] )
		);
	}

	/**
	 * Verify the CAPTCHA answer on submit.
	 *
	 * @param true|WP_Error        $result  Previous result.
	 * @param array<string, mixed> $input   Submitted data (key "_captcha").
	 * @param array<string, mixed> $meta    Request meta (ip, user agent).
	 * @return true|WP_Error
	 */
	public function verify_captcha( $result, array $input, array $meta ) {
		unset( $meta );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$s = $this->all();
		if ( '' === $s['captcha'] || '' === $s['captcha_secret'] ) {
			return $result;
		}
		$fail  = new WP_Error( 'kalendora_captcha', __( 'Please confirm that you are not a robot.', 'kalendora' ), array( 'status' => 400 ) );
		$token = is_string( $input['_captcha'] ?? null ) ? mb_substr( $input['_captcha'], 0, 4096 ) : '';
		if ( '' === $token ) {
			return $fail;
		}
		// The visitor's IP address is deliberately not sent (data minimisation).
		$response = wp_remote_post(
			self::CAPTCHA[ $s['captcha'] ]['verify'],
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => $s['captcha_secret'],
					'response' => $token,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			/**
			 * Accept submissions when the CAPTCHA service cannot be reached (default: yes, so bookings are not lost).
			 *
			 * @param bool $accept Accept.
			 */
			return apply_filters( 'kalendora_captcha_fail_open', true ) ? $result : $fail;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ok   = is_array( $body ) && ! empty( $body['success'] );
		if ( $ok && isset( $body['score'] ) && (float) $body['score'] < 0.5 ) {
			$ok = false;
		}
		return $ok ? $result : $fail;
	}

	/* ------------------------------------------------------------------ notifications */

	/**
	 * New booking.
	 *
	 * @param int $booking_id Booking id.
	 */
	public function on_booking( int $booking_id ): void {
		$this->notify(
			'booking',
			$booking_id,
			/* translators: 1: customer, 2: service, 3: date, 4: time. */
			__( 'New booking: %1$s – %2$s on %3$s at %4$s', 'kalendora' )
		);
	}

	/**
	 * Customer cancelled online.
	 *
	 * @param int $booking_id Booking id.
	 */
	public function on_cancelled( int $booking_id ): void {
		$this->notify(
			'cancelled',
			$booking_id,
			/* translators: 1: customer, 2: service, 3: date, 4: time. */
			__( 'Cancelled by the customer: %1$s – %2$s on %3$s at %4$s', 'kalendora' )
		);
	}

	/**
	 * Customer moved the appointment online.
	 *
	 * @param int $booking_id Booking id.
	 */
	public function on_rescheduled( int $booking_id ): void {
		$this->notify(
			'rescheduled',
			$booking_id,
			/* translators: 1: customer, 2: service, 3: new date, 4: new time. */
			__( 'Moved by the customer: %1$s – %2$s now on %3$s at %4$s', 'kalendora' )
		);
	}

	/**
	 * Booking notification text → channels.
	 *
	 * @param string $event      booking|cancelled|rescheduled.
	 * @param int    $booking_id Booking id.
	 * @param string $format     sprintf format.
	 */
	private function notify( string $event, int $booking_id, string $format ): void {
		if ( ! $this->wants( $event ) ) {
			return;
		}
		$b = $this->entries->booking( $booking_id );
		if ( ! $b ) {
			return;
		}
		$ts   = (int) strtotime( (string) $b['start'] );
		$text = sprintf( $format, '' !== $b['name'] ? $b['name'] : $b['email'], $b['service_name'], wp_date( get_option( 'date_format' ), $ts ), wp_date( 'H:i', $ts ) );
		if ( '' !== (string) $b['resource_name'] ) {
			$text .= ' (' . $b['resource_name'] . ')';
		}
		$this->send_all( $text . "\n" . admin_url( 'admin.php?page=kalendora-bookings' ) );
	}

	/**
	 * New form entry (forms without booking) and newsletter sign-up.
	 *
	 * @param int                  $entry_id Entry id.
	 * @param array<string, mixed> $values   Clean values.
	 * @param array<string, mixed> $form     Form.
	 */
	public function on_entry( int $entry_id, array $values, array $form ): void {
		unset( $entry_id );
		$this->maybe_subscribe( $values, $form );
		if ( 'booking' === ( $form['type'] ?? '' ) || ! $this->wants( 'entry' ) ) {
			return;
		}
		$fields = array_column( (array) $form['fields'], null, 'key' );
		$lines  = array();
		foreach ( $values as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) || in_array( $fields[ $key ]['type'], array( 'file', 'signature', 'hidden' ), true ) ) {
				continue;
			}
			$text = is_array( $value ) ? implode( ' ', array_map( 'strval', $value ) ) : (string) $value;
			if ( '' !== $text ) {
				$lines[] = ( $fields[ $key ]['label'] ?? $key ) . ': ' . mb_substr( $text, 0, 300 );
			}
		}
		/* translators: %s: form title. */
		$head = sprintf( __( 'New entry: %s', 'kalendora' ), (string) ( $form['title'] ?? '' ) );
		$this->send_all( $head . "\n" . implode( "\n", array_slice( $lines, 0, 12 ) ) . "\n" . admin_url( 'admin.php?page=kalendora-entries' ) );
	}

	/**
	 * Is this event switched on and a channel configured?
	 *
	 * @param string $event Event.
	 */
	private function wants( string $event ): bool {
		$s = $this->all();
		return ( '' !== $s['slack_webhook'] || ( '' !== $s['telegram_token'] && '' !== $s['telegram_chat'] ) ) && in_array( $event, (array) $s['notify_events'], true );
	}

	/**
	 * Send to all configured channels after the response.
	 *
	 * @param string $text Message.
	 */
	private function send_all( string $text ): void {
		$this->defer(
			function () use ( $text ) {
				$s = $this->all();
				if ( '' !== $s['slack_webhook'] ) {
					$this->slack( $text );
				}
				if ( '' !== $s['telegram_token'] && '' !== $s['telegram_chat'] ) {
					$this->telegram( $text );
				}
			}
		);
	}

	/**
	 * Slack incoming webhook.
	 *
	 * @param string $text Text.
	 * @return true|WP_Error
	 */
	public function slack( string $text ) {
		$url = (string) $this->all()['slack_webhook'];
		if ( '' === $url ) {
			return new WP_Error( 'kalendora_slack_missing', __( 'Please save a Slack webhook address first.', 'kalendora' ), array( 'status' => 400 ) );
		}
		return $this->post( $url, array( 'text' => $text ), array(), 'Slack' );
	}

	/**
	 * Telegram bot message.
	 *
	 * @param string $text Text.
	 * @return true|WP_Error
	 */
	public function telegram( string $text ) {
		$s = $this->all();
		if ( '' === $s['telegram_token'] || '' === $s['telegram_chat'] ) {
			return new WP_Error( 'kalendora_telegram_missing', __( 'Please save the bot token and the chat ID first.', 'kalendora' ), array( 'status' => 400 ) );
		}
		return $this->post(
			'https://api.telegram.org/bot' . $s['telegram_token'] . '/sendMessage',
			array(
				'chat_id'                  => $s['telegram_chat'],
				'text'                     => mb_substr( $text, 0, 4000 ),
				'disable_web_page_preview' => true,
			),
			array(),
			'Telegram'
		);
	}

	/* ------------------------------------------------------------------ newsletter */

	/**
	 * Sign up for the newsletter if the form is connected and the visitor ticked the consent box.
	 *
	 * @param array<string, mixed> $values Clean values.
	 * @param array<string, mixed> $form   Form.
	 */
	private function maybe_subscribe( array $values, array $form ): void {
		$settings = (array) ( $form['settings'] ?? array() );
		$provider = (string) ( $settings['newsletter'] ?? '' );
		$consent  = (string) ( $settings['newsletterField'] ?? '' );
		if ( ! in_array( $provider, array( 'mailchimp', 'brevo' ), true ) || '' === $consent || empty( $values[ $consent ] ) ) {
			return;
		}
		$email = '';
		$first = '';
		$last  = '';
		foreach ( (array) $form['fields'] as $field ) {
			$value = $values[ $field['key'] ?? '' ] ?? null;
			if ( 'email' === $field['type'] && '' === $email && is_string( $value ) ) {
				$email = $value;
			} elseif ( 'name' === $field['type'] && '' === $first . $last ) {
				$first = is_array( $value ) ? (string) ( $value['first'] ?? '' ) : (string) $value;
				$last  = is_array( $value ) ? (string) ( $value['last'] ?? '' ) : '';
			}
		}
		if ( ! is_email( $email ) ) {
			return;
		}
		$this->defer(
			function () use ( $provider, $email, $first, $last ) {
				$result = 'mailchimp' === $provider ? $this->mailchimp_subscribe( $email, $first, $last ) : $this->brevo_subscribe( $email, $first, $last );
				/**
				 * After a newsletter sign-up attempt.
				 *
				 * @param true|WP_Error $result   Result.
				 * @param string        $provider mailchimp|brevo.
				 * @param string        $email    Email.
				 */
				do_action( 'kalendora_newsletter_signup', $result, $provider, $email );
			}
		);
	}

	/**
	 * Mailchimp: add as "pending" – Mailchimp sends the double opt-in email.
	 *
	 * @param string $email Email.
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return true|WP_Error
	 */
	public function mailchimp_subscribe( string $email, string $first, string $last ) {
		$s = $this->all();
		if ( '' === $s['mailchimp_key'] || '' === $s['mailchimp_list'] ) {
			return new WP_Error( 'kalendora_mailchimp_missing', __( 'Mailchimp is not configured.', 'kalendora' ) );
		}
		$result = $this->post(
			$this->mailchimp_base( $s['mailchimp_key'] ) . '/lists/' . rawurlencode( $s['mailchimp_list'] ) . '/members',
			array(
				'email_address' => $email,
				'status'        => 'pending',
				'merge_fields'  => (object) array_filter(
					array(
						'FNAME' => $first,
						'LNAME' => $last,
					)
				),
			),
			array( 'Authorization' => 'Basic ' . base64_encode( 'kalendora:' . $s['mailchimp_key'] ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth.
			'Mailchimp'
		);
		// Already on the list is fine.
		if ( is_wp_error( $result ) && str_contains( $result->get_error_message(), 'Member Exists' ) ) {
			return true;
		}
		return $result;
	}

	/**
	 * Mailchimp: check key and audience.
	 *
	 * @return true|WP_Error
	 */
	private function mailchimp_check() {
		$s = $this->all();
		if ( '' === $s['mailchimp_key'] || '' === $s['mailchimp_list'] ) {
			return new WP_Error( 'kalendora_mailchimp_missing', __( 'Please save the API key and the audience ID first.', 'kalendora' ), array( 'status' => 400 ) );
		}
		return $this->request( 'GET', $this->mailchimp_base( $s['mailchimp_key'] ) . '/lists/' . rawurlencode( $s['mailchimp_list'] ) . '?fields=id,name', null, array( 'Authorization' => 'Basic ' . base64_encode( 'kalendora:' . $s['mailchimp_key'] ) ), 'Mailchimp' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth.
	}

	/**
	 * API base URL from the data center at the end of the key (…-us21).
	 *
	 * @param string $key API key.
	 */
	private function mailchimp_base( string $key ): string {
		$dc = preg_match( '/-([a-z]{2,4}\d{1,3})$/', $key, $m ) ? $m[1] : 'us1';
		return 'https://' . $dc . '.api.mailchimp.com/3.0';
	}

	/**
	 * Brevo: double opt-in – Brevo sends the confirmation email with the chosen template.
	 *
	 * @param string $email Email.
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return true|WP_Error
	 */
	public function brevo_subscribe( string $email, string $first, string $last ) {
		$s = $this->all();
		if ( '' === $s['brevo_key'] || ! $s['brevo_list'] || ! $s['brevo_template'] ) {
			return new WP_Error( 'kalendora_brevo_missing', __( 'Brevo is not configured.', 'kalendora' ) );
		}
		return $this->post(
			'https://api.brevo.com/v3/contacts/doubleOptinConfirmation',
			array(
				'email'          => $email,
				'includeListIds' => array( (int) $s['brevo_list'] ),
				'templateId'     => (int) $s['brevo_template'],
				'redirectionUrl' => '' !== $s['brevo_redirect'] ? $s['brevo_redirect'] : home_url( '/' ),
				'attributes'     => (object) array_filter(
					array(
						'FIRSTNAME' => $first,
						'LASTNAME'  => $last,
					)
				),
			),
			array( 'api-key' => $s['brevo_key'] ),
			'Brevo'
		);
	}

	/**
	 * Brevo: check key and list.
	 *
	 * @return true|WP_Error
	 */
	private function brevo_check() {
		$s = $this->all();
		if ( '' === $s['brevo_key'] || ! $s['brevo_list'] ) {
			return new WP_Error( 'kalendora_brevo_missing', __( 'Please save the API key and the list ID first.', 'kalendora' ), array( 'status' => 400 ) );
		}
		return $this->request( 'GET', 'https://api.brevo.com/v3/contacts/lists/' . (int) $s['brevo_list'], null, array( 'api-key' => $s['brevo_key'] ), 'Brevo' );
	}

	/* ------------------------------------------------------------------ HTTP */

	/**
	 * JSON POST.
	 *
	 * @param string                $url     URL.
	 * @param array<string, mixed>  $body    Body.
	 * @param array<string, string> $headers Headers.
	 * @param string                $service Name for error messages.
	 * @return true|WP_Error
	 */
	private function post( string $url, array $body, array $headers, string $service ) {
		return $this->request( 'POST', $url, $body, $headers, $service );
	}

	/**
	 * HTTP request with readable errors. Secrets in the URL are never part of an error message.
	 *
	 * @param string                    $method  GET|POST.
	 * @param string                    $url     URL.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param array<string, string>     $headers Headers.
	 * @param string                    $service Name for error messages.
	 * @return true|WP_Error
	 */
	private function request( string $method, string $url, ?array $body, array $headers, string $service ) {
		$args = array(
			'method'  => $method,
			'timeout' => 8,
			'headers' => $headers + array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = (string) wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: service name, e.g. Slack. */
			return new WP_Error( 'kalendora_integration_http', sprintf( __( '%s could not be reached. Please try again later.', 'kalendora' ), $service ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$detail = is_array( $data ) ? (string) ( $data['title'] ?? $data['description'] ?? $data['message'] ?? '' ) : trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) );
		$detail = mb_substr( $detail, 0, 200 );
		if ( in_array( $code, array( 401, 403 ), true ) ) {
			/* translators: %s: service name. */
			$message = sprintf( __( '%s rejected the access data. Please check the key or token.', 'kalendora' ), $service );
		} elseif ( 404 === $code ) {
			/* translators: %s: service name. */
			$message = sprintf( __( '%s does not know this list, chat or address. Please check the ID.', 'kalendora' ), $service );
		} else {
			/* translators: 1: service name, 2: HTTP status code. */
			$message = sprintf( __( '%1$s returned an error (%2$d).', 'kalendora' ), $service, $code );
		}
		return new WP_Error( 'kalendora_integration_' . $code, trim( $message . ' ' . $detail ), array( 'status' => 400 ) );
	}

	/**
	 * Run after the visitor's response (WP-CLI and tests: immediately).
	 *
	 * @param callable $task Task.
	 */
	private function defer( callable $task ): void {
		if ( 'cli' === PHP_SAPI ) {
			$task();
			return;
		}
		if ( ! $this->deferred ) {
			add_action( 'shutdown', array( $this, 'run_deferred' ), 110 );
		}
		$this->deferred[] = $task;
	}

	/**
	 * Shutdown handler.
	 */
	public function run_deferred(): void {
		if ( function_exists( 'fastcgi_finish_request' ) && ! headers_sent() ) {
			fastcgi_finish_request();
		}
		$tasks          = $this->deferred;
		$this->deferred = array();
		foreach ( $tasks as $task ) {
			try {
				$task();
			} catch ( \Throwable $e ) {
				// A failing service must never break the site.
				unset( $e );
			}
		}
	}
}
