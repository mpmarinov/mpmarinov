<?php
/**
 * Prints statistics for mixed-treatment days and writes an HTML timeline.
 * Usage: php tests/Simulation/report.php [days=1000] [html-output-path]
 *
 * @package Bookora
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

use Bookora\Tests\Simulation\MixedDay;

$days    = (int) ( $argv[1] ?? 1000 );
$html    = $argv[2] ?? '';
$windows = MixedDay::windows();
$open    = array_sum( array_map( static fn( $w ) => ( $w[1] - $w[0] ) / 60, $windows ) );
$result  = array();

foreach ( array( 'Festes Raster' => false, 'Lücken füllen' => true ) as $label => $fill ) {
	$counts = $treat = $idle = array();
	$per    = array();
	for ( $seed = 1; $seed <= $days; $seed++ ) {
		$day      = MixedDay::run( $seed, $fill );
		$counts[] = count( $day );
		$t        = 0;
		$blocked  = 0;
		foreach ( $day as $b ) {
			$t       += ( $b['end'] - $b['start'] ) / 60;
			foreach ( $windows as [ $wa, $wb ] ) {
				$blocked += max( 0, min( $b['block_end'], $wb ) - max( $b['block_start'], $wa ) ) / 60;
			}
			$per[ $b['service'] ] = ( $per[ $b['service'] ] ?? 0 ) + 1;
		}
		$treat[] = $t;
		$idle[]  = $open - $blocked;
	}
	$avg              = static fn( $a ) => array_sum( $a ) / count( $a );
	$result[ $label ] = array(
		'avg'   => $avg( $counts ),
		'min'   => min( $counts ),
		'max'   => max( $counts ),
		'util'  => $avg( $treat ) / $open * 100,
		'idle'  => $avg( $idle ),
		'per'   => array_map( static fn( $n ) => $n / $days, $per ),
	);
}

printf( "Simulation: %d zufällige Tage je Variante, Arbeitszeit 08:00–12:30 + 13:30–19:00 (%d min)\n\n", $days, $open );
printf( "%-17s %10s %6s %6s %14s %18s\n", 'Variante', 'Ø Termine', 'min', 'max', 'Ø Auslastung', 'Ø ungenutzt (min)' );
foreach ( $result as $label => $r ) {
	printf( "%-17s %10.1f %6d %6d %13.0f%% %18.0f\n", $label, $r['avg'], $r['min'], $r['max'], $r['util'], $r['idle'] );
}

if ( $html ) {
	$tz   = new DateTimeZone( 'Europe/Berlin' );
	$t0   = $windows[0][0];
	$span = end( $windows )[1] - $t0;
	$col  = array( '#1f6f50', '#2563eb', '#b45309', '#7c3aed' );
	$cmap = array_combine( array_keys( MixedDay::services() ), $col );
	$pct  = static fn( $t ) => round( ( $t - $t0 ) / $span * 100, 3 );
	$hm   = static fn( $t ) => ( new DateTimeImmutable( '@' . $t ) )->setTimezone( $tz )->format( 'H:i' );

	ob_start();
	echo '<!doctype html><meta charset="utf-8"><title>Gemischte Tage</title><style>body{font:14px/1.4 system-ui,sans-serif;margin:24px;color:#1e1e1e;background:#fff;max-width:1100px}h1{font-size:20px}h2{font-size:16px;margin:28px 0 8px}.row{display:flex;align-items:center;gap:12px;margin:6px 0}.lab{width:60px;color:#555;font-size:12px}.bar{position:relative;flex:1;height:34px;background:repeating-linear-gradient(90deg,#f3f4f6 0 1px,transparent 1px 10%);border:1px solid #e5e7eb;border-radius:6px;overflow:hidden}.closed{position:absolute;top:0;bottom:0;background:#e5e7eb}.t,.b{position:absolute;top:3px;bottom:3px;border-radius:4px}.t{color:#fff;font-size:10px;padding:2px 4px;overflow:hidden;white-space:nowrap}.b{background:repeating-linear-gradient(45deg,#d1d5db 0 4px,#f3f4f6 4px 8px)}.axis{display:flex;justify-content:space-between;margin-left:72px;font-size:11px;color:#777}.legend span{display:inline-flex;align-items:center;gap:6px;margin-right:16px;font-size:12px}.legend i{width:12px;height:12px;border-radius:3px;display:inline-block}table{border-collapse:collapse;margin:8px 0}td,th{border:1px solid #e5e7eb;padding:6px 10px;text-align:right}th:first-child,td:first-child{text-align:left}</style>';
	echo '<h1>Gemischte Behandlungen – 8 Beispieltage je Variante</h1><div class="legend">';
	foreach ( $cmap as $name => $c ) {
		echo '<span><i style="background:' . $c . '"></i>' . htmlspecialchars( $name ) . '</span>';
	}
	echo '<span><i style="background:repeating-linear-gradient(45deg,#d1d5db 0 4px,#f3f4f6 4px 8px)"></i>Pause nach Behandlung</span><span><i style="background:#e5e7eb"></i>Mittagspause</span></div>';
	echo '<table><tr><th>Variante</th><th>Ø Termine/Tag</th><th>min–max</th><th>Ø Auslastung</th><th>Ø ungenutzt</th></tr>';
	foreach ( $result as $label => $r ) {
		printf( '<tr><td>%s</td><td>%.1f</td><td>%d–%d</td><td>%.0f %%</td><td>%.0f min</td></tr>', $label, $r['avg'], $r['min'], $r['max'], $r['util'], $r['idle'] );
	}
	echo '</table>';
	foreach ( array( 'Festes Raster' => false, 'Lücken füllen' => true ) as $label => $fill ) {
		echo '<h2>' . $label . '</h2><div class="axis">';
		for ( $h = 8; $h <= 19; $h++ ) {
			echo '<span>' . sprintf( '%02d', $h ) . '</span>';
		}
		echo '</div>';
		for ( $seed = 1; $seed <= 8; $seed++ ) {
			$day = MixedDay::run( $seed, $fill );
			echo '<div class="row"><span class="lab">Tag ' . $seed . '<br>' . count( $day ) . ' Term.</span><div class="bar">';
			echo '<div class="closed" style="left:' . $pct( $windows[0][1] ) . '%;width:' . ( $pct( $windows[1][0] ) - $pct( $windows[0][1] ) ) . '%"></div>';
			foreach ( $day as $b ) {
				echo '<div class="b" style="left:' . $pct( $b['end'] ) . '%;width:' . ( $pct( $b['block_end'] ) - $pct( $b['end'] ) ) . '%"></div>';
				echo '<div class="t" title="' . htmlspecialchars( $b['service'] ) . '" style="background:' . $cmap[ $b['service'] ] . ';left:' . $pct( $b['start'] ) . '%;width:' . ( $pct( $b['end'] ) - $pct( $b['start'] ) ) . '%">' . $hm( $b['start'] ) . '</div>';
			}
			echo '</div></div>';
		}
	}
	file_put_contents( $html, ob_get_clean() );
	echo "\nHTML: $html\n";
}
