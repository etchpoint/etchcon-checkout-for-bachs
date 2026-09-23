<?php
/**
 * Gravity Forms verified-payment fulfillment.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\GravityForms;

use Etchpoint\BachsIntegrations\Core\Contracts\PaymentFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Payment\VerifiedPayment;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use GFAPI;
use Throwable;
use WP_Error;

/**
 * Applies verified Bachs payments through Gravity Forms' payment framework.
 */
final class GravityFormsFulfillmentHandler implements PaymentFulfillmentHandler {
	/** Gravity Forms integration identifier. */
	private const INTEGRATION = 'gravity_forms';

	/** Successful fulfillment marker stored in Gravity Forms entry meta. */
	private const FULFILLED_META = '_etchpoint_bachs_fulfilled';

	/**
	 * Payment intent repository.
	 *
	 * @var IntentRepository
	 */
	private IntentRepository $intents;

	/**
	 * Event inbox repository.
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
	 * Apply a verified payment to its Gravity Forms entry.
	 *
	 * @param int             $intent_id Intent database row identifier.
	 * @param int             $event_id  Event inbox row identifier.
	 * @param VerifiedPayment $payment   Verified provider payment evidence.
	 * @return FulfillmentDisposition
	 */
	public function fulfill( int $intent_id, int $event_id, VerifiedPayment $payment ): FulfillmentDisposition {
		if ( self::INTEGRATION !== $payment->integration() || 'entry' !== $payment->local_object_type() ) {
			return $this->requires_review( $intent_id, $event_id, 'gravity_forms_target_mismatch', 'Verified payment does not target a Gravity Forms entry.' );
		}

		$record = $this->intents->find_by_id( $intent_id );

		if ( null === $record || ! hash_equals( $record->intent()->uuid(), $payment->intent_uuid() ) ) {
			return $this->requires_review( $intent_id, $event_id, 'gravity_forms_intent_mismatch', 'Gravity Forms fulfillment could not match the verified payment intent.' );
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

			$this->events->mark_failed( $event_id, 'gravity_forms_intent_claim_busy', 'Gravity Forms fulfillment could not claim the payment intent.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		$entry_id = (int) $payment->local_object_id();
		$entry    = GFAPI::get_entry( $entry_id );

		if ( $entry instanceof WP_Error || ! is_array( $entry ) || (int) ( $entry['id'] ?? 0 ) !== $entry_id ) {
			return $this->requires_review( $intent_id, $event_id, 'gravity_forms_entry_missing', 'Gravity Forms entry could not be found.' );
		}

		$gateway = $entry['payment_gateway'] ?? null;

		if ( null !== $gateway && '' !== $gateway && 'gravityformsbachs' !== $gateway ) {
			return $this->requires_review( $intent_id, $event_id, 'gravity_forms_gateway_mismatch', 'Gravity Forms entry belongs to a different payment gateway.' );
		}

		$transaction_id = $entry['transaction_id'] ?? null;

		if ( is_string( $transaction_id ) && '' !== $transaction_id && ! hash_equals( $transaction_id, $payment->charge_id() ) ) {
			return $this->requires_review( $intent_id, $event_id, 'gravity_forms_transaction_conflict', 'Gravity Forms entry already contains a different transaction ID.' );
		}

		$fulfilled = gform_get_meta( $entry_id, self::FULFILLED_META );

		if ( '1' === $fulfilled ) {
			if ( ! is_string( $transaction_id ) || ! hash_equals( $transaction_id, $payment->charge_id() ) ) {
				return $this->requires_review( $intent_id, $event_id, 'gravity_forms_fulfillment_conflict', 'Gravity Forms fulfillment marker does not match the verified Bachs transaction.' );
			}

			return $this->finalize_duplicate( $intent_id, $event_id );
		}

		if ( 'Paid' === ( $entry['payment_status'] ?? null ) ) {
			return $this->requires_review( $intent_id, $event_id, 'gravity_forms_paid_without_marker', 'Gravity Forms entry is paid without the Bachs fulfillment marker.' );
		}

		try {
			GravityFormsAddOn::get_instance()->complete_verified_payment(
				$entry,
				$payment->charge_id(),
				$payment->amount()->amount()
			);

			$refreshed = GFAPI::get_entry( $entry_id );

			if (
				$refreshed instanceof WP_Error
				|| ! is_array( $refreshed )
				|| 'Paid' !== ( $refreshed['payment_status'] ?? null )
				|| $payment->charge_id() !== ( $refreshed['transaction_id'] ?? null )
			) {
				return $this->requires_review( $intent_id, $event_id, 'gravity_forms_completion_failed', 'Bachs payment succeeded but Gravity Forms did not confirm payment completion.' );
			}

			gform_update_meta( $entry_id, self::FULFILLED_META, '1', (int) ( $refreshed['form_id'] ?? 0 ) );
		} catch ( Throwable ) {
			return $this->requires_review( $intent_id, $event_id, 'gravity_forms_completion_exception', 'Bachs payment succeeded but Gravity Forms fulfillment ended unexpectedly.' );
		}

		if ( ! $this->intents->mark_applied( $intent_id ) ) {
			$this->events->mark_failed( $event_id, 'gravity_forms_local_finalize_failed', 'Gravity Forms payment succeeded but local intent finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		if ( ! $this->events->mark_processed( $event_id ) ) {
			$this->events->mark_failed( $event_id, 'gravity_forms_event_finalize_failed', 'Gravity Forms payment applied but event finalization failed.' );

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
	 * Repair plugin-local state for an already-completed matching Gravity entry.
	 *
	 * @param int $intent_id Intent database row identifier.
	 * @param int $event_id  Event inbox row identifier.
	 * @return FulfillmentDisposition
	 */
	private function finalize_duplicate( int $intent_id, int $event_id ): FulfillmentDisposition {
		if ( ! $this->intents->mark_applied( $intent_id ) ) {
			$this->events->mark_failed( $event_id, 'gravity_forms_local_repair_failed', 'Gravity Forms entry is fulfilled but local intent repair failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		if ( ! $this->events->mark_processed( $event_id ) ) {
			$this->events->mark_failed( $event_id, 'gravity_forms_event_repair_finalize_failed', 'Gravity Forms payment repair succeeded but event finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		return FulfillmentDisposition::DUPLICATE;
	}
}
