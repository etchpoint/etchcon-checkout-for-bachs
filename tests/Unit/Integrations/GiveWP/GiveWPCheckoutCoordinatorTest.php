<?php
/**
 * GiveWP checkout coordinator tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Integrations\GiveWP;

use DateTimeImmutable;
use Etchpoint\BachsIntegrations\Bachs\CheckoutProvider;
use Etchpoint\BachsIntegrations\Bachs\CheckoutSession;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use Etchpoint\BachsIntegrations\Integrations\GiveWP\GiveWPCheckoutCoordinator;
use Etchpoint\BachsIntegrations\Persistence\CheckoutIntentStore;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies idempotent GiveWP checkout creation and retry behavior.
 */
final class GiveWPCheckoutCoordinatorTest extends TestCase {
	/**
	 * New entries persist an intent before creating provider checkout.
	 *
	 * @return void
	 */
	public function test_new_donation_creates_intent_and_attaches_checkout(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$captured  = null;

		$intents->expects( self::once() )
			->method( 'find_latest_for_local_object' )
			->with( 'givewp', 'donation', '91' )
			->willReturn( null );
		$intents->expects( self::once() )
			->method( 'create' )
			->willReturnCallback(
				static function ( PaymentIntent $intent ) use ( &$captured ): int {
					$captured = $intent;
					return 14;
				}
			);
		$checkouts->expects( self::once() )
			->method( 'create_raw_checkout' )
			->with(
				self::anything(),
				'https://forms.example/return',
				'https://forms.example/cancel',
				array(
					'local_id' => '91',
					'form_id'  => '7',
				)
			)
			->willReturn( self::checkout_session( 'chk_give_1', 'https://checkout.example/give', 'open' ) );
		$intents->expects( self::once() )
			->method( 'attach_checkout' )
			->with( 14, 'chk_give_1' )
			->willReturn( true );

		$result = self::coordinator( $intents, $checkouts )->start(
			91,
			7,
			'25000.00',
			'NGN',
			'https://forms.example/return',
			'https://forms.example/cancel'
		);

		self::assertInstanceOf( PaymentIntent::class, $captured );
		self::assertSame( 'givewp', $captured->integration() );
		self::assertSame( 'etp:abcdef123456:give:91:checkout:1', $captured->idempotency_key() );
		self::assertSame( 'https://checkout.example/give', $result->redirect_url() );
	}

	/**
	 * An existing open checkout is reused instead of creating another charge path.
	 *
	 * @return void
	 */
	public function test_open_checkout_is_reused(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$record    = self::intent_record( 9, '25000.00', 1, 'chk_existing' );

		$intents->method( 'find_latest_for_local_object' )->willReturn( $record );
		$intents->expects( self::never() )->method( 'create' );
		$checkouts->expects( self::once() )
			->method( 'get' )
			->with( 'chk_existing' )
			->willReturn( self::checkout_session( 'chk_existing', 'https://checkout.example/existing', 'OPEN' ) );
		$checkouts->expects( self::never() )->method( 'create_raw_checkout' );

		$result = self::coordinator( $intents, $checkouts )->start(
			91,
			7,
			'25000.00',
			'NGN',
			'https://forms.example/return',
			'https://forms.example/cancel'
		);

		self::assertSame( 'https://checkout.example/existing', $result->redirect_url() );
	}

	/**
	 * A changed amount cannot run beside an earlier open checkout.
	 *
	 * @return void
	 */
	public function test_changed_amount_is_blocked_while_old_checkout_is_open(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$record    = self::intent_record( 9, '25000.00', 1, 'chk_existing' );

		$intents->method( 'find_latest_for_local_object' )->willReturn( $record );
		$checkouts->method( 'get' )
			->willReturn( self::checkout_session( 'chk_existing', 'https://checkout.example/existing', 'open' ) );
		$checkouts->expects( self::never() )->method( 'create_raw_checkout' );

		$this->expectException( RuntimeException::class );

		self::coordinator( $intents, $checkouts )->start(
			91,
			7,
			'30000.00',
			'NGN',
			'https://forms.example/return',
			'https://forms.example/cancel'
		);
	}

	/**
	 * Create a deterministic coordinator for unit tests.
	 *
	 * @param CheckoutIntentStore $intents   Intent store mock.
	 * @param CheckoutProvider    $checkouts Checkout provider mock.
	 * @return GiveWPCheckoutCoordinator
	 */
	private static function coordinator(
		CheckoutIntentStore $intents,
		CheckoutProvider $checkouts
	): GiveWPCheckoutCoordinator {
		return new GiveWPCheckoutCoordinator(
			$intents,
			$checkouts,
			Environment::SANDBOX,
			'abcdef123456',
			static fn (): string => '123e4567-e89b-42d3-a456-426614174000',
			static fn (): string => '00112233445566778899aabbccddeeff'
		);
	}

	/**
	 * Build a persisted GiveWP intent record.
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
			'givewp',
			'donation',
			'91',
			Environment::SANDBOX->value,
			'etp_bch_00112233445566778899aabbccddeeff',
			'etp:abcdef123456:give:91:checkout:' . $attempt,
			Money::from_decimal( $amount, Currency::from_code( 'NGN' ) ),
			$attempt
		);
		$now    = new DateTimeImmutable( '2026-09-23 01:00:00' );

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
				'checkout_url' => $url,
				'status'       => $status,
				'reference'    => 'etp_bch_00112233445566778899aabbccddeeff',
			)
		);
	}
}
