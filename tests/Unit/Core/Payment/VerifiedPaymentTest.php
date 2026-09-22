<?php
/**
 * Verified payment tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Core\Payment;

use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use Etchpoint\BachsIntegrations\Core\Payment\VerifiedPayment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests immutable intent correlation performed by VerifiedPayment.
 */
final class VerifiedPaymentTest extends TestCase {
	/**
	 * Verify matching evidence can produce a verified payment value object.
	 *
	 * @return void
	 */
	public function test_matching_evidence_creates_verified_payment(): void {
		$intent = $this->create_valid_intent();

		$payment = VerifiedPayment::from_verified_evidence(
			$intent,
			$intent->reference(),
			'evt_123',
			'checkout_123',
			'charge_123',
			Money::from_decimal( '2500.00', Currency::from_code( 'NGN' ) )
		);

		self::assertSame( $intent->uuid(), $payment->intent_uuid() );
		self::assertSame( 'charge_123', $payment->charge_id() );
		self::assertTrue( $intent->expected_amount()->equals( $payment->amount() ) );
	}

	/**
	 * Verify amount mismatch cannot produce verified payment evidence.
	 *
	 * @return void
	 */
	public function test_amount_mismatch_is_rejected(): void {
		$intent = $this->create_valid_intent();

		$this->expectException( InvalidArgumentException::class );

		VerifiedPayment::from_verified_evidence(
			$intent,
			$intent->reference(),
			'evt_123',
			'checkout_123',
			'charge_123',
			Money::from_decimal( '2499.99', Currency::from_code( 'NGN' ) )
		);
	}

	/**
	 * Verify currency mismatch cannot produce verified payment evidence.
	 *
	 * @return void
	 */
	public function test_currency_mismatch_is_rejected(): void {
		$intent = $this->create_valid_intent();

		$this->expectException( InvalidArgumentException::class );

		VerifiedPayment::from_verified_evidence(
			$intent,
			$intent->reference(),
			'evt_123',
			'checkout_123',
			'charge_123',
			Money::from_decimal( '2500.00', Currency::from_code( 'USD' ) )
		);
	}

	/**
	 * Verify reference mismatch cannot produce verified payment evidence.
	 *
	 * @return void
	 */
	public function test_reference_mismatch_is_rejected(): void {
		$intent = $this->create_valid_intent();

		$this->expectException( InvalidArgumentException::class );

		VerifiedPayment::from_verified_evidence(
			$intent,
			'etp_bch_wrong_reference',
			'evt_123',
			'checkout_123',
			'charge_123',
			Money::from_decimal( '2500.00', Currency::from_code( 'NGN' ) )
		);
	}

	/**
	 * Create a valid payment intent fixture.
	 *
	 * @return PaymentIntent
	 */
	private function create_valid_intent(): PaymentIntent {
		return PaymentIntent::create(
			'7d90c3d9-9780-4d20-8b55-ec72575a0fd5',
			'woocommerce',
			'order',
			'1847',
			PaymentIntent::ENVIRONMENT_SANDBOX,
			'etp_bch_reference',
			'etp:site:woo:1847:checkout:1',
			Money::from_decimal( '2500', Currency::from_code( 'NGN' ) )
		);
	}
}
