<?php
/**
 * GiveWP verified-payment fulfillment.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\GiveWP;

use Etchpoint\BachsIntegrations\Core\Contracts\PaymentFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Payment\VerifiedPayment;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Throwable;

/**
 * Applies verified Bachs payments through GiveWP's donation model APIs.
 */
final class GiveWPFulfillmentHandler implements PaymentFulfillmentHandler {
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- GiveWP model properties are camelCase.
	/** GiveWP integration identifier. */
	private const INTEGRATION = 'givewp';

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
	 * Apply a verified payment to its GiveWP donation.
	 *
	 * @param int             $intent_id Intent database row identifier.
	 * @param int             $event_id  Event inbox row identifier.
	 * @param VerifiedPayment $payment   Verified provider payment evidence.
	 * @return FulfillmentDisposition
	 */
	public function fulfill( int $intent_id, int $event_id, VerifiedPayment $payment ): FulfillmentDisposition {
		if ( self::INTEGRATION !== $payment->integration() || 'donation' !== $payment->local_object_type() ) {
			return $this->requires_review( $intent_id, $event_id, 'givewp_target_mismatch', 'Verified payment does not target a GiveWP donation.' );
		}

		$record = $this->intents->find_by_id( $intent_id );

		if ( null === $record || ! hash_equals( $record->intent()->uuid(), $payment->intent_uuid() ) ) {
			return $this->requires_review( $intent_id, $event_id, 'givewp_intent_mismatch', 'GiveWP fulfillment could not match the verified payment intent.' );
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

			$this->events->mark_failed( $event_id, 'givewp_intent_claim_busy', 'GiveWP fulfillment could not claim the payment intent.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		$donation_id = (int) $payment->local_object_id();

		if ( 1 > $donation_id ) {
			return $this->requires_review( $intent_id, $event_id, 'givewp_donation_missing', 'GiveWP donation identifier is invalid.' );
		}

		try {
			$donation = Donation::find( $donation_id );

			if ( ! $donation instanceof Donation ) {
				return $this->requires_review( $intent_id, $event_id, 'givewp_donation_missing', 'GiveWP donation could not be found.' );
			}

			if ( GiveWPGateway::id() !== $donation->gatewayId ) {
				return $this->requires_review( $intent_id, $event_id, 'givewp_gateway_mismatch', 'GiveWP donation belongs to a different payment gateway.' );
			}

			$amount_data = $donation->amount->toArray();
			$total       = $amount_data['value'] ?? null;
			$currency    = $amount_data['currency'] ?? null;

			if ( ! is_string( $total ) || ! is_string( $currency ) ) {
				return $this->requires_review( $intent_id, $event_id, 'givewp_amount_missing', 'GiveWP donation amount is incomplete.' );
			}

			$donation_money = Money::from_decimal( $total, Currency::from_code( $currency ) );

			if ( ! $donation_money->equals( $payment->amount() ) ) {
				return $this->requires_review( $intent_id, $event_id, 'givewp_amount_mismatch', 'Verified Bachs amount does not match the GiveWP donation.' );
			}

			$existing_charge = $donation->gatewayTransactionId;

			if ( '' !== $existing_charge && ! hash_equals( $existing_charge, $payment->charge_id() ) ) {
				return $this->requires_review( $intent_id, $event_id, 'givewp_transaction_conflict', 'GiveWP donation already contains a different transaction identifier.' );
			}

			if ( $donation->status->isComplete() ) {
				if ( '' === $existing_charge || ! hash_equals( $existing_charge, $payment->charge_id() ) ) {
					return $this->requires_review( $intent_id, $event_id, 'givewp_complete_without_marker', 'GiveWP donation is complete without the matching Bachs transaction.' );
				}

				return $this->finalize_duplicate( $intent_id, $event_id );
			}

			$donation->gatewayTransactionId = $payment->charge_id();
			$donation->status               = DonationStatus::COMPLETE();
			$donation->save();

			$confirmed = Donation::find( $donation_id );

			if (
				! $confirmed instanceof Donation
				|| ! $confirmed->status->isComplete()
				|| ! hash_equals( $confirmed->gatewayTransactionId, $payment->charge_id() )
			) {
				return $this->requires_review( $intent_id, $event_id, 'givewp_completion_failed', 'Bachs payment succeeded but GiveWP did not confirm donation completion.' );
			}

			DonationNote::create(
				array(
					'donationId' => $donation_id,
					'content'    => __( 'Payment verified through the signed Bachs webhook.', 'etchcon-checkout-for-bachs' ),
				)
			);
		} catch ( Throwable ) {
			return $this->requires_review( $intent_id, $event_id, 'givewp_completion_exception', 'Bachs payment succeeded but GiveWP fulfillment ended unexpectedly.' );
		}

		if ( ! $this->intents->mark_applied( $intent_id ) ) {
			$this->events->mark_failed( $event_id, 'givewp_local_finalize_failed', 'GiveWP donation succeeded but local intent finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		if ( ! $this->events->mark_processed( $event_id ) ) {
			$this->events->mark_failed( $event_id, 'givewp_event_finalize_failed', 'GiveWP payment applied but event finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		return FulfillmentDisposition::APPLIED;
	}

	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

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
	 * Repair plugin-local state for an already-completed matching donation.
	 *
	 * @param int $intent_id Intent database row identifier.
	 * @param int $event_id  Event inbox row identifier.
	 * @return FulfillmentDisposition
	 */
	private function finalize_duplicate( int $intent_id, int $event_id ): FulfillmentDisposition {
		if ( ! $this->intents->mark_applied( $intent_id ) ) {
			$this->events->mark_failed( $event_id, 'givewp_local_repair_failed', 'GiveWP donation is complete but local intent repair failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		if ( ! $this->events->mark_processed( $event_id ) ) {
			$this->events->mark_failed( $event_id, 'givewp_event_repair_finalize_failed', 'GiveWP payment repair succeeded but event finalization failed.' );

			return FulfillmentDisposition::RETRYABLE_FAILURE;
		}

		return FulfillmentDisposition::DUPLICATE;
	}
}
