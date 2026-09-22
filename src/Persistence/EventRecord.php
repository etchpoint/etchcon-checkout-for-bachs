<?php
/**
 * Persisted provider-event record.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

use DateTimeImmutable;

/**
 * Represents the normalized, deduplicated webhook event inbox record.
 */
final class EventRecord {
	/**
	 * Database row identifier.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Provider event identifier.
	 *
	 * @var string
	 */
	private string $provider_event_id;

	/**
	 * Provider event type.
	 *
	 * @var string
	 */
	private string $event_type;

	/**
	 * Provider organization/account identifier.
	 *
	 * @var string|null
	 */
	private ?string $organization_id;

	/**
	 * Matched local intent row identifier.
	 *
	 * @var int|null
	 */
	private ?int $intent_id;

	/**
	 * SHA-256 hash of the verified raw payload.
	 *
	 * @var string
	 */
	private string $payload_hash;

	/**
	 * Local processing state.
	 *
	 * @var EventProcessingStatus
	 */
	private EventProcessingStatus $processing_status;

	/**
	 * Processing claim timestamp.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $processing_started_at;

	/**
	 * Processing claim count.
	 *
	 * @var int
	 */
	private int $attempts;

	/**
	 * Last normalized failure code.
	 *
	 * @var string|null
	 */
	private ?string $failure_code;

	/**
	 * Last redacted failure message.
	 *
	 * @var string|null
	 */
	private ?string $failure_message;

	/**
	 * Event receipt timestamp.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $received_at;

	/**
	 * Successful processing timestamp.
	 *
	 * @var DateTimeImmutable|null
	 */
	private ?DateTimeImmutable $processed_at;

	/**
	 * Create a provider-event record.
	 *
	 * @param int                    $id                    Database row identifier.
	 * @param string                 $provider_event_id     Provider event identifier.
	 * @param string                 $event_type            Provider event type.
	 * @param string|null            $organization_id       Provider organization/account identifier.
	 * @param int|null               $intent_id             Matched local intent row identifier.
	 * @param string                 $payload_hash          SHA-256 hash of the verified raw payload.
	 * @param EventProcessingStatus  $processing_status     Local processing state.
	 * @param DateTimeImmutable|null $processing_started_at Processing claim timestamp.
	 * @param int                    $attempts              Processing claim count.
	 * @param string|null            $failure_code          Last normalized failure code.
	 * @param string|null            $failure_message       Last redacted failure message.
	 * @param DateTimeImmutable      $received_at           Event receipt timestamp.
	 * @param DateTimeImmutable|null $processed_at          Successful processing timestamp.
	 */
	public function __construct(
		int $id,
		string $provider_event_id,
		string $event_type,
		?string $organization_id,
		?int $intent_id,
		string $payload_hash,
		EventProcessingStatus $processing_status,
		?DateTimeImmutable $processing_started_at,
		int $attempts,
		?string $failure_code,
		?string $failure_message,
		DateTimeImmutable $received_at,
		?DateTimeImmutable $processed_at
	) {
		$this->id                    = $id;
		$this->provider_event_id     = $provider_event_id;
		$this->event_type            = $event_type;
		$this->organization_id       = $organization_id;
		$this->intent_id             = $intent_id;
		$this->payload_hash          = $payload_hash;
		$this->processing_status     = $processing_status;
		$this->processing_started_at = $processing_started_at;
		$this->attempts              = $attempts;
		$this->failure_code          = $failure_code;
		$this->failure_message       = $failure_message;
		$this->received_at           = $received_at;
		$this->processed_at          = $processed_at;
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
	 * Get the provider event identifier.
	 *
	 * @return string
	 */
	public function provider_event_id(): string {
		return $this->provider_event_id;
	}

	/**
	 * Get the provider event type.
	 *
	 * @return string
	 */
	public function event_type(): string {
		return $this->event_type;
	}

	/**
	 * Get the provider organization/account identifier.
	 *
	 * @return string|null
	 */
	public function organization_id(): ?string {
		return $this->organization_id;
	}

	/**
	 * Get the matched local intent row identifier.
	 *
	 * @return int|null
	 */
	public function intent_id(): ?int {
		return $this->intent_id;
	}

	/**
	 * Get the SHA-256 payload hash.
	 *
	 * @return string
	 */
	public function payload_hash(): string {
		return $this->payload_hash;
	}

	/**
	 * Get the local processing state.
	 *
	 * @return EventProcessingStatus
	 */
	public function processing_status(): EventProcessingStatus {
		return $this->processing_status;
	}

	/**
	 * Get the processing claim timestamp.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function processing_started_at(): ?DateTimeImmutable {
		return $this->processing_started_at;
	}

	/**
	 * Get the number of processing claims.
	 *
	 * @return int
	 */
	public function attempts(): int {
		return $this->attempts;
	}

	/**
	 * Get the last normalized failure code.
	 *
	 * @return string|null
	 */
	public function failure_code(): ?string {
		return $this->failure_code;
	}

	/**
	 * Get the last redacted failure message.
	 *
	 * @return string|null
	 */
	public function failure_message(): ?string {
		return $this->failure_message;
	}

	/**
	 * Get the event receipt timestamp.
	 *
	 * @return DateTimeImmutable
	 */
	public function received_at(): DateTimeImmutable {
		return $this->received_at;
	}

	/**
	 * Get the successful processing timestamp.
	 *
	 * @return DateTimeImmutable|null
	 */
	public function processed_at(): ?DateTimeImmutable {
		return $this->processed_at;
	}
}
