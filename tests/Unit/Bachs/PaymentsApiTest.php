<?php
/**
 * Bachs payments API tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs;

use Etchpoint\BachsIntegrations\Bachs\PaymentsApi;
use Etchpoint\BachsIntegrations\Tests\Support\Bachs\PaymentRecordingRequester;
use PHPUnit\Framework\TestCase;

/**
 * Verifies authoritative provider payment retrieval.
 */
final class PaymentsApiTest extends TestCase {
	/**
	 * Payment retrieval uses the documented v1 payment endpoint.
	 *
	 * @return void
	 */
	public function test_get_retrieves_provider_payment(): void {
		$requester = new PaymentRecordingRequester();
		$api       = new PaymentsApi( $requester );
		$payment   = $api->get( 'pay_123' );

		self::assertSame( '/v1/payments/pay_123', $requester->last_path() );
		self::assertSame( 'pay_123', $payment->payment_id() );
		self::assertSame( 'succeeded', $payment->status() );
	}
}
