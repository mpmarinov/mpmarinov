<?php
/**
 * Admin page "Kalendora → Pro": license key and webhooks (classic forms, no build step).
 *
 * @package KalendoraPro
 */

namespace KalendoraPro;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page.
 */
final class AdminPage {

	public const SLUG = 'kalendora-pro';

	/**
	 * Constructor.
	 *
	 * @param License  $license  License.
	 * @param Webhooks $webhooks Webhooks.
	 */
	public function __construct( private License $license, private Webhooks $webhooks ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_post_kalendora_pro', array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_filter( 'plugin_action_links_' . KALENDORA_PRO_BASENAME, array( $this, 'links' ) );
	}

	/**
	 * Submenu below Kalendora.
	 */
	public function menu(): void {
		add_submenu_page( 'kalendora', __( 'Kalendora Pro', 'kalendora-pro' ), __( 'Pro', 'kalendora-pro' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * "License" link in the plugin list.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'License', 'kalendora-pro' ) . '</a>' );
		return $links;
	}

	/**
	 * Reminder until a key is entered (only on Kalendora and plugin screens).
	 */
	public function notice(): void {
		$screen = get_current_screen();
		if ( License::is_active() || ! current_user_can( 'manage_options' ) || ! $screen || ( 'plugins' !== $screen->id && ! str_contains( (string) $screen->id, 'kalendora' ) ) || str_contains( (string) $screen->id, self::SLUG ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Please enter your Kalendora Pro license key to receive updates.', 'kalendora-pro' ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Enter license key', 'kalendora-pro' )
		);
	}

	/**
	 * Form actions.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'kalendora-pro' ), 403 );
		}
		check_admin_referer( 'kalendora_pro' );
		$do     = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$status = 'success';
		$msg    = '';
		switch ( $do ) {
			case 'activate':
				$result = $this->license->activate( sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ) );
				$msg    = is_wp_error( $result ) ? $result->get_error_message() : __( 'License activated. Thank you!', 'kalendora-pro' );
				$status = is_wp_error( $result ) ? 'error' : 'success';
				break;
			case 'deactivate':
				$result = $this->license->deactivate();
				$msg    = is_wp_error( $result ) ? $result->get_error_message() : __( 'License removed from this website.', 'kalendora-pro' );
				$status = is_wp_error( $result ) ? 'error' : 'success';
				break;
			case 'add_hook':
				$result = Webhooks::add( esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ), array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['events'] ?? array() ) ) );
				$msg    = is_wp_error( $result ) ? $result->get_error_message() : __( 'Webhook added.', 'kalendora-pro' );
				$status = is_wp_error( $result ) ? 'error' : 'success';
				break;
			case 'remove_hook':
				Webhooks::remove( sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ) );
				$msg = __( 'Webhook removed.', 'kalendora-pro' );
				break;
			case 'test_hook':
				$ok     = $this->webhooks->test( sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ) );
				$msg    = $ok ? __( 'Test delivered.', 'kalendora-pro' ) : __( 'The address did not answer with success (2xx). See the log below.', 'kalendora-pro' );
				$status = $ok ? 'success' : 'error';
				break;
		}
		set_transient( 'kalendora_pro_notice_' . get_current_user_id(), array( $status, $msg ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Hidden fields.
	 *
	 * @param string $action_name Action.
	 */
	private static function fields( string $action_name ): void {
		echo '<input type="hidden" name="action" value="kalendora_pro"><input type="hidden" name="do" value="' . esc_attr( $action_name ) . '">';
		wp_nonce_field( 'kalendora_pro' );
	}

	/**
	 * Page.
	 */
	public function render(): void {
		$notice = get_transient( 'kalendora_pro_notice_' . get_current_user_id() );
		delete_transient( 'kalendora_pro_notice_' . get_current_user_id() );
		$data   = License::data();
		$post   = esc_url( admin_url( 'admin-post.php' ) );
		$labels = array(
			'booking.created'                 => __( 'New booking', 'kalendora-pro' ),
			'booking.status_changed'          => __( 'Booking status changed (confirmed, cancelled, attended …)', 'kalendora-pro' ),
			'booking.moved'                   => __( 'Booking moved in the admin', 'kalendora-pro' ),
			'booking.cancelled_by_customer'   => __( 'Cancelled by the customer', 'kalendora-pro' ),
			'booking.rescheduled_by_customer' => __( 'Moved by the customer', 'kalendora-pro' ),
			'entry.created'                   => __( 'New form entry', 'kalendora-pro' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kalendora Pro', 'kalendora-pro' ); ?></h1>
			<?php if ( is_array( $notice ) && '' !== $notice[1] ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?>"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'License', 'kalendora-pro' ); ?></h2>
			<?php if ( License::is_active() ) : ?>
				<p>
					<strong><?php esc_html_e( 'Active', 'kalendora-pro' ); ?></strong> ·
					<code>…<?php echo esc_html( substr( (string) $data['key'], -4 ) ); ?></code> ·
					<?php
					/* translators: 1: used websites, 2: allowed websites. */
					echo esc_html( sprintf( __( '%1$d of %2$s websites', 'kalendora-pro' ), (int) $data['sites_used'], $data['sites_max'] ? (string) $data['sites_max'] : '∞' ) );
					if ( $data['expires'] ) {
						/* translators: %s: date. */
						echo ' · ' . esc_html( sprintf( __( 'updates until %s', 'kalendora-pro' ), wp_date( get_option( 'date_format' ), (int) strtotime( (string) $data['expires'] ) ) ) );
					}
					?>
				</p>
				<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>">
					<?php self::fields( 'deactivate' ); ?>
					<button class="button"><?php esc_html_e( 'Remove license from this website', 'kalendora-pro' ); ?></button>
				</form>
			<?php else : ?>
				<?php if ( 'expired' === $data['status'] ) : ?>
					<p><?php esc_html_e( 'Your license has expired. All Pro features keep working; updates and support resume when you renew.', 'kalendora-pro' ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
					<?php self::fields( 'activate' ); ?>
					<p>
						<label for="kalendora-pro-key"><?php esc_html_e( 'License key', 'kalendora-pro' ); ?></label><br>
						<input id="kalendora-pro-key" name="key" type="text" class="regular-text code" placeholder="BKRA-XXXX-XXXX-XXXX-XXXX" autocomplete="off" required value="<?php echo esc_attr( (string) $data['key'] ); ?>">
						<button class="button button-primary"><?php esc_html_e( 'Activate', 'kalendora-pro' ); ?></button>
					</p>
					<p class="description"><?php esc_html_e( 'You find the key in the order email and in your account on mpmarinov.com under “Licenses”. Local and staging sites do not count.', 'kalendora-pro' ); ?></p>
				</form>
			<?php endif; ?>

			<?php
			/**
			 * More sections on the Pro page (online payment …).
			 */
			do_action( 'kalendora_pro_admin_sections' );
			?>

			<h2><?php esc_html_e( 'Webhooks (Zapier, Make, n8n, Google Sheets)', 'kalendora-pro' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Kalendora sends a JSON message to the address for each chosen event. Each message is signed: header X-Kalendora-Signature = sha256 HMAC of the body with the secret shown below.', 'kalendora-pro' ); ?></p>
			<?php $hooks = Webhooks::all(); ?>
			<?php if ( $hooks ) : ?>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th><?php esc_html_e( 'Address', 'kalendora-pro' ); ?></th><th><?php esc_html_e( 'Events', 'kalendora-pro' ); ?></th><th><?php esc_html_e( 'Secret', 'kalendora-pro' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'kalendora-pro' ); ?></span></th></tr></thead>
					<tbody>
					<?php foreach ( $hooks as $hook ) : ?>
						<tr>
							<td><code><?php echo esc_html( $hook['url'] ); ?></code></td>
							<td><?php echo esc_html( implode( ', ', array_map( static fn( $e ) => $labels[ $e ] ?? $e, $hook['events'] ) ) ); ?></td>
							<td><code style="user-select:all"><?php echo esc_html( $hook['secret'] ); ?></code></td>
							<td>
								<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="display:inline"><?php self::fields( 'test_hook' ); ?><input type="hidden" name="id" value="<?php echo esc_attr( $hook['id'] ); ?>"><button class="button button-small"><?php esc_html_e( 'Send test', 'kalendora-pro' ); ?></button></form>
								<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="display:inline"><?php self::fields( 'remove_hook' ); ?><input type="hidden" name="id" value="<?php echo esc_attr( $hook['id'] ); ?>"><button class="button button-small button-link-delete"><?php esc_html_e( 'Remove', 'kalendora-pro' ); ?></button></form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="margin-top:16px">
				<?php self::fields( 'add_hook' ); ?>
				<p>
					<label for="kalendora-pro-url"><?php esc_html_e( 'Address (https://…)', 'kalendora-pro' ); ?></label><br>
					<input id="kalendora-pro-url" name="url" type="url" class="large-text" placeholder="https://hooks.zapier.com/hooks/catch/…" required>
				</p>
				<fieldset><legend><?php esc_html_e( 'Events', 'kalendora-pro' ); ?></legend>
					<?php foreach ( $labels as $event => $label ) : ?>
						<label style="display:block"><input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>" <?php checked( in_array( $event, array( 'booking.created', 'entry.created' ), true ) ); ?>> <?php echo esc_html( $label ); ?> <code><?php echo esc_html( $event ); ?></code></label>
					<?php endforeach; ?>
				</fieldset>
				<p><button class="button button-primary"><?php esc_html_e( 'Add webhook', 'kalendora-pro' ); ?></button></p>
			</form>

			<details style="max-width:900px">
				<summary><strong><?php esc_html_e( 'Google Sheets without Zapier: Apps Script in 2 minutes', 'kalendora-pro' ); ?></strong></summary>
				<ol>
					<li><?php esc_html_e( 'Open the spreadsheet → Extensions → Apps Script, paste the code and save.', 'kalendora-pro' ); ?></li>
					<li><?php esc_html_e( 'Deploy → New deployment → Web app → access “Anyone” → copy the address.', 'kalendora-pro' ); ?></li>
					<li><?php esc_html_e( 'Add the address above as a webhook. Each booking becomes a new row.', 'kalendora-pro' ); ?></li>
				</ol>
				<pre style="background:#fff;padding:12px;border:1px solid #ddd;overflow:auto">function doPost(e) {
	var msg = JSON.parse(e.postData.contents);
	var d = msg.data, c = d.customer || {};
	SpreadsheetApp.getActiveSheet().appendRow([
	new Date(), msg.event, d.date || '', d.time || '', d.service || d.form || '',
	d.staff || '', c.name || '', c.email || '', c.phone || '', d.status || ''
	]);
	return ContentService.createTextOutput('ok');
}</pre>
			</details>

			<?php $log = get_option( Webhooks::LOG, array() ); ?>
			<?php if ( is_array( $log ) && $log ) : ?>
				<h3><?php esc_html_e( 'Last deliveries', 'kalendora-pro' ); ?></h3>
				<table class="widefat striped" style="max-width:900px">
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'd.m. H:i', (int) $row['time'] ) ); ?></td>
							<td><?php echo esc_html( $row['host'] ); ?></td>
							<td><code><?php echo esc_html( $row['event'] ); ?></code></td>
							<td><?php echo $row['code'] >= 200 && $row['code'] < 300 ? '✓ ' . esc_html( (string) $row['code'] ) : '✗ ' . esc_html( $row['code'] ? (string) $row['code'] : __( 'no connection', 'kalendora-pro' ) ); ?><?php echo $row['attempt'] > 1 && $row['attempt'] < 99 ? ' · ' . esc_html( sprintf( /* translators: %d: attempt. */ __( 'attempt %d', 'kalendora-pro' ), (int) $row['attempt'] ) ) : ''; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
