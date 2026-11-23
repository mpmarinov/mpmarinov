<?php
/**
 * Global plugin settings.
 *
 * @package Bookora
 */

namespace Bookora\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stores all global settings in a single, non-autoloaded option with strict sanitization.
 */
final class Settings {

	public const OPTION = 'bookora_settings';

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'business_name'       => '',
			'admin_email'         => '',
			'time_format'         => '24h',
			'week_start'          => 1,
			'slot_interval'       => 30,
			'default_status'      => 'pending',
			'min_notice_minutes'  => 60,
			'max_advance_days'    => 90,
			'cancel_limit_hours'  => 24,
			'reminder_hours'      => array( 24 ),
			'token_ttl_hours'     => 72,
			'primary_color'       => '#2563eb',
			'global_css'          => '',
			'global_js'           => '',
			'antispam_honeypot'   => true,
			'captcha_provider'    => 'none',
			'delete_on_uninstall' => false,
		);
	}

	/**
	 * Register the option with WordPress (also exposes it to the REST settings schema).
	 */
	public function register(): void {
		add_action(
			'init',
			function () {
				register_setting(
					'bookora',
					self::OPTION,
					array(
						'type'              => 'object',
						'default'           => self::defaults(),
						'sanitize_callback' => array( $this, 'sanitize' ),
						'show_in_rest'      => false,
					)
				);
			}
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Value when missing.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Merge, sanitize and persist new values.
	 *
	 * @param array<string, mixed> $values Partial settings.
	 * @return array<string, mixed> Saved settings.
	 */
	public function update( array $values ): array {
		$clean = $this->sanitize( array_merge( $this->all(), $values ) );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Sanitize a full settings array. Unknown keys are dropped.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();

		$out['business_name']      = sanitize_text_field( (string) ( $input['business_name'] ?? '' ) );
		$out['admin_email']        = sanitize_email( (string) ( $input['admin_email'] ?? '' ) );
		$out['time_format']        = in_array( $input['time_format'] ?? '', array( '12h', '24h' ), true ) ? $input['time_format'] : $defaults['time_format'];
		$out['week_start']         = self::int_range( $input['week_start'] ?? 1, 0, 6 );
		$out['slot_interval']      = in_array( (int) ( $input['slot_interval'] ?? 0 ), array( 5, 10, 15, 20, 30, 45, 60, 90, 120 ), true ) ? (int) $input['slot_interval'] : $defaults['slot_interval'];
		$out['default_status']     = in_array( $input['default_status'] ?? '', array( 'pending', 'confirmed' ), true ) ? $input['default_status'] : $defaults['default_status'];
		$out['min_notice_minutes'] = self::int_range( $input['min_notice_minutes'] ?? 0, 0, 525600 );
		$out['max_advance_days']   = self::int_range( $input['max_advance_days'] ?? 90, 1, 1095 );
		$out['cancel_limit_hours'] = self::int_range( $input['cancel_limit_hours'] ?? 24, 0, 8760 );
		$out['token_ttl_hours']    = self::int_range( $input['token_ttl_hours'] ?? 72, 1, 720 );

		$reminders = is_array( $input['reminder_hours'] ?? null ) ? $input['reminder_hours'] : array();
		$reminders = array_values( array_unique( array_filter( array_map( static fn( $h ) => self::int_range( $h, 0, 720 ), $reminders ) ) ) );
		sort( $reminders );
		$out['reminder_hours'] = array_slice( $reminders, 0, 5 );

		$color                = sanitize_hex_color( (string) ( $input['primary_color'] ?? '' ) );
		$out['primary_color'] = $color ? $color : $defaults['primary_color'];

		// Custom code is only stored for users allowed to add unfiltered HTML.
		$can_code          = current_user_can( 'unfiltered_html' );
		$current           = get_option( self::OPTION, array() );
		$current           = is_array( $current ) ? $current : array();
		$out['global_css'] = $can_code ? wp_strip_all_tags( (string) ( $input['global_css'] ?? '' ) ) : (string) ( $current['global_css'] ?? '' );
		$out['global_js']  = $can_code ? (string) ( $input['global_js'] ?? '' ) : (string) ( $current['global_js'] ?? '' );

		$out['antispam_honeypot']   = ! empty( $input['antispam_honeypot'] );
		$out['captcha_provider']    = in_array( $input['captcha_provider'] ?? '', array( 'none', 'turnstile', 'hcaptcha', 'recaptcha' ), true ) ? $input['captcha_provider'] : 'none';
		$out['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );

		/**
		 * Filter sanitized settings (Pro add-on adds its own keys here).
		 *
		 * @param array $out   Sanitized settings.
		 * @param array $input Raw input.
		 */
		return (array) apply_filters( 'bookora_sanitize_settings', $out, $input );
	}

	/**
	 * Clamp an integer into a range.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 */
	private static function int_range( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}
}
