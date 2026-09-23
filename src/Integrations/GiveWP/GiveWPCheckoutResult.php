<?php
/**
 * GiveWP hosted checkout result.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\GiveWP;

/**
 * Carries the hosted checkout redirect and local correlation identifiers.
 */
final class GiveWPCheckoutResult {
	/**
	 * Hosted checkout redirect URL.
	 *
	 * @var string
	 */
	private string $redirect_url;

	/**
	 * Plugin payment-intent UUID.
	 *
	 * @var string
	 */
	private string $intent_uuid;

	/**
	 * Bachs checkout identifier.
	 *
	 * @var string
	 */
	private string $checkout_id;

	/**
	 * Create the result.
	 *
	 * @param string $redirect_url Hosted checkout redirect URL.
	 * @param string $intent_uuid  Plugin payment-intent UUID.
	 * @param string $checkout_id  Bachs checkout identifier.
	 */
	public function __construct( string $redirect_url, string $intent_uuid, string $checkout_id ) {
		$this->redirect_url = $redirect_url;
		$this->intent_uuid  = $intent_uuid;
		$this->checkout_id  = $checkout_id;
	}

	/**
	 * Get the hosted checkout redirect URL.
	 *
	 * @return string
	 */
	public function redirect_url(): string {
		return $this->redirect_url;
	}

	/**
	 * Get the plugin payment-intent UUID.
	 *
	 * @return string
	 */
	public function intent_uuid(): string {
		return $this->intent_uuid;
	}

	/**
	 * Get the Bachs checkout identifier.
	 *
	 * @return string
	 */
	public function checkout_id(): string {
		return $this->checkout_id;
	}
}
