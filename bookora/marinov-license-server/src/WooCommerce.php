<?php
/**
 * WooCommerce adapter: license products, keys on completed orders, email, "My account",
 * refunds and (optional) WooCommerce Subscriptions renewals.
 *
 * @package MarinovLicenseServer
 */

namespace MarinovLicenseServer;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration.
 */
final class WooCommerce {

	public const ENDPOINT = 'licenses';

	/**
	 * Constructor.
	 *
	 * @param Licenses $licenses Licenses.
	 */
	public function __construct( private Licenses $licenses ) {}

	/**
	 * Hooks (only when WooCommerce is active).
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		// Product settings.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );

		// Orders.
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_completed' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'order_revoked' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'order_revoked' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_keys' ), 10, 3 );
		add_action( 'woocommerce_thankyou', array( $this, 'thankyou_keys' ), 5 );

		// Subscriptions (optional plugin).
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( $this, 'subscription_renewed' ) );
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'subscription_status' ), 10, 3 );

		// My account.
		add_action( 'init', static fn() => add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'account_page' ) );
		add_action( 'template_redirect', array( $this, 'account_deactivate' ) );
	}

	/* ------------------------------------------------------------------ product */

	/**
	 * Fields in "Product data → General".
	 */
	public function product_fields(): void {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_mls_enabled',
				'label'       => __( 'License key', 'marinov-license-server' ),
				'description' => __( 'Create a license key when the order is completed.', 'marinov-license-server' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_mls_product',
				'label'       => __( 'Software (slug)', 'marinov-license-server' ),
				'placeholder' => 'bookora-pro',
				'desc_tip'    => true,
				'description' => __( 'Must match the slug the plugin sends, e.g. bookora-pro.', 'marinov-license-server' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_mls_sites',
				'label'             => __( 'Websites', 'marinov-license-server' ),
				'type'              => 'number',
				'placeholder'       => '1',
				'custom_attributes' => array( 'min' => '0' ),
				'desc_tip'          => true,
				'description'       => __( '0 = unlimited. Local and staging sites do not count.', 'marinov-license-server' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_mls_months',
				'label'             => __( 'Validity (months)', 'marinov-license-server' ),
				'type'              => 'number',
				'placeholder'       => '12',
				'custom_attributes' => array( 'min' => '0' ),
				'desc_tip'          => true,
				'description'       => __( '12 = one year. 0 = lifetime. With WooCommerce Subscriptions the subscription decides.', 'marinov-license-server' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Save the fields.
	 *
	 * @param \WC_Product $product Product.
	 */
	public function save_product( $product ): void {
		// Nonce checked by WooCommerce (woocommerce_meta_nonce) before this action runs.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( '_mls_enabled', empty( $_POST['_mls_enabled'] ) ? 'no' : 'yes' );
		$product->update_meta_data( '_mls_product', sanitize_key( wp_unslash( $_POST['_mls_product'] ?? 'bookora-pro' ) ) );
		$product->update_meta_data( '_mls_sites', max( 0, absint( $_POST['_mls_sites'] ?? 1 ) ) );
		$product->update_meta_data( '_mls_months', max( 0, absint( $_POST['_mls_months'] ?? 12 ) ) );
		// phpcs:enable
	}

	/**
	 * License settings of a product (variations inherit from the parent).
	 *
	 * @param \WC_Product $product Product.
	 * @return array{product:string, sites:int, months:int}|null
	 */
	private static function config( $product ): ?array {
		if ( ! $product ) {
			return null;
		}
		$source = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( ! $source || 'yes' !== $source->get_meta( '_mls_enabled' ) ) {
			return null;
		}
		$own_sites = $product->is_type( 'variation' ) ? $product->get_meta( '_mls_sites' ) : '';
		return array(
			'product' => (string) ( $source->get_meta( '_mls_product' ) ? $source->get_meta( '_mls_product' ) : 'bookora-pro' ),
			'sites'   => (int) ( '' !== $own_sites ? $own_sites : ( '' !== $source->get_meta( '_mls_sites' ) ? $source->get_meta( '_mls_sites' ) : 1 ) ),
			'months'  => (int) ( '' !== $source->get_meta( '_mls_months' ) ? $source->get_meta( '_mls_months' ) : 12 ),
		);
	}

	/* ------------------------------------------------------------------ orders */

	/**
	 * Completed order: one key per license product and quantity (never twice).
	 *
	 * @param int $order_id Order id.
	 */
	public function order_completed( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) ) {
			return; // Renewals extend existing keys (subscription_renewed).
		}
		$subscription_id = null;
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subs            = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
			$subscription_id = $subs ? (int) array_key_first( $subs ) : null;
		}
		foreach ( $order->get_items() as $item ) {
			$config = self::config( $item->get_product() );
			if ( ! $config || $item->get_meta( '_mls_license_ids' ) ) {
				continue;
			}
			$ids   = array();
			$count = max( 1, (int) $item->get_quantity() );
			for ( $i = 0; $i < $count; $i++ ) {
				$created = $this->licenses->create(
					array(
						'product'         => $config['product'],
						'email'           => (string) $order->get_billing_email(),
						'user_id'         => $order->get_customer_id() ? (int) $order->get_customer_id() : null,
						'order_id'        => $order_id,
						'subscription_id' => $subscription_id,
						'sites'           => $config['sites'],
						'months'          => $subscription_id ? 0 : $config['months'],
					)
				);
				if ( $subscription_id ) {
					$this->apply_subscription_end( (int) $created['id'], $subscription_id );
				}
				$ids[] = $created['id'];
			}
			$item->update_meta_data( '_mls_license_ids', $ids );
			$item->save();
		}
	}

	/**
	 * Refunded or cancelled: revoke the keys of the order.
	 *
	 * @param int $order_id Order id.
	 */
	public function order_revoked( int $order_id ): void {
		foreach ( $this->order_license_ids( $order_id ) as $id ) {
			$this->licenses->update( $id, array( 'status' => 'revoked' ) );
		}
	}

	/**
	 * License ids stored on the order items.
	 *
	 * @param int $order_id Order id.
	 * @return int[]
	 */
	private function order_license_ids( int $order_id ): array {
		$order = wc_get_order( $order_id );
		$ids   = array();
		if ( $order ) {
			foreach ( $order->get_items() as $item ) {
				$ids = array_merge( $ids, array_map( 'intval', (array) $item->get_meta( '_mls_license_ids' ) ) );
			}
		}
		return array_values( array_filter( $ids ) );
	}

	/**
	 * Keys in the "order completed" email to the customer.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Admin email.
	 * @param bool      $plain_text    Plain text email.
	 */
	public function email_keys( $order, $sent_to_admin, $plain_text ): void {
		if ( $sent_to_admin || ! $order || ! $order->has_status( 'completed' ) ) {
			return;
		}
		$this->print_keys( $order, (bool) $plain_text );
	}

	/**
	 * Keys on the thank-you page (only when already completed, e.g. automatic completion of virtual products).
	 *
	 * @param int $order_id Order id.
	 */
	public function thankyou_keys( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order && $order->has_status( 'completed' ) ) {
			$this->print_keys( $order, false );
		}
	}

	/**
	 * Output the keys of an order.
	 *
	 * @param \WC_Order $order Order.
	 * @param bool      $plain Plain text.
	 */
	private function print_keys( $order, bool $plain ): void {
		$rows = array();
		foreach ( $this->order_license_ids( (int) $order->get_id() ) as $id ) {
			$license = $this->licenses->find( $id );
			if ( $license ) {
				$rows[] = array( $license, Licenses::key_of( $license ) );
			}
		}
		if ( ! $rows ) {
			return;
		}
		if ( $plain ) {
			echo "\n" . esc_html__( 'Your license keys', 'marinov-license-server' ) . "\n";
			foreach ( $rows as [ $license, $key ] ) {
				echo esc_html( $key . ' (' . self::label( $license ) . ")\n" );
			}
			return;
		}
		echo '<h2>' . esc_html__( 'Your license keys', 'marinov-license-server' ) . '</h2><ul>';
		foreach ( $rows as [ $license, $key ] ) {
			echo '<li><code style="font-size:16px;">' . esc_html( $key ) . '</code> – ' . esc_html( self::label( $license ) ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'Enter the key in WordPress under Bookora → Pro license. You can see all keys and websites in your account under “Licenses”.', 'marinov-license-server' ) . '</p>';
	}

	/**
	 * "5 websites, valid until 24.11.2027".
	 *
	 * @param array<string, mixed> $license License.
	 */
	private static function label( array $license ): string {
		$sites = (int) $license['sites_max'];
		/* translators: %d: number of websites. */
		$text = 0 === $sites ? __( 'unlimited websites', 'marinov-license-server' ) : sprintf( _n( '%d website', '%d websites', $sites, 'marinov-license-server' ), $sites );
		if ( $license['expires_at'] ) {
			/* translators: %s: date. */
			$text .= ', ' . sprintf( __( 'valid until %s', 'marinov-license-server' ), wp_date( get_option( 'date_format' ), (int) strtotime( $license['expires_at'] . ' UTC' ) ) );
		}
		return $text;
	}

	/* ------------------------------------------------------------------ subscriptions */

	/**
	 * Renewal paid: the keys run until the next payment (+ 7 days grace).
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 */
	public function subscription_renewed( $subscription ): void {
		foreach ( $this->subscription_license_ids( (int) $subscription->get_id() ) as $id ) {
			$this->licenses->update( $id, array( 'status' => 'active' ) );
			$this->apply_subscription_end( $id, (int) $subscription->get_id() );
		}
	}

	/**
	 * Cancelled/expired/on-hold: valid until the end of the paid period.
	 *
	 * @param \WC_Subscription $subscription Subscription.
	 * @param string           $new_status   New status.
	 * @param string           $old_status   Old status.
	 */
	public function subscription_status( $subscription, $new_status, $old_status ): void {
		unset( $old_status );
		foreach ( $this->subscription_license_ids( (int) $subscription->get_id() ) as $id ) {
			if ( 'active' === $new_status ) {
				$this->licenses->update( $id, array( 'status' => 'active' ) );
				$this->apply_subscription_end( $id, (int) $subscription->get_id() );
			} elseif ( in_array( $new_status, array( 'cancelled', 'pending-cancel', 'expired', 'on-hold' ), true ) ) {
				$end = (int) $subscription->get_time( 'end' );
				$end = $end ? $end : (int) $subscription->get_time( 'next_payment' );
				$this->licenses->update( $id, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', $end ? $end : time() ) ) );
			}
		}
	}

	/**
	 * Expiry = next payment + 7 days grace.
	 *
	 * @param int $license_id      License.
	 * @param int $subscription_id Subscription.
	 */
	private function apply_subscription_end( int $license_id, int $subscription_id ): void {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		$subscription = wcs_get_subscription( $subscription_id );
		$next         = $subscription ? (int) $subscription->get_time( 'next_payment' ) : 0;
		$this->licenses->update(
			$license_id,
			array(
				'subscription_id' => $subscription_id,
				'expires_at'      => $next ? gmdate( 'Y-m-d H:i:s', $next + 7 * DAY_IN_SECONDS ) : null,
			)
		);
	}

	/**
	 * Licenses of a subscription.
	 *
	 * @param int $subscription_id Subscription.
	 * @return int[]
	 */
	private function subscription_license_ids( int $subscription_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Licenses::table( 'licenses' ) . ' WHERE subscription_id = %d', $subscription_id ) ) );
	}

	/* ------------------------------------------------------------------ my account */

	/**
	 * Menu item "Licenses".
	 *
	 * @param array<string, string> $items Items.
	 * @return array<string, string>
	 */
	public function menu( array $items ): array {
		$logout = array_slice( $items, -1, 1, true );
		return array_slice( $items, 0, -1, true ) + array( self::ENDPOINT => __( 'Licenses', 'marinov-license-server' ) ) + $logout;
	}

	/**
	 * Account page: keys, websites, deactivate.
	 */
	public function account_page(): void {
		$user = wp_get_current_user();
		$rows = $this->licenses->for_customer( (int) $user->ID, (string) $user->user_email );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'You do not have any licenses yet.', 'marinov-license-server' ) . '</p>';
			return;
		}
		foreach ( $rows as $license ) {
			$valid = Licenses::is_valid( $license );
			echo '<section class="mls-license" style="margin-bottom:2em;">';
			echo '<h3><code>' . esc_html( $license['key'] ) . '</code></h3>';
			echo '<p>' . esc_html( $license['product'] ) . ' · ' . esc_html( self::label( $license ) ) . ' · <strong>' . esc_html( $valid ? __( 'active', 'marinov-license-server' ) : ( 'revoked' === $license['status'] ? __( 'revoked', 'marinov-license-server' ) : __( 'expired', 'marinov-license-server' ) ) ) . '</strong></p>';
			if ( $license['activations'] ) {
				echo '<table class="shop_table"><thead><tr><th>' . esc_html__( 'Website', 'marinov-license-server' ) . '</th><th>' . esc_html__( 'Version', 'marinov-license-server' ) . '</th><th>' . esc_html__( 'Last check', 'marinov-license-server' ) . '</th><th></th></tr></thead><tbody>';
				foreach ( $license['activations'] as $a ) {
					echo '<tr><td>' . esc_html( $a['site'] ) . ( Licenses::is_dev_site( (string) $a['site'] ) ? ' <small>(' . esc_html__( 'test site, not counted', 'marinov-license-server' ) . ')</small>' : '' ) . '</td><td>' . esc_html( $a['version'] ) . '</td><td>' . esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $a['last_check'] . ' UTC' ) ) ) . '</td><td>';
					echo '<form method="post"><input type="hidden" name="mls_deactivate" value="' . esc_attr( (string) $a['id'] ) . '">';
					wp_nonce_field( 'mls_deactivate_' . $a['id'] );
					echo '<button type="submit" class="button">' . esc_html__( 'Deactivate', 'marinov-license-server' ) . '</button></form></td></tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p>' . esc_html__( 'Not activated on any website yet.', 'marinov-license-server' ) . '</p>';
			}
			echo '</section>';
		}
	}

	/**
	 * Deactivate a website from the account page.
	 */
	public function account_deactivate(): void {
		if ( empty( $_POST['mls_deactivate'] ) || ! is_user_logged_in() ) {
			return;
		}
		$id = absint( $_POST['mls_deactivate'] );
		check_admin_referer( 'mls_deactivate_' . $id );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT a.id, l.user_id, l.email FROM ' . Licenses::table( 'activations' ) . ' a JOIN ' . Licenses::table( 'licenses' ) . ' l ON l.id = a.license_id WHERE a.id = %d', $id ), ARRAY_A );
		$user = wp_get_current_user();
		if ( $row && ( (int) $row['user_id'] === (int) $user->ID || ( '' !== $row['email'] && strtolower( $row['email'] ) === strtolower( $user->user_email ) ) ) ) {
			$wpdb->delete( Licenses::table( 'activations' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wc_add_notice( __( 'The website has been deactivated.', 'marinov-license-server' ) );
		}
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}
}
