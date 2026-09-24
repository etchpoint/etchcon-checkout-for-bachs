<?php
/**
 * Bachs/local reconciliation service.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Reconciliation;

use Etchpoint\BachsIntegrations\Bachs\ApiException;
use Etchpoint\BachsIntegrations\Bachs\CheckoutProvider;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Bachs\PaymentRetriever;
use Etchpoint\BachsIntegrations\Bachs\ProviderPayment;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentRegistry;
use Etchpoint\BachsIntegrations\Core\Payment\ProviderStatus;
use Etchpoint\BachsIntegrations\Core\Payment\VerifiedPayment;
use Etchpoint\BachsIntegrations\Persistence\EventStore;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use InvalidArgumentException;

/**
 * Re-verifies Bachs evidence and safely replays native host fulfillment.
 */
final class ReconciliationService {
	/** Successful final provider states accepted by the payment core. */
	private const SUCCESSFUL_PROVIDER_STATUSES = array( 'succeeded', 'accepted', 'overpaid' );

	/** Local event type used only for reconciliation audit/deduplication. */
	private const EVENT_TYPE = 'reconciliation.payment_succeeded';

	/**
	 * Intent store.
	 *
	 * @var ReconciliationIntentStore
	 */
	private ReconciliationIntentStore $intents;

	/**
	 * Event store.
	 *
	 * @var EventStore
	 */
	private EventStore $events;

	/**
	 * Hosted checkout provider.
	 *
	 * @var CheckoutProvider
	 */
	private CheckoutProvider $checkouts;

	/**
	 * Authoritative payment retriever.
	 *
	 * @var PaymentRetriever
	 */
	private PaymentRetriever $payments;

	/**
	 * Host fulfillment registry.
	 *
	 * @var FulfillmentRegistry
	 */
	private FulfillmentRegistry $fulfillment;

	/**
	 * Active Bachs environment.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Create the reconciliation service.
	 *
	 * @param ReconciliationIntentStore $intents     Intent persistence.
	 * @param EventStore                $events      Event/audit persistence.
	 * @param CheckoutProvider          $checkouts   Checkout retrieval API.
	 * @param PaymentRetriever          $payments    Payment retrieval API.
	 * @param FulfillmentRegistry       $fulfillment Host fulfillment handlers.
	 * @param Environment               $environment Active Bachs environment.
	 */
	public function __construct(
		ReconciliationIntentStore $intents,
		EventStore $events,
		CheckoutProvider $checkouts,
		PaymentRetriever $payments,
		FulfillmentRegistry $fulfillment,
		Environment $environment
	) {
		$this->intents     = $intents;
		$this->events      = $events;
		$this->checkouts   = $checkouts;
		$this->payments    = $payments;
		$this->fulfillment = $fulfillment;
		$this->environment = $environment;
	}

	/**
	 * Reconcile one local intent against authoritative Bachs state.
	 *
	 * @param int $intent_id Intent row identifier.
	 * @return ReconciliationResult
	 */
	public function reconcile( int $intent_id ): ReconciliationResult {
		$record = $this->intents->find_by_id( $intent_id );

		if ( null === $record ) {
			return new ReconciliationResult( ReconciliationDisposition::NOT_FOUND, null, 'intent_not_found' );
		}

		$intent = $record->intent();

		if ( $intent->environment() !== $this->environment->value ) {
			return new ReconciliationResult( ReconciliationDisposition::REQUIRES_REVIEW, $intent_id, 'environment_mismatch' );
		}

		if ( ApplicationStatus::APPLIED === $intent->application_status() ) {
			return new ReconciliationResult( ReconciliationDisposition::ALREADY_APPLIED, $intent_id, 'already_applied' );
		}

		$checkout_id = $record->checkout_id();

		if ( null === $checkout_id ) {
			return new ReconciliationResult( ReconciliationDisposition::REQUIRES_REVIEW, $intent_id, 'checkout_missing' );
		}

		try {
			$checkout = $this->checkouts->get( $checkout_id );
		} catch ( ApiException ) {
			return new ReconciliationResult( ReconciliationDisposition::RETRYABLE_FAILURE, $intent_id, 'checkout_retrieval_failed' );
		}

		if ( ! hash_equals( $checkout_id, $checkout->checkout_id() ) ) {
			return new ReconciliationResult( ReconciliationDisposition::REQUIRES_REVIEW, $intent_id, 'checkout_id_mismatch' );
		}

		if ( null !== $checkout->reference() && ! hash_equals( $intent->reference(), $checkout->reference() ) ) {
			return new ReconciliationResult( ReconciliationDisposition::REQUIRES_REVIEW, $intent_id, 'checkout_reference_mismatch' );
		}

		if ( ! self::checkout_amount_matches( $record, $checkout->amount(), $checkout->currency() ) ) {
			return new ReconciliationResult( ReconciliationDisposition::REQUIRES_REVIEW, $intent_id, 'checkout_amount_mismatch' );
		}

		if (
			null === $checkout->payment_status()
			|| ! in_array( $checkout->payment_status(), self::SUCCESSFUL_PROVIDER_STATUSES, true )
			|| null === $checkout->payment_id()
		) {
			return new ReconciliationResult( ReconciliationDisposition::NO_ACTION, $intent_id, 'provider_not_succeeded' );
		}

		$checkout_payment_id = $checkout->payment_id();
		$payment_id          = $record->charge_id();

		if ( null === $payment_id ) {
			$payment_id = $checkout_payment_id;
		} elseif ( ! hash_equals( $payment_id, $checkout_payment_id ) ) {
			return new ReconciliationResult( ReconciliationDisposition::REQUIRES_REVIEW, $intent_id, 'checkout_payment_mismatch' );
		}

		try {
			$payment = $this->payments->get( $payment_id );
		} catch ( ApiException ) {
			return new ReconciliationResult( ReconciliationDisposition::RETRYABLE_FAILURE, $intent_id, 'payment_retrieval_failed' );
		}

		$verification = self::verify_provider_payment( $payment, $record, $payment_id, $checkout_id );
		$paid_amount  = $verification['money'];

		if ( null === $paid_amount ) {
			return new ReconciliationResult(
				ReconciliationDisposition::REQUIRES_REVIEW,
				$intent_id,
				$verification['code'] ?? 'provider_evidence_mismatch'
			);
		}

		if ( ! $this->intents->attach_successful_charge( $intent_id, $payment_id ) ) {
			$refreshed = $this->intents->find_by_id( $intent_id );

			if ( null === $refreshed || $refreshed->charge_id() !== $payment_id ) {
				return new ReconciliationResult( ReconciliationDisposition::REQUIRES_REVIEW, $intent_id, 'charge_assignment_conflict' );
			}
		}

		if ( ! $this->intents->update_provider_status( $intent_id, ProviderStatus::SUCCEEDED ) ) {
			return new ReconciliationResult( ReconciliationDisposition::RETRYABLE_FAILURE, $intent_id, 'provider_state_persist_failed' );
		}

		if ( ApplicationStatus::REQUIRES_REVIEW === $intent->application_status() && ! $this->intents->prepare_for_reconciliation( $intent_id ) ) {
			return new ReconciliationResult( ReconciliationDisposition::RETRYABLE_FAILURE, $intent_id, 'review_release_failed' );
		}

		$handler = $this->fulfillment->find( $intent->integration() );

		if ( null === $handler ) {
			return new ReconciliationResult( ReconciliationDisposition::REQUIRES_REVIEW, $intent_id, 'integration_unavailable' );
		}

		$event_id = self::reconciliation_event_id( $record, $payment_id );
		$hash     = hash( 'sha256', $event_id . '|' . $intent->expected_amount()->amount() . '|' . $intent->expected_amount()->currency()->code() );

		$this->events->record_received( $event_id, self::EVENT_TYPE, null, $hash );
		$event = $this->events->find_by_provider_event_id( $event_id );

		if ( null === $event ) {
			return new ReconciliationResult( ReconciliationDisposition::RETRYABLE_FAILURE, $intent_id, 'audit_event_missing' );
		}

		if ( ! $this->events->link_intent( $event->id(), $intent_id ) ) {
			return new ReconciliationResult( ReconciliationDisposition::RETRYABLE_FAILURE, $intent_id, 'audit_event_link_failed' );
		}

		if ( ! $this->events->claim_processing( $event->id() ) ) {
			$refreshed = $this->intents->find_by_id( $intent_id );

			if ( null !== $refreshed && ApplicationStatus::APPLIED === $refreshed->intent()->application_status() ) {
				return new ReconciliationResult( ReconciliationDisposition::ALREADY_APPLIED, $intent_id, 'already_applied' );
			}

			return new ReconciliationResult( ReconciliationDisposition::RETRYABLE_FAILURE, $intent_id, 'audit_event_busy' );
		}

		try {
			$verified = VerifiedPayment::from_verified_evidence(
				$intent,
				$intent->reference(),
				$event_id,
				$checkout_id,
				$payment_id,
				$paid_amount
			);
		} catch ( InvalidArgumentException ) {
			$this->events->mark_requires_review( $event->id(), 'verified_payment_rejected', 'Reconciliation evidence failed immutable intent validation.' );

			return new ReconciliationResult( ReconciliationDisposition::REQUIRES_REVIEW, $intent_id, 'verified_payment_rejected' );
		}

		return self::map_fulfillment_result( $handler->fulfill( $intent_id, $event->id(), $verified ), $intent_id );
	}

	/**
	 * Verify a retrieved Bachs payment against immutable local evidence.
	 *
	 * @param ProviderPayment $payment     Authoritative provider payment.
	 * @param IntentRecord    $record      Local payment intent record.
	 * @param string          $payment_id  Expected payment identifier.
	 * @param string          $checkout_id Expected checkout identifier.
	 * Bachs documents payment checkout/reference correlation fields as nullable.
	 * Reconciliation therefore proves correlation through the authoritative checkout
	 * first, then requires these payment fields to match only when Bachs supplies them.
	 *
	 * @return array{money: Money|null, code: string|null} Verification result.
	 */
	private static function verify_provider_payment(
		ProviderPayment $payment,
		IntentRecord $record,
		string $payment_id,
		string $checkout_id
	): array {
		if ( ! hash_equals( $payment_id, $payment->payment_id() ) ) {
			return array(
				'money' => null,
				'code'  => 'provider_payment_id_mismatch',
			);
		}

		if ( ! in_array( $payment->status(), self::SUCCESSFUL_PROVIDER_STATUSES, true ) ) {
			return array(
				'money' => null,
				'code'  => 'provider_payment_status_mismatch',
			);
		}

		if ( null !== $payment->checkout_id() && ! hash_equals( $checkout_id, $payment->checkout_id() ) ) {
			return array(
				'money' => null,
				'code'  => 'provider_checkout_mismatch',
			);
		}

		if ( null !== $payment->reference() && ! hash_equals( $record->intent()->reference(), $payment->reference() ) ) {
			return array(
				'money' => null,
				'code'  => 'provider_reference_mismatch',
			);
		}

		try {
			$money = Money::from_decimal( $payment->amount(), Currency::from_code( $payment->currency() ) );
		} catch ( InvalidArgumentException ) {
			return array(
				'money' => null,
				'code'  => 'provider_amount_invalid',
			);
		}

		if ( ! $record->intent()->expected_amount()->equals( $money ) ) {
			return array(
				'money' => null,
				'code'  => 'provider_amount_mismatch',
			);
		}

		return array(
			'money' => $money,
			'code'  => null,
		);
	}

	/**
	 * Validate checkout amount evidence when Bachs supplied it.
	 *
	 * @param IntentRecord $record   Local intent record.
	 * @param string|null  $amount   Checkout amount.
	 * @param string|null  $currency Checkout currency.
	 * @return bool
	 */
	private static function checkout_amount_matches( IntentRecord $record, ?string $amount, ?string $currency ): bool {
		if ( null === $amount && null === $currency ) {
			return true;
		}

		if ( null === $amount || null === $currency ) {
			return false;
		}

		try {
			$money = Money::from_decimal( $amount, Currency::from_code( $currency ) );
		} catch ( InvalidArgumentException ) {
			return false;
		}

		return $record->intent()->expected_amount()->equals( $money );
	}

	/**
	 * Build a deterministic local reconciliation event identifier.
	 *
	 * @param IntentRecord $record     Local intent record.
	 * @param string       $payment_id Provider payment identifier.
	 * @return string
	 */
	private static function reconciliation_event_id( IntentRecord $record, string $payment_id ): string {
		return 'reconcile_' . hash( 'sha256', $record->intent()->uuid() . '|' . $payment_id );
	}

	/**
	 * Map host fulfillment state to reconciliation state.
	 *
	 * @param FulfillmentDisposition $disposition Host fulfillment result.
	 * @param int                    $intent_id   Intent row identifier.
	 * @return ReconciliationResult
	 */
	private static function map_fulfillment_result(
		FulfillmentDisposition $disposition,
		int $intent_id
	): ReconciliationResult {
		return match ( $disposition ) {
			FulfillmentDisposition::APPLIED           => new ReconciliationResult( ReconciliationDisposition::RECOVERED, $intent_id, 'recovered' ),
			FulfillmentDisposition::DUPLICATE         => new ReconciliationResult( ReconciliationDisposition::ALREADY_APPLIED, $intent_id, 'already_applied' ),
			FulfillmentDisposition::RETRYABLE_FAILURE => new ReconciliationResult( ReconciliationDisposition::RETRYABLE_FAILURE, $intent_id, 'fulfillment_retryable' ),
			FulfillmentDisposition::REQUIRES_REVIEW   => new ReconciliationResult( ReconciliationDisposition::REQUIRES_REVIEW, $intent_id, 'fulfillment_requires_review' ),
		};
	}
}
