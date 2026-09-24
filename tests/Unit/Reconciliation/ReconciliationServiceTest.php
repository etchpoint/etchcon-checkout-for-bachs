<?php
/**
 * Reconciliation service tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Reconciliation;

use DateTimeImmutable;
use DateTimeZone;
use Etchpoint\BachsIntegrations\Bachs\CheckoutSession;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Bachs\ProviderPayment;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentRegistry;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use Etchpoint\BachsIntegrations\Reconciliation\ReconciliationDisposition;
use Etchpoint\BachsIntegrations\Reconciliation\ReconciliationService;
use Etchpoint\BachsIntegrations\Tests\Support\Bachs\Webhook\InMemoryEventStore;
use Etchpoint\BachsIntegrations\Tests\Support\Reconciliation\FakeCheckoutProvider;
use Etchpoint\BachsIntegrations\Tests\Support\Reconciliation\FakeFulfillmentHandler;
use Etchpoint\BachsIntegrations\Tests\Support\Reconciliation\FakePaymentRetriever;
use Etchpoint\BachsIntegrations\Tests\Support\Reconciliation\InMemoryReconciliationIntentStore;
use PHPUnit\Framework\TestCase;

/**
 * Verifies exact Bachs evidence is required before recovery fulfillment.
 */
final class ReconciliationServiceTest extends TestCase {
	/**
	 * A successful provider payment can recover incomplete application state.
	 *
	 * @return void
	 */
	public function test_successful_payment_recovers_incomplete_fulfillment(): void {
		$record    = self::intent_record();
		$intents   = new InMemoryReconciliationIntentStore( array( $record ) );
		$service   = new ReconciliationService(
			$intents,
			new InMemoryEventStore(),
			new FakeCheckoutProvider( self::checkout( 'succeeded', 'pay_123' ) ),
			new FakePaymentRetriever( self::payment() ),
			new FulfillmentRegistry(
				array( new FakeFulfillmentHandler( 'woocommerce', FulfillmentDisposition::APPLIED ) )
			),
			Environment::SANDBOX
		);
		$result    = $service->reconcile( 7 );
		$refreshed = $intents->find_by_id( 7 );

		self::assertSame( ReconciliationDisposition::RECOVERED, $result->disposition() );
		self::assertNotNull( $refreshed );
		self::assertSame( 'pay_123', $refreshed->charge_id() );
	}


	/**
	 * Nullable payment correlation fields do not block a checkout-correlated recovery.
	 *
	 * @return void
	 */
	public function test_successful_payment_without_optional_correlation_fields_recovers(): void {
		$record  = self::intent_record();
		$service = new ReconciliationService(
			new InMemoryReconciliationIntentStore( array( $record ) ),
			new InMemoryEventStore(),
			new FakeCheckoutProvider( self::checkout( 'succeeded', 'pay_123' ) ),
			new FakePaymentRetriever( self::payment_without_optional_correlation() ),
			new FulfillmentRegistry(
				array( new FakeFulfillmentHandler( 'woocommerce', FulfillmentDisposition::APPLIED ) )
			),
			Environment::SANDBOX
		);

		$result = $service->reconcile( 7 );

		self::assertSame( ReconciliationDisposition::RECOVERED, $result->disposition() );
	}

	/**
	 * Customer payment currency may differ from the merchant checkout currency.
	 *
	 * @return void
	 */
	public function test_adaptive_pricing_payment_recovers_against_checkout_amount(): void {
		$payment = ProviderPayment::from_api_response(
			array(
				'payment_id'  => 'pay_123',
				'status'      => 'succeeded',
				'amount'      => '65000.00',
				'currency'    => 'NGN',
				'reference'   => 'ref_1847',
				'checkout_id' => 'checkout_123',
			)
		);
		$service = new ReconciliationService(
			new InMemoryReconciliationIntentStore( array( self::intent_record() ) ),
			new InMemoryEventStore(),
			new FakeCheckoutProvider( self::checkout( 'succeeded', 'pay_123' ) ),
			new FakePaymentRetriever( $payment ),
			new FulfillmentRegistry(
				array( new FakeFulfillmentHandler( 'woocommerce', FulfillmentDisposition::APPLIED ) )
			),
			Environment::SANDBOX
		);

		$result = $service->reconcile( 7 );

		self::assertSame( ReconciliationDisposition::RECOVERED, $result->disposition() );
	}

	/**
	 * A checkout that is not authoritatively successful must not be fulfilled.
	 *
	 * @return void
	 */
	public function test_open_payment_returns_no_action(): void {
		$record  = self::intent_record();
		$service = new ReconciliationService(
			new InMemoryReconciliationIntentStore( array( $record ) ),
			new InMemoryEventStore(),
			new FakeCheckoutProvider( self::checkout( 'processing', null ) ),
			new FakePaymentRetriever( self::payment() ),
			new FulfillmentRegistry(
				array( new FakeFulfillmentHandler( 'woocommerce', FulfillmentDisposition::APPLIED ) )
			),
			Environment::SANDBOX
		);

		$result = $service->reconcile( 7 );

		self::assertSame( ReconciliationDisposition::NO_ACTION, $result->disposition() );
		self::assertSame( 'provider_not_succeeded', $result->code() );
	}

	/**
	 * Build a deterministic local payment intent record.
	 *
	 * @return IntentRecord
	 */
	private static function intent_record(): IntentRecord {
		$intent = PaymentIntent::create(
			'5ac94431-0aa0-4b16-9237-2fa043ef7a00',
			'woocommerce',
			'order',
			'1847',
			'sandbox',
			'ref_1847',
			'idem_1847',
			Money::from_decimal( '42.00', Currency::from_code( 'USD' ) )
		);
		$now    = new DateTimeImmutable( '2026-09-22T12:00:00+00:00', new DateTimeZone( 'UTC' ) );

		return new IntentRecord( 7, $intent, 'checkout_123', null, null, null, null, $now, $now, null );
	}

	/**
	 * Build a checkout-session fixture.
	 *
	 * @param string      $payment_status Provider payment status.
	 * @param string|null $payment_id     Provider payment identifier.
	 * @return CheckoutSession
	 */
	private static function checkout( string $payment_status, ?string $payment_id ): CheckoutSession {
		$data = array(
			'checkout_id'    => 'checkout_123',
			'status'         => 'open',
			'payment_status' => $payment_status,
			'amount'         => '42.00',
			'currency'       => 'USD',
			'reference'      => 'ref_1847',
		);

		if ( null !== $payment_id ) {
			$data['charge'] = array( 'payment_id' => $payment_id );
		}

		return CheckoutSession::from_api_response( $data );
	}

	/**
	 * Build authoritative provider payment evidence.
	 *
	 * @return ProviderPayment
	 */
	private static function payment(): ProviderPayment {
		return ProviderPayment::from_api_response(
			array(
				'payment_id'  => 'pay_123',
				'status'      => 'succeeded',
				'amount'      => '42.00',
				'currency'    => 'USD',
				'reference'   => 'ref_1847',
				'checkout_id' => 'checkout_123',
			)
		);
	}


	/**
	 * Build authoritative payment evidence without optional correlation fields.
	 *
	 * @return ProviderPayment
	 */
	private static function payment_without_optional_correlation(): ProviderPayment {
		return ProviderPayment::from_api_response(
			array(
				'payment_id' => 'pay_123',
				'status'     => 'succeeded',
				'amount'     => '42.00',
				'currency'   => 'USD',
			)
		);
	}
}
