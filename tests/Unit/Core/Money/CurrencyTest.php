<?php
/**
 * Currency value object tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Core\Money;

use Etchpoint\BachsIntegrations\Core\Money\Currency;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests canonical currency identity and decimal scales.
 */
final class CurrencyTest extends TestCase {
	/**
	 * Verify currency codes are normalized to uppercase.
	 *
	 * @return void
	 */
	public function test_currency_code_is_canonicalized(): void {
		$currency = Currency::from_code( 'ngn' );

		self::assertSame( 'NGN', $currency->code() );
		self::assertSame( 2, $currency->decimal_scale() );
	}

	/**
	 * Verify known non-two-decimal ISO scales are represented exactly.
	 *
	 * @return void
	 */
	public function test_non_standard_decimal_scales_are_supported(): void {
		self::assertSame( 0, Currency::from_code( 'JPY' )->decimal_scale() );
		self::assertSame( 3, Currency::from_code( 'KWD' )->decimal_scale() );
		self::assertSame( 4, Currency::from_code( 'CLF' )->decimal_scale() );
	}

	/**
	 * Verify malformed currency codes are rejected.
	 *
	 * @return void
	 */
	public function test_malformed_currency_code_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		Currency::from_code( 'US$' );
	}
}
