<?php
/**
 * Validates and stores form submissions.
 *
 * @package Kalendora
 */

namespace Kalendora\Forms;

use Kalendora\Availability\AvailabilityService;
use Kalendora\Booking\Location;
use Kalendora\Booking\BookingService;
use Kalendora\Booking\CancellationPolicy;
use Kalendora\Install\Schema;
use Kalendora\Repository\ServiceRepository;
use Kalendora\Settings\Settings;
use DateTimeImmutable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side validation mirrors the field definitions; nothing the browser sends is trusted.
 * Fields hidden by conditional logic are ignored (not required, not stored).
 */
final class Submission {

	public const RATE_LIMIT     = 10;
	public const RATE_WINDOW    = 600;
	public const MIN_SECONDS    = 3;
	public const MAX_AGE        = 7 * DAY_IN_SECONDS; // Longer than typical page-cache lifetimes.
	public const MAX_PENDING    = 3;                  // Open requests per email address.
	public const MAX_PENDING_IP = 6;                // Open requests per IP (or IPv6 /64) within 24 hours.
	private const TOKEN_FIELD   = '_bk_t';

	/**
	 * Constructor.
	 *
	 * @param BookingService      $bookings     Bookings.
	 * @param AvailabilityService $availability Availability.
	 * @param ServiceRepository   $services     Services.
	 * @param Settings|null       $settings     Settings.
	 * @param FileStore|null      $files        File storage.
	 */
	public function __construct(
		private BookingService $bookings,
		private AvailabilityService $availability,
		private ServiceRepository $services,
		private ?Settings $settings = null,
		private ?FileStore $files = null,
	) {}

	/**
	 * Whether a render token is valid for a form (signature, age).
	 *
	 * @param mixed $token   Token.
	 * @param int   $form_id Form id.
	 */
	public static function valid_render_token( $token, int $form_id ): bool {
		$parts = explode( '.', is_string( $token ) ? $token : '' );
		return 2 === count( $parts ) && ctype_digit( $parts[0] ) && time() - (int) $parts[0] <= self::MAX_AGE && hash_equals( self::sign_token( $form_id, $parts[0] ), $parts[1] );
	}

	/**
	 * Signed render timestamp (anti-bot timing check that also works with page caching).
	 *
	 * @param int $form_id Form the token is valid for.
	 */
	public static function render_token( int $form_id = 0 ): string {
		$time = (string) time();
		return $time . '.' . self::sign_token( $form_id, $time );
	}

	/**
	 * Signature of a render token, bound to the form.
	 *
	 * @param int    $form_id Form id.
	 * @param string $time    Timestamp.
	 */
	private static function sign_token( int $form_id, string $time ): string {
		return substr( wp_hash( 'kalendora_render|' . $form_id . '|' . $time, 'nonce' ), 0, 16 );
	}

	/**
	 * Group IPv6 addresses by /64 (one connection usually owns a whole /64).
	 *
	 * @param string $ip IP address.
	 */
	public static function ip_bucket( string $ip ): string {
		if ( str_contains( $ip, ':' ) ) {
			$bin = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input returns false.
			if ( false !== $bin && 16 === strlen( $bin ) ) {
				return bin2hex( substr( $bin, 0, 8 ) ) . '::/64';
			}
		}
		return $ip;
	}

	/**
	 * Process a submission.
	 *
	 * @param array<string, mixed>  $form   Form row (hydrated).
	 * @param array<string, mixed>  $input  Raw request data.
	 * @param array<string, string> $meta  ip, user_agent, source_url, locale, timezone.
	 * @return array{entry_id:int, booking?:array<string, mixed>, message:string, redirect:string}|WP_Error
	 */
	public function handle( array $form, array $input, array $meta ) {
		$spam = $this->spam_check( $input, $meta, (int) $form['id'] );
		if ( is_wp_error( $spam ) ) {
			return $spam;
		}

		$values = (array) ( $input['fields'] ?? array() );
		$fields = (array) $form['fields'];
		$clean  = array();
		$errors = array();
		$attach = array();
		// Keys of visible calculated fields.
		$calculated = array();

		foreach ( $fields as $field ) {
			if ( empty( $field['key'] ) || ! self::is_visible( $field, $values, $fields ) ) {
				continue;
			}
			if ( in_array( $field['type'], array( 'service', 'resource', 'appointment' ), true ) ) {
				continue;
			}
			if ( 'calculation' === $field['type'] ) {
				// Never trust the browser: computed below from the other values.
				$calculated[ $field['key'] ] = true;
				continue;
			}
			if ( in_array( $field['type'], array( 'file', 'signature' ), true ) ) {
				$result = $this->file_value( $form, $field, $values[ $field['key'] ] ?? null, (string) ( $meta['ip'] ?? '' ) );
				if ( is_wp_error( $result ) ) {
					$errors[ $field['key'] ] = $result->get_error_message();
				} elseif ( null !== $result ) {
					$clean[ $field['key'] ] = $result['value'];
					$attach                 = array_merge( $attach, $result['ids'] );
				}
				continue;
			}

			$result = self::validate_field( $field, $values[ $field['key'] ] ?? null );
			if ( ! is_wp_error( $result ) && 'date' === $field['type'] && ! empty( $field['workingDaysOnly'] ) && is_string( $result ) && '' !== $result ) {
				$result = $this->check_opening( $result, ! empty( $field['withTime'] ) );
			}
			if ( is_wp_error( $result ) ) {
				$errors[ $field['key'] ] = $result->get_error_message();
			} elseif ( null !== $result && '' !== $result && array() !== $result ) {
				$clean[ $field['key'] ] = $result;
			}
		}

		$booking_input = null;
		if ( 'booking' === $form['type'] ) {
			$booking_input = $this->booking_input( $form, $values, $errors );
		}

		if ( $calculated ) {
			$extra   = array();
			$service = $booking_input ? $this->services->find( (int) $booking_input['service_id'] ) : null;
			if ( $service ) {
				$extra['service_price']    = (float) $service['price'];
				$extra['service_duration'] = (float) $service['duration'];
				$extra['seats']            = (float) ( $booking_input['seats'] ?? 1 );
			}
			$by_key  = array_column( $fields, null, 'key' );
			$results = Formula::compute( $fields, $clean + $calculated, $extra );
			foreach ( $results as $key => $number ) {
				$clean[ $key ] = Formula::format( $by_key[ $key ], $number );
			}
		}

		/**
		 * Filter validation errors of a submission (add own rules).
		 *
		 * @param array<string, string> $errors Errors by field key ("_form" for the whole form).
		 * @param array<string, mixed>  $clean  Sanitized values.
		 * @param array<string, mixed>  $form   Form.
		 */
		$errors = (array) apply_filters( 'kalendora_validate_submission', $errors, $clean, $form );

		if ( $errors ) {
			return new WP_Error(
				'kalendora_validation',
				__( 'Please check the highlighted fields.', 'kalendora' ),
				array(
					'status' => 422,
					'fields' => $errors,
				)
			);
		}

		$email = self::first_of_type( $fields, $clean, 'email' );
		$name  = self::first_of_type( $fields, $clean, 'name' );
		$phone = self::first_of_type( $fields, $clean, 'phone' );
		$name  = is_array( $name ) ? trim( implode( ' ', $name ) ) : (string) $name;

		$settings = (array) ( $form['settings'] ?? array() );
		$message  = '' !== (string) ( $settings['successMessage'] ?? '' )
			? (string) $settings['successMessage']
			: ( 'booking' === $form['type']
				? __( 'Thank you! Your booking request has been received. You will receive a confirmation by email.', 'kalendora' )
				: __( 'Thank you! We have received your message and will get back to you soon.', 'kalendora' ) );

		if ( null !== $booking_input ) {
			if ( ! is_email( (string) $email ) ) {
				return new WP_Error( 'kalendora_validation', __( 'An email address is required to book an appointment.', 'kalendora' ), array( 'status' => 422 ) );
			}

			$flood = $this->too_many_open( (string) $email, (string) ( $meta['ip'] ?? '' ) );
			if ( is_wp_error( $flood ) ) {
				return $flood;
			}

			/**
			 * Booking data before it is stored (e.g. keep it unconfirmed until it is paid).
			 *
			 * @param array<string, mixed> $booking_input service_id, resource_id, start, seats, status …
			 * @param array<string, mixed> $form          Form.
			 * @param array<string, mixed> $clean         Sanitized values.
			 */
			$booking_input = (array) apply_filters( 'kalendora_booking_input', $booking_input, $form, $clean );

			$result = $this->bookings->create(
				$booking_input + array(
					'form_id'    => (int) $form['id'],
					'customer'   => array(
						'email' => (string) $email,
						'name'  => $name,
						'phone' => (string) $phone,
					),
					'values'     => self::flatten( $clean ),
					'timezone'   => $meta['timezone'] ?? '',
					'locale'     => $meta['locale'] ?? '',
					'source_url' => self::own_url( $meta['source_url'] ?? '' ),
					'ip'         => $meta['ip'] ?? '',
					'user_agent' => $meta['user_agent'] ?? '',
				)
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$this->store()->attach( $attach, (int) $result['entry_id'] );

			/**
			 * Answer to the visitor after a booking (e.g. send them to a payment page).
			 *
			 * @param array<string, mixed> $response   entry_id, booking, message, redirect.
			 * @param int                  $booking_id Booking id.
			 * @param array<string, mixed> $form       Form.
			 */
			return (array) apply_filters(
				'kalendora_booking_response',
				array(
					'entry_id' => $result['entry_id'],
					'booking'  => array(
						'uid'    => $result['uid'],
						'status' => $result['status'],
					),
					'message'  => $message,
					'redirect' => (string) ( $settings['redirectUrl'] ?? '' ),
				),
				(int) $result['booking_id'],
				$form
			);
		}

		$entry_id = 0;
		if ( ! array_key_exists( 'storeEntries', $settings ) || ! empty( $settings['storeEntries'] ) ) {
			$entry_id = $this->store_entry( (int) $form['id'], self::flatten( $clean ), (string) $email, $name, (string) $phone, $meta );
			if ( ! $entry_id ) {
				return new WP_Error( 'kalendora_db_error', __( 'Your message could not be saved. Please try again.', 'kalendora' ), array( 'status' => 500 ) );
			}
		}

		$this->store()->attach( $attach, $entry_id );

		/**
		 * Fires after a standard form was submitted successfully.
		 *
		 * @param int   $entry_id Entry id (0 if entries are not stored).
		 * @param array $values   Sanitized values.
		 * @param array $form     Form.
		 */
		do_action( 'kalendora_entry_created', $entry_id, $clean, $form );

		return array(
			'entry_id' => $entry_id,
			'message'  => $message,
			'redirect' => (string) ( $settings['redirectUrl'] ?? '' ),
		);
	}

	/**
	 * File storage.
	 */
	private function store(): FileStore {
		$this->files ??= new FileStore();
		return $this->files;
	}

	/**
	 * Validate a file or signature field.
	 *
	 * @param array<string, mixed> $form  Form.
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $raw   Raw value (tokens, data URL or "typed:Name").
	 * @param string               $ip    Client IP.
	 * @return array{value:mixed, ids:int[]}|WP_Error|null Null = empty.
	 */
	private function file_value( array $form, array $field, $raw, string $ip ) {
		$required = ! empty( $field['required'] );

		if ( 'file' === $field['type'] ) {
			$rows = $this->store()->claimable( $raw, (int) $form['id'], (string) $field['key'], max( 1, min( 10, (int) ( $field['maxFiles'] ?? 1 ) ) ) );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			if ( ! $rows ) {
				return $required ? new WP_Error( 'required', __( 'Please upload a file.', 'kalendora' ) ) : null;
			}
			return array(
				'value' => array_column( $rows, 'name' ),
				'ids'   => array_map( 'intval', array_column( $rows, 'id' ) ),
			);
		}

		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( '' === $raw ) {
			return $required ? new WP_Error( 'required', __( 'Please sign.', 'kalendora' ) ) : null;
		}
		if ( str_starts_with( $raw, 'typed:' ) ) {
			$name = trim( sanitize_text_field( substr( $raw, 6 ) ) );
			if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 100 ) {
				return new WP_Error( 'invalid', __( 'Please type your full name.', 'kalendora' ) );
			}
			return array(
				/* translators: %s: typed name. */
				'value' => sprintf( __( '%s (typed signature)', 'kalendora' ), $name ),
				'ids'   => array(),
			);
		}
		$stored = $this->store()->store_signature( (int) $form['id'], (string) $field['key'], $raw, $ip );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		return array(
			'value' => __( 'Signed (image)', 'kalendora' ),
			'ids'   => array( (int) $stored['id'] ),
		);
	}

	/**
	 * Ensure a date (and optional time) lies within the opening hours.
	 *
	 * @param string $value     Y-m-d or Y-m-d H:i.
	 * @param bool   $with_time Whether a time is included.
	 * @return string|WP_Error
	 */
	private function check_opening( string $value, bool $with_time ) {
		$day  = substr( $value, 0, 10 );
		$open = $this->availability->opening_days( $day, $day );

		if ( is_wp_error( $open ) || empty( $open[ $day ] ) ) {
			return new WP_Error( 'invalid', __( 'Please choose one of the available days.', 'kalendora' ) );
		}
		if ( $with_time && ! in_array( substr( $value, 11, 5 ), $open[ $day ], true ) ) {
			return new WP_Error( 'invalid', __( 'Please choose one of the available times.', 'kalendora' ) );
		}
		return $value;
	}

	/**
	 * Extract and validate service, resource and appointment for booking forms.
	 *
	 * @param array<string, mixed>  $form   Form.
	 * @param array<string, mixed>  $values Raw values.
	 * @param array<string, string> $errors Errors (by reference).
	 * @return array{policy_accepted:bool, service_id:int, resource_id:int|null, start:int, seats:int, location:array<string, mixed>}|null
	 */
	private function booking_input( array $form, array $values, array &$errors ): ?array {
		$fields      = (array) $form['fields'];
		$by_type     = array_column( $fields, null, 'type' );
		$appointment = $by_type['appointment'] ?? null;

		if ( ! $appointment ) {
			$errors['_form'] = __( 'This booking form is not configured yet.', 'kalendora' );
			return null;
		}

		// Service: from the service field, otherwise the only/first active service.
		$service_id = 0;
		if ( isset( $by_type['service'] ) ) {
			$service_id = absint( $values[ $by_type['service']['key'] ] ?? 0 );
			$allowed    = (array) ( $by_type['service']['serviceIds'] ?? array() );
			if ( ! $service_id || ( $allowed && ! in_array( $service_id, $allowed, true ) ) ) {
				$errors[ $by_type['service']['key'] ] = __( 'Please choose a service.', 'kalendora' );
				return null;
			}
		} else {
			$active     = $this->services->all( array( 'status' => 'active' ) );
			$service_id = $active ? (int) $active[0]['id'] : 0;
		}

		$service = $service_id ? $this->services->find( $service_id ) : null;
		if ( ! $service || 'active' !== $service['status'] ) {
			$errors[ $by_type['service']['key'] ?? '_form' ] = __( 'This service is not available.', 'kalendora' );
			return null;
		}

		$resource_id = null;
		if ( isset( $by_type['resource'] ) ) {
			$resource_id = absint( $values[ $by_type['resource']['key'] ] ?? 0 );
			$resource_id = $resource_id ? $resource_id : null;
			if ( null === $resource_id && false === ( $by_type['resource']['allowAny'] ?? true ) ) {
				$errors[ $by_type['resource']['key'] ] = __( 'Please choose a staff member.', 'kalendora' );
				return null;
			}
		}

		$raw   = $values[ $appointment['key'] ] ?? '';
		$start = is_array( $raw ) ? (string) ( $raw['start'] ?? '' ) : (string) $raw;
		$time  = strtotime( $start );
		if ( ! $start || false === $time ) {
			$errors[ $appointment['key'] ] = __( 'Please choose a date and time.', 'kalendora' );
			return null;
		}

		if ( null === $resource_id && is_array( $raw ) && ! empty( $raw['resource'] ) && isset( $by_type['resource'] ) ) {
			$resource_id = absint( $raw['resource'] );
		}

		// Where the appointment takes place (customer address, office, remote) incl. travel time.
		$place = array();
		if ( Location::options( $service ) ) {
			$place = Location::resolve(
				$service,
				is_array( $raw ) ? (string) ( $raw['place'] ?? '' ) : '',
				is_array( $raw ) ? (string) ( $raw['address'] ?? '' ) : ''
			);
			if ( is_wp_error( $place ) ) {
				$errors[ $appointment['key'] ] = $place->get_error_message();
				return null;
			}
		}

		// Cancellation fee rule: the customer has to accept it explicitly.
		$rule     = CancellationPolicy::for_service( $service, ( $this->settings ?? new Settings() )->all() );
		$accepted = false;
		if ( $rule['fee'] ) {
			$accepted = ! empty( $values['_policy'] ) && is_scalar( $values['_policy'] );
			if ( ! $accepted ) {
				$errors['_policy'] = __( 'Please accept the cancellation terms.', 'kalendora' );
				return null;
			}
		}

		return array(
			'policy_accepted' => $accepted,
			'service_id'      => $service_id,
			'resource_id'     => $resource_id,
			'start'           => (int) $time,
			'seats'           => max( 1, min( (int) $service['capacity'], absint( $values['_seats'] ?? 1 ) ) ),
			'location'        => $place,
		);
	}

	/**
	 * Validate one value.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Raw value.
	 * @return mixed|WP_Error Clean value (null/'' when empty).
	 */
	public static function validate_field( array $field, $value ) {
		$required = ! empty( $field['required'] );
		$label    = (string) ( $field['label'] ?? $field['key'] );
		$missing  = new WP_Error( 'required', __( 'This field is required.', 'kalendora' ) );

		switch ( $field['type'] ) {
			case 'name':
				if ( is_array( $value ) ) {
					$first = mb_substr( sanitize_text_field( (string) ( $value['first'] ?? '' ) ), 0, 100 );
					$last  = mb_substr( sanitize_text_field( (string) ( $value['last'] ?? '' ) ), 0, 100 );
					if ( $required && ( '' === $first || '' === $last ) ) {
						return $missing;
					}
					return '' === $first . $last ? null : array(
						'first' => $first,
						'last'  => $last,
					);
				}
				$value = mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, 200 );
				return ( $required && '' === $value ) ? $missing : $value;

			case 'address':
				$parts = array();
				foreach ( array( 'street', 'zip', 'city', 'country' ) as $part ) {
					$parts[ $part ] = mb_substr( sanitize_text_field( (string) ( is_array( $value ) ? ( $value[ $part ] ?? '' ) : '' ) ), 0, 200 );
				}
				if ( $required && ( '' === $parts['street'] || '' === $parts['city'] ) ) {
					return $missing;
				}
				return '' === implode( '', $parts ) ? null : array_filter( $parts );

			case 'checkboxes':
				$allowed = array_column( (array) ( $field['options'] ?? array() ), 'value' );
				$picked  = array_values( array_intersect( array_map( 'strval', (array) $value ), $allowed ) );
				return ( $required && ! $picked ) ? $missing : $picked;

			case 'select':
			case 'radio':
				$allowed = array_column( (array) ( $field['options'] ?? array() ), 'value' );
				if ( ! empty( $field['multiple'] ) ) {
					$picked = array_values( array_intersect( array_map( 'strval', (array) $value ), $allowed ) );
					return ( $required && ! $picked ) ? $missing : $picked;
				}
				$value = is_scalar( $value ) ? (string) $value : '';
				if ( '' === $value ) {
					return $required ? $missing : null;
				}
				return in_array( $value, $allowed, true ) ? $value : new WP_Error( 'invalid', __( 'Please choose one of the options.', 'kalendora' ) );

			case 'consent':
				return empty( $value ) ? ( $required ? new WP_Error( 'required', __( 'Please confirm to continue.', 'kalendora' ) ) : null ) : '1';

			case 'file':
			case 'signature':
				// File uploads and signatures are handled by the upload endpoint (next development step).
				return null;
		}

		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return $required ? $missing : null;
		}

		switch ( $field['type'] ) {
			case 'email':
				$email = sanitize_email( $value );
				return is_email( $email ) ? $email : new WP_Error( 'invalid', __( 'Please enter a valid email address.', 'kalendora' ) );

			case 'url':
				$url = esc_url_raw( $value, array( 'http', 'https' ) );
				return $url ? $url : new WP_Error( 'invalid', __( 'Please enter a valid web address.', 'kalendora' ) );

			case 'phone':
				return preg_match( '/^[0-9 +()\/.\-]{5,30}$/', $value ) ? $value : new WP_Error( 'invalid', __( 'Please enter a valid phone number.', 'kalendora' ) );

			case 'number':
			case 'range':
			case 'rating':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error( 'invalid', __( 'Please enter a number.', 'kalendora' ) );
				}
				$num = (float) $value;
				$min = 'rating' === $field['type'] ? 1 : ( $field['min'] ?? null );
				$max = $field['max'] ?? null;
				if ( ( null !== $min && $num < (float) $min ) || ( null !== $max && $num > (float) $max ) ) {
					/* translators: 1: minimum, 2: maximum. */
					return new WP_Error( 'invalid', sprintf( __( 'Please enter a value between %1$s and %2$s.', 'kalendora' ), $min ?? '–', $max ?? '–' ) );
				}
				$step = (float) ( $field['step'] ?? 0 );
				if ( 'number' === $field['type'] && $step > 0 ) {
					$steps = ( $num - (float) ( $min ?? 0 ) ) / $step;
					if ( abs( $steps - round( $steps ) ) > 1e-6 ) {
						/* translators: %s: step, e.g. 0.5 */
						return new WP_Error( 'invalid', sprintf( __( 'Please enter a value in steps of %s.', 'kalendora' ), (string) $step ) );
					}
				}
				return (string) ( 0.0 === fmod( $num, 1.0 ) ? (int) $num : $num );

			case 'date':
				$format = ! empty( $field['withTime'] ) ? 'Y-m-d H:i' : 'Y-m-d';
				$date   = DateTimeImmutable::createFromFormat( '!' . $format, $value );
				if ( ! $date || $date->format( $format ) !== $value ) {
					return new WP_Error( 'invalid', __( 'Please choose a valid date.', 'kalendora' ) );
				}
				// Same limits as the date picker; one day tolerance for visitors in other time zones.
				$day = substr( $value, 0, 10 );
				foreach ( array(
					'minDate' => -1,
					'maxDate' => 1,
				) as $limit => $tolerance ) {
					$bound = self::bound_date( (string) ( $field[ $limit ] ?? '' ), $tolerance );
					if ( null !== $bound && ( 'minDate' === $limit ? $day < $bound : $day > $bound ) ) {
						return new WP_Error( 'invalid', __( 'This date cannot be chosen.', 'kalendora' ) );
					}
				}
				return $value;

			case 'time':
				return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : new WP_Error( 'invalid', __( 'Please enter a valid time.', 'kalendora' ) );

			case 'textarea':
				return mb_substr( sanitize_textarea_field( $value ), 0, 10000 );

			case 'text':
				$max = (int) ( $field['maxLength'] ?? 0 );
				$val = sanitize_text_field( $value );
				return $max > 0 && mb_strlen( $val ) > $max
					/* translators: %d: maximum number of characters. */
					? new WP_Error( 'invalid', sprintf( __( 'Please use at most %d characters.', 'kalendora' ), $max ) )
					: mb_substr( $val, 0, 1000 );

			default:
				unset( $label );
				return mb_substr( sanitize_text_field( $value ), 0, 1000 );
		}
	}

	/**
	 * Evaluate conditional logic for a field.
	 *
	 * @param array<string, mixed>             $field  Field.
	 * @param array<string, mixed>             $values Raw values.
	 * @param array<int, array<string, mixed>> $fields All fields (for nested visibility).
	 * @param int                              $depth  Recursion guard.
	 */
	public static function is_visible( array $field, array $values, array $fields = array(), int $depth = 0 ): bool {
		if ( empty( $field['logic']['rules'] ) || $depth > 10 ) {
			return true;
		}

		$by_key  = array_column( $fields, null, 'key' );
		$results = array();

		foreach ( $field['logic']['rules'] as $rule ) {
			$source = $by_key[ $rule['field'] ] ?? null;

			// A rule on a hidden field counts as "empty".
			$value = ( $source && ! self::is_visible( $source, $values, $fields, $depth + 1 ) ) ? '' : ( $values[ $rule['field'] ] ?? '' );

			$results[] = self::rule_matches( $rule['operator'], $value, (string) $rule['value'] );
		}

		$matched = 'any' === ( $field['logic']['match'] ?? 'all' ) ? in_array( true, $results, true ) : ! in_array( false, $results, true );

		return 'hide' === ( $field['logic']['action'] ?? 'show' ) ? ! $matched : $matched;
	}

	/**
	 * Compare one value against a rule.
	 *
	 * @param string $operator Operator.
	 * @param mixed  $value    Submitted value (string or array).
	 * @param string $expected Expected value.
	 */
	public static function rule_matches( string $operator, $value, string $expected ): bool {
		$scalar = static fn( $v ): string => is_scalar( $v ) ? (string) $v : '';
		$list   = is_array( $value ) ? array_map( $scalar, array_values( $value ) ) : array( $scalar( $value ) );
		$text   = implode( ' ', $list );
		$empty  = '' === trim( $text );

		switch ( $operator ) {
			case 'is':
				return in_array( $expected, $list, true );
			case 'is_not':
				return ! in_array( $expected, $list, true );
			case 'contains':
				return '' !== $expected && false !== mb_stripos( $text, $expected );
			case 'not_contains':
				return '' === $expected || false === mb_stripos( $text, $expected );
			case 'empty':
				return $empty;
			case 'not_empty':
				return ! $empty;
			case 'greater':
				return is_numeric( $text ) && is_numeric( $expected ) && (float) $text > (float) $expected;
			case 'less':
				return is_numeric( $text ) && is_numeric( $expected ) && (float) $text < (float) $expected;
		}
		return false;
	}

	/**
	 * Honeypot, timing token and rate limit.
	 *
	 * @param array<string, mixed>  $input   Raw input.
	 * @param array<string, string> $meta    Meta.
	 * @param int                   $form_id Form the token must belong to.
	 * @return true|WP_Error
	 */
	private function spam_check( array $input, array $meta, int $form_id ) {
		if ( ! empty( $input['website_url_hp'] ) ) {
			return new WP_Error( 'kalendora_spam', __( 'Your submission could not be processed.', 'kalendora' ), array( 'status' => 400 ) );
		}

		$token = is_string( $input[ self::TOKEN_FIELD ] ?? null ) ? $input[ self::TOKEN_FIELD ] : '';
		$parts = explode( '.', $token );
		if ( ! self::valid_render_token( $token, $form_id ) ) {
			return new WP_Error( 'kalendora_spam', __( 'The form has expired. Please reload the page and try again.', 'kalendora' ), array( 'status' => 400 ) );
		}

		/**
		 * Minimum seconds between page load and submission.
		 *
		 * @param int $seconds Seconds.
		 */
		$min = (int) apply_filters( 'kalendora_min_submit_seconds', self::MIN_SECONDS );
		if ( time() - (int) $parts[0] < $min ) {
			return new WP_Error( 'kalendora_spam', __( 'That was a bit too fast. Please wait a moment and submit again.', 'kalendora' ), array( 'status' => 429 ) );
		}

		$ip = self::ip_bucket( (string) ( $meta['ip'] ?? '' ) );
		if ( '' !== $ip ) {
			$key   = 'kalendora_rl_' . substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 32 );
			$count = (int) get_transient( $key );
			$limit = (int) apply_filters( 'kalendora_rate_limit', self::RATE_LIMIT );
			if ( $count >= $limit ) {
				return new WP_Error( 'kalendora_rate_limited', __( 'Too many submissions. Please try again in a few minutes.', 'kalendora' ), array( 'status' => 429 ) );
			}
			set_transient( $key, $count + 1, self::RATE_WINDOW );
		}

		/**
		 * Additional spam checks (e.g. CAPTCHA). Return a WP_Error to reject.
		 *
		 * @param true|WP_Error        $result  Result so far.
		 * @param array<string, mixed> $input   Submitted data.
		 * @param array<string, mixed> $meta    ip, user_agent, …
		 */
		return apply_filters( 'kalendora_spam_check', true, $input, $meta );
	}

	/**
	 * Store an entry of a standard form.
	 *
	 * @param int                   $form_id Form id.
	 * @param array<string, string> $values  Flat values.
	 * @param string                $email   Customer email.
	 * @param string                $name    Customer name.
	 * @param string                $phone   Customer phone.
	 * @param array<string, string> $meta    Meta.
	 */
	private function store_entry( int $form_id, array $values, string $email, string $name, string $phone, array $meta ): int {
		global $wpdb;

		$now         = current_time( 'mysql', true );
		$customer_id = null;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$wpdb->query( 'START TRANSACTION' );

		if ( is_email( $email ) ) {
			$customers   = Schema::table( $wpdb->prefix, 'customers' );
			$customer_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$customers} WHERE email = %s", $email ) );
			if ( ! $customer_id ) {
				$wpdb->insert(
					$customers,
					array(
						'email'      => $email,
						'name'       => mb_substr( $name, 0, 191 ),
						'phone'      => mb_substr( $phone, 0, 50 ),
						'locale'     => mb_substr( (string) ( $meta['locale'] ?? '' ), 0, 20 ),
						'created_at' => $now,
						'updated_at' => $now,
					)
				);
				$customer_id = (int) $wpdb->insert_id;
			}
		}

		$ok = $wpdb->insert(
			Schema::table( $wpdb->prefix, 'entries' ),
			array(
				'uid'         => wp_generate_uuid4(),
				'form_id'     => $form_id,
				'customer_id' => $customer_id ? $customer_id : null,
				'status'      => 'new',
				'ip_hash'     => empty( $meta['ip'] ) ? '' : hash( 'sha256', $meta['ip'] . wp_salt( 'nonce' ) ),
				'user_agent'  => mb_substr( sanitize_text_field( (string) ( $meta['user_agent'] ?? '' ) ), 0, 255 ),
				'source_url'  => mb_substr( self::own_url( $meta['source_url'] ?? '' ), 0, 2048 ),
				'locale'      => mb_substr( (string) ( $meta['locale'] ?? '' ), 0, 20 ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);

		$entry_id = $ok ? (int) $wpdb->insert_id : 0;
		if ( ! $entry_id ) {
			$wpdb->query( 'ROLLBACK' );
			return 0;
		}

		foreach ( $values as $key => $value ) {
			$wpdb->insert(
				Schema::table( $wpdb->prefix, 'entry_values' ),
				array(
					'entry_id'  => $entry_id,
					'field_key' => $key,
					'value'     => $value,
				)
			);
		}

		$wpdb->query( 'COMMIT' );
		// phpcs:enable

		return $entry_id;
	}

	/**
	 * Arrays become JSON for storage.
	 *
	 * @param array<string, mixed> $values Values.
	 * @return array<string, string>
	 */
	private static function flatten( array $values ): array {
		return array_map( static fn( $v ) => is_array( $v ) ? (string) wp_json_encode( $v ) : (string) $v, $values );
	}

	/**
	 * First clean value of a field type.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields.
	 * @param array<string, mixed>             $clean  Clean values.
	 * @param string                           $type   Type.
	 * @return mixed
	 */
	private static function first_of_type( array $fields, array $clean, string $type ) {
		foreach ( $fields as $field ) {
			if ( $type === $field['type'] && isset( $field['key'], $clean[ $field['key'] ] ) ) {
				return $clean[ $field['key'] ];
			}
		}
		return '';
	}

	/**
	 * Limit open (unconfirmed) requests so nobody can fill the calendar or misuse the
	 * confirmation emails to contact strangers.
	 *
	 * @param string $email Customer email.
	 * @param string $ip    Client IP.
	 * @return true|WP_Error
	 */
	private function too_many_open( string $email, string $ip ) {
		global $wpdb;
		$bookings  = Schema::table( $wpdb->prefix, 'bookings' );
		$customers = Schema::table( $wpdb->prefix, 'customers' );
		$entries   = Schema::table( $wpdb->prefix, 'entries' );
		$now       = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names.
		$by_mail = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bookings} b INNER JOIN {$customers} c ON c.id = b.customer_id WHERE c.email = %s AND b.status = 'pending' AND b.end_utc > %s", $email, $now ) );
		$by_ip   = 0;
		if ( '' !== $ip ) {
			$by_ip = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bookings} b INNER JOIN {$entries} e ON e.id = b.entry_id WHERE e.ip_hash = %s AND b.status = 'pending' AND b.created_at > %s", hash( 'sha256', $ip . wp_salt( 'nonce' ) ), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		}
		// phpcs:enable

		/**
		 * Maximum open requests per email address / per IP within 24 hours.
		 *
		 * @param array{email:int, ip:int} $limits Limits.
		 */
		$limits = (array) apply_filters(
			'kalendora_open_request_limits',
			array(
				'email' => self::MAX_PENDING,
				'ip'    => self::MAX_PENDING_IP,
			)
		);
		if ( $by_mail >= (int) $limits['email'] || $by_ip >= (int) $limits['ip'] ) {
			return new WP_Error( 'kalendora_too_many_open', __( 'You already have several open requests. Please wait for our confirmation or contact us directly.', 'kalendora' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Only keep "sent from" addresses of this website (the value comes from the browser).
	 *
	 * @param mixed $url URL.
	 */
	private static function own_url( $url ): string {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ? $url : '';
	}

	/**
	 * Resolve a relative date limit ("today", "+2 weeks", "2026-12-31") to Y-m-d.
	 *
	 * @param string $expr      Expression.
	 * @param int    $tolerance Days added (time-zone tolerance).
	 */
	private static function bound_date( string $expr, int $tolerance ): ?string {
		if ( '' === $expr ) {
			return null;
		}
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		$date  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expr ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $expr, wp_timezone() ) : ( 'today' === $expr ? $today : $today->modify( $expr ) );
		return $date ? $date->modify( sprintf( '%+d days', $tolerance ) )->format( 'Y-m-d' ) : null;
	}
}
