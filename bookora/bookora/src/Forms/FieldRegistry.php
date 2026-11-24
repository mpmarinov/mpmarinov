<?php
/**
 * Field types and their sanitizing rules.
 *
 * @package Bookora
 */

namespace Bookora\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for which field types exist and which settings they accept.
 * The admin builder may send anything; only whitelisted settings are stored.
 */
final class FieldRegistry {

	public const MAX_FIELDS  = 200;
	public const MAX_OPTIONS = 200;

	/**
	 * Field types: type => [group, flags].
	 * Flags: input (collects a value), options (has choices), booking (booking forms only), layout (no value).
	 *
	 * @return array<string, array{group:string, input:bool, options?:bool, booking?:bool, layout?:bool, single?:bool}>
	 */
	public static function types(): array {
		$types = array(
			// Basic.
			'text'        => array(
				'group' => 'basic',
				'input' => true,
			),
			'name'        => array(
				'group' => 'basic',
				'input' => true,
			),
			'email'       => array(
				'group' => 'basic',
				'input' => true,
			),
			'phone'       => array(
				'group' => 'basic',
				'input' => true,
			),
			'textarea'    => array(
				'group' => 'basic',
				'input' => true,
			),
			'number'      => array(
				'group' => 'basic',
				'input' => true,
			),
			'url'         => array(
				'group' => 'basic',
				'input' => true,
			),
			// Choices.
			'select'      => array(
				'group'   => 'choice',
				'input'   => true,
				'options' => true,
			),
			'radio'       => array(
				'group'   => 'choice',
				'input'   => true,
				'options' => true,
			),
			'checkboxes'  => array(
				'group'   => 'choice',
				'input'   => true,
				'options' => true,
			),
			'consent'     => array(
				'group' => 'choice',
				'input' => true,
			),
			'rating'      => array(
				'group' => 'choice',
				'input' => true,
			),
			'range'       => array(
				'group' => 'choice',
				'input' => true,
			),
			// Advanced.
			'date'        => array(
				'group' => 'advanced',
				'input' => true,
			),
			'time'        => array(
				'group' => 'advanced',
				'input' => true,
			),
			'address'     => array(
				'group' => 'advanced',
				'input' => true,
			),
			'file'        => array(
				'group' => 'advanced',
				'input' => true,
			),
			'signature'   => array(
				'group' => 'advanced',
				'input' => true,
			),
			'hidden'      => array(
				'group' => 'advanced',
				'input' => true,
			),
			'calculation' => array(
				'group' => 'advanced',
				'input' => true,
			),
			// Layout.
			'heading'     => array(
				'group'  => 'layout',
				'input'  => false,
				'layout' => true,
			),
			'paragraph'   => array(
				'group'  => 'layout',
				'input'  => false,
				'layout' => true,
			),
			'divider'     => array(
				'group'  => 'layout',
				'input'  => false,
				'layout' => true,
			),
			'step'        => array(
				'group'  => 'layout',
				'input'  => false,
				'layout' => true,
			),
			// Booking.
			'service'     => array(
				'group'   => 'booking',
				'input'   => true,
				'booking' => true,
				'single'  => true,
			),
			'resource'    => array(
				'group'   => 'booking',
				'input'   => true,
				'booking' => true,
				'single'  => true,
			),
			'appointment' => array(
				'group'   => 'booking',
				'input'   => true,
				'booking' => true,
				'single'  => true,
			),
		);

		/**
		 * Filter available field types (the Pro add-on registers more here).
		 *
		 * @param array $types Field types.
		 */
		return (array) apply_filters( 'bookora_field_types', $types );
	}

	/**
	 * Sanitize a complete field list.
	 *
	 * @param mixed  $fields    Raw fields from the builder.
	 * @param string $form_type standard|booking.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_fields( $fields, string $form_type ): array {
		$types = self::types();
		$out   = array();
		$keys  = array();
		$seen  = array();

		foreach ( array_slice( is_array( $fields ) ? $fields : array(), 0, self::MAX_FIELDS ) as $raw ) {
			if ( ! is_array( $raw ) || ! isset( $raw['type'], $types[ $raw['type'] ] ) ) {
				continue;
			}

			$type = $raw['type'];
			$def  = $types[ $type ];

			if ( ! empty( $def['booking'] ) && 'booking' !== $form_type ) {
				continue;
			}
			if ( ! empty( $def['single'] ) && isset( $seen[ $type ] ) ) {
				continue;
			}
			$seen[ $type ] = true;

			$field = array(
				'id'    => self::id( $raw['id'] ?? '' ),
				'type'  => $type,
				'width' => in_array( $raw['width'] ?? '', array( 'full', 'half', 'third' ), true ) ? $raw['width'] : 'full',
			);

			if ( empty( $def['layout'] ) || 'heading' === $type ) {
				$field['label'] = mb_substr( sanitize_text_field( (string) ( $raw['label'] ?? '' ) ), 0, 255 );
			}

			if ( ! empty( $def['input'] ) ) {
				$key = self::key( $raw['key'] ?? '', $field['label'] ?? $type, $keys );

				$keys[]                = $key;
				$field['key']          = $key;
				$field['required']     = ! empty( $raw['required'] );
				$field['help']         = mb_substr( sanitize_text_field( (string) ( $raw['help'] ?? '' ) ), 0, 500 );
				$field['placeholder']  = mb_substr( sanitize_text_field( (string) ( $raw['placeholder'] ?? '' ) ), 0, 255 );
				$field['defaultValue'] = mb_substr( sanitize_text_field( (string) ( $raw['defaultValue'] ?? '' ) ), 0, 1000 );
			}

			if ( ! empty( $def['options'] ) ) {
				$field['options'] = self::options( $raw['options'] ?? array() );
			}

			$field += self::type_settings( $type, $raw );

			$logic = self::logic( $raw['logic'] ?? null );
			if ( $logic ) {
				$field['logic'] = $logic;
			}

			$out[] = $field;
		}

		// Conditional rules may only reference fields that exist.
		$existing = array_column( $out, 'key' );
		foreach ( $out as &$field ) {
			if ( isset( $field['logic'] ) ) {
				$own                     = $field['key'] ?? null;
				$field['logic']['rules'] = array_values(
					array_filter( $field['logic']['rules'], static fn( array $r ): bool => in_array( $r['field'], $existing, true ) && $own !== $r['field'] )
				);
				if ( ! $field['logic']['rules'] ) {
					unset( $field['logic'] );
				}
			}
		}
		unset( $field );

		return $out;
	}

	/**
	 * Type-specific settings.
	 *
	 * @param string               $type Field type.
	 * @param array<string, mixed> $raw  Raw field.
	 * @return array<string, mixed>
	 */
	private static function type_settings( string $type, array $raw ): array {
		switch ( $type ) {
			case 'number':
			case 'range':
				$min  = is_numeric( $raw['min'] ?? null ) ? (float) $raw['min'] : ( 'range' === $type ? 0.0 : null );
				$max  = is_numeric( $raw['max'] ?? null ) ? (float) $raw['max'] : ( 'range' === $type ? 100.0 : null );
				$step = is_numeric( $raw['step'] ?? null ) && (float) $raw['step'] > 0 ? (float) $raw['step'] : 1.0;
				return array(
					'min'  => $min,
					'max'  => $max,
					'step' => $step,
				);
			case 'textarea':
				return array( 'rows' => max( 2, min( 20, (int) ( $raw['rows'] ?? 4 ) ) ) );
			case 'text':
				return array( 'maxLength' => max( 0, min( 10000, (int) ( $raw['maxLength'] ?? 0 ) ) ) );
			case 'select':
				return array( 'multiple' => ! empty( $raw['multiple'] ) );
			case 'radio':
			case 'checkboxes':
				return array( 'layout' => in_array( $raw['layout'] ?? '', array( 'vertical', 'inline' ), true ) ? $raw['layout'] : 'vertical' );
			case 'consent':
				return array( 'text' => wp_kses( (string) ( $raw['text'] ?? '' ), self::inline_html() ) );
			case 'rating':
				return array( 'max' => max( 3, min( 10, (int) ( $raw['max'] ?? 5 ) ) ) );
			case 'name':
				return array( 'format' => in_array( $raw['format'] ?? '', array( 'full', 'first_last' ), true ) ? $raw['format'] : 'first_last' );
			case 'address':
				return array( 'country' => ! empty( $raw['country'] ) );
			case 'date':
				return array(
					'minDate'         => self::relative_date( $raw['minDate'] ?? '' ),
					'maxDate'         => self::relative_date( $raw['maxDate'] ?? '' ),
					'workingDaysOnly' => ! empty( $raw['workingDaysOnly'] ),
					'withTime'        => ! empty( $raw['withTime'] ),
				);
			case 'file':
				$allowed = array_keys( FileStore::SAFE_TYPES );
				$ext     = array_values( array_intersect( array_map( 'strtolower', (array) ( $raw['extensions'] ?? array( 'jpg', 'png', 'pdf' ) ) ), $allowed ) );
				return array(
					'extensions' => $ext ? $ext : array( 'jpg', 'png', 'pdf' ),
					'maxSize'    => max( 1, min( 50, (int) ( $raw['maxSize'] ?? 5 ) ) ),
					'maxFiles'   => max( 1, min( 10, (int) ( $raw['maxFiles'] ?? 1 ) ) ),
				);
			case 'heading':
				return array( 'level' => in_array( (int) ( $raw['level'] ?? 3 ), array( 2, 3, 4 ), true ) ? (int) $raw['level'] : 3 );
			case 'paragraph':
				return array( 'content' => wp_kses( (string) ( $raw['content'] ?? '' ), self::inline_html() + array( 'p' => array() ) ) );
			case 'calculation':
				return array(
					'formula'  => Formula::sanitize( $raw['formula'] ?? '' ),
					'decimals' => max( 0, min( 4, (int) ( $raw['decimals'] ?? 2 ) ) ),
					'prefix'   => mb_substr( sanitize_text_field( (string) ( $raw['prefix'] ?? '' ) ), 0, 20 ),
					'suffix'   => mb_substr( sanitize_text_field( (string) ( $raw['suffix'] ?? '' ) ), 0, 20 ),
				);
			case 'step':
				return array( 'title' => mb_substr( sanitize_text_field( (string) ( $raw['title'] ?? '' ) ), 0, 100 ) );
			case 'resource':
				return array( 'allowAny' => ! array_key_exists( 'allowAny', $raw ) || ! empty( $raw['allowAny'] ) );
			case 'service':
				return array( 'serviceIds' => array_values( array_filter( array_map( 'intval', (array) ( $raw['serviceIds'] ?? array() ) ) ) ) );
			default:
				return array();
		}
	}

	/**
	 * Choice options: [{label, value}].
	 *
	 * @param mixed $options Raw options.
	 * @return array<int, array{label:string, value:string}>
	 */
	private static function options( $options ): array {
		$out = array();
		foreach ( array_slice( is_array( $options ) ? $options : array(), 0, self::MAX_OPTIONS ) as $option ) {
			$label = mb_substr( sanitize_text_field( (string) ( is_array( $option ) ? ( $option['label'] ?? '' ) : $option ) ), 0, 255 );
			$value = is_array( $option ) && isset( $option['value'] ) && '' !== $option['value'] ? (string) $option['value'] : $label;
			$value = mb_substr( sanitize_text_field( $value ), 0, 255 );
			if ( '' !== $label ) {
				$item = array(
					'label' => $label,
					'value' => $value,
				);
				// Optional number for calculated fields (e.g. price of an extra).
				$price = is_array( $option ) ? str_replace( ',', '.', (string) ( $option['price'] ?? '' ) ) : '';
				if ( is_numeric( $price ) ) {
					$item['price'] = round( (float) $price, 4 );
				}
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Conditional logic: {action: show|hide, match: all|any, rules: [{field, operator, value}]}.
	 *
	 * @param mixed $logic Raw logic.
	 * @return array<string, mixed>|null
	 */
	private static function logic( $logic ): ?array {
		if ( ! is_array( $logic ) || empty( $logic['rules'] ) || ! is_array( $logic['rules'] ) ) {
			return null;
		}

		$operators = array( 'is', 'is_not', 'contains', 'not_contains', 'empty', 'not_empty', 'greater', 'less' );
		$rules     = array();

		foreach ( array_slice( $logic['rules'], 0, 20 ) as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['field'] ) || ! in_array( $rule['operator'] ?? '', $operators, true ) ) {
				continue;
			}
			$rules[] = array(
				'field'    => sanitize_key( (string) $rule['field'] ),
				'operator' => $rule['operator'],
				'value'    => mb_substr( sanitize_text_field( (string) ( $rule['value'] ?? '' ) ), 0, 255 ),
			);
		}

		if ( ! $rules ) {
			return null;
		}

		return array(
			'action' => 'hide' === ( $logic['action'] ?? '' ) ? 'hide' : 'show',
			'match'  => 'any' === ( $logic['match'] ?? '' ) ? 'any' : 'all',
			'rules'  => $rules,
		);
	}

	/**
	 * Stable client id of a field.
	 *
	 * @param mixed $id Raw id.
	 */
	private static function id( $id ): string {
		$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $id );
		return $id ? substr( $id, 0, 40 ) : 'f' . wp_generate_password( 8, false, false );
	}

	/**
	 * Unique machine key used for entry values and placeholders like {first_name}.
	 *
	 * @param mixed    $key      Raw key.
	 * @param string   $fallback Label to derive a key from.
	 * @param string[] $taken    Keys already in use.
	 */
	private static function key( $key, string $fallback, array $taken ): string {
		$base = sanitize_key( str_replace( '-', '_', (string) $key ) );
		if ( '' === $base ) {
			$base = sanitize_key( str_replace( array( '-', ' ' ), '_', remove_accents( $fallback ) ) );
		}
		$base = substr( '' === $base ? 'field' : $base, 0, 50 );

		$candidate = $base;
		$i         = 2;
		while ( in_array( $candidate, $taken, true ) ) {
			$candidate = $base . '_' . $i;
			++$i;
		}
		return $candidate;
	}

	/**
	 * Allowed relative date expressions like "today", "+1 day", "+3 months" or an absolute Y-m-d.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function relative_date( $value ): string {
		$value = trim( (string) $value );
		if ( preg_match( '/^(today|[+-]\d{1,4} (day|days|week|weeks|month|months|year|years)|\d{4}-\d{2}-\d{2})$/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * Inline HTML allowed in consent texts and paragraphs.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function inline_html(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}
}
