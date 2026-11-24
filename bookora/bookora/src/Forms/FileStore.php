<?php
/**
 * Private storage for uploaded files and signatures.
 *
 * @package Bookora
 */

namespace Bookora\Forms;

use Bookora\Install\Schema;
use Bookora\Security\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Security model:
 *  - Files live in uploads/bookora-private/YYYY/MM/ under a random 32-hex name WITHOUT extension,
 *    so they can never be executed, even on servers that ignore .htaccess (nginx).
 *    The folder is additionally locked with .htaccess / web.config / index.php.
 *  - Only an allow-list of harmless types (no SVG, HTML, scripts); the real content type is checked
 *    (wp_check_filetype_and_ext + image check), not only the file name.
 *  - Visitors only get an unguessable token back. The token is bound to form and field, can be used
 *    once, and expires after 24 hours if the form is not submitted (daily clean-up).
 *  - Download only for logged-in users with the "view bookings" capability, with nonce, as
 *    attachment (images inline) plus nosniff and a sandbox CSP.
 */
final class FileStore {

	public const DIR = 'bookora-private';

	/**
	 * Types that may ever be allowed (the form field narrows this further).
	 */
	public const SAFE_TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'heic' => 'image/heic',
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'odt'  => 'application/vnd.oasis.opendocument.text',
		'txt'  => 'text/plain',
		'csv'  => 'text/csv',
		'zip'  => 'application/zip',
	);

	private const INLINE = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Hours an unclaimed upload is kept.
	 */
	public const ORPHAN_HOURS = 24;

	/**
	 * Max. size of a drawn signature (bytes).
	 */
	public const SIGNATURE_MAX = 300 * 1024;

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_post_bookora_file', array( $this, 'download' ) );
	}

	/**
	 * Table name.
	 */
	private static function table(): string {
		global $wpdb;
		return Schema::table( $wpdb->prefix, 'files' );
	}

	/**
	 * Base directory (created and locked on first use).
	 */
	public static function base_dir(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( (string) $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$guards = array(
			'.htaccess'  => "# Bookora: private files, no direct access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $guards as $file => $content ) {
			if ( ! file_exists( $dir . '/' . $file ) ) {
				file_put_contents( $dir . '/' . $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return $dir;
	}

	/**
	 * Effective upload limit for a field in bytes (field setting, but never above the server limit).
	 *
	 * @param array<string, mixed> $field Field.
	 */
	public static function max_bytes( array $field ): int {
		$mb = max( 1, min( 50, (int) ( $field['maxSize'] ?? 5 ) ) );
		return (int) min( $mb * MB_IN_BYTES, wp_max_upload_size() );
	}

	/**
	 * Allowed extensions of a field.
	 *
	 * @param array<string, mixed> $field Field.
	 * @return string[]
	 */
	public static function extensions( array $field ): array {
		$ext = array_values( array_intersect( array_map( 'strtolower', (array) ( $field['extensions'] ?? array( 'jpg', 'png', 'pdf' ) ) ), array_keys( self::SAFE_TYPES ) ) );
		if ( in_array( 'jpg', $ext, true ) && ! in_array( 'jpeg', $ext, true ) ) {
			$ext[] = 'jpeg';
		}
		return $ext ? $ext : array( 'jpg', 'jpeg', 'png', 'pdf' );
	}

	/**
	 * Store an uploaded file (from $_FILES) for a form field.
	 *
	 * @param array<string, mixed> $form  Form.
	 * @param array<string, mixed> $field Field (type file).
	 * @param array<string, mixed> $file  Entry of $_FILES.
	 * @param string               $ip    Client IP.
	 * @return array{id:int, token:string, name:string, size:int}|WP_Error
	 */
	public function store_upload( array $form, array $field, array $file, string $ip ) {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return self::too_big( $field );
		}
		if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) ) {
			return new WP_Error( 'bookora_upload_failed', __( 'The file could not be uploaded. Please try again.', 'bookora' ), array( 'status' => 400 ) );
		}
		$tmp = $file['tmp_name'];
		// Real uploads only (tests may pass a file created by the test itself via the filter).
		if ( ! is_uploaded_file( $tmp ) && ! apply_filters( 'bookora_allow_local_upload', false, $tmp ) ) {
			return new WP_Error( 'bookora_upload_failed', __( 'The file could not be uploaded. Please try again.', 'bookora' ), array( 'status' => 400 ) );
		}

		$size = (int) filesize( $tmp );
		if ( $size <= 0 ) {
			return new WP_Error( 'bookora_upload_empty', __( 'The file is empty.', 'bookora' ), array( 'status' => 400 ) );
		}
		if ( $size > self::max_bytes( $field ) ) {
			return self::too_big( $field );
		}

		$name = self::clean_name( (string) ( $file['name'] ?? 'file' ) );
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$mime = self::verify_type( $tmp, $name, $ext, self::extensions( $field ) );
		if ( is_wp_error( $mime ) ) {
			return $mime;
		}

		return $this->save( (int) $form['id'], (string) $field['key'], 'upload', $name, $mime, $tmp, $ip, true );
	}

	/**
	 * Store a drawn signature (PNG data URL).
	 *
	 * @param int    $form_id   Form id.
	 * @param string $field_key Field key.
	 * @param string $data_url  PNG data URL (data:image/png;base64,...).
	 * @param string $ip        Client IP.
	 * @return array{id:int, token:string, name:string, size:int}|WP_Error
	 */
	public function store_signature( int $form_id, string $field_key, string $data_url, string $ip ) {
		$invalid = new WP_Error( 'invalid', __( 'Please sign again.', 'bookora' ) );
		if ( ! str_starts_with( $data_url, 'data:image/png;base64,' ) || strlen( $data_url ) > self::SIGNATURE_MAX * 4 / 3 + 64 ) {
			return $invalid;
		}
		$png = base64_decode( substr( $data_url, 22 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- image data.
		if ( false === $png || strlen( $png ) > self::SIGNATURE_MAX ) {
			return $invalid;
		}
		$info = @getimagesizefromstring( $png ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || IMAGETYPE_PNG !== $info[2] || $info[0] < 50 || $info[1] < 20 || $info[0] > 3000 || $info[1] > 1500 ) {
			return $invalid;
		}
		// Not wp_tempnam(): that lives in wp-admin/includes and is missing on front-end requests.
		$tmp = tempnam( get_temp_dir(), 'bksig' );
		if ( false === $tmp ) {
			return new WP_Error( 'bookora_upload_failed', __( 'The file could not be saved. Please try again.', 'bookora' ), array( 'status' => 500 ) );
		}
		file_put_contents( $tmp, $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = $this->save( $form_id, $field_key, 'signature', 'signature.png', 'image/png', $tmp, $ip, false );
		wp_delete_file( $tmp );
		return $result;
	}

	/**
	 * Unattached files for a submission, checked against form and field.
	 *
	 * @param mixed  $tokens    Raw tokens from the form.
	 * @param int    $form_id   Form id.
	 * @param string $field_key Field key.
	 * @param int    $max       Max number of files.
	 * @return array<int, array<string, mixed>>|WP_Error Rows (id, token, name, size).
	 */
	public function claimable( $tokens, int $form_id, string $field_key, int $max ) {
		global $wpdb;
		$tokens = is_array( $tokens ) ? $tokens : ( '' === (string) ( is_scalar( $tokens ) ? $tokens : '' ) ? array() : array( $tokens ) );
		$tokens = array_values( array_unique( array_filter( $tokens, static fn( $t ) => is_string( $t ) && preg_match( '/^[a-f0-9]{32}$/', $t ) ) ) );
		if ( ! $tokens ) {
			return array();
		}
		if ( count( $tokens ) > $max ) {
			/* translators: %d: number of files. */
			return new WP_Error( 'invalid', sprintf( _n( 'Please upload at most %d file.', 'Please upload at most %d files.', $max, 'bookora' ), $max ) );
		}
		$in   = implode( ',', array_fill( 0, count( $tokens ), '%s' ) );
		$args = array_merge( $tokens, array( $form_id, $field_key, gmdate( 'Y-m-d H:i:s', time() - self::ORPHAN_HOURS * HOUR_IN_SECONDS ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, token, name, size FROM ' . self::table() . " WHERE token IN ({$in}) AND form_id = %d AND field_key = %s AND entry_id IS NULL AND created_at > %s", $args ), ARRAY_A );
		if ( count( $rows ) !== count( $tokens ) ) {
			return new WP_Error( 'invalid', __( 'An uploaded file has expired. Please upload it again.', 'bookora' ) );
		}
		return $rows;
	}

	/**
	 * Attach files to the stored entry.
	 *
	 * @param int[] $ids      File ids.
	 * @param int   $entry_id Entry id.
	 */
	public function attach( array $ids, int $entry_id ): void {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! $entry_id ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- integer list.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET entry_id = %d WHERE entry_id IS NULL AND id IN (' . implode( ',', $ids ) . ')', $entry_id ) );
	}

	/**
	 * Files of an entry, grouped by field key, with download links.
	 *
	 * @param int $entry_id Entry id.
	 * @return array<string, array<int, array{id:int, name:string, size:int, mime:string, kind:string, url:string}>>
	 */
	public static function for_entry( int $entry_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- internal table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, field_key, kind, name, mime, size FROM ' . self::table() . ' WHERE entry_id = %d ORDER BY id', $entry_id ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r['field_key'] ][] = array(
				'id'   => (int) $r['id'],
				'name' => (string) $r['name'],
				'size' => (int) $r['size'],
				'mime' => (string) $r['mime'],
				'kind' => (string) $r['kind'],
				'url'  => self::download_url( (int) $r['id'] ),
			);
		}
		return $out;
	}

	/**
	 * Admin download URL (nonce-protected).
	 *
	 * @param int $id File id.
	 */
	public static function download_url( int $id ): string {
		// Not wp_nonce_url(): it HTML-encodes "&", which breaks the link when used outside HTML (REST, React).
		return add_query_arg(
			array(
				'action'   => 'bookora_file',
				'file'     => $id,
				'_wpnonce' => wp_create_nonce( 'bookora_file_' . $id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Delete files (and their database rows) of entries.
	 *
	 * @param int[] $entry_ids Entry ids.
	 */
	public static function delete_for_entries( array $entry_ids ): int {
		global $wpdb;
		$entry_ids = array_values( array_filter( array_map( 'intval', $entry_ids ) ) );
		if ( ! $entry_ids ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- integer list.
		$rows = (array) $wpdb->get_results( 'SELECT id, path FROM ' . self::table() . ' WHERE entry_id IN (' . implode( ',', $entry_ids ) . ')', ARRAY_A );
		return self::delete_rows( $rows );
	}

	/**
	 * Remove uploads that were never submitted.
	 *
	 * @param int $hours Age in hours.
	 */
	public static function purge_orphans( int $hours = self::ORPHAN_HOURS ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- internal table.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, path FROM ' . self::table() . ' WHERE entry_id IS NULL AND created_at < %s LIMIT 1000', gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ) ), ARRAY_A );
		return self::delete_rows( $rows );
	}

	/**
	 * Stream a file to an authorised user (admin-post.php?action=bookora_file).
	 */
	public function download(): void {
		$id = isset( $_GET['file'] ) ? absint( wp_unslash( $_GET['file'] ) ) : 0;
		if ( ! $id || ! check_admin_referer( 'bookora_file_' . $id ) || ! current_user_can( Capabilities::VIEW_BOOKINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to open this file.', 'bookora' ), 403 );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- internal table.
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d AND entry_id IS NOT NULL', $id ), ARRAY_A );
		$path = $row ? self::absolute( (string) $row['path'] ) : '';
		if ( ! $row || '' === $path || ! is_file( $path ) ) {
			wp_die( esc_html__( 'The file no longer exists.', 'bookora' ), 404 );
		}

		$mime   = in_array( $row['mime'], self::SAFE_TYPES, true ) ? (string) $row['mime'] : 'application/octet-stream';
		$inline = in_array( $mime, self::INLINE, true );
		$name   = self::clean_name( (string) $row['name'] );

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox" );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( '"', '', remove_accents( $name ) ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Check the real file type.
	 *
	 * @param string   $tmp     Temp file.
	 * @param string   $name    Original name.
	 * @param string   $ext     Extension.
	 * @param string[] $allowed Allowed extensions.
	 * @return string|WP_Error MIME type.
	 */
	private static function verify_type( string $tmp, string $name, string $ext, array $allowed ) {
		$wrong = new WP_Error(
			'bookora_upload_type',
			sprintf(
				/* translators: %s: list of file types. */
				__( 'This file type is not allowed. Allowed: %s', 'bookora' ),
				implode( ', ', array_diff( $allowed, array( 'jpeg' ) ) )
			),
			array( 'status' => 400 )
		);
		if ( '' === $ext || ! in_array( $ext, $allowed, true ) ) {
			return $wrong;
		}

		$mimes = array_intersect_key( self::SAFE_TYPES, array_flip( $allowed ) );
		$check = wp_check_filetype_and_ext( $tmp, $name, $mimes );
		$type  = (string) ( $check['type'] ?? '' );
		if ( '' === $type ) {
			// Plain text formats cannot be identified by content – accept them only if they contain no markup.
			if ( in_array( $ext, array( 'txt', 'csv' ), true ) ) {
				$head = (string) file_get_contents( $tmp, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( preg_match( '/<\s*(script|html|svg|\?php|iframe)/i', $head ) ) {
					return $wrong;
				}
				return self::SAFE_TYPES[ $ext ];
			}
			return $wrong;
		}
		if ( str_starts_with( $type, 'image/' ) && 'image/heic' !== $type ) {
			$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $info || $info['mime'] !== $type ) {
				return $wrong;
			}
		}
		return in_array( $type, self::SAFE_TYPES, true ) ? $type : $wrong;
	}

	/**
	 * Move a file into the private folder and insert the row.
	 *
	 * @param int    $form_id   Form.
	 * @param string $field_key Field.
	 * @param string $kind      upload|signature.
	 * @param string $name      Display name.
	 * @param string $mime      MIME type.
	 * @param string $tmp       Source file.
	 * @param string $ip        Client IP.
	 * @param bool   $uploaded  Source is a PHP upload (move) or a temp file (copy).
	 * @return array{id:int, token:string, name:string, size:int}|WP_Error
	 */
	private function save( int $form_id, string $field_key, string $kind, string $name, string $mime, string $tmp, string $ip, bool $uploaded ) {
		global $wpdb;
		$token = bin2hex( random_bytes( 16 ) );
		$rel   = gmdate( 'Y/m' ) . '/' . bin2hex( random_bytes( 16 ) );
		$dest  = self::base_dir() . '/' . $rel;
		wp_mkdir_p( dirname( $dest ) );

		$ok = $uploaded && is_uploaded_file( $tmp ) ? move_uploaded_file( $tmp, $dest ) : copy( $tmp, $dest );
		if ( ! $ok ) {
			return new WP_Error( 'bookora_upload_failed', __( 'The file could not be saved. Please try again.', 'bookora' ), array( 'status' => 500 ) );
		}
		chmod( $dest, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$size = (int) filesize( $dest );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'token'      => $token,
				'form_id'    => $form_id,
				'entry_id'   => null,
				'field_key'  => mb_substr( sanitize_key( $field_key ), 0, 64 ),
				'kind'       => $kind,
				'name'       => $name,
				'mime'       => $mime,
				'size'       => $size,
				'path'       => $rel,
				'ip_hash'    => '' !== $ip ? hash( 'sha256', $ip . wp_salt( 'nonce' ) ) : '',
				'created_at' => current_time( 'mysql', true ),
			)
		);
		if ( ! $inserted ) {
			wp_delete_file( $dest );
			return new WP_Error( 'bookora_upload_failed', __( 'The file could not be saved. Please try again.', 'bookora' ), array( 'status' => 500 ) );
		}
		return array(
			'id'    => (int) $wpdb->insert_id,
			'token' => $token,
			'name'  => $name,
			'size'  => $size,
		);
	}

	/**
	 * Delete files and rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows with id, path.
	 */
	private static function delete_rows( array $rows ): int {
		global $wpdb;
		if ( ! $rows ) {
			return 0;
		}
		foreach ( $rows as $r ) {
			$path = self::absolute( (string) $r['path'] );
			if ( '' !== $path && is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- integer list.
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_column( $rows, 'id' ) ) ) . ')' );
	}

	/**
	 * Absolute path of a stored relative path (rejects anything unexpected).
	 *
	 * @param string $rel Relative path.
	 */
	private static function absolute( string $rel ): string {
		if ( ! preg_match( '#^\d{4}/\d{2}/[a-f0-9]{32}$#', $rel ) ) {
			return '';
		}
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( (string) $uploads['basedir'] ) . self::DIR . '/' . $rel;
	}

	/**
	 * Safe display name.
	 *
	 * @param string $name Original name.
	 */
	public static function clean_name( string $name ): string {
		$name = sanitize_file_name( wp_basename( $name ) );
		$name = '' !== $name ? $name : 'file';
		return mb_substr( $name, -150 );
	}

	/**
	 * Error for too big files.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	private static function too_big( array $field ): WP_Error {
		return new WP_Error(
			'bookora_upload_size',
			sprintf(
				/* translators: %s: size, e.g. "5 MB". */
				__( 'The file is too large. Maximum: %s', 'bookora' ),
				size_format( self::max_bytes( $field ) )
			),
			array( 'status' => 413 )
		);
	}
}
