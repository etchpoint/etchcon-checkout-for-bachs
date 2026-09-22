<?php
/**
 * Bachs checkout API tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs;

use Etchpoint\BachsIntegrations\Bachs\CheckoutApi;
use Etchpoint\BachsIntegrations\Bachs\Endpoints;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Tests\Support\Bachs\RecordingRequester;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the exact product-less checkout contract sent to Bachs.
 */
final class CheckoutApiTest extends TestCase {
	/**
	 * Trusted intent data controls amount, currency, reference, and idempotency.
	 *
	 * @return void
	 */
	public function test_raw_checkout_uses_trusted_intent_data(): void {
		$requester = new RecordingRequester(
			array(
				'checkout_id'  => 'chk_123',
				'checkout_url' => 'https://checkout.bachs.io/c/test',
				'status'       => 'open',
				'reference'    => 'etp_bch_ref',
			)
		);
		$api       = new CheckoutApi( $requester );
		$intent    = self::intent();

		$checkout = $api->create_raw_checkout(
			$intent,
			'https://merchant.example/success',
			'https://merchant.example/cancel',
			array( 'purpose' => 'checkout' )
		);

		$body = $requester->last_body();

		self::assertSame( Endpoints::CHECKOUT_SESSIONS, $requester->last_path() );
		self::assertSame( 'etp:site:woo:1847:checkout:1', $requester->last_idempotency_key() );
		self::assertSame( '50000.00', $body['pricing']['amount'] ?? null );
		self::assertSame( 'NGN', $body['pricing']['currency'] ?? null );
		self::assertSame( 'etp_bch_ref', $body['reference'] ?? null );
		self::assertArrayNotHasKey( 'product_cart', $body );
		self::assertArrayNotHasKey( 'currency_options', $body['pricing'] ?? array() );
		self::assertSame( 'woocommerce', $body['metadata']['integration'] ?? null );
		self::assertSame( $intent->uuid(), $body['metadata']['intent_uuid'] ?? null );
		self::assertSame( 'chk_123', $checkout->checkout_id() );
	}


	/**
	 * Caller metadata cannot override plugin-owned correlation fields.
	 *
	 * @return void
	 */
	public function test_correlation_metadata_cannot_be_overridden(): void {
		$requester = new RecordingRequester(
			array(
				'checkout_id' => 'chk_123',
				'status'      => 'open',
			)
		);
		$api       = new CheckoutApi( $requester );
		$intent    = self::intent();

		$api->create_raw_checkout(
			$intent,
			'https://merchant.example/success',
			'https://merchant.example/cancel',
			array(
				'integration' => 'attacker-value',
				'intent_uuid' => 'attacker-value',
			)
		);

		$body = $requester->last_body();

		self::assertSame( 'woocommerce', $body['metadata']['integration'] ?? null );
		self::assertSame( $intent->uuid(), $body['metadata']['intent_uuid'] ?? null );
	}


	/**
	 * A sandbox intent cannot be sent through a live Bachs requester.
	 *
	 * @return void
	 */
	public function test_intent_environment_must_match_requester_environment(): void {
		$requester = new RecordingRequester(
			array(
				'checkout_id' => 'chk_123',
				'status'      => 'open',
			),
			Environment::LIVE
		);
		$api       = new CheckoutApi( $requester );

		$this->expectException( \InvalidArgumentException::class );
		$api->create_raw_checkout(
			self::intent(),
			'https://merchant.example/success',
			'https://merchant.example/cancel'
		);
	}

	/**
	 * Checkout retrieval uses the documented checkout-session resource path.
	 *
	 * @return void
	 */
	public function test_get_uses_checkout_endpoint(): void {
		$requester = new RecordingRequester(
			array(
				'checkout_id' => 'chk_123',
				'status'      => 'completed',
			)
		);
		$api       = new CheckoutApi( $requester );

		$api->get( 'chk_123' );

		self::assertSame( '/v1/checkout-sessions/chk_123', $requester->last_path() );
	}

	/**
	 * Create a deterministic test payment intent.
	 *
	 * @return PaymentIntent
	 */
	private static function intent(): PaymentIntent {
		return PaymentIntent::create(
			'123e4567-e89b-42d3-a456-426614174000',
			'woocommerce',
			'order',
			'1847',
			PaymentIntent::ENVIRONMENT_SANDBOX,
			'etp_bch_ref',
			'etp:site:woo:1847:checkout:1',
			Money::from_decimal( '50000', Currency::from_code( 'NGN' ) )
		);
	}
}
