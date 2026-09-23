<?php
/**
 * Reconciliation intent persistence contract.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Reconciliation;

use Etchpoint\BachsIntegrations\Core\Payment\ProviderStatus;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;

/**
 * Intent operations required by Bachs/local reconciliation.
 */
interface ReconciliationIntentStore {
	/**
	 * Find an intent by database row identifier.
	 *
	 * @param int $id Intent row identifier.
	 * @return IntentRecord|null
	 */
	public function find_by_id( int $id ): ?IntentRecord;

	/**
	 * Update normalized provider state.
	 *
	 * @param int            $id     Intent row identifier.
	 * @param ProviderStatus $status Provider state.
	 * @return bool Whether the update succeeded.
	 */
	public function update_provider_status( int $id, ProviderStatus $status ): bool;

	/**
	 * Attach the authoritative successful provider payment identifier.
	 *
	 * @param int    $id        Intent row identifier.
	 * @param string $charge_id Provider payment identifier.
	 * @return bool Whether the charge was attached by this call.
	 */
	public function attach_successful_charge( int $id, string $charge_id ): bool;

	/**
	 * Move a reviewed intent back into a retryable state after exact re-verification.
	 *
	 * @param int $id Intent row identifier.
	 * @return bool Whether the intent is ready for a reconciliation fulfillment attempt.
	 */
	public function prepare_for_reconciliation( int $id ): bool;
}
