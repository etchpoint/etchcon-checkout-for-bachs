<?php
/**
 * Fluent Forms verified-payment fulfillment.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\FluentForms;

use Etchpoint\BachsIntegrations\Core\Contracts\PaymentFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Payment\VerifiedPayment;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Throwable;

/**
 * Applies verified Bachs payments through Fluent Forms Pro payment APIs.
 */
final class FluentFormsFulfillmentHandler implements PaymentFulfillmentHandler {
	/** Fluent Forms integration identifier. */
	private const INTEGRATION = 'fluent_forms';

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
	 * Create the fulfillment handler.
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
	 * Apply a verified payment to its Fluent Forms submission.
	 *
	 * @param int             $intent_id Intent database row identifier.
	 * @param int             $event_id  Event inbox row identifier.
	 * @param VerifiedPayment $payment   Verified provider payment evidence.
	 * @return FulfillmentDisposition
	 */
	public function fulfill( int $intent_id, int $event_id, VerifiedPayment $payment ): FulfillmentDisposition {
		if ( self::INTEGRATION !== $payment->integration() || 'submission' !== $payment->local_object_type() ) {
			return $this->requires_review( $intent_id, $event_id, 'fluent_forms_target_mismatch', 'Verified payment does not target a Fluent Forms submission.' );
		}

		$record = $this->intents->find_by_id( $intent_id );

		if ( null === $record || ! hash_equals( $record->intent()->uuid(), $payment->intent_uuid() ) ) {
			return $this->requires_review( $intent_id, $event_id, 'fluent_forms_intent_mismatch', 'Fluent Forms fulfillment could not match the verified payment intent.' );
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

			$this->events->mark_failed( $event_id, 'fluent_forms_intent_claim_busy', 'Fluent Forms fulfillment could not claim the payment intent.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		try {
			$processor = new FluentFormsProcessor();
			$applied   = $processor->fulfill_verified_payment(
				(int) $payment->local_object_id(),
				$payment->charge_id(),
				$payment->amount()
			);
		} catch ( Throwable ) {
			return $this->requires_review( $intent_id, $event_id, 'fluent_forms_completion_exception', 'Bachs payment succeeded but Fluent Forms fulfillment ended unexpectedly.' );
		}

		if ( ! $this->intents->mark_applied( $intent_id ) ) {
			$this->events->mark_failed( $event_id, 'fluent_forms_local_finalize_failed', 'Fluent Forms payment succeeded but local intent finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		if ( ! $this->events->mark_processed( $event_id ) ) {
			$this->events->mark_failed( $event_id, 'fluent_forms_event_finalize_failed', 'Fluent Forms payment applied but event finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		return $applied ? FulfillmentDisposition::APPLIED : FulfillmentDisposition::DUPLICATE;
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
}
