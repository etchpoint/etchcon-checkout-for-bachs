<?php
/**
 * Fluent Forms amount conversion tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Integrations\FluentForms;

use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Integrations\FluentForms\FluentFormsAmount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies exact conversion from Fluent Forms minor units.
 */
final class FluentFormsAmountTest extends TestCase {
	/**
	 * Two-decimal currencies are converted without floating point arithmetic.
	 *
	 * @return void
	 */
	public function test_two_decimal_minor_units_are_converted_exactly(): void {
		self::assertSame( '2500.50', FluentFormsAmount::from_minor_units( 250050, Currency::from_code( 'NGN' ) ) );
		self::assertSame( '0.05', FluentFormsAmount::from_minor_units( '5', Currency::from_code( 'USD' ) ) );
	}

	/**
	 * Zero-decimal currencies remain whole numbers.
	 *
	 * @return void
	 */
	public function test_zero_decimal_currency_remains_integer_decimal(): void {
		$currency = Currency::from_code( 'JPY' );

		self::assertSame( '5000', FluentFormsAmount::from_minor_units( '005000', $currency ) );
	}

	/**
	 * Malformed minor-unit input is rejected.
	 *
	 * @return void
	 */
	public function test_malformed_minor_units_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		FluentFormsAmount::from_minor_units( '10.50', Currency::from_code( 'USD' ) );
	}
}
