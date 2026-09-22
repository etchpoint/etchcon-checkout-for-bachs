<?php
/**
 * Checkout-intent persistence contract.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;

/**
 * Persistence operations required while starting hosted checkout.
 */
interface CheckoutIntentStore {
	/**
	 * Persist a newly-created payment intent.
	 *
	 * @param PaymentIntent $intent Intent to persist.
	 * @return int Database row identifier.
	 */
	public function create( PaymentIntent $intent ): int;

	/**
	 * Find the latest logical attempt for a host application object.
	 *
	 * @param string $integration       Integration identifier.
	 * @param string $local_object_type Host object type.
	 * @param string $local_object_id   Host object identifier.
	 * @return IntentRecord|null
	 */
	public function find_latest_for_local_object(
		string $integration,
		string $local_object_type,
		string $local_object_id
	): ?IntentRecord;

	/**
	 * Attach the provider checkout identifier after checkout creation.
	 *
	 * @param int    $id          Intent row identifier.
	 * @param string $checkout_id Provider checkout identifier.
	 * @return bool Whether the update succeeded.
	 */
	public function attach_checkout( int $id, string $checkout_id ): bool;
}
