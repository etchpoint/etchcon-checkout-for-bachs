<?php
/**
 * Paid Memberships Pro checkout coordinator tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Integrations\PMPro;

use DateTimeImmutable;
use Etchpoint\BachsIntegrations\Bachs\CheckoutProvider;
use Etchpoint\BachsIntegrations\Bachs\CheckoutSession;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use Etchpoint\BachsIntegrations\Integrations\PMPro\PMProCheckoutCoordinator;
use Etchpoint\BachsIntegrations\Persistence\CheckoutIntentStore;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies idempotent PMPro checkout creation and retry behavior.
 */
final class PMProCheckoutCoordinatorTest extends TestCase {
	/**
	 * New membership orders create PMPro-scoped payment intents.
	 *
	 * @return void
	 */
	public function test_new_order_creates_pmpro_intent_and_attaches_checkout(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$captured  = null;

		$intents->expects( self::once() )
			->method( 'find_latest_for_local_object' )
			->with( 'pmpro', 'order', '73' )
			->willReturn( null );
		$intents->expects( self::once() )
			->method( 'create' )
			->willReturnCallback(
				static function ( PaymentIntent $intent ) use ( &$captured ): int {
					$captured = $intent;
					return 11;
				}
			);
		$checkouts->expects( self::once() )
			->method( 'create_raw_checkout' )
			->with(
				self::anything(),
				'https://members.example/confirmation',
				'https://members.example/checkout',
				array(
					'local_id'      => '73',
					'membership_id' => '4',
				)
			)
			->willReturn( self::checkout_session( 'chk_pmpro_1', 'https://checkout.example/pmpro', 'open' ) );
		$intents->expects( self::once() )
			->method( 'attach_checkout' )
			->with( 11, 'chk_pmpro_1' )
			->willReturn( true );

		$result = self::coordinator( $intents, $checkouts )->start(
			73,
			4,
			'50000.00',
			'NGN',
			'https://members.example/confirmation',
			'https://members.example/checkout'
		);

		self::assertInstanceOf( PaymentIntent::class, $captured );
		self::assertSame( 'pmpro', $captured->integration() );
		self::assertSame( 'etp:abcdef123456:pmpro:73:checkout:1', $captured->idempotency_key() );
		self::assertSame( 'https://checkout.example/pmpro', $result->redirect_url() );
	}

	/**
	 * An existing open checkout is reused instead of creating another charge path.
	 *
	 * @return void
	 */
	public function test_open_checkout_is_reused(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$record    = self::intent_record( 8, '50000.00', 1, 'chk_existing' );

		$intents->method( 'find_latest_for_local_object' )->willReturn( $record );
		$intents->expects( self::never() )->method( 'create' );
		$checkouts->expects( self::once() )
			->method( 'get' )
			->with( 'chk_existing' )
			->willReturn( self::checkout_session( 'chk_existing', 'https://checkout.example/existing', 'OPEN' ) );
		$checkouts->expects( self::never() )->method( 'create_raw_checkout' );

		$result = self::coordinator( $intents, $checkouts )->start(
			73,
			4,
			'50000.00',
			'NGN',
			'https://members.example/confirmation',
			'https://members.example/checkout'
		);

		self::assertSame( 'https://checkout.example/existing', $result->redirect_url() );
	}

	/**
	 * A changed order total cannot run beside an earlier open checkout.
	 *
	 * @return void
	 */
	public function test_changed_total_is_blocked_while_old_checkout_is_open(): void {
		$intents   = $this->createMock( CheckoutIntentStore::class );
		$checkouts = $this->createMock( CheckoutProvider::class );
		$record    = self::intent_record( 8, '50000.00', 1, 'chk_existing' );

		$intents->method( 'find_latest_for_local_object' )->willReturn( $record );
		$checkouts->method( 'get' )
			->willReturn( self::checkout_session( 'chk_existing', 'https://checkout.example/existing', 'open' ) );
		$checkouts->expects( self::never() )->method( 'create_raw_checkout' );

		$this->expectException( RuntimeException::class );

		self::coordinator( $intents, $checkouts )->start(
			73,
			4,
			'60000.00',
			'NGN',
			'https://members.example/confirmation',
			'https://members.example/checkout'
		);
	}

	/**
	 * Create a deterministic coordinator for unit tests.
	 *
	 * @param CheckoutIntentStore $intents   Intent store mock.
	 * @param CheckoutProvider    $checkouts Checkout provider mock.
	 * @return PMProCheckoutCoordinator
	 */
	private static function coordinator(
		CheckoutIntentStore $intents,
		CheckoutProvider $checkouts
	): PMProCheckoutCoordinator {
		return new PMProCheckoutCoordinator(
			$intents,
			$checkouts,
			Environment::SANDBOX,
			'abcdef123456',
			static fn (): string => '123e4567-e89b-42d3-a456-426614174000',
			static fn (): string => '00112233445566778899aabbccddeeff'
		);
	}

	/**
	 * Build a persisted PMPro intent record.
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
			'pmpro',
			'order',
			'73',
			Environment::SANDBOX->value,
			'etp_bch_00112233445566778899aabbccddeeff',
			'etp:abcdef123456:pmpro:73:checkout:' . $attempt,
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
				'id'           => $checkout_id,
				'checkout_url' => $url,
				'status'       => $status,
				'reference'    => 'etp_bch_00112233445566778899aabbccddeeff',
			)
		);
	}
}
