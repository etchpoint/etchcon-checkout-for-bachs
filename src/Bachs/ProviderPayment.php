<?php
/**
 * Bachs payment response.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use InvalidArgumentException;

/**
 * Immutable provider-payment evidence returned by the Bachs Payments API.
 */
final class ProviderPayment {
	/**
	 * Provider payment/charge identifier.
	 *
	 * @var string
	 */
	private string $payment_id;

	/**
	 * Raw provider payment status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Requested payment amount as returned by Bachs.
	 *
	 * @var string
	 */
	private string $amount;

	/**
	 * Payment currency code.
	 *
	 * @var string
	 */
	private string $currency;

	/**
	 * Amount received so far when available.
	 *
	 * @var string|null
	 */
	private ?string $amount_paid;

	/**
	 * Amount still expected when available.
	 *
	 * @var string|null
	 */
	private ?string $amount_remaining;

	/**
	 * Merchant correlation reference when available.
	 *
	 * @var string|null
	 */
	private ?string $reference;

	/**
	 * Associated checkout identifier when available.
	 *
	 * @var string|null
	 */
	private ?string $checkout_id;

	/**
	 * Rehydrate provider payment data from an API response.
	 *
	 * @param array<string, mixed> $data Bachs payment response.
	 * @return self
	 *
	 * @throws InvalidArgumentException When required evidence is missing.
	 */
	public static function from_api_response( array $data ): self {
		$instance                   = new self();
		$instance->payment_id       = self::required_string( $data, 'payment_id' );
		$instance->status           = self::required_string( $data, 'status' );
		$instance->amount           = self::required_string( $data, 'amount' );
		$instance->currency         = self::required_string( $data, 'currency' );
		$instance->amount_paid      = self::optional_string( $data, 'amount_paid' );
		$instance->amount_remaining = self::optional_string( $data, 'amount_remaining' );
		$instance->reference        = self::optional_string( $data, 'reference' );
		$instance->checkout_id      = self::optional_string( $data, 'checkout_id' );

		return $instance;
	}

	/**
	 * Get the provider payment identifier.
	 *
	 * @return string
	 */
	public function payment_id(): string {
		return $this->payment_id;
	}

	/**
	 * Get the raw provider status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Get the requested provider amount.
	 *
	 * @return string
	 */
	public function amount(): string {
		return $this->amount;
	}

	/**
	 * Get the payment currency code.
	 *
	 * @return string
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * Get the amount received so far.
	 *
	 * @return string|null
	 */
	public function amount_paid(): ?string {
		return $this->amount_paid;
	}

	/**
	 * Get the amount remaining.
	 *
	 * @return string|null
	 */
	public function amount_remaining(): ?string {
		return $this->amount_remaining;
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
	 * Get the associated checkout identifier.
	 *
	 * @return string|null
	 */
	public function checkout_id(): ?string {
		return $this->checkout_id;
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
			throw new InvalidArgumentException( 'Bachs payment response is missing a required string field.' );
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
