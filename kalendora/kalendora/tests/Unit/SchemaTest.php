<?php
namespace Kalendora\Tests\Unit;

use Kalendora\Install\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase {

	public function test_every_table_has_a_statement(): void {
		$sql = Schema::statements( 'wp_', 'DEFAULT CHARSET=utf8mb4' );
		$this->assertSame( Schema::TABLES, array_keys( $sql ) );
	}

	public function test_statements_follow_dbdelta_rules(): void {
		foreach ( Schema::statements( 'wp_', 'DEFAULT CHARSET=utf8mb4' ) as $name => $sql ) {
			$this->assertStringContainsString( 'CREATE TABLE wp_kalendora_' . $name . ' (', $sql );
			$this->assertMatchesRegularExpression( '/PRIMARY KEY  \(/', $sql, "$name: two spaces after PRIMARY KEY" );
			$this->assertStringNotContainsString( ' INDEX ', $sql, "$name: use KEY instead of INDEX" );
			$this->assertStringEndsWith( 'DEFAULT CHARSET=utf8mb4;', $sql );
		}
	}

	public function test_custom_prefix(): void {
		$this->assertSame( 'site2_kalendora_bookings', Schema::table( 'site2_', 'bookings' ) );
	}
}
