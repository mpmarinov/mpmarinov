<?php
/**
 * Builds and sends all Bookora emails.
 *
 * @package Bookora
 */

namespace Bookora\Notifications;

use Bookora\Install\Schema;
use Bookora\Repository\EntryRepository;
use Bookora\Repository\FormRepository;
use Bookora\Repository\ResourceRepository;
use Bookora\Repository\ServiceRepository;
use Bookora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to booking/entry events, renders templates and logs every send.
 */
final class Mailer {

	public const CRON_REMINDERS = 'bookora_send_reminders';

	/**
	 * Attachments for the next wp_mail() call (added in phpmailer_init).
	 *
	 * @var array<int, array{content:string, name:string, type:string}>
	 */
	private array $attachments = array();

	/**
	 * Constructor.
	 *
	 * @param Templates          $templates Templates.
	 * @param EntryRepository    $entries   Entries.
	 * @param ServiceRepository  $services  Services.
	 * @param ResourceRepository $resources Resources.
	 * @param FormRepository     $forms     Forms.
	 * @param ActionLinks        $links     One-click links.
	 * @param Settings           $settings  Settings.
	 */
	public function __construct(
		private Templates $templates,
		private EntryRepository $entries,
		private ServiceRepository $services,
		private ResourceRepository $resources,
		private FormRepository $forms,
		private ActionLinks $links,
		private Settings $settings,
	) {}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'bookora_booking_created', array( $this, 'on_booking_created' ), 10, 2 );
		add_action( 'bookora_booking_status_changed', array( $this, 'on_status_changed' ), 20, 3 );
		add_action( 'bookora_entry_created', array( $this, 'on_entry_created' ), 10, 3 );
		add_action( self::CRON_REMINDERS, array( $this, 'send_reminders' ) );
		add_action( 'phpmailer_init', array( $this, 'phpmailer_init' ) );

		if ( ! wp_next_scheduled( self::CRON_REMINDERS ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_REMINDERS );
		}
	}

	/**
	 * New booking: confirmation of receipt (or confirmation) to the customer + notification to the owner.
	 *
	 * @param int $booking_id Booking id.
	 * @param int $entry_id   Entry id.
	 */
	public function on_booking_created( int $booking_id, int $entry_id ): void {
		$ctx = $this->booking_context( $booking_id );
		if ( ! $ctx ) {
			return;
		}

		$this->send( 'pending' === $ctx['booking']['status'] ? 'booking_received' : 'booking_confirmed', $ctx );

		if ( $this->form_notifies_admin( (int) ( $ctx['entry']['form_id'] ?? 0 ) ) ) {
			$this->send( 'admin_new_booking', $ctx );
		}
		unset( $entry_id );
	}

	/**
	 * Status change → customer email.
	 *
	 * @param int    $booking_id Booking id.
	 * @param string $status     New status.
	 * @param string $previous   Previous status.
	 */
	public function on_status_changed( int $booking_id, string $status, string $previous ): void {
		$map = array(
			'confirmed' => 'booking_confirmed',
			'rejected'  => 'booking_rejected',
			'cancelled' => 'booking_cancelled',
		);
		if ( ! isset( $map[ $status ] ) || ( 'confirmed' === $status && in_array( $previous, array( 'completed', 'no_show' ), true ) ) ) {
			return;
		}
		$ctx = $this->booking_context( $booking_id );
		if ( $ctx ) {
			$this->send( $map[ $status ], $ctx );
		}
	}

	/**
	 * Standard form submitted.
	 *
	 * @param int                  $entry_id Entry id.
	 * @param array<string, mixed> $values   Values.
	 * @param array<string, mixed> $form     Form.
	 */
	public function on_entry_created( int $entry_id, array $values, array $form ): void {
		$ctx = $entry_id ? $this->entry_context( $entry_id ) : null;
		if ( ! $ctx ) {
			return;
		}
		$this->send( 'entry_received', $ctx );
		if ( $this->form_notifies_admin( (int) $form['id'] ) ) {
			$this->send( 'admin_new_entry', $ctx );
		}
		unset( $values );
	}

	/**
	 * Hourly: reminders for confirmed bookings (each configured lead time once).
	 */
	public function send_reminders(): int {
		global $wpdb;
		$sent  = 0;
		$hours = array_filter( array_map( 'intval', (array) $this->settings->get( 'reminder_hours', array( 24 ) ) ) );
		$now   = time();

		foreach ( $hours as $h ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal tables.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT b.id, b.start_utc, b.created_at FROM ' . Schema::table( $wpdb->prefix, 'bookings' ) . " b WHERE b.status = 'confirmed' AND b.start_utc > %s AND b.start_utc <= %s AND NOT EXISTS ( SELECT 1 FROM " . Schema::table( $wpdb->prefix, 'notification_log' ) . " l WHERE l.booking_id = b.id AND l.event = %s AND l.status = 'sent' )", gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now + $h * HOUR_IN_SECONDS ), 'booking_reminder:' . $h ), ARRAY_A );

			// Skip bookings made shortly before the appointment: the confirmation already covers them.
			$ids = array();
			foreach ( $rows as $row ) {
				if ( strtotime( $row['created_at'] . ' UTC' ) <= strtotime( $row['start_utc'] . ' UTC' ) - $h * HOUR_IN_SECONDS ) {
					$ids[] = (int) $row['id'];
				}
			}

			foreach ( $ids as $id ) {
				$ctx = $this->booking_context( (int) $id );
				if ( $ctx && $this->send( 'booking_reminder', $ctx, 'booking_reminder:' . $h ) ) {
					++$sent;
				}
			}
		}
		return $sent;
	}

	/**
	 * Render and send one event. Returns true if sent.
	 *
	 * @param string               $event     Event.
	 * @param array<string, mixed> $ctx       Context.
	 * @param string               $log_event Event name for the log (e.g. with reminder hours).
	 * @param string               $to        Override recipient (test emails).
	 */
	public function send( string $event, array $ctx, string $log_event = '', string $to = '' ): bool {
		$tpl = $this->templates->get( $event );
		if ( ! $tpl['enabled'] && '' === $to ) {
			return false;
		}

		$admin     = 'admin' === $tpl['recipient'];
		$recipient = '' !== $to ? $to : ( $admin ? $this->admin_recipients( $ctx ) : (string) ( $ctx['vars']['customer_email'] ?? '' ) );
		if ( '' === $recipient ) {
			return false;
		}

		[ $subject, $html ] = $this->render( $event, $tpl, $ctx );

		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		$from     = sanitize_email( (string) $this->settings->get( 'email_from_address', '' ) );
		$fromname = $this->business_name();
		if ( $from ) {
			$headers[] = sprintf( 'From: %s <%s>', $this->header_text( $fromname ), $from );
		}
		$reply = $admin ? (string) ( $ctx['vars']['customer_email'] ?? '' ) : $this->owner_email();
		if ( is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		$this->attachments = array();
		if ( ! $admin && ! empty( $ctx['booking'] ) && in_array( $event, array( 'booking_confirmed', 'booking_reminder', 'booking_cancelled' ), true ) ) {
			$this->attachments[] = array(
				'content' => Ics::event( $ctx['booking'], $ctx['vars']['service'] . ' – ' . $ctx['vars']['business_name'], $ctx['vars']['business_address'], '', 'booking_cancelled' === $event ? 'CANCELLED' : 'CONFIRMED' ),
				'name'    => 'termin.ics',
				'type'    => 'text/calendar; charset=utf-8; method=PUBLISH',
			);
		}

		$ok                = (bool) wp_mail( $recipient, $subject, $html, $headers );
		$this->attachments = array();

		$this->log( $ctx, '' !== $log_event ? $log_event : $event, $recipient, $ok );
		return $ok;
	}

	/**
	 * Render subject and full HTML for an event.
	 *
	 * @param string               $event Event.
	 * @param array<string, mixed> $tpl   Template.
	 * @param array<string, mixed> $ctx   Context.
	 * @return array{0:string, 1:string}
	 */
	public function render( string $event, array $tpl, array $ctx ): array {
		$vars    = $ctx['vars'];
		$fields  = (array) ( $ctx['fields'] ?? array() );
		$replace = static function ( string $text, bool $html ) use ( $vars, $fields ): string {
			$text = (string) preg_replace_callback(
				'/\{field:([a-z0-9_]+)\}/',
				static fn( $m ) => $html ? esc_html( (string) ( $fields[ $m[1] ] ?? '' ) ) : (string) ( $fields[ $m[1] ] ?? '' ),
				$text
			);
			foreach ( $vars as $key => $value ) {
				$text = str_replace( '{' . $key . '}', $html ? esc_html( (string) $value ) : (string) $value, $text );
			}
			return $text;
		};

		$subject = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $replace( (string) $tpl['subject'], false ) ) ) );

		$blocks = array(
			'{all_fields}'      => $this->fields_table( (array) ( $ctx['labeled'] ?? array() ) ),
			'{action_buttons}'  => 'admin_new_booking' === $event ? $this->action_buttons( $ctx ) : '',
			'{admin_link}'      => $this->admin_link( $ctx ),
			'{calendar_button}' => $this->calendar_button( $ctx ),
		);

		$body = wp_kses_post( (string) $tpl['body'] );
		foreach ( array_keys( $blocks ) as $i => $token ) {
			$body = str_replace( $token, '%%BOOKORA_BLOCK_' . $i . '%%', $body );
		}
		$body = $replace( $body, true );
		// Remove lines that only contained an empty placeholder (e.g. no staff member).
		$body = (string) preg_replace( "/\n[ \t]*\n[ \t]*\n+/", "\n\n", $body );
		$body = make_clickable( wpautop( $body ) );
		foreach ( array_values( $blocks ) as $i => $block ) {
			$body = str_replace( array( '<p>%%BOOKORA_BLOCK_' . $i . '%%</p>', '%%BOOKORA_BLOCK_' . $i . '%%' ), $block, $body );
		}

		return array( $subject, $this->layout( $subject, $body ) );
	}

	/**
	 * Context for a booking (vars + values).
	 *
	 * @param int $booking_id Booking id.
	 * @return array<string, mixed>|null
	 */
	public function booking_context( int $booking_id ): ?array {
		$booking = $this->entries->booking( $booking_id );
		if ( ! $booking ) {
			return null;
		}
		$entry   = $booking['entry_id'] ? $this->entries->entry( (int) $booking['entry_id'] ) : null;
		$service = $booking['service_id'] ? $this->services->find( (int) $booking['service_id'] ) : null;
		$ts      = (int) strtotime( (string) $booking['start'] );
		$end     = (int) strtotime( (string) $booking['end'] );
		$tf      = '12h' === $this->settings->get( 'time_format', '24h' ) ? 'g:i A' : 'H:i';

		$vars                         = $this->base_vars() + array(
			'customer_name'  => '' !== $booking['name'] ? $booking['name'] : __( 'customer', 'bookora' ),
			'customer_email' => $booking['email'],
			'customer_phone' => '' !== $booking['phone'] ? $booking['phone'] : '–',
			'service'        => $booking['service_name'],
			'date'           => wp_date( 'l, ' . get_option( 'date_format' ), $ts ),
			'time'           => wp_date( $tf, $ts ),
			'end_time'       => wp_date( $tf, $end ),
			/* translators: %d: minutes. */
			'duration'       => sprintf( __( '%d min', 'bookora' ), (int) round( ( $end - $ts ) / 60 ) ),
			'price'          => $service && (float) $service['price'] > 0 ? number_format_i18n( (float) $service['price'], 2 ) . ' ' . apply_filters( 'bookora_currency_symbol', '€' ) : '',
			'staff'          => $booking['resource_name'],
			'form_title'     => (string) ( $entry['form_title'] ?? '' ),
		);
		$vars['google_calendar_link'] = Ics::google_link( $booking, $vars['service'] . ' – ' . $vars['business_name'], $vars['business_address'] );
		$vars['calendar_link']        = '' !== $booking['uid'] ? CalendarLink::url( $booking['uid'] ) : '';

		return array(
			'booking' => $booking,
			'entry'   => $entry,
			'vars'    => $vars,
			'fields'  => array_column( (array) ( $entry['values'] ?? array() ), 'value', 'key' ),
			'labeled' => (array) ( $entry['values'] ?? array() ),
		);
	}

	/**
	 * Context for a standard entry.
	 *
	 * @param int $entry_id Entry id.
	 * @return array<string, mixed>|null
	 */
	public function entry_context( int $entry_id ): ?array {
		$entry = $this->entries->entry( $entry_id );
		if ( ! $entry ) {
			return null;
		}
		return array(
			'booking' => null,
			'entry'   => $entry,
			'vars'    => $this->base_vars() + array(
				'customer_name'  => '' !== $entry['name'] ? $entry['name'] : __( 'customer', 'bookora' ),
				'customer_email' => $entry['email'],
				'customer_phone' => '' !== $entry['phone'] ? $entry['phone'] : '–',
				'form_title'     => $entry['form_title'],
				'service'        => '',
				'date'           => '',
				'time'           => '',
				'end_time'       => '',
				'duration'       => '',
				'price'          => '',
				'staff'          => '',
			),
			'fields'  => array_column( $entry['values'], 'value', 'key' ),
			'labeled' => $entry['values'],
		);
	}

	/**
	 * Sample context for previews and test emails.
	 *
	 * @return array<string, mixed>
	 */
	public function sample_context(): array {
		$start                        = strtotime( 'next monday 10:00' );
		$booking                      = array(
			'id'            => 0,
			'entry_id'      => 0,
			'status'        => 'pending',
			'start'         => gmdate( 'c', $start ),
			'end'           => gmdate( 'c', $start + 45 * MINUTE_IN_SECONDS ),
			'name'          => 'Anna Muster',
			'email'         => 'anna@example.com',
			'phone'         => '0170 1234567',
			'service_name'  => __( 'Sample service', 'bookora' ),
			'resource_name' => '',
		);
		$labeled                      = array(
			array(
				'key'   => 'message',
				'label' => __( 'Message', 'bookora' ),
				'value' => __( 'This is a sample message.', 'bookora' ),
			),
		);
		$vars                         = $this->base_vars() + array(
			'customer_name'  => 'Anna Muster',
			'customer_email' => 'anna@example.com',
			'customer_phone' => '0170 1234567',
			'service'        => $booking['service_name'],
			'date'           => wp_date( 'l, ' . get_option( 'date_format' ), $start ),
			'time'           => wp_date( 'H:i', $start ),
			'end_time'       => wp_date( 'H:i', $start + 45 * MINUTE_IN_SECONDS ),
			/* translators: %d: minutes. */
			'duration'       => sprintf( __( '%d min', 'bookora' ), 45 ),
			'price'          => number_format_i18n( 35, 2 ) . ' €',
			'staff'          => '',
			'form_title'     => __( 'Contact', 'bookora' ),
		);
		$vars['google_calendar_link'] = Ics::google_link( $booking, $vars['service'], $vars['business_address'] );
		$vars['calendar_link']        = home_url( '/' );

		return array(
			'booking' => $booking,
			'entry'   => null,
			'vars'    => $vars,
			'fields'  => array( 'message' => $labeled[0]['value'] ),
			'labeled' => $labeled,
			'sample'  => true,
		);
	}

	/**
	 * Add pending string attachments (ICS) to PHPMailer.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
	 */
	public function phpmailer_init( $mailer ): void {
		foreach ( $this->attachments as $a ) {
			$mailer->addStringAttachment( $a['content'], $a['name'], 'base64', $a['type'] );
		}
		if ( 'text/html' === $mailer->ContentType && '' === $mailer->AltBody ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$mailer->AltBody = self::plain_text( (string) $mailer->Body ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * Plain-text version of an HTML email (without head, decoded entities, tidy blank lines).
	 *
	 * @param string $html HTML.
	 */
	public static function plain_text( string $html ): string {
		$html = (string) preg_replace( '#<head\b.*?</head>#is', '', $html );
		$html = (string) preg_replace( '#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html );
		$text = wp_strip_all_tags( str_replace( array( '<br>', '<br />', '</p>', '</tr>', '</td>' ), array( "\n", "\n", "\n\n", "\n", ' ' ), $html ) );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t]+\n/", "\n", $text );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/**
	 * Variables available in every email.
	 *
	 * @return array<string, string>
	 */
	private function base_vars(): array {
		return array(
			'business_name'    => $this->business_name(),
			'business_address' => (string) $this->settings->get( 'business_address', '' ),
			'site_url'         => home_url( '/' ),
		);
	}

	/**
	 * HTML wrapper (table layout for email clients).
	 *
	 * @param string $title Title.
	 * @param string $body  Body HTML.
	 */
	private function layout( string $title, string $body ): string {
		$color = sanitize_hex_color( (string) $this->settings->get( 'primary_color', '#2563eb' ) );
		$color = $color ? $color : '#2563eb';
		$name  = esc_html( $this->business_name() );

		/**
		 * Filter the complete email HTML.
		 *
		 * @param string $html  HTML.
		 * @param string $title Subject.
		 * @param string $body  Body.
		 */
		return (string) apply_filters(
			'bookora_email_html',
			'<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html( $title ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f3f4f6;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:16px;line-height:1.55;color:#1f2937;">'
			. '<tr><td style="background:' . $color . ';color:#ffffff;padding:18px 28px;font-size:18px;font-weight:bold;">' . $name . '</td></tr>'
			. '<tr><td style="padding:28px;">' . $body . '</td></tr>'
			. '<tr><td style="padding:16px 28px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;">' . $name . ' · <a href="' . esc_url( home_url( '/' ) ) . '" style="color:#6b7280;">' . esc_html( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</a></td></tr>'
			. '</table></td></tr></table></body></html>',
			$title,
			$body
		);
	}

	/**
	 * Answers as a two-column table.
	 *
	 * @param array<int, array{label:string, value:string}> $values Values.
	 */
	private function fields_table( array $values ): string {
		$values = array_filter( $values, static fn( $v ) => '' !== trim( (string) $v['value'] ) );
		if ( ! $values ) {
			return '';
		}
		$rows = '';
		foreach ( $values as $v ) {
			$rows .= '<tr><td style="padding:8px 12px 8px 0;color:#6b7280;vertical-align:top;white-space:nowrap;border-bottom:1px solid #f3f4f6;">' . esc_html( $v['label'] ) . '</td><td style="padding:8px 0;border-bottom:1px solid #f3f4f6;">' . nl2br( esc_html( (string) $v['value'] ) ) . '</td></tr>';
		}
		return '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0 16px;">' . $rows . '</table>';
	}

	/**
	 * Confirm/reject buttons (fresh one-click tokens).
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private function action_buttons( array $ctx ): string {
		$booking = (array) ( $ctx['booking'] ?? array() );
		if ( empty( $booking ) || 'pending' !== ( $booking['status'] ?? '' ) ) {
			return '';
		}
		$confirm = ! empty( $ctx['sample'] ) ? '#' : $this->links->create( (int) $booking['id'], 'confirmed' );
		$reject  = ! empty( $ctx['sample'] ) ? '#' : $this->links->create( (int) $booking['id'], 'rejected' );
		$button  = static fn( string $url, string $label, string $bg ): string => '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 24px;margin:4px 8px 4px 0;background:' . $bg . ';color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;">' . esc_html( $label ) . '</a>';

		return '<p style="margin:20px 0 4px;">' . $button( $confirm, __( 'Confirm', 'bookora' ), '#15803d' ) . $button( $reject, __( 'Reject', 'bookora' ), '#b91c1c' ) . '</p>'
			. '<p style="font-size:13px;color:#6b7280;margin:4px 0 0;">' . esc_html__( 'You will see the booking details once more before anything is changed.', 'bookora' ) . '</p>';
	}

	/**
	 * "Add to calendar" button (own .ics download) plus a small Google Calendar link.
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private function calendar_button( array $ctx ): string {
		$url = (string) ( $ctx['vars']['calendar_link'] ?? '' );
		if ( '' === $url ) {
			return '';
		}
		$color = sanitize_hex_color( (string) $this->settings->get( 'primary_color', '#2563eb' ) );
		$color = $color ? $color : '#2563eb';

		return '<p style="margin:20px 0 6px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 22px;background:' . $color . ';color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;">&#128197; ' . esc_html__( 'Add to calendar', 'bookora' ) . '</a></p>'
			. '<p style="margin:0 0 16px;font-size:13px;color:#6b7280;">' . esc_html__( 'Works with iPhone, Android and Outlook.', 'bookora' ) . ' <a href="' . esc_url( (string) $ctx['vars']['google_calendar_link'] ) . '" style="color:#6b7280;">' . esc_html__( 'Google Calendar', 'bookora' ) . '</a></p>';
	}

	/**
	 * Link to the admin screen.
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private function admin_link( array $ctx ): string {
		$page = ! empty( $ctx['booking'] ) ? 'bookora-bookings' : 'bookora-entries';
		return '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '" style="color:#2563eb;">' . esc_html__( 'Open in WordPress', 'bookora' ) . '</a></p>';
	}

	/**
	 * Owner + staff member addresses.
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private function admin_recipients( array $ctx ): string {
		$list = array( $this->owner_email() );
		if ( ! empty( $ctx['booking']['id'] ) ) {
			$row = $this->entries->booking( (int) $ctx['booking']['id'] );
			if ( $row && '' !== $row['resource_name'] ) {
				foreach ( $this->resources->all() as $resource ) {
					if ( $resource['name'] === $row['resource_name'] && is_email( $resource['email'] ) ) {
						$list[] = $resource['email'];
					}
				}
			}
		}
		return implode( ',', array_unique( array_filter( $list, 'is_email' ) ) );
	}

	/**
	 * Owner email (setting or site admin email).
	 */
	private function owner_email(): string {
		$email = sanitize_email( (string) $this->settings->get( 'admin_email', '' ) );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Business name (setting or site title).
	 */
	private function business_name(): string {
		$name = trim( (string) $this->settings->get( 'business_name', '' ) );
		return '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Header-safe text.
	 *
	 * @param string $text Text.
	 */
	private function header_text( string $text ): string {
		return str_replace( array( "\r", "\n", '<', '>', '"' ), '', $text );
	}

	/**
	 * Whether the form wants owner notifications (default yes).
	 *
	 * @param int $form_id Form id.
	 */
	private function form_notifies_admin( int $form_id ): bool {
		if ( ! $form_id ) {
			return true;
		}
		$form = $this->forms->find( $form_id );
		return ! $form || false !== ( $form['settings']['notifyAdmin'] ?? true );
	}

	/**
	 * Write to the notification log.
	 *
	 * @param array<string, mixed> $ctx       Context.
	 * @param string               $event     Event.
	 * @param string               $recipient Recipient.
	 * @param bool                 $ok        Success.
	 */
	private function log( array $ctx, string $event, string $recipient, bool $ok ): void {
		global $wpdb;
		if ( ! empty( $ctx['sample'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::table( $wpdb->prefix, 'notification_log' ),
			array(
				'entry_id'   => $ctx['entry']['id'] ?? null,
				'booking_id' => $ctx['booking']['id'] ?? null,
				'channel'    => 'email',
				'event'      => mb_substr( $event, 0, 40 ),
				'recipient'  => mb_substr( $recipient, 0, 191 ),
				'status'     => $ok ? 'sent' : 'failed',
				'error'      => $ok ? null : __( 'wp_mail() returned false. Please check the mail configuration (e.g. an SMTP plugin).', 'bookora' ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}
}
