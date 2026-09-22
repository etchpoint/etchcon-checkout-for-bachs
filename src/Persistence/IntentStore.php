<?php
/**
 * Payment-intent persistence contract.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

use Etchpoint\BachsIntegrations\Core\Payment\ProviderStatus;

/**
 * Minimal payment-intent operations required by webhook processing.
 */
interface IntentStore {
	/**
	 * Find an intent by database row identifier.
	 *
	 * @param int $id Intent row identifier.
	 * @return IntentRecord|null
	 */
	public function find_by_id( int $id ): ?IntentRecord;

	/**
	 * Find an intent by provider checkout identifier.
	 *
	 * @param string $checkout_id Provider checkout identifier.
	 * @return IntentRecord|null
	 */
	public function find_by_checkout_id( string $checkout_id ): ?IntentRecord;

	/**
	 * Find an intent by opaque merchant reference.
	 *
	 * @param string $reference Merchant reference.
	 * @return IntentRecord|null
	 */
	public function find_by_reference( string $reference ): ?IntentRecord;

	/**
	 * Update normalized provider state.
	 *
	 * @param int            $id     Intent row identifier.
	 * @param ProviderStatus $status New provider state.
	 * @return bool Whether the update succeeded.
	 */
	public function update_provider_status( int $id, ProviderStatus $status ): bool;

	/**
	 * Attach the authoritative successful provider charge identifier.
	 *
	 * @param int    $id        Intent row identifier.
	 * @param string $charge_id Provider charge/payment identifier.
	 * @return bool Whether the charge was attached by this call.
	 */
	public function attach_successful_charge( int $id, string $charge_id ): bool;
}
