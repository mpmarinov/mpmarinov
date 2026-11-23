<?php
/**
 * PSR-4 autoloader for the Bookora namespace.
 *
 * Uses the Composer autoloader when present (development), otherwise a
 * lightweight fallback so the released plugin has no runtime dependencies.
 *
 * @package Bookora
 */

defined( 'ABSPATH' ) || exit;

$bookora_composer = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( is_readable( $bookora_composer ) ) {
	require_once $bookora_composer;
	return;
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Bookora\\';

		if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
