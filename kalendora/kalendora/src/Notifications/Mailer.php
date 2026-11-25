<?php
/**
 * Builds and sends all Kalendora emails.
 *
 * @package Kalendora
 */

namespace Kalendora\Notifications;

use Kalendora\Booking\CancellationPolicy;
use Kalendora\Booking\ManagePage;
use Kalendora\Install\Schema;
use Kalendora\Repository\EntryRepository;
use Kalendora\Repository\FormRepository;
use Kalendora\Repository\ResourceRepository;
use Kalendora\Repository\ServiceRepository;
use Kalendora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to booking/entry events, renders templates and logs every send.
 */
final class Mailer {

	public const CRON_REMINDERS = 'kalendora_send_reminders';

	/** Delivery of queued emails (safety net and retries). */
	public const CRON_QUEUE = 'kalendora_mail_queue';

	/** Minutes to wait before the 2nd, 3rd and 4th attempt. */
	public const RETRY_MINUTES = array( 5, 30, 120 );

	/** Follow-up run when one run had more reminders than its batch. */
	public const CRON_REMINDERS_MORE = 'kalendora_send_reminders_more';

	/**
	 * Attachments for the next wp_mail() call (added in phpmailer_init).
	 *
	 * @var array<int, array{content:string, name:string, type:string}>
	 */
	private array $attachments = array();

	/**
	 * Whether delivery at shutdown is already registered.
	 *
	 * @var bool
	 */
	private bool $flush_scheduled = false;

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
		add_action( 'kalendora_booking_created', array( $this, 'on_booking_created' ), 10, 2 );
		add_action( 'kalendora_booking_status_changed', array( $this, 'on_status_changed' ), 20, 3 );
		add_action( 'kalendora_entry_created', array( $this, 'on_entry_created' ), 10, 3 );
		add_action( 'kalendora_booking_moved', array( $this, 'on_booking_moved' ), 10, 2 );
		add_action( 'kalendora_customer_cancelled', array( $this, 'on_customer_cancelled' ), 10, 3 );
		add_action( 'kalendora_customer_rescheduled', array( $this, 'on_customer_rescheduled' ), 10, 2 );
		add_action( self::CRON_REMINDERS, array( $this, 'send_reminders' ) );
		add_action( self::CRON_REMINDERS_MORE, array( $this, 'send_reminders' ) );
		add_action( self::CRON_QUEUE, array( $this, 'process_queue' ) );
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
		/**
		 * Send the emails for a new booking now? Return false to send them later yourself
		 * (e.g. only after an online payment).
		 *
		 * @param bool $notify     Default true.
		 * @param int  $booking_id Booking id.
		 */
		if ( ! apply_filters( 'kalendora_notify_booking_created', true, $booking_id ) ) {
			return;
		}
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
	 * Booking moved in the timeline → inform the customer (can be suppressed per move).
	 *
	 * @param int    $booking_id     Booking id.
	 * @param string $previous_start Previous UTC start.
	 */
	public function on_booking_moved( int $booking_id, string $previous_start ): void {
		/**
		 * Whether to email the customer about a moved appointment.
		 *
		 * @param bool $send       Send.
		 * @param int  $booking_id Booking id.
		 */
		if ( ! apply_filters( 'kalendora_send_reschedule_email', true, $booking_id ) ) {
			return;
		}
		$ctx = $this->booking_context( $booking_id );
		if ( ! $ctx ) {
			return;
		}
		$prev                         = (int) strtotime( $previous_start . ' UTC' );
		$tf                           = '12h' === $this->settings->get( 'time_format', '24h' ) ? 'g:i A' : 'H:i';
		$ctx['vars']['previous_date'] = wp_date( 'l, ' . get_option( 'date_format' ), $prev );
		$ctx['vars']['previous_time'] = wp_date( $tf, $prev );
		$this->send( 'booking_rescheduled', $ctx );
	}

	/**
	 * Customer cancelled via the manage link → inform the owner.
	 *
	 * @param int    $booking_id Booking id.
	 * @param float  $fee        Fee.
	 * @param string $reason     Reason.
	 */
	public function on_customer_cancelled( int $booking_id, float $fee, string $reason ): void {
		$ctx = $this->booking_context( $booking_id );
		if ( ! $ctx ) {
			return;
		}
		$ctx['vars']['cancel_reason'] = '' !== $reason ? $reason : '–';
		$this->send( 'admin_booking_cancelled', $ctx );
		unset( $fee );
	}

	/**
	 * Customer moved the appointment via the manage link → inform the owner.
	 *
	 * @param int    $booking_id     Booking id.
	 * @param string $previous_start Previous UTC start.
	 */
	public function on_customer_rescheduled( int $booking_id, string $previous_start ): void {
		$ctx = $this->booking_context( $booking_id );
		if ( ! $ctx ) {
			return;
		}
		$prev                         = (int) strtotime( $previous_start . ' UTC' );
		$tf                           = '12h' === $this->settings->get( 'time_format', '24h' ) ? 'g:i A' : 'H:i';
		$ctx['vars']['previous_date'] = wp_date( 'l, ' . get_option( 'date_format' ), $prev );
		$ctx['vars']['previous_time'] = wp_date( $tf, $prev );
		$this->send( 'admin_booking_rescheduled', $ctx );
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
	 *
	 * Rules (e.g. 7 days + 24 hours):
	 *  - per booking only the closest due reminder is sent (after a cron outage nobody gets two at once),
	 *  - a reminder is skipped when it would come much too late, e.g. the appointment was confirmed only
	 *    3 days before – then "in 7 days" makes no sense; the 24-hour reminder still follows,
	 *  - bookings made shortly before the appointment get no reminder for that lead time (the confirmation covers it),
	 *  - services can switch reminders off.
	 */
	public function send_reminders(): int {
		global $wpdb;
		$sent  = 0;
		$hours = array_values( array_unique( array_filter( array_map( 'intval', (array) $this->settings->get( 'reminder_hours', array( 168, 24 ) ) ) ) ) );
		sort( $hours );
		if ( ! $hours ) {
			return 0;
		}
		$now = time();
		$max = max( $hours );
		$tab = Schema::table( $wpdb->prefix, 'bookings' );
		$log = Schema::table( $wpdb->prefix, 'notification_log' );
		$svc = Schema::table( $wpdb->prefix, 'services' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal tables.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT b.id, b.start_utc, b.created_at, s.settings FROM {$tab} b LEFT JOIN {$svc} s ON s.id = b.service_id WHERE b.status = 'confirmed' AND b.start_utc > %s AND b.start_utc <= %s", gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now + $max * HOUR_IN_SECONDS ) ), ARRAY_A );
		if ( ! $rows ) {
			return 0;
		}

		$ids  = implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) );
		$done = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table, integer list.
		foreach ( (array) $wpdb->get_results( "SELECT booking_id, event FROM {$log} WHERE booking_id IN ({$ids}) AND event LIKE 'booking_reminder:%' AND status IN ('sent','queued')", ARRAY_A ) as $l ) {
			$done[ (int) $l['booking_id'] ][ (int) substr( (string) $l['event'], 17 ) ] = true;
		}

		/**
		 * Maximum reminders per cron run. The rest follows a minute later,
		 * so a busy calendar never runs into the host's time limit or the mail provider's rate limit.
		 *
		 * @param int $batch Default 40.
		 */
		$batch   = max( 1, (int) apply_filters( 'kalendora_reminder_batch', 40 ) );
		$started = microtime( true );

		foreach ( $rows as $row ) {
			if ( $sent >= $batch || microtime( true ) - $started > 20 ) {
				if ( ! wp_next_scheduled( self::CRON_REMINDERS_MORE ) ) {
					wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON_REMINDERS_MORE );
				}
				break;
			}
			$id    = (int) $row['id'];
			$start = (int) strtotime( $row['start_utc'] . ' UTC' );
			$left  = $start - $now;
			$conf  = json_decode( (string) $row['settings'], true );
			if ( is_array( $conf ) && array_key_exists( 'reminders', $conf ) && ! $conf['reminders'] ) {
				continue;
			}
			// Closest due lead time (smallest window that contains the appointment).
			$due = null;
			foreach ( $hours as $h ) {
				if ( $left <= $h * HOUR_IN_SECONDS ) {
					$due = $h;
					break;
				}
			}
			if ( null === $due || ! empty( $done[ $id ][ $due ] ) ) {
				continue;
			}
			if ( ! self::reminder_in_time( $due, $left ) ) {
				continue;
			}
			if ( strtotime( $row['created_at'] . ' UTC' ) > $start - $due * HOUR_IN_SECONDS ) {
				continue;
			}
			$ctx = $this->booking_context( $id );
			if ( $ctx && $this->send( 'booking_reminder', $ctx, 'booking_reminder:' . $due ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Whether a reminder for a lead time still makes sense with this much time left.
	 * Sent at the latest at half the lead time (7 days → until 3.5 days before), but always
	 * with 90 minutes tolerance so short lead times are not lost between hourly cron runs.
	 *
	 * @param int $hours Lead time.
	 * @param int $left  Seconds until the appointment.
	 */
	public static function reminder_in_time( int $hours, int $left ): bool {
		$late = max( $hours * HOUR_IN_SECONDS / 2, 90 * MINUTE_IN_SECONDS );
		return $hours * HOUR_IN_SECONDS - $left <= $late;
	}

	/**
	 * "(08:00 – 09:00 your time, New York)" when the customer booked from another time zone, else ''.
	 *
	 * @param string $timezone Customer's time zone (IANA).
	 * @param int    $start    Start timestamp.
	 * @param int    $end      End timestamp.
	 * @param string $format   PHP time format.
	 */
	public static function local_time( string $timezone, int $start, int $end, string $format = 'H:i' ): string {
		if ( '' === $timezone || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
			return '';
		}
		$theirs = new \DateTimeZone( $timezone );
		$ours   = wp_timezone();
		$at     = new \DateTimeImmutable( '@' . $start );
		if ( $theirs->getOffset( $at ) === $ours->getOffset( $at ) ) {
			return '';
		}
		$day_differs = $at->setTimezone( $theirs )->format( 'Y-m-d' ) !== $at->setTimezone( $ours )->format( 'Y-m-d' );
		$from        = wp_date( ( $day_differs ? 'D ' : '' ) . $format, $start, $theirs );
		$to          = wp_date( $format, $end, $theirs );
		$city        = str_replace( '_', ' ', (string) substr( (string) strrchr( '/' . $timezone, '/' ), 1 ) );
		/* translators: 1: start time, 2: end time, 3: city of the customer's time zone. */
		return sprintf( __( '(%1$s – %2$s your time, %3$s)', 'kalendora' ), $from, $to, $city );
	}

	/**
	 * "in 7 days", "tomorrow", "today", "in 3 hours".
	 *
	 * @param int $start Appointment start.
	 * @param int $now   Now.
	 */
	public static function time_until( int $start, int $now ): string {
		$tz   = wp_timezone();
		$days = (int) ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->setTime( 0, 0 )->diff( ( new \DateTimeImmutable( '@' . $start ) )->setTimezone( $tz )->setTime( 0, 0 ) )->format( '%r%a' );
		if ( $days <= 0 ) {
			$h = (int) round( ( $start - $now ) / HOUR_IN_SECONDS );
			/* translators: %d: hours. */
			return $h >= 2 ? sprintf( _n( 'in %d hour', 'in %d hours', $h, 'kalendora' ), $h ) : __( 'today', 'kalendora' );
		}
		if ( 1 === $days ) {
			return __( 'tomorrow', 'kalendora' );
		}
		/* translators: %d: days. */
		return sprintf( _n( 'in %d day', 'in %d days', $days, 'kalendora' ), $days );
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
		if ( ! $admin && ! empty( $ctx['booking'] ) && in_array( $event, array( 'booking_confirmed', 'booking_reminder', 'booking_cancelled', 'booking_rescheduled' ), true ) ) {
			$this->attachments[] = array(
				'content' => Ics::event( $ctx['booking'], $ctx['vars']['service'] . ' – ' . $ctx['vars']['business_name'], $ctx['vars']['business_address'], '', 'booking_cancelled' === $event ? 'CANCELLED' : 'CONFIRMED' ),
				'name'    => 'termin.ics',
				'type'    => 'text/calendar; charset=utf-8; method=PUBLISH',
			);
		}

		$attachments       = $this->attachments;
		$this->attachments = array();

		return $this->deliver( $ctx, '' !== $log_event ? $log_event : $event, $recipient, $subject, $html, $headers, $attachments );
	}

	/**
	 * Send a one-off message (no template) in the Kalendora email design.
	 *
	 * @param string $to        Recipient.
	 * @param string $subject   Subject.
	 * @param string $body_html Body HTML (already escaped).
	 * @param string $log_event Name in the send log.
	 */
	public function send_message( string $to, string $subject, string $body_html, string $log_event ): bool {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = sanitize_email( (string) $this->settings->get( 'email_from_address', '' ) );
		if ( $from ) {
			$headers[] = sprintf( 'From: %s <%s>', $this->header_text( $this->business_name() ), $from );
		}
		return $this->deliver( array(), $log_event, $to, $subject, $this->layout( $subject, $body_html ), $headers, array() );
	}

	/**
	 * Queue an email (or send it directly for previews/tests).
	 *
	 * Queued emails are delivered after the visitor's page has been sent (shutdown),
	 * so a slow mail server never delays a booking. Failed deliveries are retried
	 * after 5, 30 and 120 minutes.
	 *
	 * @param array<string, mixed>                                        $ctx         Context.
	 * @param string                                                      $event       Log event.
	 * @param string                                                      $to          Recipient(s).
	 * @param string                                                      $subject     Subject.
	 * @param string                                                      $html        HTML body.
	 * @param string[]                                                    $headers     Headers.
	 * @param array<int, array{content:string, name:string, type:string}> $attachments String attachments.
	 */
	private function deliver( array $ctx, string $event, string $to, string $subject, string $html, array $headers, array $attachments ): bool {
		global $wpdb;

		/**
		 * Use the mail queue. Return false to send every email immediately (as before 1.8).
		 *
		 * @param bool $queue Default true.
		 */
		if ( ! empty( $ctx['sample'] ) || ! apply_filters( 'kalendora_mail_queue', true ) ) {
			[ $ok, $error ] = $this->transport( $to, $subject, $html, $headers, $attachments );
			$this->log( $ctx, $event, $to, $ok ? 'sent' : 'failed', $error );
			return $ok;
		}

		$log_id = $this->log( $ctx, $event, $to, 'queued' );
		$now    = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			Schema::table( $wpdb->prefix, 'mail_queue' ),
			array(
				'log_id'       => $log_id ? $log_id : null,
				'recipient'    => mb_substr( $to, 0, 191 ),
				'subject'      => mb_substr( $subject, 0, 255 ),
				'body'         => $html,
				'headers'      => (string) wp_json_encode( $headers ),
				'attachments'  => $attachments ? (string) wp_json_encode( $attachments ) : null,
				'attempts'     => 0,
				'next_attempt' => $now,
				'created_at'   => $now,
			)
		);

		// Command line (WP-CLI, real cron): no visitor is waiting, deliver right away.
		if ( 'cli' === PHP_SAPI ) {
			$this->process_queue();
			return true;
		}
		if ( ! $this->flush_scheduled ) {
			$this->flush_scheduled = true;
			add_action( 'shutdown', array( $this, 'flush_after_response' ), 100 );
		}
		if ( ! wp_next_scheduled( self::CRON_QUEUE ) ) {
			// Safety net if the shutdown delivery is interrupted.
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::CRON_QUEUE );
		}
		return true;
	}

	/**
	 * Shutdown: finish the response for the visitor first, then deliver.
	 */
	public function flush_after_response(): void {
		if ( function_exists( 'fastcgi_finish_request' ) && ! headers_sent() ) {
			fastcgi_finish_request();
		}
		$this->process_queue();
	}

	/**
	 * Deliver due emails from the queue.
	 *
	 * @param int $limit Max emails in this run.
	 * @return array{sent:int, retry:int, failed:int}
	 */
	public function process_queue( int $limit = 25 ): array {
		global $wpdb;
		$stats = array(
			'sent'   => 0,
			'retry'  => 0,
			'failed' => 0,
		);
		$table = Schema::table( $wpdb->prefix, 'mail_queue' );
		$lock  = 'kalendora_mail_queue_lock';
		// Atomic lock: add_option fails if another process holds it (expires after 2 minutes).
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock ) < 2 * MINUTE_IN_SECONDS ) {
				return $stats;
			}
			update_option( $lock, time(), false );
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE next_attempt <= %s ORDER BY id LIMIT %d", current_time( 'mysql', true ), $limit ), ARRAY_A );
			foreach ( $rows as $row ) {
				[ $ok, $error ] = $this->transport(
					(string) $row['recipient'],
					(string) $row['subject'],
					(string) $row['body'],
					(array) json_decode( (string) $row['headers'], true ),
					(array) json_decode( (string) $row['attachments'], true )
				);
				$attempts       = (int) $row['attempts'] + 1;
				if ( $ok || $attempts >= count( self::RETRY_MINUTES ) + 1 ) {
					$wpdb->delete( $table, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$this->update_log( (int) $row['log_id'], $ok ? 'sent' : 'failed', $error );
					++$stats[ $ok ? 'sent' : 'failed' ];
				} else {
					$wait = self::RETRY_MINUTES[ $attempts - 1 ];
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update(
						$table,
						array(
							'attempts'     => $attempts,
							'next_attempt' => gmdate( 'Y-m-d H:i:s', time() + $wait * MINUTE_IN_SECONDS ),
						),
						array( 'id' => (int) $row['id'] )
					);
					/* translators: 1: attempt number, 2: minutes, 3: error message. */
					$this->update_log( (int) $row['log_id'], 'queued', sprintf( __( 'Attempt %1$d failed, next try in %2$d minutes: %3$s', 'kalendora' ), $attempts, $wait, $error ) );
					++$stats['retry'];
				}
			}

			// More waiting? Continue later.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table.
			$next = $wpdb->get_var( "SELECT MIN(next_attempt) FROM {$table}" );
			if ( $next && ! wp_next_scheduled( self::CRON_QUEUE ) ) {
				wp_schedule_single_event( max( time() + MINUTE_IN_SECONDS, (int) strtotime( $next . ' UTC' ) ), self::CRON_QUEUE );
			}
		} finally {
			delete_option( $lock );
		}
		return $stats;
	}

	/**
	 * Send one email now.
	 *
	 * @param string                                                      $to          Recipient(s).
	 * @param string                                                      $subject     Subject.
	 * @param string                                                      $html        HTML body.
	 * @param string[]                                                    $headers     Headers.
	 * @param array<int, array{content:string, name:string, type:string}> $attachments String attachments.
	 * @return array{0:bool, 1:string} Success and error message.
	 */
	private function transport( string $to, string $subject, string $html, array $headers, array $attachments ): array {
		$error             = '';
		$catch             = static function ( $wp_error ) use ( &$error ): void {
			$error = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : '';
		};
		$this->attachments = $attachments;
		add_action( 'wp_mail_failed', $catch );
		$ok = (bool) wp_mail( $to, $subject, $html, $headers );
		remove_action( 'wp_mail_failed', $catch );
		$this->attachments = array();
		if ( ! $ok && '' === $error ) {
			$error = __( 'wp_mail() returned false. Please check the mail configuration (e.g. an SMTP plugin).', 'kalendora' );
		}
		return array( $ok, $error );
	}

	/**
	 * Update a log row after delivery.
	 *
	 * @param int    $log_id Log id.
	 * @param string $status sent|failed|queued.
	 * @param string $error  Error.
	 */
	private function update_log( int $log_id, string $status, string $error ): void {
		global $wpdb;
		if ( ! $log_id ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			Schema::table( $wpdb->prefix, 'notification_log' ),
			array(
				'status' => $status,
				'error'  => '' === $error ? null : mb_substr( $error, 0, 1000 ),
			),
			array( 'id' => $log_id )
		);
	}

	/**
	 * Owner address (settings or site admin).
	 */
	public function owner_address(): string {
		return $this->owner_email();
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
			'{manage_button}'   => $this->manage_button( $ctx ),
		);

		$body = wp_kses_post( (string) $tpl['body'] );
		foreach ( array_keys( $blocks ) as $i => $token ) {
			$body = str_replace( $token, '%%KALENDORA_BLOCK_' . $i . '%%', $body );
		}
		// Links are only created from the template itself and from trusted URLs – never from customer input
		// (otherwise "name: verify at evil.example" would become a clickable phishing link).
		$body       = make_clickable( $body );
		$link_texts = array(
			'google_calendar_link' => __( 'Add to Google Calendar', 'kalendora' ),
			'manage_link'          => __( 'Move or cancel appointment', 'kalendora' ),
		);
		foreach ( array( 'site_url', 'google_calendar_link', 'manage_link' ) as $url_key ) {
			$url = esc_url( (string) ( $vars[ $url_key ] ?? '' ) );
			if ( '' === $url ) {
				continue;
			}
			$body = str_replace( '"{' . $url_key . '}"', '"' . $url . '"', $body );
			$text = $link_texts[ $url_key ] ?? (string) $vars[ $url_key ];
			$body = str_replace( '{' . $url_key . '}', '<a href="' . $url . '">' . esc_html( $text ) . '</a>', $body );
		}
		$body = $replace( $body, true );
		// Remove lines that only contained an empty placeholder (e.g. no staff member).
		$body = (string) preg_replace( "/\n[ \t]*\n[ \t]*\n+/", "\n\n", $body );
		$body = wpautop( $body );
		foreach ( array_values( $blocks ) as $i => $block ) {
			$body = str_replace( array( '<p>%%KALENDORA_BLOCK_' . $i . '%%</p>', '%%KALENDORA_BLOCK_' . $i . '%%' ), $block, $body );
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
			'customer_name'  => '' !== $booking['name'] ? $booking['name'] : __( 'customer', 'kalendora' ),
			'customer_email' => $booking['email'],
			'customer_phone' => '' !== $booking['phone'] ? $booking['phone'] : '–',
			'service'        => $booking['service_name'],
			'date'           => wp_date( 'l, ' . get_option( 'date_format' ), $ts ),
			'time'           => wp_date( $tf, $ts ),
			'end_time'       => wp_date( $tf, $end ),
			/* translators: %d: minutes. */
			'duration'       => sprintf( __( '%d min', 'kalendora' ), (int) round( ( $end - $ts ) / 60 ) ),
			'price'          => $service && (float) $service['price'] > 0 ? number_format_i18n( (float) $service['price'], 2 ) . ' ' . apply_filters( 'kalendora_currency_symbol', '€' ) : '',
			'staff'          => $booking['resource_name'],
			'form_title'     => (string) ( $entry['form_title'] ?? '' ),
			'previous_date'  => '',
			'previous_time'  => '',
		);
		$vars['google_calendar_link'] = Ics::google_link( $booking, $vars['service'] . ' – ' . $vars['business_name'], $vars['business_address'] );
		$vars['calendar_link']        = '' !== $booking['uid'] ? CalendarLink::url( $booking['uid'] ) : '';
		$vars                        += $this->policy_vars( $booking, $service );
		$vars['time_until']           = self::time_until( $ts, time() );
		$vars['local_time']           = self::local_time( (string) ( $booking['timezone'] ?? '' ), $ts, $end, $tf );

		return array(
			'booking' => $booking,
			'entry'   => $entry,
			'vars'    => $vars,
			'fields'  => array_column( (array) ( $entry['values'] ?? array() ), 'value', 'key' ),
			'labeled' => (array) ( $entry['values'] ?? array() ),
		);
	}

	/**
	 * Placeholders for self-service and cancellation terms.
	 *
	 * @param array<string, mixed>      $booking Booking.
	 * @param array<string, mixed>|null $service Service.
	 * @return array<string, string>
	 */
	private function policy_vars( array $booking, ?array $service ): array {
		$rule   = CancellationPolicy::for_service( $service, $this->settings->all() );
		$agreed = is_array( $booking['policy'] ?? null ) ? $booking['policy'] : null;
		$text   = $agreed ? (string) ( $agreed['text'] ?? '' ) : CancellationPolicy::text( array( 'fee' => false ) + $rule, 0.0 );
		$hours  = $agreed ? (int) ( $agreed['hours'] ?? 0 ) : (int) $rule['hours'];
		$tf     = '12h' === $this->settings->get( 'time_format', '24h' ) ? 'g:i A' : 'H:i';
		$start  = (int) strtotime( (string) $booking['start'] );
		$fee    = (float) ( $booking['fee_amount'] ?? 0 );
		$open   = in_array( $booking['status'], array( 'pending', 'confirmed' ), true );
		$manage = ( $rule['self_cancel'] || $rule['self_reschedule'] ) && $open && '' !== (string) ( $booking['uid'] ?? '' );

		return array(
			'manage_link'           => $manage ? ManagePage::url( (string) $booking['uid'] ) : '',
			'cancellation_policy'   => $text,
			'cancellation_deadline' => $hours > 0 ? wp_date( 'l, ' . get_option( 'date_format' ) . ', ' . $tf, $start - $hours * HOUR_IN_SECONDS ) : '',
			'cancellation_fee'      => $fee > 0 ? CancellationPolicy::money( $fee ) : '',
			'cancellation_fee_note' => $fee > 0
				? sprintf(
					/* translators: %s: fee amount. */
					'no_show' === ( $booking['fee_reason'] ?? '' ) ? __( 'As you did not attend the appointment, we charge a fee of %s according to our cancellation terms.', 'kalendora' ) : __( 'As the cancellation was made after the free cancellation period, we charge a fee of %s according to our cancellation terms.', 'kalendora' ),
					CancellationPolicy::money( $fee )
				)
				: '',
			'cancel_reason'         => '',
			'fee_info'              => $fee > 0
				? sprintf(
					/* translators: %s: fee amount. */
					__( 'Cancellation fee recorded: %s (open)', 'kalendora' ),
					CancellationPolicy::money( $fee )
				)
				: '',
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
				'customer_name'  => '' !== $entry['name'] ? $entry['name'] : __( 'customer', 'kalendora' ),
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
			) + array_fill_keys( array( 'manage_link', 'cancellation_policy', 'cancellation_deadline', 'cancellation_fee', 'cancellation_fee_note', 'cancel_reason', 'fee_info', 'time_until' ), '' ),
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
			'service_name'  => __( 'Sample service', 'kalendora' ),
			'resource_name' => '',
		);
		$labeled                      = array(
			array(
				'key'   => 'message',
				'label' => __( 'Message', 'kalendora' ),
				'value' => __( 'This is a sample message.', 'kalendora' ),
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
			'duration'       => sprintf( __( '%d min', 'kalendora' ), 45 ),
			'price'          => number_format_i18n( 35, 2 ) . ' €',
			'staff'          => '',
			'form_title'     => __( 'Contact', 'kalendora' ),
			'previous_date'  => wp_date( 'l, ' . get_option( 'date_format' ), $start - DAY_IN_SECONDS ),
			'previous_time'  => '09:00',
		);
		$vars['google_calendar_link'] = Ics::google_link( $booking, $vars['service'], $vars['business_address'] );
		$vars['calendar_link']        = home_url( '/' );
		$vars                        += array(
			'manage_link'           => home_url( '/' ),
			'cancellation_policy'   => CancellationPolicy::text( CancellationPolicy::for_service( null, $this->settings->all() ), 35.0 ),
			'cancellation_deadline' => wp_date( 'l, ' . get_option( 'date_format' ) . ', H:i', $start - DAY_IN_SECONDS ),
			'cancellation_fee'      => CancellationPolicy::money( 17.5 ),
			/* translators: %s: fee amount. */
			'cancellation_fee_note' => sprintf( __( 'As the cancellation was made after the free cancellation period, we charge a fee of %s according to our cancellation terms.', 'kalendora' ), CancellationPolicy::money( 17.5 ) ),
			'cancel_reason'         => __( 'Sample reason', 'kalendora' ),
			'time_until'            => __( 'tomorrow', 'kalendora' ),
			/* translators: %s: fee amount. */
			'fee_info'              => sprintf( __( 'Cancellation fee recorded: %s (open)', 'kalendora' ), CancellationPolicy::money( 17.5 ) ),
		);

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
			'business_phone'   => (string) $this->settings->get( 'business_phone', '' ),
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
			'kalendora_email_html',
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

		return '<p style="margin:20px 0 4px;">' . $button( $confirm, __( 'Confirm', 'kalendora' ), '#15803d' ) . $button( $reject, __( 'Reject', 'kalendora' ), '#b91c1c' ) . '</p>'
			. '<p style="font-size:13px;color:#6b7280;margin:4px 0 0;">' . esc_html__( 'You will see the booking details once more before anything is changed.', 'kalendora' ) . '</p>';
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

		return '<p style="margin:20px 0 6px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 22px;background:' . $color . ';color:#ffffff;text-decoration:none;border-radius:8px;font-weight:bold;">&#128197; ' . esc_html__( 'Add to calendar', 'kalendora' ) . '</a></p>'
			. '<p style="margin:0 0 16px;font-size:13px;color:#6b7280;">' . esc_html__( 'Works with iPhone, Android and Outlook.', 'kalendora' ) . ' <a href="' . esc_url( (string) $ctx['vars']['google_calendar_link'] ) . '" style="color:#6b7280;">' . esc_html__( 'Google Calendar', 'kalendora' ) . '</a></p>';
	}

	/**
	 * Button "Move or cancel appointment" (empty when self-service is off or the booking is closed).
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private function manage_button( array $ctx ): string {
		$url = (string) ( $ctx['vars']['manage_link'] ?? '' );
		if ( '' === $url ) {
			return '';
		}
		return '<p style="margin:16px 0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:10px 18px;border:1px solid #d1d5db;color:#1f2937;text-decoration:none;border-radius:8px;font-weight:bold;">' . esc_html__( 'Move or cancel appointment', 'kalendora' ) . '</a></p>';
	}

	/**
	 * Link to the admin screen.
	 *
	 * @param array<string, mixed> $ctx Context.
	 */
	private function admin_link( array $ctx ): string {
		$page = ! empty( $ctx['booking'] ) ? 'kalendora-bookings' : 'kalendora-entries';
		return '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '" style="color:#2563eb;">' . esc_html__( 'Open in WordPress', 'kalendora' ) . '</a></p>';
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
	 * @param string               $status    queued|sent|failed.
	 * @param string               $error     Error message.
	 * @return int Log id (0 for previews).
	 */
	private function log( array $ctx, string $event, string $recipient, string $status, string $error = '' ): int {
		global $wpdb;
		if ( ! empty( $ctx['sample'] ) ) {
			return 0;
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
				'status'     => $status,
				'error'      => '' === $error ? null : mb_substr( $error, 0, 1000 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}
}
