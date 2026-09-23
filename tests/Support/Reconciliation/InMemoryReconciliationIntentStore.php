<?php
/**
 * In-memory reconciliation intent store.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Support\Reconciliation;

use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use Etchpoint\BachsIntegrations\Core\Payment\ProviderStatus;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use Etchpoint\BachsIntegrations\Reconciliation\ReconciliationIntentStore;

/**
 * Deterministic reconciliation store for unit tests.
 */
final class InMemoryReconciliationIntentStore implements ReconciliationIntentStore {
	/**
	 * Intent records keyed by row identifier.
	 *
	 * @var array<int, IntentRecord>
	 */
	private array $records = array();

	/**
	 * Seed the store.
	 *
	 * @param array<int, IntentRecord> $records Intent records.
	 */
	public function __construct( array $records ) {
		foreach ( $records as $record ) {
			$this->records[ $record->id() ] = $record;
		}
	}

	/**
	 * Find an intent by row identifier.
	 *
	 * @param int $id Intent row identifier.
	 * @return IntentRecord|null
	 */
	public function find_by_id( int $id ): ?IntentRecord {
		return $this->records[ $id ] ?? null;
	}

	/**
	 * Update provider state.
	 *
	 * @param int            $id     Intent row identifier.
	 * @param ProviderStatus $status Provider state.
	 * @return bool
	 */
	public function update_provider_status( int $id, ProviderStatus $status ): bool {
		$record = $this->find_by_id( $id );

		if ( null === $record ) {
			return false;
		}

		$this->records[ $id ] = $this->copy( $record, $status, $record->intent()->application_status(), $record->charge_id() );

		return true;
	}

	/**
	 * Attach a successful provider payment identifier.
	 *
	 * @param int    $id        Intent row identifier.
	 * @param string $charge_id Provider payment identifier.
	 * @return bool
	 */
	public function attach_successful_charge( int $id, string $charge_id ): bool {
		$record = $this->find_by_id( $id );

		if ( null === $record || null !== $record->charge_id() ) {
			return false;
		}

		$this->records[ $id ] = $this->copy( $record, ProviderStatus::SUCCEEDED, $record->intent()->application_status(), $charge_id );

		return true;
	}

	/**
	 * Release reviewed state for verified reconciliation.
	 *
	 * @param int $id Intent row identifier.
	 * @return bool
	 */
	public function prepare_for_reconciliation( int $id ): bool {
		$record = $this->find_by_id( $id );

		if ( null === $record ) {
			return false;
		}

		if ( ApplicationStatus::REQUIRES_REVIEW !== $record->intent()->application_status() ) {
			return true;
		}

		$this->records[ $id ] = $this->copy( $record, $record->intent()->provider_status(), ApplicationStatus::FAILED, $record->charge_id() );

		return true;
	}

	/**
	 * Copy a record with updated domain state.
	 *
	 * @param IntentRecord      $record             Existing record.
	 * @param ProviderStatus    $provider_status    Provider state.
	 * @param ApplicationStatus $application_status Application state.
	 * @param string|null       $charge_id           Charge identifier.
	 * @return IntentRecord
	 */
	private function copy(
		IntentRecord $record,
		ProviderStatus $provider_status,
		ApplicationStatus $application_status,
		?string $charge_id
	): IntentRecord {
		$source = $record->intent();
		$intent = PaymentIntent::rehydrate(
			$source->uuid(),
			$source->integration(),
			$source->local_object_type(),
			$source->local_object_id(),
			$source->environment(),
			$source->reference(),
			$source->idempotency_key(),
			$source->expected_amount(),
			$source->attempt(),
			$provider_status,
			$application_status
		);

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
}
