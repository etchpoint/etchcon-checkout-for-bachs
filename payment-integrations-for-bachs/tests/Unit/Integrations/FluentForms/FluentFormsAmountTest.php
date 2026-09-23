<?php
/**
 * Fluent Forms amount normalization tests.
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
	 * Two-decimal currencies are converted without floating point.
	 *
	 * @return void
	 */
	public function test_minor_units_are_converted_exactly(): void {
		self::assertSame( '25000.00', FluentFormsAmount::from_minor_units( 2500000, Currency::from_code( 'NGN' ) ) );
	}

	/**
	 * Zero-decimal currencies remain integer amounts.
	 *
	 * @return void
	 */
	public function test_zero_decimal_currency_is_supported(): void {
		self::assertSame( '5000', FluentFormsAmount::from_minor_units( '5000', Currency::from_code( 'JPY' ) ) );
	}

	/**
	 * Non-integer minor-unit values are rejected.
	 *
	 * @return void
	 */
	public function test_invalid_minor_units_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		FluentFormsAmount::from_minor_units( '12.50', Currency::from_code( 'USD' ) );
	}
}
