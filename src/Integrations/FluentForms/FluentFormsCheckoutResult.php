<?php
/**
 * Fluent Forms hosted checkout result.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\FluentForms;

/**
 * Immutable result returned after starting or resuming Bachs checkout.
 */
final class FluentFormsCheckoutResult {
	/**
	 * Browser redirect URL.
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
	 * @param string $redirect_url Browser redirect URL.
	 * @param string $intent_uuid  Local payment intent UUID.
	 * @param string $checkout_id  Provider checkout identifier.
	 */
	public function __construct( string $redirect_url, string $intent_uuid, string $checkout_id ) {
		$this->redirect_url = $redirect_url;
		$this->intent_uuid  = $intent_uuid;
		$this->checkout_id  = $checkout_id;
	}

	/**
	 * Get the browser redirect URL.
	 *
	 * @return string
	 */
	public function redirect_url(): string {
		return $this->redirect_url;
	}

	/**
	 * Get the payment intent UUID.
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
