<?php
/**
 * Verified refund evidence.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Refund;

use Etchpoint\BachsIntegrations\Core\Money\Money;

/**
 * Immutable refund evidence reconstructed from authoritative Bachs state.
 */
final class VerifiedRefund {
	/**
	 * Integration identifier.
	 *
	 * @var string
	 */
	private string $integration;

	/**
	 * Host object type.
	 *
	 * @var string
	 */
	private string $local_object_type;

	/**
	 * Host object identifier.
	 *
	 * @var string
	 */
	private string $local_object_id;

	/**
	 * Original payment intent UUID.
	 *
	 * @var string
	 */
	private string $intent_uuid;

	/**
	 * Bachs refund identifier.
	 *
	 * @var string
	 */
	private string $provider_refund_id;

	/**
	 * Original Bachs charge identifier.
	 *
	 * @var string
	 */
	private string $charge_id;

	/**
	 * Requested refund amount.
	 *
	 * @var Money
	 */
	private Money $requested_amount;

	/**
	 * Amount Bachs confirmed refunded.
	 *
	 * @var Money
	 */
	private Money $refunded_amount;

	/**
	 * Whether this refunds the full original payment.
	 *
	 * @var bool
	 */
	private bool $full_refund;

	/**
	 * Optional merchant refund reason.
	 *
	 * @var string|null
	 */
	private ?string $reason;

	/**
	 * Create verified refund evidence.
	 *
	 * @param string      $integration        Integration identifier.
	 * @param string      $local_object_type  Host object type.
	 * @param string      $local_object_id    Host object identifier.
	 * @param string      $intent_uuid        Original payment intent UUID.
	 * @param string      $provider_refund_id Bachs refund identifier.
	 * @param string      $charge_id          Original Bachs charge identifier.
	 * @param Money       $requested_amount   Requested refund amount.
	 * @param Money       $refunded_amount    Amount Bachs confirmed refunded.
	 * @param bool        $full_refund        Whether this refunds the full original payment.
	 * @param string|null $reason             Optional merchant refund reason.
	 */
	public function __construct(
		string $integration,
		string $local_object_type,
		string $local_object_id,
		string $intent_uuid,
		string $provider_refund_id,
		string $charge_id,
		Money $requested_amount,
		Money $refunded_amount,
		bool $full_refund,
		?string $reason
	) {
		$this->integration        = $integration;
		$this->local_object_type  = $local_object_type;
		$this->local_object_id    = $local_object_id;
		$this->intent_uuid        = $intent_uuid;
		$this->provider_refund_id = $provider_refund_id;
		$this->charge_id          = $charge_id;
		$this->requested_amount   = $requested_amount;
		$this->refunded_amount    = $refunded_amount;
		$this->full_refund        = $full_refund;
		$this->reason             = $reason;
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
	 * Get the host object type.
	 *
	 * @return string
	 */
	public function local_object_type(): string {
		return $this->local_object_type;
	}

	/**
	 * Get the host object identifier.
	 *
	 * @return string
	 */
	public function local_object_id(): string {
		return $this->local_object_id;
	}

	/**
	 * Get the original intent UUID.
	 *
	 * @return string
	 */
	public function intent_uuid(): string {
		return $this->intent_uuid;
	}

	/**
	 * Get the Bachs refund identifier.
	 *
	 * @return string
	 */
	public function provider_refund_id(): string {
		return $this->provider_refund_id;
	}

	/**
	 * Get the original Bachs charge identifier.
	 *
	 * @return string
	 */
	public function charge_id(): string {
		return $this->charge_id;
	}

	/**
	 * Get the requested refund amount.
	 *
	 * @return Money
	 */
	public function requested_amount(): Money {
		return $this->requested_amount;
	}

	/**
	 * Get the provider-confirmed refunded amount.
	 *
	 * @return Money
	 */
	public function refunded_amount(): Money {
		return $this->refunded_amount;
	}

	/**
	 * Determine whether this is a full refund.
	 *
	 * @return bool
	 */
	public function is_full_refund(): bool {
		return $this->full_refund;
	}

	/**
	 * Get the optional refund reason.
	 *
	 * @return string|null
	 */
	public function reason(): ?string {
		return $this->reason;
	}
}
