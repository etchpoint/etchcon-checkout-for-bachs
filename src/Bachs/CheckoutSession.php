<?php
/**
 * Bachs checkout-session response.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use InvalidArgumentException;

/**
 * Immutable subset of checkout-session data needed by the plugin core.
 */
final class CheckoutSession {
	/**
	 * Provider checkout identifier.
	 *
	 * @var string
	 */
	private string $checkout_id;

	/**
	 * Raw checkout status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Hosted checkout URL when returned by Bachs.
	 *
	 * @var string|null
	 */
	private ?string $checkout_url;

	/**
	 * Raw checkout payment status when available.
	 *
	 * @var string|null
	 */
	private ?string $payment_status;

	/**
	 * Raw checkout amount when available.
	 *
	 * @var string|null
	 */
	private ?string $amount;

	/**
	 * Checkout currency when available.
	 *
	 * @var string|null
	 */
	private ?string $currency;

	/**
	 * Merchant correlation reference when available.
	 *
	 * @var string|null
	 */
	private ?string $reference;

	/**
	 * Provider payment identifier nested under charge when available.
	 *
	 * @var string|null
	 */
	private ?string $payment_id;

	/**
	 * Create checkout-session data from a provider response.
	 *
	 * @param array<string, mixed> $data Bachs checkout-session response.
	 * @return self
	 *
	 * @throws InvalidArgumentException When required response fields are missing.
	 */
	public static function from_api_response( array $data ): self {
		$checkout_id = self::required_string( $data, 'checkout_id' );
		$status      = self::required_string( $data, 'status' );
		$charge      = $data['charge'] ?? null;
		$payment_id  = null;

		if ( is_array( $charge ) && isset( $charge['payment_id'] ) && is_string( $charge['payment_id'] ) ) {
			$payment_id = $charge['payment_id'];
		}

		$instance                 = new self();
		$instance->checkout_id    = $checkout_id;
		$instance->status         = $status;
		$checkout_url             = self::optional_string( $data, 'checkout_url' );
		$instance->checkout_url   = null === $checkout_url ? null : trim( $checkout_url );
		$instance->payment_status = self::optional_string( $data, 'payment_status' );
		$instance->amount         = self::optional_string( $data, 'amount' );
		$instance->currency       = self::optional_string( $data, 'currency' );
		$instance->reference      = self::optional_string( $data, 'reference' );
		$instance->payment_id     = $payment_id;

		return $instance;
	}

	/**
	 * Get the provider checkout identifier.
	 *
	 * @return string
	 */
	public function checkout_id(): string {
		return $this->checkout_id;
	}

	/**
	 * Get the raw checkout status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Get the hosted checkout URL.
	 *
	 * @return string|null
	 */
	public function checkout_url(): ?string {
		return $this->checkout_url;
	}

	/**
	 * Get the raw payment status.
	 *
	 * @return string|null
	 */
	public function payment_status(): ?string {
		return $this->payment_status;
	}

	/**
	 * Get the raw checkout amount.
	 *
	 * @return string|null
	 */
	public function amount(): ?string {
		return $this->amount;
	}

	/**
	 * Get the checkout currency.
	 *
	 * @return string|null
	 */
	public function currency(): ?string {
		return $this->currency;
	}

	/**
	 * Get the merchant correlation reference.
	 *
	 * @return string|null
	 */
	public function reference(): ?string {
		return $this->reference;
	}

	/**
	 * Get the provider payment identifier when present.
	 *
	 * @return string|null
	 */
	public function payment_id(): ?string {
		return $this->payment_id;
	}

	/**
	 * Read a required non-empty string field.
	 *
	 * @param array<string, mixed> $data Response data.
	 * @param string               $key  Field key.
	 * @return string
	 *
	 * @throws InvalidArgumentException When the field is missing or invalid.
	 */
	private static function required_string( array $data, string $key ): string {
		$value = self::optional_string( $data, $key );

		if ( null === $value || '' === $value ) {
			throw new InvalidArgumentException( 'Bachs checkout response is missing a required string field.' );
		}

		return $value;
	}

	/**
	 * Read an optional string field.
	 *
	 * @param array<string, mixed> $data Response data.
	 * @param string               $key  Field key.
	 * @return string|null
	 */
	private static function optional_string( array $data, string $key ): ?string {
		$value = $data[ $key ] ?? null;

		return is_string( $value ) ? $value : null;
	}
}
