<?php
/**
 * Admin section "Travel & kilometres" on Kalendora → Pro.
 *
 * @package KalendoraPro
 */

namespace KalendoraPro;

defined( 'ABSPATH' ) || exit;

/**
 * Travel settings and a test lookup.
 */
final class TravelAdmin {

	/**
	 * Constructor.
	 *
	 * @param Travel $travel Travel.
	 */
	public function __construct( private Travel $travel ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_kalendora_pro_travel', array( $this, 'handle' ) );
		add_action( 'kalendora_pro_admin_sections', array( $this, 'render' ), 5 );
	}

	/**
	 * Save or test.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'kalendora-pro' ), 403 );
		}
		check_admin_referer( 'kalendora_pro_travel' );
		if ( 'test' === sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ) ) {
			$address = \Kalendora\Booking\Location::clean_address( sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) ) );
			$s       = Travel::settings();
			$route   = Travel::provider( $s ) ? $this->travel->route( $address, $s ) : new \WP_Error( 'off', __( 'Please choose a route service and save first.', 'kalendora-pro' ) );
			if ( is_wp_error( $route ) ) {
				$notice = array( 'error', $route->get_error_message() );
			} else {
				$km     = round( $route['meters'] / 1000, 1 );
				$notice = array(
					'success',
					sprintf(
						/* translators: 1: address, 2: km, 3: driving minutes, 4: blocked minutes, 5: costs. */
						__( '%1$s: %2$s km, %3$d min driving → %4$d min blocked each way, travel costs %5$s.', 'kalendora-pro' ),
						$address,
						number_format_i18n( $km, 1 ),
						(int) round( $route['seconds'] / 60 ),
						Travel::minutes( $route['seconds'], $s ),
						Payments::money( Travel::cost( $km, $s ) )
					),
				);
			}
		} else {
			$result = Travel::save( wp_unslash( $_POST ) ); // Sanitised field by field in save().
			$notice = is_wp_error( $result ) ? array( 'error', $result->get_error_message() ) : array( 'success', __( 'Travel settings saved.', 'kalendora-pro' ) );
		}
		set_transient( 'kalendora_pro_notice_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . AdminPage::SLUG . '#travel' ) );
		exit;
	}

	/**
	 * Section on the Pro page.
	 */
	public function render(): void {
		$s    = Travel::settings();
		$post = esc_url( admin_url( 'admin-post.php' ) );
		$home = (string) \Kalendora\Core\Plugin::instance()->container()->get( \Kalendora\Settings\Settings::class )->get( 'business_address', '' );
		?>
		<h2 id="travel"><?php esc_html_e( 'Travel time & kilometre allowance', 'kalendora-pro' ); ?></h2>
		<p class="description" style="max-width:900px"><?php esc_html_e( 'For services "at the customer’s address": the route from your address to the customer is calculated when booking. The driving time is blocked before and after the appointment (there and back), the travel costs are shown to the customer and added to the booking.', 'kalendora-pro' ); ?></p>
		<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" style="max-width:900px">
			<input type="hidden" name="action" value="kalendora_pro_travel"><input type="hidden" name="do" value="save">
			<?php wp_nonce_field( 'kalendora_pro_travel' ); ?>
			<table class="form-table"><tbody>
				<tr><th scope="row"><label for="kt-provider"><?php esc_html_e( 'Route service', 'kalendora-pro' ); ?></label></th><td>
					<select id="kt-provider" name="provider">
						<option value="off" <?php selected( $s['provider'], 'off' ); ?>><?php esc_html_e( 'Off – fixed travel time from the service', 'kalendora-pro' ); ?></option>
						<option value="ors" <?php selected( $s['provider'], 'ors' ); ?>><?php esc_html_e( 'OpenRouteService (free key, servers in Germany)', 'kalendora-pro' ); ?></option>
						<option value="google" <?php selected( $s['provider'], 'google' ); ?>><?php esc_html_e( 'Google Maps (Routes API, paid after the free quota)', 'kalendora-pro' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'OpenRouteService: register at openrouteservice.org → Dashboard → create token. Google: Google Cloud Console → enable "Routes API" → create API key (restrict it to the Routes API).', 'kalendora-pro' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="kt-key"><?php esc_html_e( 'API key', 'kalendora-pro' ); ?></label></th><td>
					<input id="kt-key" name="api_key" type="password" class="regular-text code" autocomplete="off" placeholder="<?php echo esc_attr( '' !== $s['api_key'] ? '•••• ' . substr( (string) $s['api_key'], -4 ) . ' – ' . __( 'leave empty to keep', 'kalendora-pro' ) : '' ); ?>">
					<?php if ( '' !== $s['api_key'] ) : ?>
						<label><input type="checkbox" name="api_key_remove" value="1"> <?php esc_html_e( 'remove', 'kalendora-pro' ); ?></label>
					<?php endif; ?>
				</td></tr>
				<tr><th scope="row"><label for="kt-origin"><?php esc_html_e( 'Starting address', 'kalendora-pro' ); ?></label></th><td>
					<input id="kt-origin" name="origin" type="text" class="regular-text" value="<?php echo esc_attr( (string) $s['origin'] ); ?>" placeholder="<?php echo esc_attr( $home ); ?>">
					<p class="description"><?php esc_html_e( 'Empty = your business address from Kalendora → Settings.', 'kalendora-pro' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="kt-country"><?php esc_html_e( 'Country', 'kalendora-pro' ); ?></label></th><td>
					<input id="kt-country" name="country" type="text" value="<?php echo esc_attr( (string) $s['country'] ); ?>" style="width:60px" maxlength="2">
					<span class="description"><?php esc_html_e( 'Two-letter code, e.g. DE, AT, CH, BG. Empty = worldwide.', 'kalendora-pro' ); ?></span>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Travel time', 'kalendora-pro' ); ?></th><td>
					<label><?php esc_html_e( 'Surcharge for traffic and parking', 'kalendora-pro' ); ?> <input name="extra" type="number" min="0" max="100" value="<?php echo esc_attr( (string) $s['extra'] ); ?>" style="width:70px"> %</label><br>
					<label><?php esc_html_e( 'Round up to', 'kalendora-pro' ); ?>
						<select name="round_to">
							<?php foreach ( array( 1, 5, 10, 15 ) as $m ) : ?>
								<option value="<?php echo esc_attr( (string) $m ); ?>" <?php selected( (int) $s['round_to'], $m ); ?>><?php echo esc_html( sprintf( /* translators: %d: minutes. */ __( '%d min', 'kalendora-pro' ), $m ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Kilometre allowance', 'kalendora-pro' ); ?></th><td>
					<label><input name="price_km" type="text" inputmode="decimal" value="<?php echo esc_attr( number_format( (float) $s['price_km'], 2, '.', '' ) ); ?>" style="width:80px"> <?php esc_html_e( '€ per km', 'kalendora-pro' ); ?></label>
					<select name="charge" aria-label="<?php esc_attr_e( 'Charged distance', 'kalendora-pro' ); ?>">
						<option value="round" <?php selected( $s['charge'], 'round' ); ?>><?php esc_html_e( 'there and back', 'kalendora-pro' ); ?></option>
						<option value="oneway" <?php selected( $s['charge'], 'oneway' ); ?>><?php esc_html_e( 'one way only', 'kalendora-pro' ); ?></option>
					</select><br>
					<label><?php esc_html_e( 'Free kilometres', 'kalendora-pro' ); ?> <input name="free_km" type="text" inputmode="decimal" value="<?php echo esc_attr( (string) $s['free_km'] ); ?>" style="width:80px"> km</label>
					<p class="description"><?php esc_html_e( '0 € = travel costs are not charged (only the travel time is blocked). Example: 12 km, there and back, 0.30 €/km, 10 free km → (24 − 10) × 0.30 = 4.20 €.', 'kalendora-pro' ); ?></p>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Service area', 'kalendora-pro' ); ?></th><td>
					<label><?php esc_html_e( 'Max. distance', 'kalendora-pro' ); ?> <input name="max_km" type="text" inputmode="decimal" value="<?php echo esc_attr( (string) $s['max_km'] ); ?>" style="width:80px"> <?php esc_html_e( 'km (0 = no limit)', 'kalendora-pro' ); ?></label><br>
					<label for="kt-plz"><?php esc_html_e( 'Only these postcodes', 'kalendora-pro' ); ?></label><br>
					<input id="kt-plz" name="postcodes" type="text" class="large-text" value="<?php echo esc_attr( (string) $s['postcodes'] ); ?>" placeholder="10115, 10117, 12*, 13*">
					<p class="description"><?php esc_html_e( 'Comma separated; 12* = all postcodes starting with 12. Empty = everywhere. Works without a route service, too.', 'kalendora-pro' ); ?></p>
				</td></tr>
			</tbody></table>
			<p class="description" style="max-width:900px"><strong><?php esc_html_e( 'Data protection:', 'kalendora-pro' ); ?></strong> <?php esc_html_e( 'The address entered by the customer is sent to the chosen route service to calculate the route. Please mention this in your privacy policy (OpenRouteService: HeiGIT gGmbH, Heidelberg; Google: Google Ireland Ltd.).', 'kalendora-pro' ); ?></p>
			<p><button class="button button-primary"><?php esc_html_e( 'Save travel settings', 'kalendora-pro' ); ?></button></p>
		</form>
		<?php if ( Travel::provider( $s ) ) : ?>
			<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="display:flex;gap:8px;align-items:center;max-width:900px">
				<input type="hidden" name="action" value="kalendora_pro_travel"><input type="hidden" name="do" value="test">
				<?php wp_nonce_field( 'kalendora_pro_travel' ); ?>
				<label for="kt-test"><?php esc_html_e( 'Test with an address:', 'kalendora-pro' ); ?></label>
				<input id="kt-test" name="address" type="text" class="regular-text" placeholder="<?php esc_attr_e( 'Street and no., postcode, town', 'kalendora-pro' ); ?>" required>
				<button class="button"><?php esc_html_e( 'Calculate route', 'kalendora-pro' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}
}
