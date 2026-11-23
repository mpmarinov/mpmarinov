<?php
namespace Bookora\Tests\Unit;

use Bookora\Tests\Simulation\MixedDay;
use PHPUnit\Framework\TestCase;

/**
 * 200 random days per variant with mixed treatments: the rules must always hold.
 */
final class MixedDaySimulationTest extends TestCase {

	public static function variants(): array {
		return array(
			'fixed grid'      => array( false, 0 ),
			'fill gaps'       => array( true, 0 ),
			'grid + limit 5'  => array( false, 5 ),
			'gaps + limit 5'  => array( true, 5 ),
		);
	}

	/**
	 * @dataProvider variants
	 */
	public function test_rules_hold_for_random_mixed_days( bool $fill_gaps, int $limit ): void {
		$windows = MixedDay::windows();

		for ( $seed = 1; $seed <= 200; $seed++ ) {
			$day = MixedDay::run( $seed, $fill_gaps, array(), $limit );

			$this->assertNotEmpty( $day, "seed $seed: at least one booking" );
			if ( $limit ) {
				$this->assertLessThanOrEqual( $limit, count( $day ), "seed $seed: daily limit" );
			}

			foreach ( $day as $i => $b ) {
				$inside = false;
				foreach ( $windows as [ $open, $close ] ) {
					$inside = $inside || ( $b['start'] >= $open && $b['end'] <= $close );
				}
				$this->assertTrue( $inside, "seed $seed: treatment inside working hours" );

				if ( $i > 0 ) {
					$prev = $day[ $i - 1 ];
					$this->assertGreaterThanOrEqual( $prev['block_end'], $b['block_start'], "seed $seed: no overlap incl. breaks ({$prev['service']} → {$b['service']})" );
				}
			}
		}
	}

	public function test_fill_gaps_never_books_less_on_average(): void {
		$grid = $gaps = 0;
		for ( $seed = 1; $seed <= 200; $seed++ ) {
			$grid += count( MixedDay::run( $seed, false ) );
			$gaps += count( MixedDay::run( $seed, true ) );
		}
		$this->assertGreaterThanOrEqual( $grid, $gaps );
	}
}
