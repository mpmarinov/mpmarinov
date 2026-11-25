<?php
/**
 * Admin page "Licenses": search, revoke/extend, manual keys, upload releases.
 *
 * @package MarinovLicenseServer
 */

namespace MarinovLicenseServer;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen (classic WordPress forms, no build step).
 */
final class Admin {

	public const SLUG = 'mls-licenses';

	/**
	 * Constructor.
	 *
	 * @param Licenses $licenses Licenses.
	 */
	public function __construct( private Licenses $licenses ) {}

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_mls_action', array( $this, 'handle' ) );
	}

	/**
	 * Menu below WooCommerce (or Tools without WooCommerce).
	 */
	public function menu(): void {
		add_submenu_page( class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php', __( 'Licenses', 'marinov-license-server' ), __( 'Licenses', 'marinov-license-server' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Form actions.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'marinov-license-server' ), 403 );
		}
		check_admin_referer( 'mls_action' );
		$do      = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$id      = absint( $_POST['id'] ?? 0 );
		$message = '';

		switch ( $do ) {
			case 'revoke':
				$this->licenses->update( $id, array( 'status' => 'revoked' ) );
				$message = __( 'License revoked.', 'marinov-license-server' );
				break;
			case 'activate':
				$this->licenses->update( $id, array( 'status' => 'active' ) );
				$message = __( 'License active again.', 'marinov-license-server' );
				break;
			case 'extend':
				$this->licenses->extend( $id, 12 );
				$message = __( 'License extended by 12 months.', 'marinov-license-server' );
				break;
			case 'create':
				$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
				if ( ! is_email( $email ) ) {
					$message = __( 'Please enter a valid email address.', 'marinov-license-server' );
					break;
				}
				$user    = get_user_by( 'email', $email );
				$created = $this->licenses->create(
					array(
						'product' => sanitize_key( wp_unslash( $_POST['product'] ?? 'kalendora-pro' ) ),
						'email'   => $email,
						'user_id' => $user ? (int) $user->ID : null,
						'sites'   => absint( $_POST['sites'] ?? 1 ),
						'months'  => absint( $_POST['months'] ?? 12 ),
					)
				);
				/* translators: %s: license key. */
				$message = sprintf( __( 'License created: %s', 'marinov-license-server' ), $created['key'] );
				break;
			case 'release':
				$file = $_FILES['zip'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked below.
				if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
					$message = __( 'Please choose a ZIP file.', 'marinov-license-server' );
					break;
				}
				$result = Releases::add(
					sanitize_key( wp_unslash( $_POST['product'] ?? 'kalendora-pro' ) ),
					(string) $file['tmp_name'],
					array(
						'version'      => sanitize_text_field( wp_unslash( $_POST['version'] ?? '' ) ),
						'changelog'    => wp_kses_post( wp_unslash( $_POST['changelog'] ?? '' ) ),
						'requires'     => sanitize_text_field( wp_unslash( $_POST['requires'] ?? '6.6' ) ),
						'tested'       => sanitize_text_field( wp_unslash( $_POST['tested'] ?? '' ) ),
						'requires_php' => sanitize_text_field( wp_unslash( $_POST['requires_php'] ?? '8.1' ) ),
					)
				);
				/* translators: %s: version. */
				$message = is_wp_error( $result ) ? $result->get_error_message() : sprintf( __( 'Version %s published. Activated websites see the update within 12 hours.', 'marinov-license-server' ), $result['version'] );
				break;
		}
		wp_safe_redirect( add_query_arg( 'mls_msg', rawurlencode( $message ), admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Hidden fields of a form.
	 *
	 * @param string $action_name Action.
	 * @param int    $id License id.
	 */
	private static function fields( string $action_name, int $id = 0 ): void {
		echo '<input type="hidden" name="action" value="mls_action"><input type="hidden" name="do" value="' . esc_attr( $action_name ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'mls_action' );
	}

	/**
	 * Page.
	 */
	public function render(): void {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$msg    = sanitize_text_field( wp_unslash( $_GET['mls_msg'] ?? '' ) );
		// phpcs:enable
		$where = '1=1';
		$args  = array();
		if ( '' !== $search ) {
			$key = Licenses::normalize_key( $search );
			if ( '' !== $key ) {
				$where  = 'key_hash = %s';
				$args[] = Licenses::hash( $key );
			} else {
				$where  = '(email LIKE %s OR key_last4 = %s OR order_id = %d)';
				$args[] = '%' . $wpdb->esc_like( $search ) . '%';
				$args[] = strtoupper( $search );
				$args[] = absint( $search );
			}
		}
		$sql  = 'SELECT * FROM ' . Licenses::table( 'licenses' ) . " WHERE {$where} ORDER BY id DESC LIMIT 100";
		$rows = (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$post = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Licenses', 'marinov-license-server' ); ?></h1>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<form method="get" style="margin:12px 0;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label class="screen-reader-text" for="mls-s"><?php esc_html_e( 'Search', 'marinov-license-server' ); ?></label>
				<input id="mls-s" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Key, email or order number', 'marinov-license-server' ); ?>" style="min-width:320px;">
				<button class="button"><?php esc_html_e( 'Search', 'marinov-license-server' ); ?></button>
			</form>

			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Key', 'marinov-license-server' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'marinov-license-server' ); ?></th>
					<th><?php esc_html_e( 'Product', 'marinov-license-server' ); ?></th>
					<th><?php esc_html_e( 'Websites', 'marinov-license-server' ); ?></th>
					<th><?php esc_html_e( 'Valid until', 'marinov-license-server' ); ?></th>
					<th><?php esc_html_e( 'Status', 'marinov-license-server' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No licenses found.', 'marinov-license-server' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $l ) : ?>
					<?php $acts = $this->licenses->activations( (int) $l['id'] ); ?>
					<tr>
						<td><code>…<?php echo esc_html( $l['key_last4'] ); ?></code></td>
						<td><?php echo esc_html( $l['email'] ); ?><?php echo $l['order_id'] ? ' · #' . esc_html( (string) $l['order_id'] ) : ''; ?></td>
						<td><?php echo esc_html( $l['product'] ); ?></td>
						<td><?php echo esc_html( count( $acts ) . ' / ' . ( (int) $l['sites_max'] ? $l['sites_max'] : '∞' ) ); ?><br><small><?php echo esc_html( implode( ', ', array_column( $acts, 'site' ) ) ); ?></small></td>
						<td><?php echo $l['expires_at'] ? esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $l['expires_at'] . ' UTC' ) ) ) : esc_html__( 'lifetime', 'marinov-license-server' ); ?></td>
						<td><?php echo esc_html( Licenses::is_valid( $l ) ? __( 'active', 'marinov-license-server' ) : $l['status'] ); ?></td>
						<td>
							<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" style="display:inline"><?php self::fields( 'extend', (int) $l['id'] ); ?><button class="button button-small"><?php esc_html_e( '+12 months', 'marinov-license-server' ); ?></button></form>
							<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" style="display:inline"><?php self::fields( 'revoked' === $l['status'] ? 'activate' : 'revoke', (int) $l['id'] ); ?><button class="button button-small"><?php echo 'revoked' === $l['status'] ? esc_html__( 'Reactivate', 'marinov-license-server' ) : esc_html__( 'Revoke', 'marinov-license-server' ); ?></button></form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Create a license manually', 'marinov-license-server' ); ?></h2>
			<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
				<?php self::fields( 'create' ); ?>
				<p>
					<label><?php esc_html_e( 'Email', 'marinov-license-server' ); ?> <input type="email" name="email" required></label>
					<label><?php esc_html_e( 'Product', 'marinov-license-server' ); ?> <input type="text" name="product" value="kalendora-pro" size="14"></label>
					<label><?php esc_html_e( 'Websites (0 = unlimited)', 'marinov-license-server' ); ?> <input type="number" name="sites" value="1" min="0" style="width:70px"></label>
					<label><?php esc_html_e( 'Months (0 = lifetime)', 'marinov-license-server' ); ?> <input type="number" name="months" value="12" min="0" style="width:70px"></label>
					<button class="button button-primary"><?php esc_html_e( 'Create', 'marinov-license-server' ); ?></button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Publish a new version', 'marinov-license-server' ); ?></h2>
			<?php $latest = Releases::latest( 'kalendora-pro' ); ?>
			<p><?php echo $latest ? esc_html( sprintf( /* translators: 1: version, 2: date. */ __( 'Current version of kalendora-pro: %1$s (%2$s)', 'marinov-license-server' ), $latest['version'], $latest['date'] ) ) : esc_html__( 'No version published yet.', 'marinov-license-server' ); ?></p>
			<form method="post" action="<?php echo $post; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" enctype="multipart/form-data">
				<?php self::fields( 'release' ); ?>
				<p>
					<label><?php esc_html_e( 'Product', 'marinov-license-server' ); ?> <input type="text" name="product" value="kalendora-pro" size="14"></label>
					<label><?php esc_html_e( 'Version', 'marinov-license-server' ); ?> <input type="text" name="version" placeholder="1.0.0" size="8" required></label>
					<label><?php esc_html_e( 'Requires WordPress', 'marinov-license-server' ); ?> <input type="text" name="requires" value="6.6" size="5"></label>
					<label><?php esc_html_e( 'Tested up to', 'marinov-license-server' ); ?> <input type="text" name="tested" value="<?php echo esc_attr( get_bloginfo( 'version' ) ); ?>" size="5"></label>
					<label><?php esc_html_e( 'Requires PHP', 'marinov-license-server' ); ?> <input type="text" name="requires_php" value="8.1" size="5"></label>
				</p>
				<p><label><?php esc_html_e( 'Changes (shown in WordPress)', 'marinov-license-server' ); ?><br><textarea name="changelog" rows="4" cols="80"></textarea></label></p>
				<p><input type="file" name="zip" accept=".zip,application/zip" required> <button class="button button-primary"><?php esc_html_e( 'Publish', 'marinov-license-server' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
