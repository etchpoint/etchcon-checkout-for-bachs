<?php
/**
 * In-memory event store for webhook processor tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Support\Bachs\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use Etchpoint\BachsIntegrations\Persistence\EventProcessingStatus;
use Etchpoint\BachsIntegrations\Persistence\EventRecord;
use Etchpoint\BachsIntegrations\Persistence\EventStore;

/**
 * Deterministic in-memory implementation of the event inbox contract.
 */
final class InMemoryEventStore implements EventStore {
	/**
	 * Event records keyed by provider event ID.
	 *
	 * @var array<string, EventRecord>
	 */
	private array $records = array();

	/**
	 * Next synthetic database row identifier.
	 *
	 * @var int
	 */
	private int $next_id = 1;

	/**
	 * Record a new event if its provider event ID has not been seen.
	 *
	 * @param string      $provider_event_id Provider event identifier.
	 * @param string      $event_type        Provider event type.
	 * @param string|null $organization_id   Provider organization identifier.
	 * @param string      $payload_hash      Payload hash.
	 * @return bool
	 */
	public function record_received(
		string $provider_event_id,
		string $event_type,
		?string $organization_id,
		string $payload_hash
	): bool {
		if ( isset( $this->records[ $provider_event_id ] ) ) {
			return false;
		}

		$now = self::now();

		$this->records[ $provider_event_id ] = new EventRecord(
			$this->next_id++,
			$provider_event_id,
			$event_type,
			$organization_id,
			null,
			$payload_hash,
			EventProcessingStatus::RECEIVED,
			null,
			0,
			null,
			null,
			$now,
			null
		);

		return true;
	}

	/**
	 * Find an event by provider event identifier.
	 *
	 * @param string $provider_event_id Provider event identifier.
	 * @return EventRecord|null
	 */
	public function find_by_provider_event_id( string $provider_event_id ): ?EventRecord {
		return $this->records[ $provider_event_id ] ?? null;
	}

	/**
	 * Associate an event with an intent.
	 *
	 * @param int $event_id  Event row identifier.
	 * @param int $intent_id Intent row identifier.
	 * @return bool
	 */
	public function link_intent( int $event_id, int $intent_id ): bool {
		$record = $this->find_by_row_id( $event_id );

		if ( null === $record ) {
			return false;
		}

		$this->store(
			new EventRecord(
				$record->id(),
				$record->provider_event_id(),
				$record->event_type(),
				$record->organization_id(),
				$intent_id,
				$record->payload_hash(),
				$record->processing_status(),
				$record->processing_started_at(),
				$record->attempts(),
				$record->failure_code(),
				$record->failure_message(),
				$record->received_at(),
				$record->processed_at()
			)
		);

		return true;
	}

	/**
	 * Claim an event for processing.
	 *
	 * @param int $event_id      Event row identifier.
	 * @param int $stale_seconds Unused synthetic stale threshold.
	 * @return bool
	 */
	public function claim_processing( int $event_id, int $stale_seconds = 300 ): bool {
		unset( $stale_seconds );
		$record = $this->find_by_row_id( $event_id );

		if ( null === $record ) {
			return false;
		}

		if ( ! in_array(
			$record->processing_status(),
			array( EventProcessingStatus::RECEIVED, EventProcessingStatus::FAILED ),
			true
		) ) {
			return false;
		}

		$this->store(
			new EventRecord(
				$record->id(),
				$record->provider_event_id(),
				$record->event_type(),
				$record->organization_id(),
				$record->intent_id(),
				$record->payload_hash(),
				EventProcessingStatus::PROCESSING,
				self::now(),
				$record->attempts() + 1,
				$record->failure_code(),
				$record->failure_message(),
				$record->received_at(),
				$record->processed_at()
			)
		);

		return true;
	}

	/**
	 * Mark an event as processed.
	 *
	 * @param int $event_id Event row identifier.
	 * @return bool
	 */
	public function mark_processed( int $event_id ): bool {
		return $this->transition( $event_id, EventProcessingStatus::PROCESSED, null, null, self::now() );
	}

	/**
	 * Mark an event as failed.
	 *
	 * @param int         $event_id Event row identifier.
	 * @param string|null $code     Failure code.
	 * @param string|null $message  Failure message.
	 * @return bool
	 */
	public function mark_failed( int $event_id, ?string $code = null, ?string $message = null ): bool {
		return $this->transition( $event_id, EventProcessingStatus::FAILED, $code, $message, null );
	}

	/**
	 * Mark an event as requiring review.
	 *
	 * @param int         $event_id Event row identifier.
	 * @param string|null $code     Review code.
	 * @param string|null $message  Review message.
	 * @return bool
	 */
	public function mark_requires_review( int $event_id, ?string $code = null, ?string $message = null ): bool {
		return $this->transition( $event_id, EventProcessingStatus::REQUIRES_REVIEW, $code, $message, null );
	}

	/**
	 * Replace a stored event with the same provider event ID.
	 *
	 * @param EventRecord $record Event record.
	 * @return void
	 */
	public function seed( EventRecord $record ): void {
		$this->store( $record );
	}

	/**
	 * Transition a claimed event to a terminal local processing state.
	 *
	 * @param int                    $event_id     Event row identifier.
	 * @param EventProcessingStatus  $status       Target status.
	 * @param string|null            $failure_code Failure code.
	 * @param string|null            $failure_text Failure message.
	 * @param DateTimeImmutable|null $processed_at Processing timestamp.
	 * @return bool
	 */
	private function transition(
		int $event_id,
		EventProcessingStatus $status,
		?string $failure_code,
		?string $failure_text,
		?DateTimeImmutable $processed_at
	): bool {
		$record = $this->find_by_row_id( $event_id );

		if ( null === $record || EventProcessingStatus::PROCESSING !== $record->processing_status() ) {
			return false;
		}

		$this->store(
			new EventRecord(
				$record->id(),
				$record->provider_event_id(),
				$record->event_type(),
				$record->organization_id(),
				$record->intent_id(),
				$record->payload_hash(),
				$status,
				null,
				$record->attempts(),
				$failure_code,
				$failure_text,
				$record->received_at(),
				$processed_at
			)
		);

		return true;
	}

	/**
	 * Find a record by synthetic database row identifier.
	 *
	 * @param int $event_id Event row identifier.
	 * @return EventRecord|null
	 */
	private function find_by_row_id( int $event_id ): ?EventRecord {
		foreach ( $this->records as $record ) {
			if ( $event_id === $record->id() ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Store an event record by provider event ID.
	 *
	 * @param EventRecord $record Event record.
	 * @return void
	 */
	private function store( EventRecord $record ): void {
		$this->records[ $record->provider_event_id() ] = $record;
	}

	/**
	 * Return a deterministic UTC timestamp for tests.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable( '2026-09-22T12:00:00+00:00', new DateTimeZone( 'UTC' ) );
	}
}
