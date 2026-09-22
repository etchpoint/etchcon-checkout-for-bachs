<?php
/**
 * WooCommerce verified-payment fulfillment.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\WooCommerce;

use Etchpoint\BachsIntegrations\Core\Contracts\PaymentFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Payment\VerifiedPayment;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Throwable;
use WC_Order;

/**
 * Applies verified Bachs payments through WooCommerce order APIs only.
 */
final class WooCommerceFulfillmentHandler implements PaymentFulfillmentHandler {
	/** WooCommerce integration identifier. */
	private const INTEGRATION = 'woocommerce';

	/**
	 * Intent repository.
	 *
	 * @var IntentRepository
	 */
	private IntentRepository $intents;

	/**
	 * Event repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Create the handler.
	 *
	 * @param IntentRepository $intents Payment intent repository.
	 * @param EventRepository  $events  Event inbox repository.
	 */
	public function __construct( IntentRepository $intents, EventRepository $events ) {
		$this->intents = $intents;
		$this->events  = $events;
	}

	/**
	 * Get the integration identifier.
	 *
	 * @return string
	 */
	public function integration(): string {
		return self::INTEGRATION;
	}

	/**
	 * Apply a verified payment to its WooCommerce order.
	 *
	 * @param int             $intent_id Intent database row identifier.
	 * @param int             $event_id  Event inbox row identifier.
	 * @param VerifiedPayment $payment   Verified provider payment evidence.
	 * @return FulfillmentDisposition
	 */
	public function fulfill( int $intent_id, int $event_id, VerifiedPayment $payment ): FulfillmentDisposition {
		$record = $this->intents->find_by_id( $intent_id );

		if (
			null === $record
			|| self::INTEGRATION !== $payment->integration()
			|| 'order' !== $payment->local_object_type()
			|| ! hash_equals( $record->intent()->uuid(), $payment->intent_uuid() )
		) {
			$this->events->mark_requires_review( $event_id, 'woo_intent_mismatch', 'WooCommerce intent correlation failed.' );

			return FulfillmentDisposition::REQUIRES_REVIEW;
		}

		if ( ApplicationStatus::APPLIED === $record->intent()->application_status() ) {
			$this->events->mark_processed( $event_id );

			return FulfillmentDisposition::DUPLICATE;
		}

		if ( ! $this->intents->claim_application_processing( $intent_id ) ) {
			$record = $this->intents->find_by_id( $intent_id );

			if ( null !== $record && ApplicationStatus::APPLIED === $record->intent()->application_status() ) {
				$this->events->mark_processed( $event_id );

				return FulfillmentDisposition::DUPLICATE;
			}

			$this->events->mark_failed( $event_id, 'woo_intent_claim_busy', 'WooCommerce fulfillment could not claim the payment intent.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		$order = wc_get_order( (int) $payment->local_object_id() );

		if ( ! $order instanceof WC_Order ) {
			$this->intents->mark_requires_review( $intent_id, 'woo_order_missing', 'WooCommerce order could not be found.' );
			$this->events->mark_requires_review( $event_id, 'woo_order_missing', 'WooCommerce order could not be found.' );

			return FulfillmentDisposition::REQUIRES_REVIEW;
		}

		if ( $order->is_paid() ) {
			return $this->repair_already_paid_order( $order, $intent_id, $event_id, $payment );
		}

		$existing_transaction_id = $order->get_transaction_id();

		if ( '' !== $existing_transaction_id && ! hash_equals( $existing_transaction_id, $payment->charge_id() ) ) {
			$this->intents->mark_requires_review(
				$intent_id,
				'woo_transaction_conflict',
				'WooCommerce order already contains a different transaction ID.'
			);
			$this->events->mark_requires_review(
				$event_id,
				'woo_transaction_conflict',
				'WooCommerce order already contains a different transaction ID.'
			);

			return FulfillmentDisposition::REQUIRES_REVIEW;
		}

		try {
			$payment_completed = $order->payment_complete( $payment->charge_id() );

			if ( ! $payment_completed ) {
				$this->intents->mark_failed( $intent_id, 'woo_payment_not_applied', 'WooCommerce did not accept the verified payment.' );
				$this->events->mark_failed( $event_id, 'woo_payment_not_applied', 'WooCommerce did not accept the verified payment.' );

				return FulfillmentDisposition::RETRYABLE_FAILURE;
			}

			$order->add_order_note(
				__( 'Bachs payment verified and applied.', 'payment-integrations-for-bachs' )
			);
		} catch ( Throwable ) {
			$this->intents->mark_failed( $intent_id, 'woo_payment_complete_failed', 'WooCommerce could not apply the verified payment.' );
			$this->events->mark_failed( $event_id, 'woo_payment_complete_failed', 'WooCommerce could not apply the verified payment.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		if ( ! $this->intents->mark_applied( $intent_id ) ) {
			$this->events->mark_failed( $event_id, 'woo_local_finalize_failed', 'WooCommerce payment succeeded but local intent finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		if ( ! $this->events->mark_processed( $event_id ) ) {
			$this->events->mark_failed( $event_id, 'woo_event_finalize_failed', 'WooCommerce payment applied but event finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		return FulfillmentDisposition::APPLIED;
	}

	/**
	 * Repair local state when WooCommerce already contains the same transaction.
	 *
	 * @param WC_Order        $order     WooCommerce order.
	 * @param int             $intent_id Intent database row identifier.
	 * @param int             $event_id  Event inbox row identifier.
	 * @param VerifiedPayment $payment   Verified payment evidence.
	 * @return FulfillmentDisposition
	 */
	private function repair_already_paid_order(
		WC_Order $order,
		int $intent_id,
		int $event_id,
		VerifiedPayment $payment
	): FulfillmentDisposition {
		$transaction_id = $order->get_transaction_id();

		if ( '' === $transaction_id || ! hash_equals( $transaction_id, $payment->charge_id() ) ) {
			$this->intents->mark_requires_review( $intent_id, 'woo_transaction_conflict', 'WooCommerce order is paid with a different transaction ID.' );
			$this->events->mark_requires_review( $event_id, 'woo_transaction_conflict', 'WooCommerce order is paid with a different transaction ID.' );

			return FulfillmentDisposition::REQUIRES_REVIEW;
		}

		if ( ! $this->intents->mark_applied( $intent_id ) ) {
			$this->events->mark_failed( $event_id, 'woo_local_repair_failed', 'WooCommerce order is paid but local intent repair failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		if ( ! $this->events->mark_processed( $event_id ) ) {
			$this->events->mark_failed( $event_id, 'woo_event_repair_finalize_failed', 'WooCommerce payment repair succeeded but event finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		return FulfillmentDisposition::DUPLICATE;
	}
}
