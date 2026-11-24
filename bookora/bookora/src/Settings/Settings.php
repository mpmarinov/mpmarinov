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
			'business_name'         => '',
			'business_address'      => '',
			'business_phone'        => '',
			'email_from_address'    => '',
			'admin_email'           => '',
			'time_format'           => '24h',
			'week_start'            => 1,
			'slot_interval'         => 30,
			'default_status'        => 'pending',
			'min_notice_minutes'    => 60,
			'max_advance_days'      => 90,
			'max_per_day'           => 0,
			'fill_gaps'             => true,
			'cancel_limit_hours'    => 24,
			'self_cancel'           => true,
			'self_reschedule'       => true,
			'cancel_fee_enabled'    => false,
			'cancel_fee_type'       => 'percent',
			'cancel_fee_value'      => 50,
			'cancel_fee_no_show'    => true,
			'cancel_late_online'    => 'fee',
			'cancel_policy_text'    => '',
			'reminder_hours'        => array( 168, 24 ),
			'token_ttl_hours'       => 72,
			'primary_color'         => '#2563eb',
			'form_radius'           => 10,
			'form_density'          => 'normal',
			'form_scheme'           => 'light',
			'form_font'             => 'inherit',
			'global_css'            => '',
			'global_js'             => '',
			'delete_on_uninstall'   => false,
			'feed_include_pending'  => true,
			'feed_customer_details' => true,
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
		$out['business_address']   = mb_substr( sanitize_text_field( (string) ( $input['business_address'] ?? '' ) ), 0, 255 );
		$out['email_from_address'] = sanitize_email( (string) ( $input['email_from_address'] ?? '' ) );
		$out['time_format']        = in_array( $input['time_format'] ?? '', array( '12h', '24h' ), true ) ? $input['time_format'] : $defaults['time_format'];
		$out['week_start']         = self::int_range( $input['week_start'] ?? 1, 0, 6 );
		$out['slot_interval']      = in_array( (int) ( $input['slot_interval'] ?? 0 ), array( 5, 10, 15, 20, 30, 45, 60, 90, 120 ), true ) ? (int) $input['slot_interval'] : $defaults['slot_interval'];
		$out['default_status']     = in_array( $input['default_status'] ?? '', array( 'pending', 'confirmed' ), true ) ? $input['default_status'] : $defaults['default_status'];
		$out['min_notice_minutes'] = self::int_range( $input['min_notice_minutes'] ?? 0, 0, 525600 );
		$out['max_advance_days']   = self::int_range( $input['max_advance_days'] ?? 90, 1, 1095 );
		$out['max_per_day']        = self::int_range( $input['max_per_day'] ?? 0, 0, 1000 );
		$out['fill_gaps']          = ! empty( $input['fill_gaps'] );
		$out['cancel_limit_hours'] = self::int_range( $input['cancel_limit_hours'] ?? 24, 0, 8760 );
		$out['token_ttl_hours']    = self::int_range( $input['token_ttl_hours'] ?? 72, 1, 720 );
		$out['business_phone']     = mb_substr( sanitize_text_field( (string) ( $input['business_phone'] ?? '' ) ), 0, 50 );

		$out['self_cancel']        = ! empty( $input['self_cancel'] );
		$out['self_reschedule']    = ! empty( $input['self_reschedule'] );
		$out['cancel_fee_enabled'] = ! empty( $input['cancel_fee_enabled'] );
		$out['cancel_fee_type']    = in_array( $input['cancel_fee_type'] ?? '', array( 'percent', 'fixed' ), true ) ? $input['cancel_fee_type'] : 'percent';
		$fee                       = round( max( 0.0, (float) str_replace( ',', '.', (string) ( $input['cancel_fee_value'] ?? 0 ) ) ), 2 );
		$out['cancel_fee_value']   = 'percent' === $out['cancel_fee_type'] ? min( 100.0, $fee ) : min( 100000.0, $fee );
		$out['cancel_fee_no_show'] = ! empty( $input['cancel_fee_no_show'] );
		$out['cancel_late_online'] = 'block' === ( $input['cancel_late_online'] ?? '' ) ? 'block' : 'fee';
		$out['cancel_policy_text'] = mb_substr( sanitize_textarea_field( (string) ( $input['cancel_policy_text'] ?? '' ) ), 0, 1000 );

		$reminders = is_array( $input['reminder_hours'] ?? null ) ? $input['reminder_hours'] : array();
		$reminders = array_values( array_unique( array_filter( array_map( static fn( $h ) => self::int_range( $h, 0, 720 ), $reminders ) ) ) );
		sort( $reminders );
		$out['reminder_hours'] = array_slice( $reminders, 0, 5 );

		$color                = sanitize_hex_color( (string) ( $input['primary_color'] ?? '' ) );
		$out['primary_color'] = $color ? $color : $defaults['primary_color'];
		$out['form_radius']   = self::int_range( $input['form_radius'] ?? 10, 0, 24 );
		$out['form_density']  = in_array( $input['form_density'] ?? '', array( 'compact', 'normal', 'comfortable' ), true ) ? $input['form_density'] : 'normal';
		$out['form_scheme']   = in_array( $input['form_scheme'] ?? '', array( 'light', 'dark', 'auto' ), true ) ? $input['form_scheme'] : 'light';
		$out['form_font']     = in_array( $input['form_font'] ?? '', array( 'inherit', 'system' ), true ) ? $input['form_font'] : 'inherit';

		// Custom code is only stored for users allowed to add unfiltered HTML.
		$can_code          = current_user_can( 'unfiltered_html' );
		$current           = get_option( self::OPTION, array() );
		$current           = is_array( $current ) ? $current : array();
		$out['global_css'] = $can_code ? \Bookora\Repository\FormRepository::clean_css( (string) ( $input['global_css'] ?? '' ) ) : (string) ( $current['global_css'] ?? '' );
		$out['global_js']  = $can_code ? (string) ( $input['global_js'] ?? '' ) : (string) ( $current['global_js'] ?? '' );

		$out['delete_on_uninstall'] = ! empty( $input['delete_on_uninstall'] );

		$out['feed_include_pending']  = ! empty( $input['feed_include_pending'] );
		$out['feed_customer_details'] = ! empty( $input['feed_customer_details'] );

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
