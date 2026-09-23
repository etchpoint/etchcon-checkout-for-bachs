<?php
/**
 * Refund fulfillment contract.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Contracts;

use Etchpoint\BachsIntegrations\Core\Refund\RefundFulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Refund\VerifiedRefund;

/**
 * Applies an authoritative Bachs refund to one host application.
 */
interface RefundFulfillmentHandler {
	/**
	 * Get the integration identifier handled by this adapter.
	 *
	 * @return string
	 */
	public function integration(): string;

	/**
	 * Apply the confirmed provider refund to the host record.
	 *
	 * @param int            $refund_id Local refund row identifier.
	 * @param int            $event_id  Webhook event row identifier.
	 * @param VerifiedRefund $refund    Verified provider refund evidence.
	 * @return RefundFulfillmentDisposition
	 */
	public function fulfill( int $refund_id, int $event_id, VerifiedRefund $refund ): RefundFulfillmentDisposition;
}
