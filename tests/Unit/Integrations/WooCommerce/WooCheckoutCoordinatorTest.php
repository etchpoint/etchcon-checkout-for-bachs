<?php
/**
 * WooCommerce checkout coordinator tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Integrations\WooCommerce;

use DateTimeImmutable;
use Etchpoint\BachsIntegrations\Bachs\CheckoutProvider;
use Etchpoint\BachsIntegrations\Bachs\CheckoutSession;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use Etchpoint\BachsIntegrations\Persistence\CheckoutIntentStore;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use Etchpoint\BachsIntegrations\Integrations\WooCommerce\WooCheckoutCoordinator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies idempotent WooCommerce checkout creation and retry behavior.
 */
final class WooCheckoutCoordinatorTest extends TestCase {
	/**
	 * New orders persist an intent before creating provider checkout.
	 *
	 * @return void
	 */
	public function test_new_order_creates_intent_and_attaches_checkout(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$captured  = null;

		$intents->expects( self::once() )
			->method( 'find_latest_for_local_object' )
			->with( 'woocommerce', 'order', '42' )
			->willReturn( null );
		$intents->expects( self::once() )
			->method( 'create' )
			->willReturnCallback(
				static function ( PaymentIntent $intent ) use ( &$captured ): int {
					$captured = $intent;
					return 7;
				}
			);
		$checkouts->expects( self::once() )
			->method( 'create_raw_checkout' )
			->willReturn( self::checkout_session( 'chk_1', 'https://checkout.example/1', 'open' ) );
		$intents->expects( self::once() )
			->method( 'attach_checkout' )
			->with( 7, 'chk_1' )
			->willReturn( true );

		$coordinator = self::coordinator( $intents, $checkouts );
		$result      = $coordinator->start(
			42,
			'1250.00',
			'NGN',
			'https://shop.example/success',
			'https://shop.example/cancel'
		);

		self::assertInstanceOf( PaymentIntent::class, $captured );
		self::assertSame( '1250.00', $captured->expected_amount()->amount() );
		self::assertSame( 'etp:abcdef123456:woo:42:checkout:1', $captured->idempotency_key() );
		self::assertSame( 'https://checkout.example/1', $result->redirect_url() );
	}

	/**
	 * A persisted attempt without checkout ID is reused after transport failure.
	 *
	 * @return void
	 */
	public function test_unattached_intent_reuses_same_logical_attempt(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$record    = self::intent_record( 3, '1250.00', 2, null );

		$intents->method( 'find_latest_for_local_object' )->willReturn( $record );
		$intents->expects( self::never() )->method( 'create' );
		$checkouts->expects( self::once() )
			->method( 'create_raw_checkout' )
			->with(
				self::callback(
					static fn ( PaymentIntent $intent ): bool => 2 === $intent->attempt()
						&& 'etp:abcdef123456:woo:42:checkout:2' === $intent->idempotency_key()
				),
				self::anything(),
				self::anything(),
				self::anything()
			)
			->willReturn( self::checkout_session( 'chk_2', 'https://checkout.example/2', 'open' ) );
		$intents->method( 'attach_checkout' )->willReturn( true );

		self::coordinator( $intents, $checkouts )->start(
			42,
			'1250.00',
			'NGN',
			'https://shop.example/success',
			'https://shop.example/cancel'
		);
	}

	/**
	 * An open existing provider checkout is reused instead of duplicated.
	 *
	 * @return void
	 */
	public function test_open_checkout_is_reused(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$record    = self::intent_record( 4, '1250.00', 1, 'chk_existing' );

		$intents->method( 'find_latest_for_local_object' )->willReturn( $record );
		$intents->expects( self::never() )->method( 'create' );
		$intents->expects( self::never() )->method( 'attach_checkout' );
		$checkouts->expects( self::once() )
			->method( 'get' )
			->with( 'chk_existing' )
			->willReturn( self::checkout_session( 'chk_existing', 'https://checkout.example/existing', 'open' ) );
		$checkouts->expects( self::never() )->method( 'create_raw_checkout' );

		$result = self::coordinator( $intents, $checkouts )->start(
			42,
			'1250.00',
			'NGN',
			'https://shop.example/success',
			'https://shop.example/cancel'
		);

		self::assertSame( 'https://checkout.example/existing', $result->redirect_url() );
	}

	/**
	 * A changed order cannot create a parallel checkout while the old one is open.
	 *
	 * @return void
	 */
	public function test_changed_order_total_is_blocked_while_old_checkout_is_open(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$record    = self::intent_record( 4, '1250.00', 2, 'chk_old' );

		$intents->method( 'find_latest_for_local_object' )->willReturn( $record );
		$intents->expects( self::never() )->method( 'create' );
		$checkouts->expects( self::once() )
			->method( 'get' )
			->with( 'chk_old' )
			->willReturn( self::checkout_session( 'chk_old', 'https://checkout.example/old', 'OPEN' ) );
		$checkouts->expects( self::never() )->method( 'create_raw_checkout' );

		$this->expectException( RuntimeException::class );

		self::coordinator( $intents, $checkouts )->start(
			42,
			'1500.00',
			'NGN',
			'https://shop.example/success',
			'https://shop.example/cancel'
		);
	}

	/**
	 * An expired checkout allows a fresh logical attempt.
	 *
	 * @return void
	 */
	public function test_expired_checkout_starts_new_attempt(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$record    = self::intent_record( 4, '1250.00', 2, 'chk_old' );
		$captured  = null;

		$intents->method( 'find_latest_for_local_object' )->willReturn( $record );
		$checkouts->expects( self::once() )
			->method( 'get' )
			->with( 'chk_old' )
			->willReturn( self::checkout_session( 'chk_old', 'https://checkout.example/old', 'EXPIRED' ) );
		$intents->expects( self::once() )
			->method( 'create' )
			->willReturnCallback(
				static function ( PaymentIntent $intent ) use ( &$captured ): int {
					$captured = $intent;
					return 9;
				}
			);
		$checkouts->method( 'create_raw_checkout' )
			->willReturn( self::checkout_session( 'chk_new', 'https://checkout.example/new', 'OPEN' ) );
		$intents->method( 'attach_checkout' )->willReturn( true );

		self::coordinator( $intents, $checkouts )->start(
			42,
			'1250.00',
			'NGN',
			'https://shop.example/success',
			'https://shop.example/cancel'
		);

		self::assertInstanceOf( PaymentIntent::class, $captured );
		self::assertSame( 3, $captured->attempt() );
	}

	/**
	 * A completed checkout sends the browser to confirmation without charging again.
	 *
	 * @return void
	 */
	public function test_completed_checkout_redirects_to_confirmation_without_new_checkout(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$record    = self::intent_record( 4, '1250.00', 1, 'chk_complete' );

		$intents->method( 'find_latest_for_local_object' )->willReturn( $record );
		$intents->expects( self::never() )->method( 'create' );
		$intents->expects( self::never() )->method( 'attach_checkout' );
		$checkouts->expects( self::once() )
			->method( 'get' )
			->willReturn( self::checkout_session( 'chk_complete', 'https://checkout.example/complete', 'COMPLETED' ) );
		$checkouts->expects( self::never() )->method( 'create_raw_checkout' );

		$result = self::coordinator( $intents, $checkouts )->start(
			42,
			'1250.00',
			'NGN',
			'https://shop.example/success',
			'https://shop.example/cancel'
		);

		self::assertSame( 'https://shop.example/success', $result->redirect_url() );
	}

	/**
	 * Create a deterministic coordinator for unit tests.
	 *
	 * @param CheckoutIntentStore $intents   Intent store mock.
	 * @param CheckoutProvider    $checkouts Checkout provider mock.
	 * @return WooCheckoutCoordinator
	 */
	private static function coordinator(
		CheckoutIntentStore $intents,
		CheckoutProvider $checkouts
	): WooCheckoutCoordinator {
		return new WooCheckoutCoordinator(
			$intents,
			$checkouts,
			Environment::SANDBOX,
			'abcdef123456',
			static fn (): string => '123e4567-e89b-42d3-a456-426614174000',
			static fn (): string => '00112233445566778899aabbccddeeff'
		);
	}

	/**
	 * Build a persisted Woo intent record.
	 *
	 * @param int         $id          Database row identifier.
	 * @param string      $amount      Trusted decimal amount.
	 * @param int         $attempt     Logical checkout attempt.
	 * @param string|null $checkout_id Provider checkout identifier.
	 * @return IntentRecord
	 */
	private static function intent_record(
		int $id,
		string $amount,
		int $attempt,
		?string $checkout_id
	): IntentRecord {
		$intent = PaymentIntent::create(
			'123e4567-e89b-42d3-a456-426614174000',
			'woocommerce',
			'order',
			'42',
			Environment::SANDBOX->value,
			'etp_bch_00112233445566778899aabbccddeeff',
			'etp:abcdef123456:woo:42:checkout:' . $attempt,
			Money::from_decimal( $amount, Currency::from_code( 'NGN' ) ),
			$attempt
		);
		$now    = new DateTimeImmutable( '2026-09-22 12:00:00' );

		return new IntentRecord(
			$id,
			$intent,
			$checkout_id,
			null,
			null,
			null,
			null,
			$now,
			$now,
			null
		);
	}

	/**
	 * Build a provider checkout session response.
	 *
	 * @param string $checkout_id Provider checkout identifier.
	 * @param string $url         Hosted checkout URL.
	 * @param string $status      Provider checkout status.
	 * @return CheckoutSession
	 */
	private static function checkout_session( string $checkout_id, string $url, string $status ): CheckoutSession {
		return CheckoutSession::from_api_response(
			array(
				'checkout_id'  => $checkout_id,
				'status'       => $status,
				'checkout_url' => $url,
			)
		);
	}
}
