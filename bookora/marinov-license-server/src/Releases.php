<?php
/**
 * Release store: ZIP files in a protected folder, metadata in an option.
 *
 * @package MarinovLicenseServer
 */

namespace MarinovLicenseServer;

defined( 'ABSPATH' ) || exit;

/**
 * Releases.
 */
final class Releases {

	public const OPTION = 'mls_releases';

	/**
	 * Protected folder (not reachable from the web).
	 */
	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'mls-releases';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		foreach (
			array(
				'.htaccess'  => "Require all denied\nDeny from all\n",
				'index.php'  => "<?php\n// Silence is golden.\n",
				'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
			) as $file => $content
		) {
			if ( ! file_exists( $dir . '/' . $file ) ) {
				file_put_contents( $dir . '/' . $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return $dir;
	}

	/**
	 * All releases of a product, newest first.
	 *
	 * @param string $product Product.
	 * @return list<array<string, mixed>>
	 */
	public static function all( string $product ): array {
		$all  = (array) get_option( self::OPTION, array() );
		$list = (array) ( $all[ $product ] ?? array() );
		usort( $list, static fn( $a, $b ) => version_compare( (string) $b['version'], (string) $a['version'] ) );
		return $list;
	}

	/**
	 * Newest release.
	 *
	 * @param string $product Product.
	 * @return array<string, mixed>|null
	 */
	public static function latest( string $product ): ?array {
		return self::all( $product )[0] ?? null;
	}

	/**
	 * Add a release from an uploaded/local ZIP (moved into the protected folder).
	 *
	 * @param string               $product Product slug.
	 * @param string               $source  Path of the ZIP.
	 * @param array<string, mixed> $meta    version, changelog, requires, tested, requires_php.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function add( string $product, string $source, array $meta ) {
		$product = sanitize_key( $product );
		$version = (string) ( $meta['version'] ?? '' );
		if ( '' === $product || ! preg_match( '/^\d+\.\d+(\.\d+)?(-[a-z0-9.]+)?$/i', $version ) ) {
			return new \WP_Error( 'mls_release', __( 'Please enter a product and a version such as 1.2.0.', 'marinov-license-server' ) );
		}
		$head = (string) file_get_contents( $source, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( "PK\x03\x04" !== $head ) {
			return new \WP_Error( 'mls_release_zip', __( 'The file is not a ZIP archive.', 'marinov-license-server' ) );
		}
		$name   = $product . '-' . $version . '-' . wp_generate_password( 12, false ) . '.zip';
		$target = self::dir() . '/' . $name;
		if ( ! @rename( $source, $target ) && ! @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return new \WP_Error( 'mls_release_move', __( 'The file could not be saved.', 'marinov-license-server' ) );
		}
		$release           = array(
			'version'      => $version,
			'file'         => $name,
			'changelog'    => wp_kses_post( (string) ( $meta['changelog'] ?? '' ) ),
			'requires'     => sanitize_text_field( (string) ( $meta['requires'] ?? '6.6' ) ),
			'tested'       => sanitize_text_field( (string) ( $meta['tested'] ?? '' ) ),
			'requires_php' => sanitize_text_field( (string) ( $meta['requires_php'] ?? '8.1' ) ),
			'date'         => gmdate( 'Y-m-d' ),
		);
		$all               = (array) get_option( self::OPTION, array() );
		$all[ $product ]   = array_values( array_filter( (array) ( $all[ $product ] ?? array() ), static fn( $r ) => $r['version'] !== $version ) );
		$all[ $product ][] = $release;
		update_option( self::OPTION, $all, false );
		return $release;
	}

	/**
	 * Path of a release file.
	 *
	 * @param array<string, mixed> $release Release.
	 */
	public static function path( array $release ): string {
		return self::dir() . '/' . basename( (string) $release['file'] );
	}

	/**
	 * What the plugin may see (no file name).
	 *
	 * @param array<string, mixed> $release Release.
	 * @return array<string, mixed>
	 */
	public static function public_info( array $release ): array {
		return array_intersect_key( $release, array_flip( array( 'version', 'changelog', 'requires', 'tested', 'requires_php', 'date' ) ) );
	}
}
