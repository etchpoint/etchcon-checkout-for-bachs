<?php
/**
 * Fake reconciliation fulfillment handler.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Support\Reconciliation;

use Etchpoint\BachsIntegrations\Core\Contracts\PaymentFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Payment\VerifiedPayment;

/**
 * Returns a deterministic fulfillment disposition.
 */
final class FakeFulfillmentHandler implements PaymentFulfillmentHandler {
	/**
	 * Integration identifier.
	 *
	 * @var string
	 */
	private string $integration;

	/**
	 * Configured fulfillment disposition.
	 *
	 * @var FulfillmentDisposition
	 */
	private FulfillmentDisposition $disposition;

	/**
	 * Create the fake handler.
	 *
	 * @param string                 $integration Integration identifier.
	 * @param FulfillmentDisposition $disposition Fulfillment result.
	 */
	public function __construct( string $integration, FulfillmentDisposition $disposition ) {
		$this->integration = $integration;
		$this->disposition = $disposition;
	}

	/**
	 * Get the integration identifier.
	 *
	 * @return string
	 */
	public function integration(): string {
		return $this->integration;
	}

	/**
	 * Return the configured fulfillment result.
	 *
	 * @param int             $intent_id Intent row identifier.
	 * @param int             $event_id  Event row identifier.
	 * @param VerifiedPayment $payment   Verified payment evidence.
	 * @return FulfillmentDisposition
	 */
	public function fulfill( int $intent_id, int $event_id, VerifiedPayment $payment ): FulfillmentDisposition {
		unset( $intent_id, $event_id, $payment );

		return $this->disposition;
	}
}
