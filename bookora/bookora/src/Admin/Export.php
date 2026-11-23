<?php
/**
 * CSV export of form entries.
 *
 * @package Bookora
 */

namespace Bookora\Admin;

use Bookora\Repository\EntryRepository;
use Bookora\Repository\FormRepository;
use Bookora\Security\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Download via admin-post.php?action=bookora_export&form_id=1&_wpnonce=…
 */
final class Export {

	public const ACTION = 'bookora_export';

	/**
	 * Constructor.
	 *
	 * @param EntryRepository $entries Entries.
	 * @param FormRepository  $forms   Forms.
	 */
	public function __construct(
		private EntryRepository $entries,
		private FormRepository $forms,
	) {}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'download' ) );
	}

	/**
	 * Signed download URL for a form.
	 *
	 * @param int $form_id Form id.
	 */
	public static function url( int $form_id ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&form_id=' . $form_id ), self::ACTION );
	}

	/**
	 * Stream the CSV file.
	 */
	public function download(): void {
		$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.

		if ( ! current_user_can( Capabilities::VIEW_BOOKINGS ) || ! check_admin_referer( self::ACTION ) ) {
			wp_die( esc_html__( 'You are not allowed to export entries.', 'bookora' ), 403 );
		}

		$form = $this->forms->find( $form_id );
		if ( ! $form ) {
			wp_die( esc_html__( 'Not found.', 'bookora' ), 404 );
		}

		[ $header, $rows ] = $this->entries->export( $form_id );

		/**
		 * CSV delimiter. Semicolon by default for German/Bulgarian Excel, comma otherwise.
		 *
		 * @param string $delimiter Delimiter.
		 */
		$delimiter = (string) apply_filters( 'bookora_csv_delimiter', preg_match( '/^(de|bg|fr|it|es|nl|pl|ru)/', determine_locale() ) ? ';' : ',' );
		$filename  = sanitize_file_name( sanitize_title( $form['title'] ) . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming output.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so Excel shows umlauts.
		fputcsv( $out, $header, $delimiter, '"', '' );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row, $delimiter, '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
