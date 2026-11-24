<?php
/**
 * Forms.
 *
 * @package Bookora
 */

namespace Bookora\Repository;

use Bookora\Forms\FieldRegistry;
use Bookora\Install\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Stores forms with their field definitions and settings.
 */
final class FormRepository extends TableRepository {

	/**
	 * Table.
	 */
	protected function table_name(): string {
		return 'forms';
	}

	/**
	 * Fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'title'      => array(
				'type'    => 'string',
				'default' => '',
			),
			'type'       => array(
				'type'    => 'enum',
				'default' => 'standard',
				'options' => array( 'standard', 'booking' ),
			),
			'status'     => array(
				'type'    => 'enum',
				'default' => 'draft',
				'options' => array( 'draft', 'published' ),
			),
			'fields'     => array(
				'type'    => 'json',
				'default' => array(),
			),
			'settings'   => array(
				'type'    => 'json',
				'default' => array(),
			),
			'custom_css' => array(
				'type'    => 'code',
				'default' => '',
			),
			'custom_js'  => array(
				'type'    => 'code',
				'default' => '',
			),
		);
	}

	/**
	 * Default form settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'submitLabel'    => '',
			'successMessage' => '',
			'redirectUrl'    => '',
			'labelPlacement' => 'top',
			'stepProgress'   => 'bar',
			'notifyAdmin'    => true,
			'storeEntries'   => true,
		);
	}

	/**
	 * Sanitize including field list, settings and custom code.
	 *
	 * @param array<string, mixed> $data Raw data.
	 * @param array<string, mixed> $current Existing row (for type and code fallback).
	 * @return array<string, mixed>
	 */
	public function sanitize_form( array $data, array $current = array() ): array {
		$type = $data['type'] ?? ( $current['type'] ?? 'standard' );
		$type = in_array( $type, array( 'standard', 'booking' ), true ) ? $type : 'standard';

		if ( array_key_exists( 'fields', $data ) ) {
			$data['fields'] = FieldRegistry::sanitize_fields( $data['fields'], $type );
		}

		if ( array_key_exists( 'settings', $data ) ) {
			$data['settings'] = self::sanitize_settings( $data['settings'] );
		}

		// Custom CSS/JS only for users who may post unfiltered HTML (admins on single sites).
		foreach ( array( 'custom_css', 'custom_js' ) as $code ) {
			if ( ! array_key_exists( $code, $data ) ) {
				continue;
			}
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				unset( $data[ $code ] );
				continue;
			}
			$value         = (string) $data[ $code ];
			$data[ $code ] = 'custom_css' === $code ? self::clean_css( $value ) : $value;
		}

		return $data;
	}

	/**
	 * Create a form.
	 *
	 * @param array<string, mixed> $data Raw data.
	 */
	public function create( array $data ): int {
		$data = $this->sanitize_form( $data ) + array(
			'fields'   => array(),
			'settings' => self::default_settings(),
		);
		return parent::create( $data );
	}

	/**
	 * Update a form.
	 *
	 * @param int                  $id   Form id.
	 * @param array<string, mixed> $data Raw data.
	 */
	public function update( int $id, array $data ): bool {
		return parent::update( $id, $this->sanitize_form( $data, (array) $this->find( $id ) ) );
	}

	/**
	 * Duplicate a form as draft.
	 *
	 * @param int $id Form id.
	 * @return int New id (0 on failure).
	 */
	public function duplicate( int $id ): int {
		$form = $this->find( $id );
		if ( ! $form ) {
			return 0;
		}
		/* translators: %s: form title. */
		$form['title']  = sprintf( __( '%s (copy)', 'bookora' ), $form['title'] );
		$form['status'] = 'draft';
		unset( $form['id'], $form['created_at'], $form['updated_at'] );

		return parent::create( $form );
	}

	/**
	 * Number of entries per form.
	 *
	 * @return array<int, int> form id => count
	 */
	public function entry_counts(): array {
		global $wpdb;
		$table = Schema::table( $wpdb->prefix, 'entries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$rows = (array) $wpdb->get_results( "SELECT form_id, COUNT(*) AS total FROM {$table} WHERE status <> 'trash' GROUP BY form_id", ARRAY_A );
		return array_map( 'intval', array_column( $rows, 'total', 'form_id' ) );
	}

	/**
	 * Remove HTML tags (e.g. a pasted <style> wrapper) but keep the CSS itself,
	 * so the value can never close the surrounding style element.
	 *
	 * @param string $css Raw CSS.
	 */
	public static function clean_css( string $css ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- wp_strip_all_tags() would also delete the CSS inside <style>.
		return trim( strip_tags( (string) preg_replace( '#</?style[^>]*>#i', '', $css ) ) );
	}

	/**
	 * Sanitize form settings.
	 *
	 * @param mixed $settings Raw settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize_settings( $settings ): array {
		$s = is_array( $settings ) ? $settings : array();
		$d = self::default_settings();

		return array(
			'submitLabel'     => mb_substr( sanitize_text_field( (string) ( $s['submitLabel'] ?? '' ) ), 0, 100 ),
			'successMessage'  => wp_kses_post( (string) ( $s['successMessage'] ?? '' ) ),
			'redirectUrl'     => esc_url_raw( (string) ( $s['redirectUrl'] ?? '' ) ),
			'labelPlacement'  => in_array( $s['labelPlacement'] ?? '', array( 'top', 'left', 'hidden' ), true ) ? $s['labelPlacement'] : $d['labelPlacement'],
			'stepProgress'    => in_array( $s['stepProgress'] ?? '', array( 'bar', 'steps', 'none' ), true ) ? $s['stepProgress'] : $d['stepProgress'],
			'notifyAdmin'     => ! array_key_exists( 'notifyAdmin', $s ) || ! empty( $s['notifyAdmin'] ),
			'storeEntries'    => ! array_key_exists( 'storeEntries', $s ) || ! empty( $s['storeEntries'] ),
			// Newsletter sign-up: provider and the checkbox the visitor must tick.
			'newsletter'      => in_array( $s['newsletter'] ?? '', array( 'mailchimp', 'brevo' ), true ) ? $s['newsletter'] : '',
			'newsletterField' => sanitize_key( (string) ( $s['newsletterField'] ?? '' ) ),
		);
	}
}
