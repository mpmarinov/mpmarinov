<?php
/**
 * Minimal standalone page (one-click links, customer manage page).
 *
 * @package Bookora
 */

namespace Bookora\Frontend;

use Bookora\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a small, theme-independent page and stops. Works on every theme, no JavaScript.
 */
final class StandalonePage {

	/**
	 * Output the page and exit.
	 *
	 * @param Settings $settings Settings.
	 * @param string   $title    Title (plain text).
	 * @param string   $body     Escaped HTML.
	 * @param string   $tone     success|neutral.
	 * @param int      $status   HTTP status.
	 */
	public static function output( Settings $settings, string $title, string $body, string $tone = 'neutral', int $status = 200 ): void {
		$color = (string) $settings->get( 'primary_color', '#2563eb' );
		$color = sanitize_hex_color( $color ) ? $color : '#2563eb';
		$name  = (string) $settings->get( 'business_name', '' );
		$name  = '' !== $name ? $name : get_bloginfo( 'name' );

		nocache_headers();
		status_header( $status );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html( $title . ' – ' . $name ); ?></title>
<style>
:root{--p:<?php echo esc_attr( $color ); ?>}
*{box-sizing:border-box}
body{margin:0;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f3f4f6;color:#1f2937}
.card{max-width:520px;margin:40px auto;background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08);overflow:hidden}
.head{background:var(--p);color:#fff;padding:18px 24px;font-weight:600}
.body{padding:24px}h1{font-size:22px;margin:0 0 16px}h2{font-size:17px;margin:22px 0 10px}
dl{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin:0 0 20px;padding:16px;background:#f9fafb;border-radius:10px}
dt{color:#6b7280}dd{margin:0;font-weight:500}
.btn{display:block;width:100%;padding:14px;border:0;border-radius:10px;font:inherit;font-weight:600;color:#fff;cursor:pointer;text-align:center;text-decoration:none;margin:0 0 10px;background:var(--p)}
.btn:focus-visible,.chip:focus-visible,.slot:focus-visible,a:focus-visible,textarea:focus-visible,input:focus-visible{outline:3px solid #111827;outline-offset:2px}
.btn-confirm{background:#15803d}.btn-reject{background:#b91c1c}
.btn-secondary{background:#fff;color:#1f2937;border:1px solid #d1d5db}
.hint{color:#4b5563;font-size:14px;margin-top:12px}.ok{color:<?php echo 'success' === $tone ? '#15803d' : '#374151'; ?>;font-weight:600}
.notice{padding:12px 14px;border-radius:10px;background:#fef3c7;color:#78350f;margin:0 0 16px}
.notice-error{background:#fee2e2;color:#7f1d1d}
.policy{padding:12px 14px;border-radius:10px;background:#eff6ff;color:#1e3a8a;margin:0 0 16px;font-size:15px}
.link{color:var(--p)}
.chips,.slots{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px;padding:0;list-style:none}
.chip{display:inline-block;padding:8px 12px;border-radius:999px;border:1px solid #d1d5db;color:#1f2937;text-decoration:none;font-size:15px}
.chip[aria-current="date"]{background:var(--p);border-color:var(--p);color:#fff}
.slot{padding:10px 14px;border-radius:10px;border:1px solid var(--p);background:#fff;color:var(--p);font:inherit;font-weight:600;cursor:pointer;min-width:84px}
.slot:hover{background:var(--p);color:#fff}
label{display:block;font-weight:600;margin:0 0 6px}
textarea{width:100%;min-height:80px;padding:10px;border:1px solid #d1d5db;border-radius:10px;font:inherit;margin:0 0 14px}
.status{display:inline-block;padding:2px 10px;border-radius:999px;font-size:14px;font-weight:600;background:#e5e7eb}
.status-confirmed{background:#dcfce7;color:#166534}.status-pending{background:#fef3c7;color:#92400e}.status-cancelled,.status-rejected{background:#fee2e2;color:#991b1b}
@media (max-width:560px){.card{margin:0;border-radius:0;min-height:100vh}}
</style>
</head>
<body>
<main class="card">
	<div class="head"><?php echo esc_html( $name ); ?></div>
	<div class="body">
		<h1><?php echo esc_html( $title ); ?></h1>
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
	</div>
</main>
</body>
</html>
		<?php
		exit;
	}
}
