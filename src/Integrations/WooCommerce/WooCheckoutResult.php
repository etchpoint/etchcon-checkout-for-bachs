<?php
/**
 * WooCommerce checkout-start result.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\WooCommerce;

/**
 * Immutable hosted-checkout redirect data returned to the Woo gateway.
 */
final class WooCheckoutResult {
	/**
	 * Hosted checkout URL.
	 *
	 * @var string
	 */
	private string $redirect_url;

	/**
	 * Local payment intent UUID.
	 *
	 * @var string
	 */
	private string $intent_uuid;

	/**
	 * Provider checkout identifier.
	 *
	 * @var string
	 */
	private string $checkout_id;

	/**
	 * Create the result.
	 *
	 * @param string $redirect_url Hosted checkout URL.
	 * @param string $intent_uuid  Local intent UUID.
	 * @param string $checkout_id  Provider checkout identifier.
	 */
	public function __construct( string $redirect_url, string $intent_uuid, string $checkout_id ) {
		$this->redirect_url = $redirect_url;
		$this->intent_uuid  = $intent_uuid;
		$this->checkout_id  = $checkout_id;
	}

	/**
	 * Get the hosted checkout URL.
	 *
	 * @return string
	 */
	public function redirect_url(): string {
		return $this->redirect_url;
	}

	/**
	 * Get the local intent UUID.
	 *
	 * @return string
	 */
	public function intent_uuid(): string {
		return $this->intent_uuid;
	}

	/**
	 * Get the provider checkout identifier.
	 *
	 * @return string
	 */
	public function checkout_id(): string {
		return $this->checkout_id;
	}
}
