<?php
/**
 * Fake checkout provider for reconciliation tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Support\Reconciliation;

use Etchpoint\BachsIntegrations\Bachs\CheckoutProvider;
use Etchpoint\BachsIntegrations\Bachs\CheckoutSession;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;

/**
 * Returns one preconfigured checkout session.
 */
final class FakeCheckoutProvider implements CheckoutProvider {
	/**
	 * Preconfigured checkout session.
	 *
	 * @var CheckoutSession
	 */
	private CheckoutSession $checkout;

	/**
	 * Create the fake provider.
	 *
	 * @param CheckoutSession $checkout Checkout session fixture.
	 */
	public function __construct( CheckoutSession $checkout ) {
		$this->checkout = $checkout;
	}

	/**
	 * Return the configured checkout for the unused create path.
	 *
	 * @param PaymentIntent         $intent      Payment intent.
	 * @param string                $success_url Success URL.
	 * @param string                $cancel_url  Cancel URL.
	 * @param array<string, string> $metadata    Checkout metadata.
	 * @param array<string, string> $customer    Inline customer details.
	 * @return CheckoutSession
	 */
	public function create_raw_checkout(
		PaymentIntent $intent,
		string $success_url,
		string $cancel_url,
		array $metadata = array(),
		array $customer = array()
	): CheckoutSession {
		unset( $intent, $success_url, $cancel_url, $metadata, $customer );

		return $this->checkout;
	}

	/**
	 * Retrieve the preconfigured checkout.
	 *
	 * @param string $checkout_id Checkout identifier.
	 * @return CheckoutSession
	 */
	public function get( string $checkout_id ): CheckoutSession {
		unset( $checkout_id );

		return $this->checkout;
	}
}
