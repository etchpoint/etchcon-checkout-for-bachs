<?php
/**
 * Fake provider payment retriever for webhook processor tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Support\Bachs\Webhook;

use Etchpoint\BachsIntegrations\Bachs\ApiException;
use Etchpoint\BachsIntegrations\Bachs\PaymentRetriever;
use Etchpoint\BachsIntegrations\Bachs\ProviderPayment;

/**
 * Returns one configured provider payment or exception.
 */
final class FakePaymentRetriever implements PaymentRetriever {
	/**
	 * Provider payment returned by the fake.
	 *
	 * @var ProviderPayment|null
	 */
	private ?ProviderPayment $payment;

	/**
	 * Provider exception thrown by the fake.
	 *
	 * @var ApiException|null
	 */
	private ?ApiException $exception;

	/**
	 * Last requested provider payment identifier.
	 *
	 * @var string|null
	 */
	private ?string $requested_id = null;

	/**
	 * Create a fake provider payment retriever.
	 *
	 * @param ProviderPayment|null $payment   Payment to return.
	 * @param ApiException|null    $exception Exception to throw.
	 */
	public function __construct( ?ProviderPayment $payment = null, ?ApiException $exception = null ) {
		$this->payment   = $payment;
		$this->exception = $exception;
	}

	/**
	 * Retrieve the configured payment.
	 *
	 * @param string $payment_id Provider payment identifier.
	 * @return ProviderPayment
	 *
	 * @throws ApiException When configured to fail or no payment exists.
	 */
	public function get( string $payment_id ): ProviderPayment {
		$this->requested_id = $payment_id;

		if ( null !== $this->exception ) {
			throw $this->exception;
		}

		if ( null === $this->payment ) {
			throw ApiException::protocol( 'Test payment was not configured.' );
		}

		return $this->payment;
	}

	/**
	 * Get the last requested provider payment identifier.
	 *
	 * @return string|null
	 */
	public function requested_id(): ?string {
		return $this->requested_id;
	}
}
