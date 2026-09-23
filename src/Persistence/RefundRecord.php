<?php
/**
 * Persisted refund record.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

use DateTimeImmutable;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Refund\RefundApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Refund\RefundStatus;

/**
 * Immutable view of one plugin-owned Bachs refund operation.
 */
final class RefundRecord {
	/**
	 * Database row identifier.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Local refund UUID.
	 *
	 * @var string
	 */
	private string $uuid;

	/**
	 * Original payment intent row identifier.
	 *
	 * @var int
	 */
	private int $intent_id;

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
	 * Bachs environment.
	 *
	 * @var string
	 */
	private string $environment;

	/**
	 * Original Bachs charge identifier.
	 *
	 * @var string
	 */
	private string $charge_id;

	/**
	 * Bachs refund identifier.
	 *
	 * @var string|null
	 */
	private ?string $provider_refund_id;

	/**
	 * Merchant refund reference.
	 *
	 * @var string
	 */
	private string $reference;

	/**
	 * Stable provider idempotency key.
	 *
	 * @var string
	 */
	private string $idempotency_key;

	/**
	 * Requested refund amount.
	 *
	 * @var Money
	 */
	private Money $requested_amount;

	/**
	 * Provider-confirmed refunded amount.
	 *
	 * @var Money|null
	 */
	private ?Money $refunded_amount;

	/**
	 * Normalized provider refund state.
	 *
	 * @var RefundStatus
	 */
	private RefundStatus $provider_status;

	/**
	 * WordPress application state.
	 *
	 * @var RefundApplicationStatus
	 */
	private RefundApplicationStatus $application_status;

	/**
	 * Optional merchant reason.
	 *
	 * @var string|null
	 */
	private ?string $reason;

	/**
	 * Host-side refund identifier when available.
	 *
	 * @var string|null
	 */
	private ?string $local_refund_id;

	/**
	 * Stable diagnostic code.
	 *
	 * @var string|null
	 */
	private ?string $last_error_code;

	/**
	 * Redacted diagnostic message.
	 *
	 * @var string|null
	 */
	private ?string $last_error_message;

	/**
	 * Creation time.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * Last update time.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $updated_at;

	/**
	 * Completion time.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $completed_at;

	/**
	 * Create the persisted refund record.
	 *
	 * @param int                     $id                 Database row identifier.
	 * @param string                  $uuid               Local refund UUID.
	 * @param int                     $intent_id          Original payment intent row identifier.
	 * @param string                  $integration        Integration identifier.
	 * @param string                  $local_object_type  Host object type.
	 * @param string                  $local_object_id    Host object identifier.
	 * @param string                  $environment        Bachs environment.
	 * @param string                  $charge_id          Original Bachs charge identifier.
	 * @param string|null             $provider_refund_id Bachs refund identifier.
	 * @param string                  $reference          Merchant refund reference.
	 * @param string                  $idempotency_key    Stable provider idempotency key.
	 * @param Money                   $requested_amount   Requested refund amount.
	 * @param Money|null              $refunded_amount    Provider-confirmed refunded amount.
	 * @param RefundStatus            $provider_status    Normalized provider refund state.
	 * @param RefundApplicationStatus $application_status WordPress application state.
	 * @param string|null             $reason             Optional merchant reason.
	 * @param string|null             $local_refund_id    Host-side refund identifier when available.
	 * @param string|null             $last_error_code    Stable diagnostic code.
	 * @param string|null             $last_error_message Redacted diagnostic message.
	 * @param DateTimeImmutable       $created_at         Creation time.
	 * @param DateTimeImmutable       $updated_at         Last update time.
	 * @param DateTimeImmutable|null  $completed_at       Completion time.
	 */
	public function __construct(
		int $id,
		string $uuid,
		int $intent_id,
		string $integration,
		string $local_object_type,
		string $local_object_id,
		string $environment,
		string $charge_id,
		?string $provider_refund_id,
		string $reference,
		string $idempotency_key,
		Money $requested_amount,
		?Money $refunded_amount,
		RefundStatus $provider_status,
		RefundApplicationStatus $application_status,
		?string $reason,
		?string $local_refund_id,
		?string $last_error_code,
		?string $last_error_message,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at,
		?DateTimeImmutable $completed_at
	) {
		$this->id                 = $id;
		$this->uuid               = $uuid;
		$this->intent_id          = $intent_id;
		$this->integration        = $integration;
		$this->local_object_type  = $local_object_type;
		$this->local_object_id    = $local_object_id;
		$this->environment        = $environment;
		$this->charge_id          = $charge_id;
		$this->provider_refund_id = $provider_refund_id;
		$this->reference          = $reference;
		$this->idempotency_key    = $idempotency_key;
		$this->requested_amount   = $requested_amount;
		$this->refunded_amount    = $refunded_amount;
		$this->provider_status    = $provider_status;
		$this->application_status = $application_status;
		$this->reason             = $reason;
		$this->local_refund_id    = $local_refund_id;
		$this->last_error_code    = $last_error_code;
		$this->last_error_message = $last_error_message;
		$this->created_at         = $created_at;
		$this->updated_at         = $updated_at;
		$this->completed_at       = $completed_at;
	}

	/**
	 * Get the database identifier.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Get the local UUID.
	 *
	 * @return string
	 */
	public function uuid(): string {
		return $this->uuid;
	}

	/**
	 * Get the original payment intent identifier.
	 *
	 * @return int
	 */
	public function intent_id(): int {
		return $this->intent_id;
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
	 * Get the Bachs environment.
	 *
	 * @return string
	 */
	public function environment(): string {
		return $this->environment;
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
	 * Get the provider refund identifier.
	 *
	 * @return string|null
	 */
	public function provider_refund_id(): ?string {
		return $this->provider_refund_id;
	}

	/**
	 * Get the merchant reference.
	 *
	 * @return string
	 */
	public function reference(): string {
		return $this->reference;
	}

	/**
	 * Get the provider idempotency key.
	 *
	 * @return string
	 */
	public function idempotency_key(): string {
		return $this->idempotency_key;
	}

	/**
	 * Get the requested amount.
	 *
	 * @return Money
	 */
	public function requested_amount(): Money {
		return $this->requested_amount;
	}

	/**
	 * Get the confirmed refunded amount.
	 *
	 * @return Money|null
	 */
	public function refunded_amount(): ?Money {
		return $this->refunded_amount;
	}

	/**
	 * Get the provider refund status.
	 *
	 * @return RefundStatus
	 */
	public function provider_status(): RefundStatus {
		return $this->provider_status;
	}

	/**
	 * Get the host application status.
	 *
	 * @return RefundApplicationStatus
	 */
	public function application_status(): RefundApplicationStatus {
		return $this->application_status;
	}

	/**
	 * Get the optional reason.
	 *
	 * @return string|null
	 */
	public function reason(): ?string {
		return $this->reason;
	}

	/**
	 * Get the host refund identifier.
	 *
	 * @return string|null
	 */
	public function local_refund_id(): ?string {
		return $this->local_refund_id;
	}

	/**
	 * Get the last diagnostic code.
	 *
	 * @return string|null
	 */
	public function last_error_code(): ?string {
		return $this->last_error_code;
	}

	/**
	 * Get the last redacted diagnostic message.
	 *
	 * @return string|null
	 */
	public function last_error_message(): ?string {
		return $this->last_error_message;
	}

	/**
	 * Get the creation time.
	 *
	 * @return DateTimeImmutable
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Get the last update time.
	 *
	 * @return DateTimeImmutable
	 */
	public function updated_at(): DateTimeImmutable {
		return $this->updated_at;
	}

	/**
	 * Get the completion time.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function completed_at(): ?DateTimeImmutable {
		return $this->completed_at;
	}
}
