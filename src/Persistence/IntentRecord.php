<?php
/**
 * Persisted payment-intent record.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

use DateTimeImmutable;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;

/**
 * Combines the immutable payment intent with database-owned provider metadata.
 */
final class IntentRecord {
	/**
	 * Database row identifier.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Domain payment intent.
	 *
	 * @var PaymentIntent
	 */
	private PaymentIntent $intent;

	/**
	 * Provider checkout identifier.
	 *
	 * @var string|null
	 */
	private ?string $checkout_id;

	/**
	 * Successful provider charge identifier.
	 *
	 * @var string|null
	 */
	private ?string $charge_id;

	/**
	 * Processing claim timestamp.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $processing_started_at;

	/**
	 * Last normalized error code.
	 *
	 * @var string|null
	 */
	private ?string $last_error_code;

	/**
	 * Last redacted error message.
	 *
	 * @var string|null
	 */
	private ?string $last_error_message;

	/**
	 * Creation timestamp.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $created_at;

	/**
	 * Last update timestamp.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $updated_at;

	/**
	 * Completion timestamp.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $completed_at;

	/**
	 * Create a persisted intent record.
	 *
	 * @param int                    $id                    Database row identifier.
	 * @param PaymentIntent          $intent                Domain payment intent.
	 * @param string|null            $checkout_id           Provider checkout identifier.
	 * @param string|null            $charge_id             Successful provider charge identifier.
	 * @param DateTimeImmutable|null $processing_started_at Processing claim timestamp.
	 * @param string|null            $last_error_code       Last normalized error code.
	 * @param string|null            $last_error_message    Last redacted error message.
	 * @param DateTimeImmutable      $created_at            Creation timestamp.
	 * @param DateTimeImmutable      $updated_at            Last update timestamp.
	 * @param DateTimeImmutable|null $completed_at          Completion timestamp.
	 */
	public function __construct(
		int $id,
		PaymentIntent $intent,
		?string $checkout_id,
		?string $charge_id,
		?DateTimeImmutable $processing_started_at,
		?string $last_error_code,
		?string $last_error_message,
		DateTimeImmutable $created_at,
		DateTimeImmutable $updated_at,
		?DateTimeImmutable $completed_at
	) {
		$this->id                    = $id;
		$this->intent                = $intent;
		$this->checkout_id           = $checkout_id;
		$this->charge_id             = $charge_id;
		$this->processing_started_at = $processing_started_at;
		$this->last_error_code       = $last_error_code;
		$this->last_error_message    = $last_error_message;
		$this->created_at            = $created_at;
		$this->updated_at            = $updated_at;
		$this->completed_at          = $completed_at;
	}

	/**
	 * Get the database row identifier.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Get the domain payment intent.
	 *
	 * @return PaymentIntent
	 */
	public function intent(): PaymentIntent {
		return $this->intent;
	}

	/**
	 * Get the provider checkout identifier.
	 *
	 * @return string|null
	 */
	public function checkout_id(): ?string {
		return $this->checkout_id;
	}

	/**
	 * Get the authoritative successful provider charge identifier.
	 *
	 * @return string|null
	 */
	public function charge_id(): ?string {
		return $this->charge_id;
	}

	/**
	 * Get the application-processing claim timestamp.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function processing_started_at(): ?DateTimeImmutable {
		return $this->processing_started_at;
	}

	/**
	 * Get the last normalized error code.
	 *
	 * @return string|null
	 */
	public function last_error_code(): ?string {
		return $this->last_error_code;
	}

	/**
	 * Get the last redacted error message.
	 *
	 * @return string|null
	 */
	public function last_error_message(): ?string {
		return $this->last_error_message;
	}

	/**
	 * Get the creation timestamp.
	 *
	 * @return DateTimeImmutable
	 */
	public function created_at(): DateTimeImmutable {
		return $this->created_at;
	}

	/**
	 * Get the last update timestamp.
	 *
	 * @return DateTimeImmutable
	 */
	public function updated_at(): DateTimeImmutable {
		return $this->updated_at;
	}

	/**
	 * Get the completion timestamp.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function completed_at(): ?DateTimeImmutable {
		return $this->completed_at;
	}
}
