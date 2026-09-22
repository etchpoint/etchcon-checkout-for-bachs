<?php
/**
 * Bachs payments API tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs;

use Etchpoint\BachsIntegrations\Bachs\ApiRequester;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Bachs\PaymentsApi;
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

/**
 * Returns one deterministic provider payment for API tests.
 */
final class PaymentRecordingRequester implements ApiRequester {
	/**
	 * Get the request environment.
	 *
	 * @return Environment
	 */
	public function environment(): Environment {
		return Environment::SANDBOX;
	}

	/**
	 * Last requested path.
	 *
	 * @var string
	 */
	private string $path = '';

	/**
	 * Record and answer a GET request.
	 *
	 * @param string $path Bachs API path.
	 * @return array<string, mixed>
	 */
	public function get( string $path ): array {
		$this->path = $path;

		return array(
			'payment_id'  => 'pay_123',
			'status'      => 'succeeded',
			'amount'      => '42.00',
			'currency'    => 'USD',
			'reference'   => 'ref_123',
			'checkout_id' => 'chk_123',
		);
	}

	/**
	 * POST is unused in this retrieval-only test double.
	 *
	 * @param string               $path            Bachs API path.
	 * @param array<string, mixed> $body            Request body.
	 * @param string               $idempotency_key Idempotency key.
	 * @return array<string, mixed>
	 */
	public function post( string $path, array $body, string $idempotency_key ): array {
		unset( $path, $body, $idempotency_key );

		return array();
	}

	/**
	 * Get the last requested path.
	 *
	 * @return string
	 */
	public function last_path(): string {
		return $this->path;
	}
}
