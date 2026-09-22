<?php
/**
 * In-memory payment-intent store for webhook processor tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Support\Bachs\Webhook;

use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use Etchpoint\BachsIntegrations\Core\Payment\ProviderStatus;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use Etchpoint\BachsIntegrations\Persistence\IntentStore;

/**
 * Deterministic in-memory implementation of webhook intent lookups and updates.
 */
final class InMemoryIntentStore implements IntentStore {
	/**
	 * Intent records keyed by row identifier.
	 *
	 * @var array<int, IntentRecord>
	 */
	private array $records = array();

	/**
	 * Create a store with optional seed records.
	 *
	 * @param array<int, IntentRecord> $records Seed intent records.
	 */
	public function __construct( array $records = array() ) {
		foreach ( $records as $record ) {
			$this->records[ $record->id() ] = $record;
		}
	}

	/**
	 * Find by row identifier.
	 *
	 * @param int $id Intent row identifier.
	 * @return IntentRecord|null
	 */
	public function find_by_id( int $id ): ?IntentRecord {
		return $this->records[ $id ] ?? null;
	}

	/**
	 * Find by provider checkout identifier.
	 *
	 * @param string $checkout_id Provider checkout identifier.
	 * @return IntentRecord|null
	 */
	public function find_by_checkout_id( string $checkout_id ): ?IntentRecord {
		foreach ( $this->records as $record ) {
			if ( $record->checkout_id() === $checkout_id ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Find by opaque merchant reference.
	 *
	 * @param string $reference Merchant reference.
	 * @return IntentRecord|null
	 */
	public function find_by_reference( string $reference ): ?IntentRecord {
		foreach ( $this->records as $record ) {
			if ( $record->intent()->reference() === $reference ) {
				return $record;
			}
		}

		return null;
	}

	/**
	 * Update the normalized provider status.
	 *
	 * @param int            $id     Intent row identifier.
	 * @param ProviderStatus $status Provider status.
	 * @return bool
	 */
	public function update_provider_status( int $id, ProviderStatus $status ): bool {
		$record = $this->find_by_id( $id );

		if ( null === $record ) {
			return false;
		}

		$this->records[ $id ] = $this->copy_record(
			$record,
			self::rehydrate_with_status( $record->intent(), $status ),
			$record->charge_id()
		);

		return true;
	}

	/**
	 * Attach the successful provider charge once.
	 *
	 * @param int    $id        Intent row identifier.
	 * @param string $charge_id Provider charge identifier.
	 * @return bool
	 */
	public function attach_successful_charge( int $id, string $charge_id ): bool {
		$record = $this->find_by_id( $id );

		if ( null === $record || null !== $record->charge_id() ) {
			return false;
		}

		foreach ( $this->records as $other ) {
			if ( $other->id() !== $id && $other->charge_id() === $charge_id ) {
				return false;
			}
		}

		$this->records[ $id ] = $this->copy_record(
			$record,
			self::rehydrate_with_status( $record->intent(), ProviderStatus::SUCCEEDED ),
			$charge_id
		);

		return true;
	}

	/**
	 * Create a copy of a persisted record with updated domain state.
	 *
	 * @param IntentRecord  $record    Existing record.
	 * @param PaymentIntent $intent    Updated domain intent.
	 * @param string|null   $charge_id Updated charge identifier.
	 * @return IntentRecord
	 */
	private function copy_record(
		IntentRecord $record,
		PaymentIntent $intent,
		?string $charge_id
	): IntentRecord {
		return new IntentRecord(
			$record->id(),
			$intent,
			$record->checkout_id(),
			$charge_id,
			$record->processing_started_at(),
			$record->last_error_code(),
			$record->last_error_message(),
			$record->created_at(),
			$record->updated_at(),
			$record->completed_at()
		);
	}

	/**
	 * Rehydrate a domain intent with a new provider status.
	 *
	 * @param PaymentIntent  $intent Original intent.
	 * @param ProviderStatus $status New provider status.
	 * @return PaymentIntent
	 */
	private static function rehydrate_with_status(
		PaymentIntent $intent,
		ProviderStatus $status
	): PaymentIntent {
		return PaymentIntent::rehydrate(
			$intent->uuid(),
			$intent->integration(),
			$intent->local_object_type(),
			$intent->local_object_id(),
			$intent->environment(),
			$intent->reference(),
			$intent->idempotency_key(),
			$intent->expected_amount(),
			$intent->attempt(),
			$status,
			$intent->application_status()
		);
	}
}
