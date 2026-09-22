<?php
/**
 * Provider-payment requester test double.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Support\Bachs;

use Etchpoint\BachsIntegrations\Bachs\ApiRequester;
use Etchpoint\BachsIntegrations\Bachs\Environment;

/**
 * Returns one deterministic provider payment for API tests.
 */
final class PaymentRecordingRequester implements ApiRequester {
	/**
	 * Last requested path.
	 *
	 * @var string
	 */
	private string $path = '';

	/**
	 * Get the request environment.
	 *
	 * @return Environment
	 */
	public function environment(): Environment {
		return Environment::SANDBOX;
	}

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
