<?php
/**
 * Webhook-event persistence contract.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

/**
 * Minimal deduplicated event-inbox operations used by webhook processing.
 */
interface EventStore {
	/**
	 * Record a verified provider event if its event ID is new.
	 *
	 * @param string      $provider_event_id Provider event identifier.
	 * @param string      $event_type        Provider event type.
	 * @param string|null $organization_id   Provider organization identifier.
	 * @param string      $payload_hash      SHA-256 hash of the verified raw payload.
	 * @return bool Whether a new event row was inserted.
	 */
	public function record_received(
		string $provider_event_id,
		string $event_type,
		?string $organization_id,
		string $payload_hash
	): bool;

	/**
	 * Find an event by provider event identifier.
	 *
	 * @param string $provider_event_id Provider event identifier.
	 * @return EventRecord|null
	 */
	public function find_by_provider_event_id( string $provider_event_id ): ?EventRecord;

	/**
	 * Associate an event with its matched local intent.
	 *
	 * @param int $event_id  Event row identifier.
	 * @param int $intent_id Intent row identifier.
	 * @return bool Whether the update succeeded.
	 */
	public function link_intent( int $event_id, int $intent_id ): bool;

	/**
	 * Atomically claim an event for processing.
	 *
	 * @param int $event_id      Event row identifier.
	 * @param int $stale_seconds Age after which a processing claim is recoverable.
	 * @return bool Whether the claim was acquired.
	 */
	public function claim_processing( int $event_id, int $stale_seconds = 300 ): bool;

	/**
	 * Mark event processing as complete.
	 *
	 * @param int $event_id Event row identifier.
	 * @return bool Whether the event was completed.
	 */
	public function mark_processed( int $event_id ): bool;

	/**
	 * Mark an event as failed and retryable.
	 *
	 * @param int         $event_id Event row identifier.
	 * @param string|null $code     Failure code.
	 * @param string|null $message  Redacted failure message.
	 * @return bool Whether the event was updated.
	 */
	public function mark_failed( int $event_id, ?string $code = null, ?string $message = null ): bool;

	/**
	 * Mark an event as requiring review.
	 *
	 * @param int         $event_id Event row identifier.
	 * @param string|null $code     Review code.
	 * @param string|null $message  Redacted review message.
	 * @return bool Whether the event was updated.
	 */
	public function mark_requires_review( int $event_id, ?string $code = null, ?string $message = null ): bool;
}
