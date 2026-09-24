<?php
/**
 * Bachs provider response model tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs;

use Etchpoint\BachsIntegrations\Bachs\CheckoutSession;
use Etchpoint\BachsIntegrations\Bachs\ProviderPayment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies provider response parsing without inferring payment authorization.
 */
final class ProviderModelsTest extends TestCase {
	/**
	 * Checkout retrieval preserves raw charge/payment evidence.
	 *
	 * @return void
	 */
	public function test_checkout_session_preserves_nested_payment_id(): void {
		$checkout = CheckoutSession::from_api_response(
			array(
				'checkout_id'    => 'chk_123',
				'status'         => 'completed',
				'payment_status' => 'succeeded',
				'amount'         => '50.00',
				'currency'       => 'USD',
				'reference'      => 'ref_123',
				'charge'         => array( 'payment_id' => 'pay_123' ),
			)
		);

		self::assertSame( 'pay_123', $checkout->payment_id() );
		self::assertSame( 'succeeded', $checkout->payment_status() );
		self::assertSame( '50.00', $checkout->amount() );
	}

	/**
	 * Payment retrieval preserves exact provider evidence as strings.
	 *
	 * @return void
	 */
	public function test_provider_payment_preserves_exact_evidence(): void {
		$payment = ProviderPayment::from_api_response(
			array(
				'payment_id'          => 'pay_123',
				'status'              => 'succeeded',
				'amount'              => '75000.00',
				'amount_paid'         => '75000.00',
				'amount_remaining'    => '0.00',
				'settlement_amount'   => '48.25',
				'settlement_currency' => 'USD',
				'currency'            => 'NGN',
				'is_refundable'       => true,
				'reference'           => 'ref_123',
				'checkout_id'         => 'chk_123',
			)
		);

		self::assertSame( 'pay_123', $payment->payment_id() );
		self::assertSame( '75000.00', $payment->amount_paid() );
		self::assertSame( 'NGN', $payment->currency() );
		self::assertSame( '48.25', $payment->settlement_amount() );
		self::assertSame( 'USD', $payment->settlement_currency() );
		self::assertTrue( $payment->is_refundable() );
		self::assertSame( 'chk_123', $payment->checkout_id() );
	}

	/**
	 * Missing required payment evidence is rejected.
	 *
	 * @return void
	 */
	public function test_provider_payment_rejects_missing_required_fields(): void {
		$this->expectException( InvalidArgumentException::class );

		ProviderPayment::from_api_response(
			array(
				'payment_id' => 'pay_123',
				'status'     => 'succeeded',
			)
		);
	}
}
