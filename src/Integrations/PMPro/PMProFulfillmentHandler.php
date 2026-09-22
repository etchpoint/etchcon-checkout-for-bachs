<?php
/**
 * Paid Memberships Pro verified-payment fulfillment.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\PMPro;

use Etchpoint\BachsIntegrations\Core\Contracts\PaymentFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Payment\VerifiedPayment;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use MemberOrder;
use Throwable;

/**
 * Applies verified Bachs payments through Paid Memberships Pro's completion API.
 */
final class PMProFulfillmentHandler implements PaymentFulfillmentHandler {
	/** Paid Memberships Pro integration identifier. */
	private const INTEGRATION = 'pmpro';

	/** Successful fulfillment marker stored on the PMPro order. */
	private const FULFILLED_META = '_etchpoint_bachs_fulfilled';

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
	 * Apply a verified payment to its Paid Memberships Pro order.
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
			$this->events->mark_requires_review( $event_id, 'pmpro_intent_mismatch', 'Paid Memberships Pro intent correlation failed.' );

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

			$this->events->mark_failed( $event_id, 'pmpro_intent_claim_busy', 'Paid Memberships Pro fulfillment could not claim the payment intent.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		$order = new MemberOrder( (int) $payment->local_object_id() );

		if ( 1 > (int) $order->id ) {
			return $this->requires_review(
				$intent_id,
				$event_id,
				'pmpro_order_missing',
				'Paid Memberships Pro order could not be found.'
			);
		}

		if ( 'bachs' !== (string) $order->gateway ) {
			return $this->requires_review(
				$intent_id,
				$event_id,
				'pmpro_gateway_mismatch',
				'Paid Memberships Pro order belongs to a different payment gateway.'
			);
		}

		$transaction_id = (string) $order->payment_transaction_id;

		if ( '' !== $transaction_id && ! hash_equals( $transaction_id, $payment->charge_id() ) ) {
			return $this->requires_review(
				$intent_id,
				$event_id,
				'pmpro_transaction_conflict',
				'Paid Memberships Pro order already contains a different transaction ID.'
			);
		}

		$fulfilled = (string) get_pmpro_membership_order_meta( (int) $order->id, self::FULFILLED_META, true );

		if ( '1' === $fulfilled ) {
			if ( ! hash_equals( (string) $order->payment_transaction_id, $payment->charge_id() ) ) {
				return $this->requires_review(
					$intent_id,
					$event_id,
					'pmpro_fulfillment_conflict',
					'Paid Memberships Pro fulfillment marker does not match the verified Bachs transaction.'
				);
			}

			return $this->finalize_duplicate( $intent_id, $event_id );
		}

		if ( 'success' === (string) $order->status ) {
			return $this->requires_review(
				$intent_id,
				$event_id,
				'pmpro_success_without_marker',
				'Paid Memberships Pro order is successful without the Bachs fulfillment marker.'
			);
		}

		try {
			$order->payment_transaction_id = $payment->charge_id();
			pmpro_pull_checkout_data_from_order( $order );

			if ( ! pmpro_complete_async_checkout( $order ) ) {
				return $this->requires_review(
					$intent_id,
					$event_id,
					'pmpro_completion_failed',
					'Bachs payment succeeded but Paid Memberships Pro could not complete membership checkout.'
				);
			}

			if ( false === update_pmpro_membership_order_meta( (int) $order->id, self::FULFILLED_META, '1' ) ) {
				return $this->requires_review(
					$intent_id,
					$event_id,
					'pmpro_fulfillment_marker_failed',
					'Paid Memberships Pro completed checkout but the Bachs fulfillment marker could not be stored.'
				);
			}
		} catch ( Throwable ) {
			return $this->requires_review(
				$intent_id,
				$event_id,
				'pmpro_completion_exception',
				'Bachs payment succeeded but Paid Memberships Pro fulfillment ended unexpectedly.'
			);
		}

		if ( ! $this->intents->mark_applied( $intent_id ) ) {
			$this->events->mark_failed( $event_id, 'pmpro_local_finalize_failed', 'Paid Memberships Pro payment succeeded but local intent finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		if ( ! $this->events->mark_processed( $event_id ) ) {
			$this->events->mark_failed( $event_id, 'pmpro_event_finalize_failed', 'Paid Memberships Pro payment applied but event finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		return FulfillmentDisposition::APPLIED;
	}

	/**
	 * Put the payment into review in both persistence stores.
	 *
	 * @param int    $intent_id Intent database row identifier.
	 * @param int    $event_id  Event inbox row identifier.
	 * @param string $code      Stable internal review code.
	 * @param string $message   Redacted review message.
	 * @return FulfillmentDisposition
	 */
	private function requires_review(
		int $intent_id,
		int $event_id,
		string $code,
		string $message
	): FulfillmentDisposition {
		$this->intents->mark_requires_review( $intent_id, $code, $message );
		$this->events->mark_requires_review( $event_id, $code, $message );

		return FulfillmentDisposition::REQUIRES_REVIEW;
	}

	/**
	 * Repair plugin-local state for an already-completed matching PMPro order.
	 *
	 * @param int $intent_id Intent database row identifier.
	 * @param int $event_id  Event inbox row identifier.
	 * @return FulfillmentDisposition
	 */
	private function finalize_duplicate( int $intent_id, int $event_id ): FulfillmentDisposition {
		if ( ! $this->intents->mark_applied( $intent_id ) ) {
			$this->events->mark_failed( $event_id, 'pmpro_local_repair_failed', 'Paid Memberships Pro order is fulfilled but local intent repair failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		if ( ! $this->events->mark_processed( $event_id ) ) {
			$this->events->mark_failed( $event_id, 'pmpro_event_repair_finalize_failed', 'Paid Memberships Pro payment repair succeeded but event finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		return FulfillmentDisposition::DUPLICATE;
	}
}
