<?php
/**
 * Bachs refund response.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use InvalidArgumentException;

/**
 * Immutable refund state returned by the Bachs Refunds API.
 */
final class ProviderRefund {
	/**
	 * Refund identifier.
	 *
	 * @var string
	 */
	private string $refund_id;

	/**
	 * Original charge identifier.
	 *
	 * @var string
	 */
	private string $charge_id;

	/**
	 * Merchant correlation reference.
	 *
	 * @var string
	 */
	private string $reference;

	/**
	 * Raw provider refund status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Requested refund amount.
	 *
	 * @var string
	 */
	private string $requested_amount;

	/**
	 * Settled refunded amount when available.
	 *
	 * @var string|null
	 */
	private ?string $refunded_amount;

	/**
	 * Optional refund reason.
	 *
	 * @var string|null
	 */
	private ?string $reason;

	/**
	 * Rehydrate refund data from an API response.
	 *
	 * @param array<string, mixed> $data Bachs refund response.
	 * @return self
	 *
	 * @throws InvalidArgumentException When required evidence is missing.
	 */
	public static function from_api_response( array $data ): self {
		$instance                   = new self();
		$instance->refund_id        = self::required_string( $data, 'refund_id' );
		$instance->charge_id        = self::required_string( $data, 'charge_id' );
		$instance->reference        = self::required_string( $data, 'reference' );
		$instance->status           = self::required_string( $data, 'status' );
		$instance->requested_amount = self::required_string( $data, 'requested_amount' );
		$instance->refunded_amount  = self::optional_string( $data, 'refunded_amount' );
		$instance->reason           = self::optional_string( $data, 'reason' );

		return $instance;
	}

	/**
	 * Get the refund identifier.
	 *
	 * @return string
	 */
	public function refund_id(): string {
		return $this->refund_id;
	}

	/**
	 * Get the original charge identifier.
	 *
	 * @return string
	 */
	public function charge_id(): string {
		return $this->charge_id;
	}

	/**
	 * Get the merchant correlation reference.
	 *
	 * @return string
	 */
	public function reference(): string {
		return $this->reference;
	}

	/**
	 * Get the raw provider refund status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Get the requested refund amount.
	 *
	 * @return string
	 */
	public function requested_amount(): string {
		return $this->requested_amount;
	}

	/**
	 * Get the settled refunded amount.
	 *
	 * @return string|null
	 */
	public function refunded_amount(): ?string {
		return $this->refunded_amount;
	}

	/**
	 * Get the optional refund reason.
	 *
	 * @return string|null
	 */
	public function reason(): ?string {
		return $this->reason;
	}

	/**
	 * Read a required string field.
	 *
	 * @param array<string, mixed> $data Response data.
	 * @param string               $key  Field key.
	 * @return string
	 *
	 * @throws InvalidArgumentException When the field is missing.
	 */
	private static function required_string( array $data, string $key ): string {
		$value = self::optional_string( $data, $key );

		if ( null === $value || '' === $value ) {
			throw new InvalidArgumentException( 'Bachs refund response is missing a required string field.' );
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
