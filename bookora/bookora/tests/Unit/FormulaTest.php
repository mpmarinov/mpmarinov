<?php
/**
 * Calculated fields: parser, evaluation, field values.
 *
 * @package Bookora
 */

// phpcs:ignoreFile

namespace Bookora\Tests\Unit;

use Bookora\Forms\Formula;
use PHPUnit\Framework\TestCase;

final class FormulaTest extends TestCase {

	public function test_arithmetic_and_precedence(): void {
		$this->assertSame( 14.0, Formula::evaluate( '2 + 3 * 4', array() ) );
		$this->assertSame( 20.0, Formula::evaluate( '(2 + 3) * 4', array() ) );
		$this->assertSame( -6.0, Formula::evaluate( '-2 * 3', array() ) );
		$this->assertSame( 1.0, Formula::evaluate( '3 - -2 - 4', array() ) );
		$this->assertSame( 2.5, Formula::evaluate( '10 / 4', array() ) );
		$this->assertSame( 0.0, Formula::evaluate( '5 / 0', array() ), 'division by zero gives 0' );
		$this->assertSame( 8.0, Formula::evaluate( '2 * 2 * 2', array() ) );
		$this->assertSame( 1.0, Formula::evaluate( '8 / 4 / 2', array() ), 'left associative' );
	}

	public function test_variables_and_functions(): void {
		$vars = array( 'persons' => 3, 'service_price' => 49.5, 'extras' => 10 );
		$this->assertSame( 158.5, Formula::evaluate( '{persons} * {service_price} + {extras}', $vars ) );
		$this->assertSame( 0.0, Formula::evaluate( '{unknown} * 5', $vars ) );
		$this->assertSame( 3.33, Formula::evaluate( 'round(10 / 3, 2)', array() ) );
		$this->assertSame( 4.0, Formula::evaluate( 'ceil(3.1)', array() ) );
		$this->assertSame( 50.0, Formula::evaluate( 'max(20, {extras} * 5, 3)', $vars ) );
		$this->assertSame( 3.0, Formula::evaluate( 'min({persons}, 5)', $vars ) );
		$this->assertSame( 7.0, Formula::evaluate( 'abs(2 - 9)', array() ) );
		$this->assertSame( 6.0, Formula::evaluate( 'max(min(9, 6), 2)', array() ), 'nested functions' );
	}

	public function test_invalid_formulas_are_rejected(): void {
		foreach ( array( '', '2 +', '(2', '2)', '2 3', 'system(1)', 'phpinfo()', '{a} {b}', '1;2', '$x', 'max()', '"1"', '(1, 2)', '2 ** 3', '{A}' ) as $bad ) {
			$this->assertSame( '', Formula::sanitize( $bad ), "rejected: {$bad}" );
			$this->assertSame( 0.0, Formula::evaluate( $bad, array( 'a' => 1, 'b' => 2 ) ) );
		}
		$this->assertSame( '2*3', Formula::sanitize( '2×3' ) );
		$this->assertSame( '{a} + 1', Formula::sanitize( ' {a} + 1 ' ) );
		$this->assertSame( array( 'a', 'b' ), Formula::variables( '{a} * {b} + {a}' ) );
	}

	public function test_field_numbers(): void {
		$extras = array(
			'type'    => 'checkboxes',
			'options' => array(
				array( 'value' => 'wash', 'label' => 'Wash', 'price' => 5 ),
				array( 'value' => 'dry', 'label' => 'Dry', 'price' => 7.5 ),
				array( 'value' => '3', 'label' => 'Three' ),
			),
		);
		$this->assertSame( 12.5, Formula::field_number( $extras, array( 'wash', 'dry' ) ) );
		$this->assertSame( 3.0, Formula::field_number( $extras, array( '3' ) ), 'numeric value without price' );
		$this->assertSame( 0.0, Formula::field_number( $extras, array( 'evil' ) ) );
		$this->assertSame( 1.0, Formula::field_number( array( 'type' => 'consent' ), '1' ) );
		$this->assertSame( 4.0, Formula::field_number( array( 'type' => 'number' ), '4' ) );
		$this->assertSame( 0.0, Formula::field_number( array( 'type' => 'text' ), 'abc' ) );
	}

	public function test_compute_chain_in_field_order(): void {
		$fields = array(
			array( 'key' => 'persons', 'type' => 'number' ),
			array( 'key' => 'net', 'type' => 'calculation', 'formula' => '{persons} * {service_price}', 'decimals' => 2 ),
			array( 'key' => 'gross', 'type' => 'calculation', 'formula' => '{net} * 1.19', 'decimals' => 2 ),
			array( 'key' => 'hidden_calc', 'type' => 'calculation', 'formula' => '99' ),
		);
		$clean  = array( 'persons' => '2', 'net' => 'client says 1', 'gross' => '' );
		$this->assertSame( array( 'net' => 60.0, 'gross' => 71.4 ), Formula::compute( $fields, $clean, array( 'service_price' => 30 ) ) );
	}
}
