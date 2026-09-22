<?php
/**
 * Provider payment retrieval contract.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

/**
 * Retrieves authoritative payment state from Bachs.
 */
interface PaymentRetriever {
	/**
	 * Retrieve one provider payment by its opaque identifier.
	 *
	 * @param string $payment_id Bachs payment/charge identifier.
	 * @return ProviderPayment
	 *
	 * @throws ApiException When the provider request fails.
	 */
	public function get( string $payment_id ): ProviderPayment;
}
