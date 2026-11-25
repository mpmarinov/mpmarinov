<?php
/**
 * Database schema definitions (dbDelta format).
 *
 * @package Kalendora
 */

namespace Kalendora\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Holds CREATE TABLE statements. Keep the dbDelta formatting rules:
 * one column per line, two spaces after PRIMARY KEY, use KEY instead of INDEX.
 */
final class Schema {

	public const VERSION = '1.8.0';

	/**
	 * Unprefixed table names.
	 */
	public const TABLES = array(
		'forms',
		'services',
		'resources',
		'resource_services',
		'schedules',
		'schedule_exceptions',
		'customers',
		'entries',
		'entry_values',
		'bookings',
		'action_tokens',
		'email_templates',
		'notification_log',
		'mail_queue',
		'calendar_connections',
		'external_events',
		'files',
		'booking_sync',
	);

	/**
	 * Full table name including the WordPress and Kalendora prefix.
	 *
	 * @param string $db_prefix WordPress table prefix.
	 * @param string $name      Unprefixed table name.
	 */
	public static function table( string $db_prefix, string $name ): string {
		return $db_prefix . 'kalendora_' . $name;
	}

	/**
	 * Build all CREATE TABLE statements.
	 *
	 * @param string $db_prefix WordPress table prefix.
	 * @param string $collate   Charset/collation clause from $wpdb->get_charset_collate().
	 * @return array<string, string> SQL keyed by unprefixed table name.
	 */
	public static function statements( string $db_prefix, string $collate ): array {
		$t = static fn( string $name ): string => self::table( $db_prefix, $name );

		return array(
			'forms'                => "CREATE TABLE {$t('forms')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(191) NOT NULL DEFAULT '',
  type varchar(20) NOT NULL DEFAULT 'standard',
  status varchar(20) NOT NULL DEFAULT 'draft',
  fields longtext NULL,
  settings longtext NULL,
  custom_css longtext NULL,
  custom_js longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY type_status (type,status)
) $collate;",

			'services'             => "CREATE TABLE {$t('services')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL DEFAULT '',
  description text NULL,
  duration smallint(5) unsigned NOT NULL DEFAULT 60,
  buffer_before smallint(5) unsigned NOT NULL DEFAULT 0,
  buffer_after smallint(5) unsigned NOT NULL DEFAULT 0,
  slot_interval smallint(5) unsigned NOT NULL DEFAULT 0,
  max_per_day smallint(5) unsigned NOT NULL DEFAULT 0,
  capacity smallint(5) unsigned NOT NULL DEFAULT 1,
  price decimal(12,2) NOT NULL DEFAULT 0.00,
  color varchar(9) NOT NULL DEFAULT '#2563eb',
  min_notice int(10) unsigned NOT NULL DEFAULT 0,
  max_advance int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  settings longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $collate;",

			'resources'            => "CREATE TABLE {$t('resources')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL DEFAULT 'staff',
  name varchar(191) NOT NULL DEFAULT '',
  email varchar(191) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NULL,
  timezone varchar(64) NOT NULL DEFAULT '',
  color varchar(9) NOT NULL DEFAULT '#2563eb',
  status varchar(20) NOT NULL DEFAULT 'active',
  settings longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY status (status)
) $collate;",

			'resource_services'    => "CREATE TABLE {$t('resource_services')} (
  resource_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (resource_id,service_id),
  KEY service_id (service_id)
) $collate;",

			'schedules'            => "CREATE TABLE {$t('schedules')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resource_id bigint(20) unsigned NULL,
  weekday tinyint(1) unsigned NOT NULL,
  start_time time NOT NULL,
  end_time time NOT NULL,
  PRIMARY KEY  (id),
  KEY resource_weekday (resource_id,weekday)
) $collate;",

			'schedule_exceptions'  => "CREATE TABLE {$t('schedule_exceptions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resource_id bigint(20) unsigned NULL,
  type varchar(10) NOT NULL DEFAULT 'closed',
  date_start date NOT NULL,
  date_end date NOT NULL,
  start_time time NULL,
  end_time time NULL,
  note varchar(191) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY resource_dates (resource_id,date_start,date_end)
) $collate;",

			'customers'            => "CREATE TABLE {$t('customers')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  email varchar(191) NOT NULL,
  name varchar(191) NOT NULL DEFAULT '',
  phone varchar(50) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NULL,
  locale varchar(20) NOT NULL DEFAULT '',
  timezone varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY email (email),
  KEY user_id (user_id)
) $collate;",

			'entries'              => "CREATE TABLE {$t('entries')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uid char(36) NOT NULL,
  form_id bigint(20) unsigned NOT NULL,
  customer_id bigint(20) unsigned NULL,
  status varchar(20) NOT NULL DEFAULT 'new',
  ip_hash char(64) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  source_url varchar(2048) NOT NULL DEFAULT '',
  locale varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uid (uid),
  KEY form_status (form_id,status,created_at),
  KEY status (status),
  KEY customer_id (customer_id)
) $collate;",

			'entry_values'         => "CREATE TABLE {$t('entry_values')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  field_key varchar(64) NOT NULL,
  value longtext NULL,
  PRIMARY KEY  (id),
  KEY entry_field (entry_id,field_key)
) $collate;",

			'bookings'             => "CREATE TABLE {$t('bookings')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uid char(36) NOT NULL,
  entry_id bigint(20) unsigned NULL,
  form_id bigint(20) unsigned NULL,
  service_id bigint(20) unsigned NULL,
  resource_id bigint(20) unsigned NULL,
  customer_id bigint(20) unsigned NULL,
  start_utc datetime NOT NULL,
  end_utc datetime NOT NULL,
  timezone varchar(64) NOT NULL DEFAULT 'UTC',
  status varchar(20) NOT NULL DEFAULT 'pending',
  seats smallint(5) unsigned NOT NULL DEFAULT 1,
  notes text NULL,
  source varchar(20) NOT NULL DEFAULT 'web',
  policy longtext NULL,
  fee_amount decimal(12,2) NOT NULL DEFAULT 0.00,
  fee_status varchar(20) NOT NULL DEFAULT '',
  fee_reason varchar(20) NOT NULL DEFAULT '',
  cancelled_by varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uid (uid),
  KEY resource_time (resource_id,start_utc,end_utc),
  KEY status_time (status,start_utc),
  KEY customer_id (customer_id),
  KEY entry_id (entry_id),
  KEY form_id (form_id),
  KEY fee_status (fee_status)
) $collate;",

			'action_tokens'        => "CREATE TABLE {$t('action_tokens')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  action varchar(20) NOT NULL,
  token_hash char(64) NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY booking_id (booking_id)
) $collate;",

			'email_templates'      => "CREATE TABLE {$t('email_templates')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event varchar(40) NOT NULL,
  recipient varchar(20) NOT NULL DEFAULT 'customer',
  form_id bigint(20) unsigned NULL,
  locale varchar(20) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  body longtext NULL,
  enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY event_lookup (event,recipient,form_id,locale)
) $collate;",

			'notification_log'     => "CREATE TABLE {$t('notification_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NULL,
  booking_id bigint(20) unsigned NULL,
  channel varchar(20) NOT NULL DEFAULT 'email',
  event varchar(40) NOT NULL,
  recipient varchar(191) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'queued',
  error text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id),
  KEY booking_id (booking_id),
  KEY status (status)
) $collate;",

			'mail_queue'           => "CREATE TABLE {$t('mail_queue')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  log_id bigint(20) unsigned NULL,
  recipient varchar(191) NOT NULL DEFAULT '',
  subject varchar(255) NOT NULL DEFAULT '',
  body longtext NULL,
  headers text NULL,
  attachments longtext NULL,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  next_attempt datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY next_attempt (next_attempt)
) $collate;",

			'calendar_connections' => "CREATE TABLE {$t('calendar_connections')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resource_id bigint(20) unsigned NULL,
  provider varchar(20) NOT NULL,
  label varchar(191) NOT NULL DEFAULT '',
  account varchar(191) NOT NULL DEFAULT '',
  credentials longtext NULL,
  calendar_id varchar(255) NOT NULL DEFAULT '',
  sync_token text NULL,
  options longtext NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  last_error text NULL,
  last_synced_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY resource_id (resource_id)
) $collate;",

			'external_events'      => "CREATE TABLE {$t('external_events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  connection_id bigint(20) unsigned NOT NULL,
  external_id varchar(255) NOT NULL,
  start_utc datetime NOT NULL,
  end_utc datetime NOT NULL,
  all_day tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY connection_ext (connection_id,external_id(150)),
  KEY time_range (start_utc,end_utc)
) $collate;",

			'files'                => "CREATE TABLE {$t('files')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token char(32) NOT NULL,
  form_id bigint(20) unsigned NOT NULL DEFAULT 0,
  entry_id bigint(20) unsigned NULL,
  field_key varchar(64) NOT NULL DEFAULT '',
  kind varchar(20) NOT NULL DEFAULT 'upload',
  name varchar(191) NOT NULL DEFAULT '',
  mime varchar(100) NOT NULL DEFAULT '',
  size int(10) unsigned NOT NULL DEFAULT 0,
  path varchar(255) NOT NULL DEFAULT '',
  ip_hash char(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY entry_id (entry_id),
  KEY orphan (entry_id,created_at)
) $collate;",

			'booking_sync'         => "CREATE TABLE {$t('booking_sync')} (
  booking_id bigint(20) unsigned NOT NULL,
  connection_id bigint(20) unsigned NOT NULL,
  external_id varchar(255) NOT NULL,
  etag varchar(255) NOT NULL DEFAULT '',
  synced_at datetime NOT NULL,
  PRIMARY KEY  (booking_id,connection_id)
) $collate;",
		);
	}
}
