<?php
/**
 * Admin section "Online payment" on Kalendora → Pro: settings, payments, refunds.
 *
 * @package KalendoraPro
 */

namespace KalendoraPro;

use Kalendora\Repository\EntryRepository;
use Kalendora\Repository\ServiceRepository;
use Kalendora\Core\Plugin as Kalendora;

defined( 'ABSPATH' ) || exit;

/**
 * Payments admin.
 */
final class PaymentsAdmin {

	/**
	 * Constructor.
	 *
	 * @param Payments $payments Payments.
	 */
	public function __construct( private Payments $payments ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_post_kalendora_pro_payments', array( $this, 'handle' ) );
		add_action( 'kalendora_pro_admin_sections', array( $this, 'render' ) );
	}

	/**
	 * Save settings / refund.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'kalendora-pro' ), 403 );
		}
		check_admin_referer( 'kalendora_pro_payments' );
		$action = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$status = 'success';
		if ( 'received' === $action ) {
			$ok     = $this->payments->mark_received( absint( $_POST['id'] ?? 0 ) );
			$msg    = $ok ? __( 'Marked as paid on site.', 'kalendora-pro' ) : __( 'This payment cannot be marked as paid.', 'kalendora-pro' );
			$status = $ok ? 'success' : 'error';
		} elseif ( 'refund' === $action ) {
			$payment = Payments::find( absint( $_POST['id'] ?? 0 ) );
			$amount  = (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['amount'] ?? '' ) ) );
			$result  = $payment && in_array( $payment['status'], array( 'paid', 'partially_refunded' ), true ) ? $this->payments->refund( $payment, $amount ) : new \WP_Error( 'x', __( 'This payment cannot be refunded.', 'kalendora-pro' ) );
			$msg     = is_wp_error( $result ) ? $result->get_error_message() : __( 'Refund sent.', 'kalendora-pro' );
			$status  = is_wp_error( $result ) ? 'error' : 'success';
		} else {
			$result = Payments::save( wp_unslash( $_POST ) ); // Sanitised field by field in save().
			$msg    = is_wp_error( $result ) ? $result->get_error_message() : __( 'Payment settings saved.', 'kalendora-pro' );
			$status = is_wp_error( $result ) ? 'error' : 'success';
		}
		set_transient( 'kalendora_pro_notice_' . get_current_user_id(), array( $status, $msg ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . AdminPage::SLUG . '#payments' ) );
		exit;
	}

	/**
	 * Hidden fields.
	 *
	 * @param string $action_name Action.
	 */
	private static function fields( string $action_name ): void {
		echo '<input type="hidden" name="action" value="kalendora_pro_payments"><input type="hidden" name="do" value="' . esc_attr( $action_name ) . '">';
		wp_nonce_field( 'kalendora_pro_payments' );
	}

	/**
	 * Secret input: never shows the stored value.
	 *
	 * @param string $name  Field.
	 * @param string $label Label.
	 * @param string $value Stored value.
	 * @param string $hint  Placeholder.
	 */
	private static function secret( string $name, string $label, string $value, string $hint ): void {
		printf(
			'<p><label for="bp-%1$s">%2$s</label><br><input id="bp-%1$s" name="%1$s" type="password" class="regular-text code" autocomplete="off" placeholder="%3$s"> %4$s</p>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( '' !== $value ? '•••• ' . substr( $value, -4 ) . ' – ' . __( 'leave empty to keep', 'kalendora-pro' ) : $hint ),
			'' !== $value ? '<label><input type="checkbox" name="' . esc_attr( $name ) . '_remove" value="1"> ' . esc_html__( 'remove', 'kalendora-pro' ) . '</label>' : ''
		);
	}

	/**
	 * Readable status.
	 *
	 * @param string $status Status.
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			'open'               => __( 'waiting for payment', 'kalendora-pro' ),
			'onsite'             => __( 'pays on site', 'kalendora-pro' ),
			'paid'               => __( 'paid', 'kalendora-pro' ),
			'expired'            => __( 'not paid in time', 'kalendora-pro' ),
			'refunded'           => __( 'refunded', 'kalendora-pro' ),
			'partially_refunded' => __( 'partly refunded', 'kalendora-pro' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Section on the Pro page.
	 */
	public function render(): void {
		$s        = Payments::settings();
		$post     = esc_url( admin_url( 'admin-post.php' ) );
		$services = Kalendora::instance()->container()->get( ServiceRepository::class )->all( array( 'status' => 'active' ) );
		$hook     = rest_url( 'kalendora/v1/pro/stripe-webhook' );
		?>
		<h2 id="payments"><?php esc_html_e( 'Online payment', 'kalendora-pro' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Customers pay on the secure page of Stripe or PayPal – card data never touches your website. The appointment is reserved until payment and confirmed automatically afterwards. If it is not paid in time, it is released.', 'kalendora-pro' ); ?></p>
		<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" style="max-width:900px">
			<?php self::fields( 'save' ); ?>
			<table class="form-table"><tbody>
				<tr><th scope="row"><label for="bp-mode"><?php esc_html_e( 'Payment', 'kalendora-pro' ); ?></label></th><td>
					<select id="bp-mode" name="mode">
						<option value="off" <?php selected( $s['mode'], 'off' ); ?>><?php esc_html_e( 'No online payment', 'kalendora-pro' ); ?></option>
						<option value="full" <?php selected( $s['mode'], 'full' ); ?>><?php esc_html_e( 'Full price when booking', 'kalendora-pro' ); ?></option>
						<option value="deposit" <?php selected( $s['mode'], 'deposit' ); ?>><?php esc_html_e( 'Deposit when booking', 'kalendora-pro' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Only services with a price. Free services are booked as before.', 'kalendora-pro' ); ?></p>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Deposit', 'kalendora-pro' ); ?></th><td>
					<label class="screen-reader-text" for="bp-dv"><?php esc_html_e( 'Deposit value', 'kalendora-pro' ); ?></label>
					<input id="bp-dv" name="deposit_value" type="text" inputmode="decimal" value="<?php echo esc_attr( (string) $s['deposit_value'] ); ?>" style="width:90px">
					<select name="deposit_type" aria-label="<?php esc_attr_e( 'Deposit type', 'kalendora-pro' ); ?>">
						<option value="percent" <?php selected( $s['deposit_type'], 'percent' ); ?>><?php esc_html_e( '% of the price', 'kalendora-pro' ); ?></option>
						<option value="fixed" <?php selected( $s['deposit_type'], 'fixed' ); ?>><?php esc_html_e( '€ fixed', 'kalendora-pro' ); ?></option>
					</select>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Only for these services', 'kalendora-pro' ); ?></th><td>
					<?php foreach ( $services as $svc ) : ?>
						<label style="display:block"><input type="checkbox" name="services[]" value="<?php echo esc_attr( (string) $svc['id'] ); ?>" <?php checked( in_array( (int) $svc['id'], array_map( 'intval', (array) $s['services'] ), true ) ); ?>> <?php echo esc_html( $svc['name'] . ( (float) $svc['price'] > 0 ? ' · ' . Payments::money( (float) $svc['price'] ) : '' ) ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'None ticked = all services with a price.', 'kalendora-pro' ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="bp-min"><?php esc_html_e( 'Time to pay', 'kalendora-pro' ); ?></label></th><td>
					<input id="bp-min" name="minutes" type="number" min="30" max="240" value="<?php echo esc_attr( (string) $s['minutes'] ); ?>" style="width:80px"> <?php esc_html_e( 'minutes (at least 30)', 'kalendora-pro' ); ?>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Refunds', 'kalendora-pro' ); ?></th><td>
					<label><input type="checkbox" name="refund_cancel" value="1" <?php checked( $s['refund_cancel'] ); ?>> <?php esc_html_e( 'Refund automatically when an appointment is cancelled (customer cancellations minus the cancellation fee, if any).', 'kalendora-pro' ); ?></label>
				</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Pay on site', 'kalendora-pro' ); ?></th><td>
					<label><input type="checkbox" name="onsite" value="1" <?php checked( $s['onsite'] ); ?>> <?php esc_html_e( 'Customers may also choose to pay on site (cash or card at your location).', 'kalendora-pro' ); ?></label>
					<p class="description"><?php esc_html_e( 'The booking is then handled like one without online payment (confirmed automatically or by you, as set under Settings). When the customer has paid, click “Received” in the list below.', 'kalendora-pro' ); ?></p>
				</td></tr>
			</tbody></table>

			<h3>Stripe</h3>
			<?php self::secret( 'stripe_secret', __( 'Secret key', 'kalendora-pro' ), (string) $s['stripe_secret'], 'sk_live_… / sk_test_…' ); ?>
			<?php self::secret( 'stripe_webhook', __( 'Webhook signing secret (recommended)', 'kalendora-pro' ), (string) $s['stripe_webhook'], 'whsec_…' ); ?>
			<p class="description">
				<?php esc_html_e( 'Stripe → Developers → Webhooks → add endpoint with this address and the event checkout.session.completed:', 'kalendora-pro' ); ?>
				<code style="user-select:all"><?php echo esc_html( $hook ); ?></code>
			</p>

			<h3>PayPal</h3>
			<p><label for="bp-ppc"><?php esc_html_e( 'Client ID', 'kalendora-pro' ); ?></label><br><input id="bp-ppc" name="paypal_client" type="text" class="regular-text code" value="<?php echo esc_attr( (string) $s['paypal_client'] ); ?>"></p>
			<?php self::secret( 'paypal_secret', __( 'Secret', 'kalendora-pro' ), (string) $s['paypal_secret'], '' ); ?>
			<p><label><input type="checkbox" name="paypal_sandbox" value="1" <?php checked( $s['paypal_sandbox'] ); ?>> <?php esc_html_e( 'Sandbox (test mode)', 'kalendora-pro' ); ?></label></p>
			<p class="description"><?php esc_html_e( 'developer.paypal.com → Apps & Credentials → create app → copy Client ID and Secret.', 'kalendora-pro' ); ?></p>

			<p><button class="button button-primary"><?php esc_html_e( 'Save payment settings', 'kalendora-pro' ); ?></button></p>
		</form>

		<?php $rows = Payments::latest( 50 ); ?>
		<?php if ( $rows ) : ?>
			<h3><?php esc_html_e( 'Latest payments', 'kalendora-pro' ); ?></h3>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th><?php esc_html_e( 'Date', 'kalendora-pro' ); ?></th><th><?php esc_html_e( 'Customer', 'kalendora-pro' ); ?></th><th><?php esc_html_e( 'Amount', 'kalendora-pro' ); ?></th><th><?php esc_html_e( 'Via', 'kalendora-pro' ); ?></th><th><?php esc_html_e( 'Status', 'kalendora-pro' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'kalendora-pro' ); ?></span></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $p ) : ?>
					<?php $b = Kalendora::instance()->container()->get( EntryRepository::class )->booking( (int) $p['booking_id'] ); ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'd.m.Y H:i', (int) strtotime( $p['created_at'] . ' UTC' ) ) ); ?></td>
						<td><?php echo esc_html( $b ? ( '' !== $b['name'] ? $b['name'] : $b['email'] ) . ' · ' . $b['service_name'] : '#' . $p['booking_id'] ); ?></td>
						<td><?php echo esc_html( 'onsite' === $p['status'] ? Payments::money( (float) $p['total'], (string) $p['currency'] ) : Payments::money( (float) $p['amount'], (string) $p['currency'] ) . ( 'deposit' === $p['kind'] ? ' (' . __( 'deposit', 'kalendora-pro' ) . ')' : '' ) ); ?><?php echo (float) $p['refunded'] > 0 ? '<br><small>' . esc_html( sprintf( /* translators: %s: amount. */ __( 'refunded %s', 'kalendora-pro' ), Payments::money( (float) $p['refunded'], (string) $p['currency'] ) ) ) . '</small>' : ''; ?></td>
						<td><?php echo esc_html( 'onsite' === $p['provider'] || 'onsite' === $p['status'] ? __( 'on site', 'kalendora-pro' ) : ( 'paypal' === $p['provider'] ? 'PayPal' : ucfirst( (string) $p['provider'] ) ) ); ?></td>
						<td><?php echo esc_html( self::status_label( (string) $p['status'] ) ); ?></td>
						<td>
							<?php if ( 'onsite' === $p['status'] ) : ?>
								<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
									<?php self::fields( 'received' ); ?>
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
									<button class="button button-small"><?php echo esc_html( sprintf( /* translators: %s: amount. */ __( 'Received (%s)', 'kalendora-pro' ), Payments::money( (float) $p['total'], (string) $p['currency'] ) ) ); ?></button>
								</form>
							<?php elseif ( in_array( $p['status'], array( 'paid', 'partially_refunded' ), true ) && 'onsite' !== $p['provider'] ) : ?>
								<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="display:flex;gap:6px">
									<?php self::fields( 'refund' ); ?>
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $p['id'] ); ?>">
									<label class="screen-reader-text" for="bp-r<?php echo esc_attr( (string) $p['id'] ); ?>"><?php esc_html_e( 'Amount to refund', 'kalendora-pro' ); ?></label>
									<input id="bp-r<?php echo esc_attr( (string) $p['id'] ); ?>" name="amount" type="text" inputmode="decimal" style="width:80px" value="<?php echo esc_attr( number_format( (float) $p['amount'] - (float) $p['refunded'], 2, '.', '' ) ); ?>">
									<button class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Refund this amount to the customer?', 'kalendora-pro' ) ); ?>')"><?php esc_html_e( 'Refund', 'kalendora-pro' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
