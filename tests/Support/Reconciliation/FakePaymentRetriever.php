<?php
/**
 * Fake provider payment retriever.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Support\Reconciliation;

use Etchpoint\BachsIntegrations\Bachs\PaymentRetriever;
use Etchpoint\BachsIntegrations\Bachs\ProviderPayment;

/**
 * Returns one preconfigured provider payment.
 */
final class FakePaymentRetriever implements PaymentRetriever {
	/**
	 * Preconfigured provider payment.
	 *
	 * @var ProviderPayment
	 */
	private ProviderPayment $payment;

	/**
	 * Create the fake retriever.
	 *
	 * @param ProviderPayment $payment Provider payment fixture.
	 */
	public function __construct( ProviderPayment $payment ) {
		$this->payment = $payment;
	}

	/**
	 * Retrieve the preconfigured payment.
	 *
	 * @param string $payment_id Payment identifier.
	 * @return ProviderPayment
	 */
	public function get( string $payment_id ): ProviderPayment {
		unset( $payment_id );

		return $this->payment;
	}
}
