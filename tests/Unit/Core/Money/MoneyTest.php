<?php
/**
 * Money value object tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Core\Money;

use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests exact decimal normalization without floating point.
 */
final class MoneyTest extends TestCase {
	/**
	 * Provide equivalent two-decimal inputs.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function equivalent_two_decimal_amounts(): array {
		return array(
			'integer'        => array( '50000' ),
			'one decimal'    => array( '50000.0' ),
			'two decimals'   => array( '50000.00' ),
			'leading zeroes' => array( '00050000.00' ),
		);
	}

	/**
	 * Verify equivalent decimal inputs normalize identically.
	 *
	 * @param string $input Input amount.
	 * @return void
	 */
	#[DataProvider( 'equivalent_two_decimal_amounts' )]
	public function test_equivalent_amounts_normalize_to_same_value( string $input ): void {
		$money = Money::from_decimal( $input, Currency::from_code( 'NGN' ) );

		self::assertSame( '50000.00', $money->amount() );
	}

	/**
	 * Provide malformed decimal strings.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_amounts(): array {
		return array(
			'negative'         => array( '-1.00' ),
			'positive sign'    => array( '+1.00' ),
			'exponent'         => array( '1e3' ),
			'comma'            => array( '1,000.00' ),
			'leading space'    => array( ' 1.00' ),
			'trailing space'   => array( '1.00 ' ),
			'decimal only'     => array( '.50' ),
			'trailing decimal' => array( '1.' ),
		);
	}

	/**
	 * Verify unsafe or locale-formatted inputs are rejected.
	 *
	 * @param string $input Input amount.
	 * @return void
	 */
	#[DataProvider( 'malformed_amounts' )]
	public function test_malformed_amount_is_rejected( string $input ): void {
		$this->expectException( InvalidArgumentException::class );

		Money::from_decimal( $input, Currency::from_code( 'USD' ) );
	}

	/**
	 * Verify currency decimal scale is enforced.
	 *
	 * @return void
	 */
	public function test_fraction_exceeding_currency_scale_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		Money::from_decimal( '10.001', Currency::from_code( 'USD' ) );
	}

	/**
	 * Verify exact equality includes currency identity.
	 *
	 * @return void
	 */
	public function test_exact_equality_includes_currency(): void {
		$ngn = Money::from_decimal( '100', Currency::from_code( 'NGN' ) );
		$usd = Money::from_decimal( '100', Currency::from_code( 'USD' ) );

		self::assertFalse( $ngn->equals( $usd ) );
	}

	/**
	 * Verify zero and positive values can be distinguished without floats.
	 *
	 * @return void
	 */
	public function test_zero_and_positive_detection(): void {
		$currency = Currency::from_code( 'USD' );

		self::assertTrue( Money::from_decimal( '0', $currency )->is_zero() );
		self::assertFalse( Money::from_decimal( '0', $currency )->is_positive() );
		self::assertTrue( Money::from_decimal( '0.01', $currency )->is_positive() );
	}
}
