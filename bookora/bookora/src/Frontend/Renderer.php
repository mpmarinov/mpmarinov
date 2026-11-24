<?php
/**
 * Renders a form for visitors.
 *
 * @package Bookora
 */

namespace Bookora\Frontend;

use Bookora\Booking\CancellationPolicy;
use Bookora\Forms\Submission;
use Bookora\Repository\FormRepository;
use Bookora\Repository\ResourceRepository;
use Bookora\Repository\ServiceRepository;
use Bookora\Security\Capabilities;
use Bookora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side HTML (accessible, works before JavaScript loads); frontend.js adds
 * calendar, conditional logic, steps and AJAX submission.
 */
final class Renderer {

	/**
	 * Inline scripts collected for the footer.
	 *
	 * @var array<int, string>
	 */
	private array $scripts = array();

	/**
	 * Whether a form was rendered on this page.
	 *
	 * @var bool
	 */
	private bool $rendered = false;

	/**
	 * Whether the global CSS was already attached.
	 *
	 * @var bool
	 */
	private bool $global_css_added = false;

	/**
	 * Cancellation terms per service (request cache).
	 *
	 * @var array<int, string>|null
	 */
	private ?array $policy_cache = null;

	/**
	 * Constructor.
	 *
	 * @param FormRepository     $forms     Forms.
	 * @param ServiceRepository  $services  Services.
	 * @param ResourceRepository $resources Resources.
	 * @param Settings           $settings  Settings.
	 */
	public function __construct(
		private FormRepository $forms,
		private ServiceRepository $services,
		private ResourceRepository $resources,
		private Settings $settings,
	) {}

	/**
	 * Register shortcode and block.
	 */
	public function register(): void {
		add_shortcode( 'bookora', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'print_custom_scripts' ), 100 );
	}

	/**
	 * Register frontend assets (enqueued only when a form is rendered).
	 */
	public function register_assets(): void {
		$asset_file = BOOKORA_DIR . 'build/frontend/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_register_script( 'bookora-frontend', BOOKORA_URL . 'build/frontend/index.js', $asset['dependencies'], $asset['version'], array( 'strategy' => 'defer' ) );
		wp_set_script_translations( 'bookora-frontend', 'bookora', BOOKORA_DIR . 'languages' );

		foreach ( array( 'style-index', 'index' ) as $css ) {
			if ( is_readable( BOOKORA_DIR . 'build/frontend/' . $css . '.css' ) ) {
				wp_register_style( 'bookora-frontend', BOOKORA_URL . 'build/frontend/' . $css . '.css', array(), $asset['version'] );
				break;
			}
		}
	}

	/**
	 * Dynamic block "bookora/form" (server-rendered).
	 */
	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			'bookora/form',
			array(
				'api_version'     => 3,
				'title'           => 'Bookora',
				'category'        => 'widgets',
				'icon'            => 'calendar-alt',
				'attributes'      => array(
					'formId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'render_callback' => fn( array $attrs ): string => $this->render( (int) ( $attrs['formId'] ?? 0 ) ),
			)
		);
	}

	/**
	 * Shortcode [bookora id="1"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'bookora' );
		return $this->render( (int) $atts['id'] );
	}

	/**
	 * Render a form.
	 *
	 * @param int $form_id Form id.
	 */
	public function render( int $form_id ): string {
		$form       = $form_id ? $this->forms->find( $form_id ) : null;
		$can_manage = current_user_can( Capabilities::MANAGE_FORMS );

		if ( ! $form || ( 'published' !== $form['status'] && ! $can_manage ) ) {
			return $can_manage ? '<p class="bookora-notice">' . esc_html__( 'Bookora: this form does not exist or is not published.', 'bookora' ) . '</p>' : '';
		}

		wp_enqueue_script( 'bookora-frontend' );
		wp_enqueue_style( 'bookora-frontend' );

		$settings       = FormRepository::sanitize_settings( $form['settings'] );
		$fields         = (array) $form['fields'];
		$site           = $this->settings->all();
		$config         = $this->config( $form, $fields, $site );
		$classes        = array_merge(
			array(
				'bookora',
				'bookora-form-' . $form['id'],
				'bookora--' . $form['type'],
				'bookora--labels-' . $settings['labelPlacement'],
			),
			self::design_classes( $site )
		);
		$this->rendered = true;

		$steps   = array( array() );
		$titles  = array( '' );
		$current = 0;
		foreach ( $fields as $field ) {
			if ( 'step' === $field['type'] ) {
				++$current;
				$steps[ $current ]  = array();
				$titles[ $current ] = (string) ( $field['title'] ?? '' );
				continue;
			}
			$steps[ $current ][] = $field;
		}
		$multi = count( $steps ) > 1;

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-bookora="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>" style="<?php echo esc_attr( self::design_style( $site ) ); ?>">
			<?php if ( 'published' !== $form['status'] ) : ?>
				<p class="bookora__draft"><?php esc_html_e( 'Preview: this form is a draft and only visible to you.', 'bookora' ); ?></p>
			<?php endif; ?>
			<form class="bookora__form" novalidate>
				<?php if ( $multi && 'none' !== $settings['stepProgress'] ) : ?>
					<div class="bookora__progress bookora__progress--<?php echo esc_attr( $settings['stepProgress'] ); ?>" aria-hidden="true">
						<?php if ( 'steps' === $settings['stepProgress'] ) : ?>
							<ol>
								<?php foreach ( $titles as $i => $title ) : ?>
									<li data-step="<?php echo (int) $i; ?>"><?php echo esc_html( '' !== $title ? $title : sprintf( /* translators: %d: step number. */ __( 'Step %d', 'bookora' ), $i + 1 ) ); ?></li>
								<?php endforeach; ?>
							</ol>
						<?php else : ?>
							<span class="bookora__progress-bar"><span></span></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php foreach ( $steps as $i => $step_fields ) : ?>
					<div class="bookora__step" data-step="<?php echo (int) $i; ?>"<?php echo $i > 0 ? ' hidden' : ''; ?>>
						<div class="bookora__fields">
							<?php
							foreach ( $step_fields as $field ) {
								$this->field( $field, $form, $site );
							}
							if ( count( $steps ) - 1 === $i ) {
								$this->policy_field( $form );
							}
							?>
						</div>
					</div>
				<?php endforeach; ?>

				<div class="bookora__hp" aria-hidden="true">
					<label><?php esc_html_e( 'Leave this field empty', 'bookora' ); ?><input type="text" name="website_url_hp" tabindex="-1" autocomplete="off"></label>
				</div>
				<input type="hidden" name="_bk_t" value="<?php echo esc_attr( Submission::render_token( (int) $form['id'] ) ); ?>">

				<div class="bookora__message" role="alert" aria-live="assertive" hidden></div>

				<div class="bookora__nav">
					<?php if ( $multi ) : ?>
						<button type="button" class="bookora__button bookora__button--secondary" data-action="prev" hidden><?php esc_html_e( 'Back', 'bookora' ); ?></button>
						<button type="button" class="bookora__button" data-action="next"><?php esc_html_e( 'Next', 'bookora' ); ?></button>
					<?php endif; ?>
					<button type="submit" class="bookora__button bookora__submit"<?php echo $multi ? ' hidden' : ''; ?>>
						<?php echo esc_html( '' !== $settings['submitLabel'] ? $settings['submitLabel'] : ( 'booking' === $form['type'] ? __( 'Book now', 'bookora' ) : __( 'Send', 'bookora' ) ) ); ?>
					</button>
				</div>
			</form>
			<div class="bookora__success" role="status" aria-live="polite" hidden></div>
			<noscript><p class="bookora__noscript"><?php esc_html_e( 'Please enable JavaScript to use this form.', 'bookora' ); ?></p></noscript>
		</div>
		<?php
		$html = (string) ob_get_clean();

		if ( '' !== trim( (string) $form['custom_css'] ) ) {
			$html .= '<style id="bookora-form-' . (int) $form['id'] . '-css">' . FormRepository::clean_css( (string) $form['custom_css'] ) . '</style>';
		}
		if ( ! $this->global_css_added && '' !== trim( (string) ( $site['global_css'] ?? '' ) ) ) {
			$this->global_css_added = true;
			wp_add_inline_style( 'bookora-frontend', FormRepository::clean_css( (string) $site['global_css'] ) );
		}
		if ( '' !== trim( (string) $form['custom_js'] ) ) {
			// Stored only for users with unfiltered_html (see FormRepository::sanitize_form()).
			$this->scripts[ (int) $form['id'] ] = (string) $form['custom_js'];
		}

		/**
		 * Filter the complete HTML of a form.
		 *
		 * @param string               $html HTML.
		 * @param array<string, mixed> $form Form.
		 */
		return (string) apply_filters( 'bookora_form_html', $html, $form );
	}

	/**
	 * CSS variables for the design settings (Bookora → Settings → Design).
	 *
	 * @param array<string, mixed> $site Settings.
	 */
	public static function design_style( array $site ): string {
		$color = sanitize_hex_color( (string) ( $site['primary_color'] ?? '' ) );
		$vars  = array(
			'--bookora-primary' => $color ? $color : '#2563eb',
			'--bookora-radius'  => max( 0, min( 24, (int) ( $site['form_radius'] ?? 10 ) ) ) . 'px',
		);
		$out   = '';
		foreach ( $vars as $name => $value ) {
			$out .= $name . ': ' . $value . '; ';
		}
		return trim( $out );
	}

	/**
	 * Modifier classes for density, color scheme and font.
	 *
	 * @param array<string, mixed> $site Settings.
	 * @return list<string>
	 */
	public static function design_classes( array $site ): array {
		$classes = array();
		if ( in_array( $site['form_density'] ?? 'normal', array( 'compact', 'comfortable' ), true ) ) {
			$classes[] = 'bookora--' . $site['form_density'];
		}
		if ( in_array( $site['form_scheme'] ?? 'light', array( 'dark', 'auto' ), true ) ) {
			$classes[] = 'bookora--scheme-' . $site['form_scheme'];
		}
		if ( 'system' === ( $site['form_font'] ?? 'inherit' ) ) {
			$classes[] = 'bookora--font-system';
		}
		return $classes;
	}

	/**
	 * Make admin code safe to place inside a script element (no early "</script>").
	 *
	 * @param string $code JavaScript.
	 */
	private static function script_safe( string $code ): string {
		return str_ireplace( array( '</script', '<!--' ), array( '<\/script', '<\!--' ), $code );
	}

	/**
	 * Print the global and per-form custom JavaScript.
	 * Each block is queued (window.bookoraQueue), so it works regardless of script order,
	 * and wrapped so errors in one block do not affect the form or other blocks.
	 */
	public function print_custom_scripts(): void {
		if ( ! $this->rendered ) {
			return;
		}
		$global = trim( (string) $this->settings->get( 'global_js', '' ) );
		if ( '' !== $global ) {
			// Global code runs once per page and receives window.bookora.
			printf(
				"<script id=\"bookora-global-js\">(window.bookoraQueue=window.bookoraQueue||[]).push({id:null,fn:function(bookora){\n%s\n}});</script>\n",
				self::script_safe( $global ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted admin code (unfiltered_html).
			);
		}
		foreach ( $this->scripts as $id => $code ) {
			printf(
				"<script id=\"bookora-form-%1\$d-js\">(window.bookoraQueue=window.bookoraQueue||[]).push({id:%1\$d,fn:function(bookora,form){\n%2\$s\n}});</script>\n",
				(int) $id,
				self::script_safe( $code ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- trusted admin code (unfiltered_html).
			);
		}
	}

	/**
	 * Configuration passed to frontend.js.
	 *
	 * @param array<string, mixed>             $form   Form.
	 * @param array<int, array<string, mixed>> $fields Fields.
	 * @param array<string, mixed>             $site Global settings.
	 * @return array<string, mixed>
	 */
	private function config( array $form, array $fields, array $site ): array {
		$public_fields = array();
		foreach ( $fields as $field ) {
			if ( 'step' === $field['type'] ) {
				continue;
			}
			$public_fields[] = array_intersect_key(
				$field,
				array_flip( array( 'key', 'type', 'required', 'logic', 'workingDaysOnly', 'withTime', 'minDate', 'maxDate', 'allowAny', 'serviceIds', 'label' ) )
			);
		}

		$resources       = array();
		$default_service = 0;
		$policies        = $this->policies( $form );
		if ( $policies ) {
			$public_fields[] = array(
				'key'      => '_policy',
				'type'     => 'consent',
				'required' => true,
			);
		}
		if ( 'booking' === $form['type'] ) {
			$active = array_column( $this->resources->all( array( 'status' => 'active' ) ), 'name', 'id' );
			foreach ( $this->services->all( array( 'status' => 'active' ) ) as $service ) {
				$default_service             = $default_service ? $default_service : (int) $service['id'];
				$resources[ $service['id'] ] = array();
				foreach ( $this->services->resource_ids( (int) $service['id'] ) as $rid ) {
					if ( isset( $active[ $rid ] ) ) {
						$resources[ $service['id'] ][] = array(
							'id'   => $rid,
							'name' => $active[ $rid ],
						);
					}
				}
			}
		}

		$config = array(
			'id'         => (int) $form['id'],
			'type'       => $form['type'],
			'rest'       => esc_url_raw( rest_url( 'bookora/v1' ) ),
			'fields'     => $public_fields,
			'resources'  => (object) $resources,
			'policies'   => (object) $policies,
			'service'    => $default_service,
			'weekStart'  => (int) $site['week_start'],
			'maxAdvance' => (int) $site['max_advance_days'],
			'timeFormat' => (string) $site['time_format'],
			'locale'     => str_replace( '_', '-', determine_locale() ),
			'timezone'   => wp_timezone_string(),
		);

		/**
		 * Filter the configuration passed to the form script (data-bookora attribute).
		 *
		 * @param array<string, mixed> $config Config.
		 * @param array<string, mixed> $form   Form.
		 */
		return (array) apply_filters( 'bookora_frontend_config', $config, $form );
	}

	/**
	 * Cancellation terms that need the customer's consent, per service id (booking forms only).
	 * Cached per request.
	 *
	 * @param array<string, mixed> $form Form.
	 * @return array<int, string> Service id => text.
	 */
	private function policies( array $form ): array {
		if ( 'booking' !== $form['type'] ) {
			return array();
		}
		if ( null === $this->policy_cache ) {
			$this->policy_cache = array();
			$site               = $this->settings->all();
			foreach ( $this->services->all( array( 'status' => 'active' ) ) as $service ) {
				$rule = CancellationPolicy::for_service( $service, $site );
				if ( $rule['fee'] ) {
					$this->policy_cache[ (int) $service['id'] ] = CancellationPolicy::text( $rule, (float) $service['price'] );
				}
			}
		}
		return $this->policy_cache;
	}

	/**
	 * Consent box for the cancellation terms (shown by the script for services with a fee rule).
	 *
	 * @param array<string, mixed> $form Form.
	 */
	private function policy_field( array $form ): void {
		$policies = $this->policies( $form );
		if ( ! $policies ) {
			return;
		}
		$id = 'bookora-' . (int) $form['id'] . '-policy';
		// Visible from the start when the form books one fixed service with a rule.
		$fields  = (array) $form['fields'];
		$fixed   = ! in_array( 'service', array_column( $fields, 'type' ), true );
		$first   = (int) array_key_first( $policies );
		$default = 0;
		foreach ( $this->services->all( array( 'status' => 'active' ) ) as $service ) {
			$default = (int) $service['id'];
			break;
		}
		$visible = $fixed && isset( $policies[ $default ] );
		$text    = $visible ? $policies[ $default ] : $policies[ $first ];
		?>
		<div class="bookora__field bookora__field--consent bookora__field--policy" data-key="_policy"<?php echo $visible ? '' : ' hidden'; ?>>
			<div class="bookora__control">
				<p class="bookora__policy-text" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $text ); ?></p>
				<label class="bookora__choice bookora__consent"><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="fields[_policy]" value="1" required aria-describedby="<?php echo esc_attr( $id ); ?>-help <?php echo esc_attr( $id ); ?>-error"> <span><?php esc_html_e( 'I accept the cancellation terms.', 'bookora' ); ?></span></label>
			</div>
			<p class="bookora__error" id="<?php echo esc_attr( $id ); ?>-error" role="alert" hidden></p>
		</div>
		<?php
	}

	/**
	 * Render one field.
	 *
	 * @param array<string, mixed> $field  Field.
	 * @param array<string, mixed> $form   Form.
	 * @param array<string, mixed> $site Global settings.
	 */
	private function field( array $field, array $form, array $site ): void {
		$type    = (string) $field['type'];
		$key     = (string) ( $field['key'] ?? '' );
		$id      = 'bk-' . $form['id'] . '-' . ( '' !== $key ? $key : $field['id'] );
		$name    = 'fields[' . $key . ']';
		$req     = ! empty( $field['required'] );
		$classes = array( 'bookora__field', 'bookora__field--' . $type, 'bookora__w--' . ( $field['width'] ?? 'full' ) );
		$label   = (string) ( $field['label'] ?? '' );
		$help    = (string) ( $field['help'] ?? '' );
		$place   = (string) ( $field['placeholder'] ?? '' );
		$value   = (string) ( $field['defaultValue'] ?? '' );
		// Help text and the (initially empty) error message are announced together with the field.
		$aria    = ' aria-describedby="' . esc_attr( trim( ( $help ? $id . '-help ' : '' ) . $id . '-error' ) ) . '"';
		$req_att = $req ? ' required aria-required="true"' : '';

		$group = in_array( $type, array( 'radio', 'checkboxes', 'name', 'address', 'rating', 'appointment' ), true );

		printf(
			'<div class="%s" data-key="%s" data-type="%s"%s>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $key ),
			esc_attr( $type ),
			'hidden' === $type ? ' hidden' : ''
		);

		if ( $group ) {
			echo '<fieldset class="bookora__group" aria-describedby="' . esc_attr( trim( ( $help ? $id . '-help ' : '' ) . $id . '-error' ) ) . '"><legend class="bookora__label">' . esc_html( $label ) . ( $req ? '<span class="bookora__req" aria-hidden="true">*</span>' : '' ) . '</legend>';
		} elseif ( '' !== $label && ! in_array( $type, array( 'heading', 'paragraph', 'divider', 'consent', 'hidden' ), true ) ) {
			echo '<label class="bookora__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . ( $req ? '<span class="bookora__req" aria-hidden="true">*</span>' : '' ) . '</label>';
		}

		echo '<div class="bookora__control">';

		switch ( $type ) {
			case 'heading':
				$level = in_array( (int) ( $field['level'] ?? 3 ), array( 2, 3, 4 ), true ) ? (int) $field['level'] : 3;
				printf( '<h%1$d class="bookora__heading">%2$s</h%1$d>', (int) $level, esc_html( $label ) );
				break;

			case 'paragraph':
				echo '<div class="bookora__paragraph">' . wp_kses_post( wpautop( (string) ( $field['content'] ?? '' ) ) ) . '</div>';
				break;

			case 'divider':
				echo '<hr class="bookora__divider">';
				break;

			case 'textarea':
				printf( '<textarea id="%s" name="%s" rows="%d" placeholder="%s"%s%s>%s</textarea>', esc_attr( $id ), esc_attr( $name ), (int) ( $field['rows'] ?? 4 ), esc_attr( $place ), $req_att, $aria, esc_textarea( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute strings.
				break;

			case 'select':
				printf( '<select id="%s" name="%s"%s%s>', esc_attr( $id ), esc_attr( $name ), $req_att, $aria ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute strings.
				echo '<option value="">' . esc_html( '' !== $place ? $place : __( '— Please choose —', 'bookora' ) ) . '</option>';
				foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $opt['value'] ), selected( $value, $opt['value'], false ), esc_html( $opt['label'] ) );
				}
				echo '</select>';
				break;

			case 'radio':
			case 'checkboxes':
				$input = 'radio' === $type ? 'radio' : 'checkbox';
				echo '<div class="bookora__choices bookora__choices--' . esc_attr( $field['layout'] ?? 'vertical' ) . '">';
				foreach ( (array) ( $field['options'] ?? array() ) as $i => $opt ) {
					printf(
						'<label class="bookora__choice"><input type="%s" name="%s" value="%s"%s> <span>%s</span></label>',
						esc_attr( $input ),
						esc_attr( 'radio' === $type ? $name : $name . '[]' ),
						esc_attr( $opt['value'] ),
						checked( $value, $opt['value'], false ),
						esc_html( $opt['label'] )
					);
				}
				echo '</div>';
				break;

			case 'consent':
				printf(
					'<label class="bookora__choice bookora__consent"><input type="checkbox" id="%s" name="%s" value="1"%s> <span>%s%s</span></label>',
					esc_attr( $id ),
					esc_attr( $name ),
					$req_att, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string.
					wp_kses(
						(string) ( $field['text'] ?? $label ),
						array(
							'a'      => array(
								'href'   => true,
								'target' => true,
								'rel'    => true,
							),
							'strong' => array(),
							'em'     => array(),
							'br'     => array(),
						)
					),
					$req ? '<span class="bookora__req" aria-hidden="true">*</span>' : ''
				);
				break;

			case 'name':
				if ( 'full' === ( $field['format'] ?? 'first_last' ) ) {
					printf( '<input type="text" id="%s" name="%s" autocomplete="name" placeholder="%s"%s>', esc_attr( $id ), esc_attr( $name ), esc_attr( $place ), $req_att ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string.
				} else {
					echo '<div class="bookora__row">';
					printf( '<input type="text" id="%s" name="%s[first]" autocomplete="given-name" placeholder="%s" aria-label="%s"%s>', esc_attr( $id ), esc_attr( $name ), esc_attr__( 'First name', 'bookora' ), esc_attr__( 'First name', 'bookora' ), $req_att ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					printf( '<input type="text" name="%s[last]" autocomplete="family-name" placeholder="%s" aria-label="%s"%s>', esc_attr( $name ), esc_attr__( 'Last name', 'bookora' ), esc_attr__( 'Last name', 'bookora' ), $req_att ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '</div>';
				}
				break;

			case 'address':
				printf( '<input type="text" name="%s[street]" autocomplete="street-address" placeholder="%s" aria-label="%s">', esc_attr( $name ), esc_attr__( 'Street and number', 'bookora' ), esc_attr__( 'Street and number', 'bookora' ) );
				echo '<div class="bookora__row">';
				printf( '<input type="text" name="%s[zip]" autocomplete="postal-code" placeholder="%s" aria-label="%s" class="bookora__zip">', esc_attr( $name ), esc_attr__( 'ZIP', 'bookora' ), esc_attr__( 'ZIP', 'bookora' ) );
				printf( '<input type="text" name="%s[city]" autocomplete="address-level2" placeholder="%s" aria-label="%s">', esc_attr( $name ), esc_attr__( 'City', 'bookora' ), esc_attr__( 'City', 'bookora' ) );
				echo '</div>';
				if ( ! empty( $field['country'] ) ) {
					printf( '<input type="text" name="%s[country]" autocomplete="country-name" placeholder="%s" aria-label="%s">', esc_attr( $name ), esc_attr__( 'Country', 'bookora' ), esc_attr__( 'Country', 'bookora' ) );
				}
				break;

			case 'rating':
				$max = (int) ( $field['max'] ?? 5 );
				echo '<div class="bookora__rating">';
				for ( $i = $max; $i >= 1; $i-- ) {
					printf(
						'<input type="radio" id="%1$s-%2$d" name="%3$s" value="%2$d"><label for="%1$s-%2$d" title="%2$d"><span class="screen-reader-text">%2$d</span></label>',
						esc_attr( $id ),
						(int) $i,
						esc_attr( $name )
					);
				}
				echo '</div>';
				break;

			case 'range':
				$min  = $field['min'] ?? 0;
				$max  = $field['max'] ?? 100;
				$step = $field['step'] ?? 1;
				$val  = '' !== $value ? $value : $min;
				printf( '<div class="bookora__range"><input type="range" id="%s" name="%s" min="%s" max="%s" step="%s" value="%s"%s><output for="%s">%s</output></div>', esc_attr( $id ), esc_attr( $name ), esc_attr( (string) $min ), esc_attr( (string) $max ), esc_attr( (string) $step ), esc_attr( (string) $val ), $aria, esc_attr( $id ), esc_html( (string) $val ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				break;

			case 'date':
				printf(
					'<input type="date" id="%s" name="%s" class="bookora__date-input" data-picker="1" placeholder="%s"%s%s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( '' !== $place ? $place : ( ! empty( $field['withTime'] ) ? __( 'Please choose a date and time', 'bookora' ) : __( 'Please choose a date', 'bookora' ) ) ),
					$req_att, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$aria // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				break;

			case 'service':
				$allowed  = (array) ( $field['serviceIds'] ?? array() );
				$services = array_filter( $this->services->all( array( 'status' => 'active' ) ), static fn( $s ) => ! $allowed || in_array( (int) $s['id'], $allowed, true ) );
				if ( 1 === count( $services ) ) {
					$only = reset( $services );
					printf( '<input type="hidden" name="%s" value="%d"><p class="bookora__static">%s</p>', esc_attr( $name ), (int) $only['id'], esc_html( self::service_label( $only ) ) );
					break;
				}
				printf( '<select id="%s" name="%s" data-role="service"%s%s>', esc_attr( $id ), esc_attr( $name ), $req_att, $aria ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '<option value="">' . esc_html__( '— Please choose —', 'bookora' ) . '</option>';
				foreach ( $services as $service ) {
					printf( '<option value="%d">%s</option>', (int) $service['id'], esc_html( self::service_label( $service ) ) );
				}
				echo '</select>';
				break;

			case 'resource':
				printf( '<select id="%s" name="%s" data-role="resource"%s>', esc_attr( $id ), esc_attr( $name ), $aria ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				if ( false !== ( $field['allowAny'] ?? true ) ) {
					echo '<option value="">' . esc_html__( 'Any available', 'bookora' ) . '</option>';
				}
				echo '</select>';
				break;

			case 'appointment':
				printf( '<div class="bookora__appointment" data-role="appointment" id="%s"></div>', esc_attr( $id ) );
				printf( '<input type="hidden" name="%s[start]" data-role="appointment-start"><input type="hidden" name="%s[resource]" data-role="appointment-resource">', esc_attr( $name ), esc_attr( $name ) );
				break;

			case 'file':
			case 'signature':
				echo '<p class="bookora__static bookora__muted">' . esc_html__( 'This field type will be available in an upcoming version.', 'bookora' ) . '</p>';
				break;

			case 'hidden':
				printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( self::dynamic_value( $value ) ) );
				break;

			default:
				$input_types  = array(
					'email'  => 'email',
					'phone'  => 'tel',
					'number' => 'number',
					'url'    => 'url',
					'time'   => 'time',
				);
				$autocomplete = array(
					'email' => 'email',
					'phone' => 'tel',
					'url'   => 'url',
				);
				$extra        = '';
				if ( 'number' === $type ) {
					foreach ( array( 'min', 'max', 'step' ) as $attr ) {
						if ( isset( $field[ $attr ] ) && null !== $field[ $attr ] && '' !== $field[ $attr ] ) {
							$extra .= sprintf( ' %s="%s"', $attr, esc_attr( (string) $field[ $attr ] ) );
						}
					}
				}
				if ( 'text' === $type && ! empty( $field['maxLength'] ) ) {
					$extra .= sprintf( ' maxlength="%d"', (int) $field['maxLength'] );
				}
				if ( isset( $autocomplete[ $type ] ) ) {
					$extra .= sprintf( ' autocomplete="%s"', esc_attr( $autocomplete[ $type ] ) );
				}
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" placeholder="%s"%s%s%s>',
					esc_attr( $input_types[ $type ] ?? 'text' ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( self::dynamic_value( $value ) ),
					esc_attr( $place ),
					$extra, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
					$req_att, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$aria // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
		}

		echo '</div>';

		if ( '' !== $help && 'hidden' !== $type ) {
			echo '<p class="bookora__help" id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $help ) . '</p>';
		}
		echo '<p class="bookora__error" id="' . esc_attr( $id . '-error' ) . '" role="alert" hidden></p>';

		if ( $group ) {
			echo '</fieldset>';
		}
		echo '</div>';

		unset( $site );
	}

	/**
	 * "Haircut · 45 min · 30,00 €".
	 *
	 * @param array<string, mixed> $service Service.
	 */
	private static function service_label( array $service ): string {
		$parts = array(
			$service['name'],
			/* translators: %d: minutes. */
			sprintf( __( '%d min', 'bookora' ), (int) $service['duration'] ),
		);
		if ( (float) $service['price'] > 0 ) {
			$parts[] = number_format_i18n( (float) $service['price'], 2 ) . ' ' . apply_filters( 'bookora_currency_symbol', '€' );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Resolve {url:param} and {user:email|name} placeholders in default values.
	 *
	 * @param string $value Default value.
	 */
	private static function dynamic_value( string $value ): string {
		return (string) preg_replace_callback(
			'/\{(url|user):([a-z0-9_]+)\}/i',
			static function ( array $m ): string {
				if ( 'url' === strtolower( $m[1] ) ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill.
					return isset( $_GET[ $m[2] ] ) ? sanitize_text_field( wp_unslash( $_GET[ $m[2] ] ) ) : '';
				}
				$user = wp_get_current_user();
				if ( ! $user->exists() ) {
					return '';
				}
				return 'email' === $m[2] ? $user->user_email : ( 'name' === $m[2] ? $user->display_name : '' );
			},
			$value
		);
	}
}
