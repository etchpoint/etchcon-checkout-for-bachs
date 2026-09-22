<?php
/**
 * Bachs webhook processor.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use Etchpoint\BachsIntegrations\Bachs\ApiException;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Bachs\PaymentRetriever;
use Etchpoint\BachsIntegrations\Bachs\ProviderPayment;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\ProviderStatus;
use Etchpoint\BachsIntegrations\Core\Payment\VerifiedPayment;
use Etchpoint\BachsIntegrations\Persistence\EventProcessingStatus;
use Etchpoint\BachsIntegrations\Persistence\EventRecord;
use Etchpoint\BachsIntegrations\Persistence\EventStore;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use Etchpoint\BachsIntegrations\Persistence\IntentStore;
use InvalidArgumentException;

/**
 * Turns a signature-verified Bachs event into safe local state or payment evidence.
 */
final class WebhookProcessor {
	/** Successful collection event. */
	private const EVENT_COLLECTION_SUCCEEDED = 'collection.succeeded';

	/** Failed collection event. */
	private const EVENT_COLLECTION_FAILED = 'collection.failed';

	/** Underpaid collection event. */
	private const EVENT_COLLECTION_UNDERPAID = 'collection.underpaid';

	/** Expired checkout event. */
	private const EVENT_CHECKOUT_EXPIRED = 'checkout.expired';

	/**
	 * Provider statuses that Bachs documents as successful final collection.
	 *
	 * @var array<int, string>
	 */
	private const SUCCESSFUL_PROVIDER_STATUSES = array( 'succeeded', 'accepted', 'overpaid' );

	/**
	 * Event store.
	 *
	 * @var EventStore
	 */
	private EventStore $events;

	/**
	 * Intent store.
	 *
	 * @var IntentStore
	 */
	private IntentStore $intents;

	/**
	 * Authoritative provider payment retriever.
	 *
	 * @var PaymentRetriever
	 */
	private PaymentRetriever $payments;

	/**
	 * Webhook event parser.
	 *
	 * @var WebhookEventParser
	 */
	private WebhookEventParser $parser;

	/**
	 * Bachs environment handled by this processor.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Expected Bachs organization when account pinning is configured.
	 *
	 * @var string|null
	 */
	private ?string $organization_id;

	/**
	 * Create the webhook processor.
	 *
	 * @param EventStore         $events          Deduplicated event inbox.
	 * @param IntentStore        $intents         Payment-intent store.
	 * @param PaymentRetriever   $payments        Authoritative payment retriever.
	 * @param WebhookEventParser $parser          Verified-body event parser.
	 * @param Environment        $environment     Bachs environment for this endpoint.
	 * @param string|null        $organization_id Optional pinned Bachs organization ID.
	 */
	public function __construct(
		EventStore $events,
		IntentStore $intents,
		PaymentRetriever $payments,
		WebhookEventParser $parser,
		Environment $environment,
		?string $organization_id = null
	) {
		$this->events          = $events;
		$this->intents         = $intents;
		$this->payments        = $payments;
		$this->parser          = $parser;
		$this->environment     = $environment;
		$this->organization_id = $organization_id;
	}

	/**
	 * Process one raw webhook body after V2 signature verification succeeded.
	 *
	 * A successful collection remains in event processing state when this method
	 * returns READY_FOR_FULFILLMENT. The host adapter in the next stage owns the
	 * final application claim and only then marks the event processed.
	 *
	 * @param VerifiedWebhookSignature $signature Verified signature evidence for this raw body.
	 * @param string                   $raw_body  Exact untouched request body.
	 * @return WebhookProcessingResult
	 *
	 * @throws WebhookProcessingException When signature evidence and body disagree or the envelope is malformed.
	 */
	public function process( VerifiedWebhookSignature $signature, string $raw_body ): WebhookProcessingResult {
		$payload_hash = hash( 'sha256', $raw_body );

		if ( ! hash_equals( $signature->payload_hash(), $payload_hash ) ) {
			// Internal exception metadata, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw self::processing_exception(
				WebhookProcessingException::CODE_PAYLOAD_HASH_MISMATCH,
				'Verified webhook signature does not belong to this request body.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$event = $this->parser->parse( $raw_body );

		if ( null !== $this->organization_id && ! hash_equals( $this->organization_id, $event->organization_id() ) ) {
			// Internal exception metadata, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw self::processing_exception(
				WebhookProcessingException::CODE_ORGANIZATION_MISMATCH,
				'Webhook organization does not match the configured Bachs organization.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( null !== $event->account() ) {
			// Internal exception metadata, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw self::processing_exception(
				WebhookProcessingException::CODE_UNSUPPORTED_CONNECT_EVENT,
				'Connected-account webhook events are outside this plugin release scope.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$record = $this->record_and_claim( $event, $payload_hash );

		if ( $record instanceof WebhookProcessingResult ) {
			return $record;
		}

		if ( ! self::is_supported_event( $event->type() ) ) {
			if ( ! $this->events->mark_processed( $record->id() ) ) {
				return $this->retryable_failure( $record, null, 'event_finalize_failed' );
			}

			return new WebhookProcessingResult( WebhookProcessingDisposition::IGNORED, $record->id() );
		}

		$intent = $this->locate_intent( $event );

		if ( null === $intent ) {
			return $this->requires_review(
				$record,
				null,
				'unknown_intent',
				'Webhook could not be matched to a known local payment intent.'
			);
		}

		if ( ! $this->events->link_intent( $record->id(), $intent->id() ) ) {
			return $this->retryable_failure( $record, $intent, 'event_intent_link_failed' );
		}

		if ( $intent->intent()->environment() !== $this->environment->value ) {
			return $this->requires_review(
				$record,
				$intent,
				'environment_mismatch',
				'Webhook endpoint environment does not match the local payment intent.'
			);
		}

		return match ( $event->type() ) {
			self::EVENT_COLLECTION_SUCCEEDED => $this->process_success( $record, $intent, $event ),
			self::EVENT_COLLECTION_FAILED    => $this->process_failed( $record, $intent, $event ),
			self::EVENT_COLLECTION_UNDERPAID => $this->process_underpaid( $record, $intent, $event ),
			self::EVENT_CHECKOUT_EXPIRED     => $this->process_expired( $record, $intent, $event ),
			default                          => new WebhookProcessingResult(
				WebhookProcessingDisposition::IGNORED,
				$record->id(),
				$intent->id()
			),
		};
	}

	/**
	 * Insert or recover a deduplicated event and atomically claim it.
	 *
	 * @param WebhookEvent $event        Parsed event.
	 * @param string       $payload_hash SHA-256 payload hash.
	 * @return EventRecord|WebhookProcessingResult
	 *
	 * @throws WebhookProcessingException When persistence becomes inconsistent.
	 */
	private function record_and_claim( WebhookEvent $event, string $payload_hash ): EventRecord|WebhookProcessingResult {
		$this->events->record_received(
			$event->id(),
			$event->type(),
			$event->organization_id(),
			$payload_hash
		);

		$record = $this->events->find_by_provider_event_id( $event->id() );

		if ( null === $record ) {
			// Internal exception metadata, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw self::processing_exception(
				WebhookProcessingException::CODE_EVENT_STORE_INCONSISTENT,
				'Webhook event could not be loaded after deduplication.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( ! hash_equals( $record->payload_hash(), $payload_hash ) || $record->event_type() !== $event->type() ) {
			if ( $this->events->claim_processing( $record->id() ) ) {
				if ( ! $this->events->mark_requires_review(
					$record->id(),
					'duplicate_payload_mismatch',
					'A repeated provider event ID arrived with different verified contents.'
				) ) {
					return $this->retryable_failure( $record, null, 'duplicate_review_persist_failed' );
				}
			}

			return new WebhookProcessingResult( WebhookProcessingDisposition::REQUIRES_REVIEW, $record->id() );
		}

		if ( EventProcessingStatus::PROCESSED === $record->processing_status() ) {
			return new WebhookProcessingResult(
				WebhookProcessingDisposition::DUPLICATE,
				$record->id(),
				$record->intent_id()
			);
		}

		if ( EventProcessingStatus::REQUIRES_REVIEW === $record->processing_status() ) {
			return new WebhookProcessingResult(
				WebhookProcessingDisposition::REQUIRES_REVIEW,
				$record->id(),
				$record->intent_id()
			);
		}

		if ( ! $this->events->claim_processing( $record->id() ) ) {
			return new WebhookProcessingResult(
				WebhookProcessingDisposition::IN_PROGRESS,
				$record->id(),
				$record->intent_id()
			);
		}

		$claimed = $this->events->find_by_provider_event_id( $event->id() );

		if ( null === $claimed ) {
			// Internal exception metadata, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw self::processing_exception(
				WebhookProcessingException::CODE_EVENT_STORE_INCONSISTENT,
				'Claimed webhook event could not be reloaded.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $claimed;
	}

	/**
	 * Locate a local intent using checkout ID first and reference only as fallback.
	 *
	 * @param WebhookEvent $event Parsed provider event.
	 * @return IntentRecord|null
	 */
	private function locate_intent( WebhookEvent $event ): ?IntentRecord {
		$checkout_id = self::optional_data_string( $event, 'checkout_id' );
		$reference   = self::optional_data_string( $event, 'reference' );

		if ( null !== $checkout_id ) {
			$intent = $this->intents->find_by_checkout_id( $checkout_id );

			if ( null !== $intent ) {
				return $intent;
			}
		}

		if ( null !== $reference ) {
			return $this->intents->find_by_reference( $reference );
		}

		return null;
	}

	/**
	 * Validate a successful collection against authoritative provider state.
	 *
	 * @param EventRecord  $record Local event record.
	 * @param IntentRecord $intent Matched local intent.
	 * @param WebhookEvent $event  Parsed provider event.
	 * @return WebhookProcessingResult
	 */
	private function process_success(
		EventRecord $record,
		IntentRecord $intent,
		WebhookEvent $event
	): WebhookProcessingResult {
		$charge_id   = self::optional_data_string( $event, 'charge_id' );
		$checkout_id = self::optional_data_string( $event, 'checkout_id' );
		$reference   = self::optional_data_string( $event, 'reference' );
		$status      = self::optional_data_string( $event, 'status' );

		if ( null === $charge_id || null === $checkout_id || null === $reference || null === $status ) {
			return $this->requires_review(
				$record,
				$intent,
				'incomplete_success_evidence',
				'Success event is missing identifiers required for authoritative verification.'
			);
		}

		if ( ! in_array( $status, self::SUCCESSFUL_PROVIDER_STATUSES, true ) ) {
			return $this->requires_review(
				$record,
				$intent,
				'unexpected_success_status',
				'Success event contains a provider state that is not final successful collection.'
			);
		}

		if ( $intent->checkout_id() !== $checkout_id || ! hash_equals( $intent->intent()->reference(), $reference ) ) {
			return $this->requires_review(
				$record,
				$intent,
				'event_intent_mismatch',
				'Success event identifiers do not match the local payment intent.'
			);
		}

		$event_money = self::event_money( $event, 'amount', 'currency' );

		if ( null === $event_money || ! $intent->intent()->expected_amount()->equals( $event_money ) ) {
			return $this->requires_review(
				$record,
				$intent,
				'event_amount_mismatch',
				'Success event amount or currency does not match the local payment intent.'
			);
		}

		try {
			$payment = $this->payments->get( $charge_id );
		} catch ( ApiException $exception ) {
			$this->events->mark_failed(
				$record->id(),
				$exception->error_code(),
				'Authoritative Bachs payment retrieval failed.'
			);

			return new WebhookProcessingResult(
				WebhookProcessingDisposition::RETRYABLE_FAILURE,
				$record->id(),
				$intent->id()
			);
		}

		$verified_money = $this->verify_provider_payment( $payment, $intent, $charge_id, $checkout_id, $reference );

		if ( null === $verified_money ) {
			return $this->requires_review(
				$record,
				$intent,
				'provider_evidence_mismatch',
				'Authoritative Bachs payment state does not match the local payment intent.'
			);
		}

		if ( ! $this->intents->attach_successful_charge( $intent->id(), $charge_id ) ) {
			$refreshed = $this->intents->find_by_id( $intent->id() );

			if ( null === $refreshed || $refreshed->charge_id() !== $charge_id ) {
				return $this->requires_review(
					$record,
					$intent,
					'charge_assignment_conflict',
					'Authoritative Bachs charge could not be assigned uniquely to this payment intent.'
				);
			}
		}

		$current_intent = $this->intents->find_by_id( $intent->id() );

		if ( null === $current_intent || $current_intent->charge_id() !== $charge_id ) {
			return $this->requires_review(
				$record,
				$intent,
				'charge_assignment_unconfirmed',
				'Authoritative Bachs charge assignment could not be confirmed after persistence.'
			);
		}

		if ( ApplicationStatus::APPLIED === $current_intent->intent()->application_status() ) {
			if ( ! $this->events->mark_processed( $record->id() ) ) {
				return $this->retryable_failure( $record, $current_intent, 'event_finalize_failed' );
			}

			return new WebhookProcessingResult(
				WebhookProcessingDisposition::DUPLICATE,
				$record->id(),
				$current_intent->id()
			);
		}

		if ( ApplicationStatus::PROCESSING === $current_intent->intent()->application_status() ) {
			if ( ! $this->events->mark_processed( $record->id() ) ) {
				return $this->retryable_failure( $record, $current_intent, 'event_finalize_failed' );
			}

			return new WebhookProcessingResult(
				WebhookProcessingDisposition::IN_PROGRESS,
				$record->id(),
				$current_intent->id()
			);
		}

		if ( ApplicationStatus::REQUIRES_REVIEW === $current_intent->intent()->application_status() ) {
			return $this->requires_review(
				$record,
				$current_intent,
				'application_requires_review',
				'Local application state already requires review before fulfillment can continue.'
			);
		}

		try {
			$verified_payment = VerifiedPayment::from_verified_evidence(
				$intent->intent(),
				$reference,
				$event->id(),
				$checkout_id,
				$charge_id,
				$verified_money
			);
		} catch ( InvalidArgumentException ) {
			return $this->requires_review(
				$record,
				$intent,
				'verified_payment_rejected',
				'Verified payment value object rejected provider correlation evidence.'
			);
		}

		return new WebhookProcessingResult(
			WebhookProcessingDisposition::READY_FOR_FULFILLMENT,
			$record->id(),
			$intent->id(),
			$verified_payment
		);
	}

	/**
	 * Apply a failed provider state without granting application fulfillment.
	 *
	 * @param EventRecord  $record Local event record.
	 * @param IntentRecord $intent Matched local intent.
	 * @param WebhookEvent $event  Parsed provider event.
	 * @return WebhookProcessingResult
	 */
	private function process_failed(
		EventRecord $record,
		IntentRecord $intent,
		WebhookEvent $event
	): WebhookProcessingResult {
		if ( ! self::terminal_event_correlates( $event, $intent, 'amount' ) ) {
			return $this->requires_review(
				$record,
				$intent,
				'failed_event_mismatch',
				'Failed collection evidence does not match the local payment intent.'
			);
		}

		$status = self::optional_data_string( $event, 'status' );

		if ( ! in_array( $status, array( 'failed', 'expired' ), true ) ) {
			return $this->requires_review(
				$record,
				$intent,
				'unexpected_failed_status',
				'Failed collection event contains an unexpected provider status.'
			);
		}

		$provider_status = 'expired' === $status ? ProviderStatus::EXPIRED : ProviderStatus::FAILED;

		return $this->apply_terminal_status( $record, $intent, $provider_status );
	}

	/**
	 * Apply an underpaid provider state without granting application fulfillment.
	 *
	 * @param EventRecord  $record Local event record.
	 * @param IntentRecord $intent Matched local intent.
	 * @param WebhookEvent $event  Parsed provider event.
	 * @return WebhookProcessingResult
	 */
	private function process_underpaid(
		EventRecord $record,
		IntentRecord $intent,
		WebhookEvent $event
	): WebhookProcessingResult {
		if ( ! self::terminal_event_correlates( $event, $intent, 'amount_expected' ) ) {
			return $this->requires_review(
				$record,
				$intent,
				'underpaid_event_mismatch',
				'Underpaid collection evidence does not match the local payment intent.'
			);
		}

		if ( 'underpaid' !== self::optional_data_string( $event, 'status' ) ) {
			return $this->requires_review(
				$record,
				$intent,
				'unexpected_underpaid_status',
				'Underpaid collection event contains an unexpected provider status.'
			);
		}

		return $this->apply_terminal_status( $record, $intent, ProviderStatus::UNDERPAID );
	}

	/**
	 * Apply an expired checkout state without granting application fulfillment.
	 *
	 * @param EventRecord  $record Local event record.
	 * @param IntentRecord $intent Matched local intent.
	 * @param WebhookEvent $event  Parsed provider event.
	 * @return WebhookProcessingResult
	 */
	private function process_expired(
		EventRecord $record,
		IntentRecord $intent,
		WebhookEvent $event
	): WebhookProcessingResult {
		if ( ! self::terminal_event_correlates( $event, $intent, 'amount' ) ) {
			return $this->requires_review(
				$record,
				$intent,
				'expired_event_mismatch',
				'Expired checkout evidence does not match the local payment intent.'
			);
		}

		if ( 'expired' !== self::optional_data_string( $event, 'status' ) ) {
			return $this->requires_review(
				$record,
				$intent,
				'unexpected_checkout_status',
				'Expired checkout event contains an unexpected provider status.'
			);
		}

		return $this->apply_terminal_status( $record, $intent, ProviderStatus::EXPIRED );
	}

	/**
	 * Update a non-success provider state and complete the event inbox item.
	 *
	 * @param EventRecord    $record Local event record.
	 * @param IntentRecord   $intent Matched local intent.
	 * @param ProviderStatus $status Provider status to persist.
	 * @return WebhookProcessingResult
	 */
	private function apply_terminal_status(
		EventRecord $record,
		IntentRecord $intent,
		ProviderStatus $status
	): WebhookProcessingResult {
		if ( in_array(
			$intent->intent()->provider_status(),
			array( ProviderStatus::SUCCEEDED, ProviderStatus::REFUNDED, ProviderStatus::PARTIALLY_REFUNDED ),
			true
		) ) {
			if ( ! $this->events->mark_processed( $record->id() ) ) {
				return $this->retryable_failure( $record, $intent, 'event_finalize_failed' );
			}

			return new WebhookProcessingResult(
				WebhookProcessingDisposition::STATUS_UPDATED,
				$record->id(),
				$intent->id()
			);
		}

		if ( ! $this->intents->update_provider_status( $intent->id(), $status ) ) {
			return $this->retryable_failure( $record, $intent, 'provider_status_update_failed' );
		}

		if ( ! $this->events->mark_processed( $record->id() ) ) {
			return $this->retryable_failure( $record, $intent, 'event_finalize_failed' );
		}

		return new WebhookProcessingResult(
			WebhookProcessingDisposition::STATUS_UPDATED,
			$record->id(),
			$intent->id()
		);
	}

	/**
	 * Verify retrieved provider payment state against immutable local evidence.
	 *
	 * @param ProviderPayment $payment     Authoritative Bachs payment.
	 * @param IntentRecord    $intent      Local payment intent.
	 * @param string          $charge_id   Event charge identifier.
	 * @param string          $checkout_id Event checkout identifier.
	 * @param string          $reference   Event merchant reference.
	 * @return Money|null Exact verified amount and currency, or null on mismatch.
	 */
	private function verify_provider_payment(
		ProviderPayment $payment,
		IntentRecord $intent,
		string $charge_id,
		string $checkout_id,
		string $reference
	): ?Money {
		if ( ! hash_equals( $charge_id, $payment->payment_id() ) ) {
			return null;
		}

		if ( ! in_array( $payment->status(), self::SUCCESSFUL_PROVIDER_STATUSES, true ) ) {
			return null;
		}

		if ( null === $payment->checkout_id() || ! hash_equals( $checkout_id, $payment->checkout_id() ) ) {
			return null;
		}

		if ( null === $payment->reference() || ! hash_equals( $reference, $payment->reference() ) ) {
			return null;
		}

		try {
			$money = Money::from_decimal(
				$payment->amount(),
				Currency::from_code( $payment->currency() )
			);
		} catch ( InvalidArgumentException ) {
			return null;
		}

		return $intent->intent()->expected_amount()->equals( $money ) ? $money : null;
	}

	/**
	 * Mark an event as requiring review and return the matching disposition.
	 *
	 * @param EventRecord       $record  Local event record.
	 * @param IntentRecord|null $intent  Matched local intent when available.
	 * @param string            $code    Review code.
	 * @param string            $message Redacted review message.
	 * @return WebhookProcessingResult
	 */
	private function requires_review(
		EventRecord $record,
		?IntentRecord $intent,
		string $code,
		string $message
	): WebhookProcessingResult {
		if ( ! $this->events->mark_requires_review( $record->id(), $code, $message ) ) {
			return $this->retryable_failure( $record, $intent, 'review_state_persist_failed' );
		}

		return new WebhookProcessingResult(
			WebhookProcessingDisposition::REQUIRES_REVIEW,
			$record->id(),
			$intent?->id()
		);
	}

	/**
	 * Mark an event as failed and return a retryable result.
	 *
	 * @param EventRecord       $record Local event record.
	 * @param IntentRecord|null $intent Matched local intent when available.
	 * @param string            $code   Stable failure code.
	 * @return WebhookProcessingResult
	 */
	private function retryable_failure(
		EventRecord $record,
		?IntentRecord $intent,
		string $code
	): WebhookProcessingResult {
		$this->events->mark_failed( $record->id(), $code, 'Webhook processing can be retried safely.' );

		return new WebhookProcessingResult(
			WebhookProcessingDisposition::RETRYABLE_FAILURE,
			$record->id(),
			$intent?->id()
		);
	}

	/**
	 * Confirm a non-success event still correlates to the matched local intent.
	 *
	 * Checkout ID and reference are checked whenever Bachs supplied them. The
	 * event's expected/original amount and currency must always match the local
	 * immutable payment expectation before provider state is changed.
	 *
	 * @param WebhookEvent $event      Parsed provider event.
	 * @param IntentRecord $intent     Matched local intent.
	 * @param string       $amount_key Event field containing the expected/original amount.
	 * @return bool
	 */
	private static function terminal_event_correlates(
		WebhookEvent $event,
		IntentRecord $intent,
		string $amount_key
	): bool {
		$checkout_id = self::optional_data_string( $event, 'checkout_id' );
		$reference   = self::optional_data_string( $event, 'reference' );

		if ( null !== $checkout_id && $intent->checkout_id() !== $checkout_id ) {
			return false;
		}

		if ( null !== $reference && ! hash_equals( $intent->intent()->reference(), $reference ) ) {
			return false;
		}

		$event_money = self::event_money( $event, $amount_key, 'currency' );

		return null !== $event_money && $intent->intent()->expected_amount()->equals( $event_money );
	}

	/**
	 * Build exact money evidence from event payload fields.
	 *
	 * @param WebhookEvent $event        Parsed provider event.
	 * @param string       $amount_key   Amount field key.
	 * @param string       $currency_key Currency field key.
	 * @return Money|null
	 */
	private static function event_money(
		WebhookEvent $event,
		string $amount_key,
		string $currency_key
	): ?Money {
		$amount   = self::optional_data_string( $event, $amount_key );
		$currency = self::optional_data_string( $event, $currency_key );

		if ( null === $amount || null === $currency ) {
			return null;
		}

		try {
			return Money::from_decimal( $amount, Currency::from_code( $currency ) );
		} catch ( InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * Read an optional string from the event data object.
	 *
	 * @param WebhookEvent $event Parsed provider event.
	 * @param string       $key   Data field key.
	 * @return string|null
	 */
	private static function optional_data_string( WebhookEvent $event, string $key ): ?string {
		$value = $event->data()[ $key ] ?? null;

		if ( ! is_string( $value ) || '' === $value || trim( $value ) !== $value ) {
			return null;
		}

		return $value;
	}

	/**
	 * Create a processing exception without treating exception text as rendered output.
	 *
	 * @param string $code    Stable processing error code.
	 * @param string $message Safe diagnostic message.
	 * @return WebhookProcessingException
	 */
	private static function processing_exception( string $code, string $message ): WebhookProcessingException {
		// Processing codes and messages are internal exception data, not rendered output.
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		$exception = new WebhookProcessingException( $code, $message );
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

		return $exception;
	}

	/**
	 * Determine whether this 1.0 processor has behavior for an event type.
	 *
	 * @param string $event_type Provider event type.
	 * @return bool
	 */
	private static function is_supported_event( string $event_type ): bool {
		return in_array(
			$event_type,
			array(
				self::EVENT_COLLECTION_SUCCEEDED,
				self::EVENT_COLLECTION_FAILED,
				self::EVENT_COLLECTION_UNDERPAID,
				self::EVENT_CHECKOUT_EXPIRED,
			),
			true
		);
	}
}
