<?php
/**
 * Email templates: defaults, placeholders and storage.
 *
 * @package Bookora
 */

namespace Bookora\Notifications;

use Bookora\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Each event has a default subject/body (translatable) that can be overridden in the database.
 */
final class Templates {

	/**
	 * Event definitions: event => [recipient, enabled by default].
	 *
	 * @return array<string, array{recipient:string, enabled:bool, group:string}>
	 */
	public static function events(): array {
		$events = array(
			'booking_received'          => array(
				'recipient' => 'customer',
				'enabled'   => true,
				'group'     => 'booking',
			),
			'booking_confirmed'         => array(
				'recipient' => 'customer',
				'enabled'   => true,
				'group'     => 'booking',
			),
			'booking_rejected'          => array(
				'recipient' => 'customer',
				'enabled'   => true,
				'group'     => 'booking',
			),
			'booking_cancelled'         => array(
				'recipient' => 'customer',
				'enabled'   => true,
				'group'     => 'booking',
			),
			'booking_reminder'          => array(
				'recipient' => 'customer',
				'enabled'   => true,
				'group'     => 'booking',
			),
			'booking_rescheduled'       => array(
				'recipient' => 'customer',
				'enabled'   => true,
				'group'     => 'booking',
			),
			'admin_new_booking'         => array(
				'recipient' => 'admin',
				'enabled'   => true,
				'group'     => 'booking',
			),
			'admin_booking_cancelled'   => array(
				'recipient' => 'admin',
				'enabled'   => true,
				'group'     => 'booking',
			),
			'admin_booking_rescheduled' => array(
				'recipient' => 'admin',
				'enabled'   => true,
				'group'     => 'booking',
			),
			'entry_received'            => array(
				'recipient' => 'customer',
				'enabled'   => false,
				'group'     => 'entry',
			),
			'admin_new_entry'           => array(
				'recipient' => 'admin',
				'enabled'   => true,
				'group'     => 'entry',
			),
		);

		/**
		 * Filter email events (Pro adds e.g. SMS channels here).
		 *
		 * @param array $events Events.
		 */
		return (array) apply_filters( 'bookora_email_events', $events );
	}

	/**
	 * Human labels for the admin.
	 *
	 * @return array<string, array{title:string, description:string}>
	 */
	public static function labels(): array {
		return array(
			'booking_received'          => array(
				'title'       => __( 'Booking received', 'bookora' ),
				'description' => __( 'To the customer right after booking, while the request is still pending.', 'bookora' ),
			),
			'booking_confirmed'         => array(
				'title'       => __( 'Booking confirmed', 'bookora' ),
				'description' => __( 'To the customer when you confirm the booking. Includes a calendar file.', 'bookora' ),
			),
			'booking_rejected'          => array(
				'title'       => __( 'Booking rejected', 'bookora' ),
				'description' => __( 'To the customer when you reject the request.', 'bookora' ),
			),
			'booking_cancelled'         => array(
				'title'       => __( 'Booking cancelled', 'bookora' ),
				'description' => __( 'To the customer when the appointment is cancelled.', 'bookora' ),
			),
			'booking_reminder'          => array(
				'title'       => __( 'Appointment reminder', 'bookora' ),
				'description' => __( 'To the customer before the appointment (time set in the settings).', 'bookora' ),
			),
			'booking_rescheduled'       => array(
				'title'       => __( 'Appointment moved', 'bookora' ),
				'description' => __( 'To the customer when you move the appointment in the timeline. Includes the new calendar file.', 'bookora' ),
			),
			'admin_new_booking'         => array(
				'title'       => __( 'New booking (to you)', 'bookora' ),
				'description' => __( 'To you with buttons to confirm or reject with one click.', 'bookora' ),
			),
			'admin_booking_cancelled'   => array(
				'title'       => __( 'Cancelled by the customer (to you)', 'bookora' ),
				'description' => __( 'To you when a customer cancels online, with reason and fee (if any).', 'bookora' ),
			),
			'admin_booking_rescheduled' => array(
				'title'       => __( 'Moved by the customer (to you)', 'bookora' ),
				'description' => __( 'To you when a customer moves the appointment online.', 'bookora' ),
			),
			'entry_received'            => array(
				'title'       => __( 'Message received (auto-reply)', 'bookora' ),
				'description' => __( 'To the sender of a contact or request form.', 'bookora' ),
			),
			'admin_new_entry'           => array(
				'title'       => __( 'New message (to you)', 'bookora' ),
				'description' => __( 'To you when someone submits a contact or request form.', 'bookora' ),
			),
		);
	}

	/**
	 * Default subject and body per event (translatable).
	 *
	 * @param string $event Event.
	 * @return array{subject:string, body:string}
	 */
	public static function default_template( string $event ): array {
		$defaults = array(
			'booking_received'          => array(
				'subject' => __( 'We have received your booking request', 'bookora' ),
				'body'    => __( "Hello {customer_name},\n\nthank you for your request. We have received the following booking:\n\n<strong>{service}</strong>\n{date}, {time} – {end_time}\n\nWe will check the appointment and confirm it shortly by email.\n\n{manage_button}\n\nKind regards\n{business_name}", 'bookora' ),
			),
			'booking_confirmed'         => array(
				'subject' => __( 'Your appointment on {date} is confirmed', 'bookora' ),
				'body'    => __( "Hello {customer_name},\n\nyour appointment is confirmed:\n\n<strong>{service}</strong>\n{date}, {time} – {end_time}\n{staff}\n\n{calendar_button}\n\nIf you cannot make it, please let us know in good time.\n{manage_button}\n{cancellation_policy}\n\nWe look forward to seeing you!\n{business_name}", 'bookora' ),
			),
			'booking_rejected'          => array(
				'subject' => __( 'Your booking request for {date}', 'bookora' ),
				'body'    => __( "Hello {customer_name},\n\nunfortunately we cannot offer you the requested appointment ({service}, {date} at {time}).\n\nPlease choose another time on our website: {site_url}\n\nKind regards\n{business_name}", 'bookora' ),
			),
			'booking_cancelled'         => array(
				'subject' => __( 'Your appointment on {date} has been cancelled', 'bookora' ),
				'body'    => __( "Hello {customer_name},\n\nyour appointment has been cancelled:\n\n{service}\n{date}, {time}\n\n{cancellation_fee_note}\n\nYou are welcome to book a new appointment at any time: {site_url}\n\nKind regards\n{business_name}", 'bookora' ),
			),
			'booking_reminder'          => array(
				'subject' => __( 'Reminder: your appointment on {date} at {time}', 'bookora' ),
				'body'    => __( "Hello {customer_name},\n\nwe would like to remind you of your appointment {time_until}:\n\n<strong>{service}</strong>\n{date}, {time} – {end_time}\n\n{calendar_button}\n\nIf you cannot make it, please let us know as soon as possible.\n{manage_button}\n{cancellation_policy}\n\nSee you soon!\n{business_name}", 'bookora' ),
			),
			'booking_rescheduled'       => array(
				'subject' => __( 'Your appointment has been moved to {date} at {time}', 'bookora' ),
				'body'    => __( "Hello {customer_name},\n\nyour appointment has been moved:\n\n<strong>{service}</strong>\nNew: {date}, {time} – {end_time}\n<s>Previously: {previous_date}, {previous_time}</s>\n\n{calendar_button}\n\nIf the new time does not suit you, please let us know.\n{manage_button}\n\nKind regards\n{business_name}", 'bookora' ),
			),
			'admin_new_booking'         => array(
				'subject' => __( 'New booking: {service} on {date} at {time}', 'bookora' ),
				'body'    => __( "New booking from <strong>{customer_name}</strong>\n\n{service}\n{date}, {time} – {end_time}\n{staff}\n\nEmail: {customer_email}\nPhone: {customer_phone}\n\n{all_fields}\n\n{action_buttons}", 'bookora' ),
			),
			'admin_booking_cancelled'   => array(
				'subject' => __( 'Cancelled by the customer: {service} on {date} at {time}', 'bookora' ),
				'body'    => __( "<strong>{customer_name}</strong> has cancelled the appointment online. The time is free again.\n\n{service}\n{date}, {time} – {end_time}\n{staff}\n\nReason: {cancel_reason}\n{fee_info}\n\nEmail: {customer_email}\nPhone: {customer_phone}\n\n{admin_link}", 'bookora' ),
			),
			'admin_booking_rescheduled' => array(
				'subject' => __( 'Moved by the customer: {service} now on {date} at {time}', 'bookora' ),
				'body'    => __( "<strong>{customer_name}</strong> has moved the appointment online.\n\n{service}\nNew: {date}, {time} – {end_time}\n<s>Previously: {previous_date}, {previous_time}</s>\n{staff}\n\nEmail: {customer_email}\nPhone: {customer_phone}\n\n{admin_link}", 'bookora' ),
			),
			'entry_received'            => array(
				'subject' => __( 'Thank you for your message', 'bookora' ),
				'body'    => __( "Hello {customer_name},\n\nthank you for your message. We will get back to you as soon as possible.\n\nKind regards\n{business_name}", 'bookora' ),
			),
			'admin_new_entry'           => array(
				'subject' => __( 'New message via {form_title}', 'bookora' ),
				'body'    => __( "New message from <strong>{customer_name}</strong> ({customer_email})\n\n{all_fields}\n\n{admin_link}", 'bookora' ),
			),
		);

		return $defaults[ $event ] ?? array(
			'subject' => '',
			'body'    => '',
		);
	}

	/**
	 * Placeholders with a description (shown in the editor).
	 *
	 * @return array<string, string>
	 */
	public static function placeholders(): array {
		return array(
			'{customer_name}'         => __( 'Customer name', 'bookora' ),
			'{customer_email}'        => __( 'Customer email', 'bookora' ),
			'{customer_phone}'        => __( 'Customer phone', 'bookora' ),
			'{service}'               => __( 'Service', 'bookora' ),
			'{date}'                  => __( 'Date', 'bookora' ),
			'{time}'                  => __( 'Start time', 'bookora' ),
			'{end_time}'              => __( 'End time', 'bookora' ),
			'{previous_date}'         => __( 'Previous date (when moved)', 'bookora' ),
			'{previous_time}'         => __( 'Previous time (when moved)', 'bookora' ),
			'{duration}'              => __( 'Duration', 'bookora' ),
			'{price}'                 => __( 'Price', 'bookora' ),
			'{staff}'                 => __( 'Staff member', 'bookora' ),
			'{business_name}'         => __( 'Business name', 'bookora' ),
			'{business_address}'      => __( 'Business address', 'bookora' ),
			'{site_url}'              => __( 'Website address', 'bookora' ),
			'{form_title}'            => __( 'Form title', 'bookora' ),
			'{all_fields}'            => __( 'All form answers as a table', 'bookora' ),
			'{field:key}'             => __( 'A single answer, e.g. {field:message}', 'bookora' ),
			'{calendar_button}'       => __( 'Button "Add to calendar" (iPhone, Android, Outlook)', 'bookora' ),
			'{google_calendar_link}'  => __( 'Link "Add to Google Calendar"', 'bookora' ),
			'{action_buttons}'        => __( 'Buttons confirm / reject (only in emails to you)', 'bookora' ),
			'{manage_button}'         => __( 'Button "Move or cancel appointment" for the customer', 'bookora' ),
			'{manage_link}'           => __( 'Link "Move or cancel appointment"', 'bookora' ),
			'{cancellation_policy}'   => __( 'Cancellation terms (as agreed with the booking)', 'bookora' ),
			'{cancellation_deadline}' => __( 'Date and time up to which cancelling is free', 'bookora' ),
			'{cancellation_fee}'      => __( 'Recorded cancellation fee', 'bookora' ),
			'{cancellation_fee_note}' => __( 'Sentence about the fee (only if a fee was recorded)', 'bookora' ),
			'{cancel_reason}'         => __( 'Reason given by the customer when cancelling', 'bookora' ),
			'{fee_info}'              => __( 'Fee note for you (only if a fee was recorded)', 'bookora' ),
			'{business_phone}'        => __( 'Business phone', 'bookora' ),
			'{admin_link}'            => __( 'Link to the entry in WordPress', 'bookora' ),
		);
	}

	/**
	 * Current template (database override or default).
	 *
	 * @param string $event Event.
	 * @return array{event:string, recipient:string, enabled:bool, subject:string, body:string, customized:bool}
	 */
	public function get( string $event ): array {
		global $wpdb;
		$def = self::events()[ $event ] ?? array(
			'recipient' => 'customer',
			'enabled'   => false,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT subject, body, enabled FROM ' . Schema::table( $wpdb->prefix, 'email_templates' ) . " WHERE event = %s AND form_id IS NULL AND locale = '' ORDER BY id DESC LIMIT 1", $event ), ARRAY_A );

		$default = self::default_template( $event );

		return array(
			'event'      => $event,
			'recipient'  => $def['recipient'],
			'enabled'    => $row ? (bool) $row['enabled'] : (bool) $def['enabled'],
			'subject'    => $row && '' !== $row['subject'] ? (string) $row['subject'] : $default['subject'],
			'body'       => $row && '' !== (string) $row['body'] ? (string) $row['body'] : $default['body'],
			'customized' => (bool) $row,
		);
	}

	/**
	 * Save an override.
	 *
	 * @param string $event   Event.
	 * @param bool   $enabled Enabled.
	 * @param string $subject Subject.
	 * @param string $body    Body (limited HTML).
	 */
	public function save( string $event, bool $enabled, string $subject, string $body ): void {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'email_templates' );
		$this->reset( $event );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'event'      => $event,
				'recipient'  => self::events()[ $event ]['recipient'] ?? 'customer',
				'form_id'    => null,
				'locale'     => '',
				'subject'    => mb_substr( sanitize_text_field( $subject ), 0, 255 ),
				'body'       => wp_kses_post( $body ),
				'enabled'    => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Remove the override (back to the default text).
	 *
	 * @param string $event Event.
	 */
	public function reset( string $event ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( $wpdb->prefix, 'email_templates' ) . " WHERE event = %s AND form_id IS NULL AND locale = ''", $event ) );
	}
}
