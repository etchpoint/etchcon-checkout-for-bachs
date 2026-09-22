<?php
/**
 * Payment fulfillment handler contract.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Contracts;

use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Payment\VerifiedPayment;

/**
 * Applies a verified provider payment through one host application's native API.
 */
interface PaymentFulfillmentHandler {
	/**
	 * Get the integration identifier handled by this adapter.
	 *
	 * @return string
	 */
	public function integration(): string;

	/**
	 * Apply one verified payment to its host object.
	 *
	 * @param int             $intent_id Intent database row identifier.
	 * @param int             $event_id  Event inbox row identifier.
	 * @param VerifiedPayment $payment   Verified provider payment evidence.
	 * @return FulfillmentDisposition
	 */
	public function fulfill( int $intent_id, int $event_id, VerifiedPayment $payment ): FulfillmentDisposition;
}
