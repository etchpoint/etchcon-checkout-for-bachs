<?php
/**
 * Payment intent tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Core\Payment;

use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use Etchpoint\BachsIntegrations\Core\Payment\ProviderStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests immutable payment intent invariants.
 */
final class PaymentIntentTest extends TestCase {
	/**
	 * Verify a valid new intent starts in safe initial states.
	 *
	 * @return void
	 */
	public function test_new_intent_starts_created_and_pending(): void {
		$intent = $this->create_valid_intent();

		self::assertSame( ProviderStatus::CREATED, $intent->provider_status() );
		self::assertSame( ApplicationStatus::PENDING, $intent->application_status() );
		self::assertSame( '2500.00', $intent->expected_amount()->amount() );
		self::assertSame( 1, $intent->attempt() );
	}

	/**
	 * Verify zero-value checkouts cannot become payment intents.
	 *
	 * @return void
	 */
	public function test_zero_amount_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PaymentIntent::create(
			'7d90c3d9-9780-4d20-8b55-ec72575a0fd5',
			'woocommerce',
			'order',
			'1847',
			PaymentIntent::ENVIRONMENT_SANDBOX,
			'etp_bch_reference',
			'etp:site:woo:1847:checkout:1',
			Money::from_decimal( '0', Currency::from_code( 'NGN' ) )
		);
	}

	/**
	 * Verify a non-v4 UUID is rejected.
	 *
	 * @return void
	 */
	public function test_invalid_uuid_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PaymentIntent::create(
			'not-a-uuid',
			'woocommerce',
			'order',
			'1847',
			PaymentIntent::ENVIRONMENT_SANDBOX,
			'etp_bch_reference',
			'etp:site:woo:1847:checkout:1',
			Money::from_decimal( '2500', Currency::from_code( 'NGN' ) )
		);
	}

	/**
	 * Verify unknown environments are rejected.
	 *
	 * @return void
	 */
	public function test_unknown_environment_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		PaymentIntent::create(
			'7d90c3d9-9780-4d20-8b55-ec72575a0fd5',
			'woocommerce',
			'order',
			'1847',
			'production',
			'etp_bch_reference',
			'etp:site:woo:1847:checkout:1',
			Money::from_decimal( '2500', Currency::from_code( 'NGN' ) )
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
