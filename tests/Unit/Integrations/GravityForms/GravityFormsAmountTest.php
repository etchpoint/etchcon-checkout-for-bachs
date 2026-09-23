<?php
/**
 * Gravity Forms amount normalization tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Integrations\GravityForms;

use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Integrations\GravityForms\GravityFormsAmount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies canonical conversion at the Gravity Forms payment boundary.
 */
final class GravityFormsAmountTest extends TestCase {
	/**
	 * Float payment amounts are immediately canonicalized to the currency scale.
	 *
	 * @return void
	 */
	public function test_float_amount_is_canonicalized(): void {
		self::assertSame( '1250.50', GravityFormsAmount::normalize( 1250.5, Currency::from_code( 'NGN' ) ) );
	}

	/**
	 * String payment amounts preserve exact decimal data.
	 *
	 * @return void
	 */
	public function test_string_amount_is_canonicalized_without_float_conversion(): void {
		self::assertSame( '50000.00', GravityFormsAmount::normalize( '50000', Currency::from_code( 'NGN' ) ) );
	}

	/**
	 * Invalid boundary values are rejected.
	 *
	 * @return void
	 */
	public function test_invalid_amount_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		GravityFormsAmount::normalize( '-1', Currency::from_code( 'USD' ) );
	}
}
