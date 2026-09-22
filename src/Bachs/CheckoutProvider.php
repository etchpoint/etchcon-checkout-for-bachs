<?php
/**
 * Hosted checkout provider contract.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;

/**
 * Provider operations required to create and recover hosted checkout sessions.
 */
interface CheckoutProvider {
	/**
	 * Create a hosted checkout from a trusted local payment intent.
	 *
	 * @param PaymentIntent         $intent      Trusted local payment intent.
	 * @param string                $success_url Browser success destination.
	 * @param string                $cancel_url  Browser cancellation destination.
	 * @param array<string, string> $metadata    Optional non-sensitive metadata.
	 * @return CheckoutSession
	 */
	public function create_raw_checkout(
		PaymentIntent $intent,
		string $success_url,
		string $cancel_url,
		array $metadata = array()
	): CheckoutSession;

	/**
	 * Retrieve an existing hosted checkout session.
	 *
	 * @param string $checkout_id Opaque provider checkout identifier.
	 * @return CheckoutSession
	 */
	public function get( string $checkout_id ): CheckoutSession;
}
