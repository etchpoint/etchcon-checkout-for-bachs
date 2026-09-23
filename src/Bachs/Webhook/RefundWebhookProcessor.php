<?php
/**
 * Bachs refund webhook processor.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use Etchpoint\BachsIntegrations\Bachs\ApiException;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Bachs\ProviderRefund;
use Etchpoint\BachsIntegrations\Bachs\RefundsApi;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Refund\RefundApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Refund\RefundStatus;
use Etchpoint\BachsIntegrations\Core\Refund\VerifiedRefund;
use Etchpoint\BachsIntegrations\Persistence\EventProcessingStatus;
use Etchpoint\BachsIntegrations\Persistence\EventRecord;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Etchpoint\BachsIntegrations\Persistence\RefundRecord;
use Etchpoint\BachsIntegrations\Persistence\RefundRepository;
use InvalidArgumentException;

/**
 * Converts signed refund events into authoritative refund fulfillment evidence.
 */
final class RefundWebhookProcessor {
	/** Refund request accepted event. */
	private const EVENT_CREATED = 'refund.created';

	/** Refund settled successfully event. */
	private const EVENT_PAID = 'refund.paid';

	/** Refund failed event. */
	private const EVENT_FAILED = 'refund.failed';

	/**
	 * Event inbox repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Payment intent repository.
	 *
	 * @var IntentRepository
	 */
	private IntentRepository $intents;

	/**
	 * Refund repository.
	 *
	 * @var RefundRepository
	 */
	private RefundRepository $refunds;

	/**
	 * Authoritative refunds API.
	 *
	 * @var RefundsApi
	 */
	private RefundsApi $api;

	/**
	 * Event parser.
	 *
	 * @var WebhookEventParser
	 */
	private WebhookEventParser $parser;

	/**
	 * Bachs environment.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Optional pinned organization identifier.
	 *
	 * @var string|null
	 */
	private ?string $organization_id;

	/**
	 * Create the processor.
	 *
	 * @param EventRepository    $events          Event inbox repository.
	 * @param IntentRepository   $intents         Payment intent repository.
	 * @param RefundRepository   $refunds         Refund repository.
	 * @param RefundsApi         $api             Authoritative refunds API.
	 * @param WebhookEventParser $parser          Event parser.
	 * @param Environment        $environment     Bachs environment.
	 * @param string|null        $organization_id Optional pinned organization identifier.
	 */
	public function __construct(
		EventRepository $events,
		IntentRepository $intents,
		RefundRepository $refunds,
		RefundsApi $api,
		WebhookEventParser $parser,
		Environment $environment,
		?string $organization_id = null
	) {
		$this->events          = $events;
		$this->intents         = $intents;
		$this->refunds         = $refunds;
		$this->api             = $api;
		$this->parser          = $parser;
		$this->environment     = $environment;
		$this->organization_id = $organization_id;
	}

	/**
	 * Process the request when it is a refund event; otherwise leave it for the payment processor.
	 *
	 * @param VerifiedWebhookSignature $signature Verified signature evidence.
	 * @param string                   $raw_body  Exact raw webhook body.
	 * @return RefundWebhookProcessingResult|null
	 *
	 * @throws WebhookProcessingException When signed event evidence is malformed or mismatched.
	 */
	public function process_if_refund( VerifiedWebhookSignature $signature, string $raw_body ): ?RefundWebhookProcessingResult {
		$payload_hash = hash( 'sha256', $raw_body );

		if ( ! hash_equals( $signature->payload_hash(), $payload_hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- First exception argument is an internal machine code, not rendered output.
			throw new WebhookProcessingException(
				WebhookProcessingException::CODE_PAYLOAD_HASH_MISMATCH,
				'Verified refund webhook signature does not belong to this request body.'
			);
		}

		$event = $this->parser->parse( $raw_body );

		if ( ! self::is_refund_event( $event->type() ) ) {
			return null;
		}

		if ( null !== $this->organization_id && ! hash_equals( $this->organization_id, $event->organization_id() ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- First exception argument is an internal machine code, not rendered output.
			throw new WebhookProcessingException(
				WebhookProcessingException::CODE_ORGANIZATION_MISMATCH,
				'Refund webhook organization does not match the configured Bachs organization.'
			);
		}

		if ( null !== $event->account() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- First exception argument is an internal machine code, not rendered output.
			throw new WebhookProcessingException(
				WebhookProcessingException::CODE_UNSUPPORTED_CONNECT_EVENT,
				'Connected-account refund webhook events are outside this plugin release scope.'
			);
		}

		$record = $this->record_and_claim( $event, $payload_hash );

		if ( $record instanceof RefundWebhookProcessingResult ) {
			return $record;
		}

		$refund_id = self::refund_id_from_event( $event );

		if ( null === $refund_id ) {
			return $this->requires_review( $record, null, 'refund_id_missing', 'Refund webhook did not contain a refund identifier.' );
		}

		try {
			$provider_refund = $this->api->get( $refund_id );
		} catch ( ApiException $exception ) {
			if ( $exception->is_retryable() ) {
				return $this->retryable_failure( $record, null, 'refund_retrieval_failed' );
			}

			return $this->requires_review( $record, null, 'refund_retrieval_rejected', 'Authoritative Bachs refund state could not be retrieved.' );
		}

		$refund = $this->locate_refund( $provider_refund );

		if ( null === $refund ) {
			return $this->requires_review( $record, null, 'unknown_refund', 'Refund webhook could not be matched to a local refund operation.' );
		}

		$intent = $this->intents->find_by_id( $refund->intent_id() );

		if ( null === $intent || null === $intent->charge_id() ) {
			return $this->requires_review( $record, $refund, 'refund_intent_missing', 'Refund no longer has a valid original payment intent.' );
		}

		if ( ! $this->events->link_intent( $record->id(), $intent->id() ) ) {
			return $this->retryable_failure( $record, $refund, 'refund_event_link_failed' );
		}

		if ( $refund->environment() !== $this->environment->value ) {
			return $this->requires_review( $record, $refund, 'refund_environment_mismatch', 'Refund environment does not match this webhook endpoint.' );
		}

		if ( ! $this->provider_refund_matches( $refund, $provider_refund ) ) {
			return $this->requires_review( $record, $refund, 'refund_correlation_mismatch', 'Authoritative Bachs refund state does not match the local request.' );
		}

		if ( RefundApplicationStatus::APPLIED === $refund->application_status() ) {
			if ( ! $this->events->mark_processed( $record->id() ) ) {
				return $this->retryable_failure( $record, $refund, 'refund_duplicate_finalize_failed' );
			}

			return new RefundWebhookProcessingResult( RefundWebhookProcessingDisposition::DUPLICATE, $record->id(), $refund->id() );
		}

		if ( self::EVENT_FAILED === $event->type() ) {
			if ( 'failed' !== $provider_refund->status() ) {
				return $this->retryable_failure( $record, $refund, 'refund_failed_state_not_visible' );
			}

			$this->refunds->update_provider_status( $refund->id(), RefundStatus::FAILED, $provider_refund->refunded_amount() );

			return $this->finalize_status_event( $record, $refund );
		}

		if ( self::EVENT_CREATED === $event->type() ) {
			$this->refunds->update_provider_status( $refund->id(), self::normalize_status( $provider_refund->status() ), $provider_refund->refunded_amount() );

			return $this->finalize_status_event( $record, $refund );
		}

		if ( 'success' !== $provider_refund->status() || null === $provider_refund->refunded_amount() ) {
			return $this->retryable_failure( $record, $refund, 'refund_success_state_not_visible' );
		}

		try {
			$refunded_amount = Money::from_decimal( $provider_refund->refunded_amount(), $refund->requested_amount()->currency() );
		} catch ( InvalidArgumentException ) {
			return $this->requires_review( $record, $refund, 'refund_amount_invalid', 'Bachs returned an invalid refunded amount.' );
		}

		if ( ! $refunded_amount->equals( $refund->requested_amount() ) ) {
			return $this->requires_review( $record, $refund, 'refund_amount_mismatch', 'Bachs confirmed a refund amount different from the persisted request.' );
		}

		if ( ! $this->refunds->update_provider_status( $refund->id(), RefundStatus::SUCCEEDED, $refunded_amount->amount() ) ) {
			return $this->retryable_failure( $record, $refund, 'refund_status_update_failed' );
		}

		$full_refund = $refunded_amount->equals( $intent->intent()->expected_amount() );
		$verified    = new VerifiedRefund(
			$refund->integration(),
			$refund->local_object_type(),
			$refund->local_object_id(),
			$intent->intent()->uuid(),
			$provider_refund->refund_id(),
			$provider_refund->charge_id(),
			$refund->requested_amount(),
			$refunded_amount,
			$full_refund,
			$refund->reason()
		);

		return new RefundWebhookProcessingResult(
			RefundWebhookProcessingDisposition::READY_FOR_FULFILLMENT,
			$record->id(),
			$refund->id(),
			$verified
		);
	}

	/**
	 * Record and claim a refund event with provider event ID deduplication.
	 *
	 * @param WebhookEvent $event        Parsed event.
	 * @param string       $payload_hash Verified payload hash.
	 * @return EventRecord|RefundWebhookProcessingResult
	 *
	 * @throws WebhookProcessingException When the event inbox cannot be reloaded consistently.
	 */
	private function record_and_claim( WebhookEvent $event, string $payload_hash ): EventRecord|RefundWebhookProcessingResult {
		$this->events->record_received( $event->id(), $event->type(), $event->organization_id(), $payload_hash );
		$record = $this->events->find_by_provider_event_id( $event->id() );

		if ( null === $record ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- First exception argument is an internal machine code, not rendered output.
			throw new WebhookProcessingException( WebhookProcessingException::CODE_EVENT_STORE_INCONSISTENT, 'Refund webhook event could not be loaded after deduplication.' );
		}

		if ( ! hash_equals( $record->payload_hash(), $payload_hash ) || $record->event_type() !== $event->type() ) {
			if ( $this->events->claim_processing( $record->id() ) ) {
				$this->events->mark_requires_review( $record->id(), 'duplicate_payload_mismatch', 'Repeated refund event ID arrived with different verified contents.' );
			}

			return new RefundWebhookProcessingResult( RefundWebhookProcessingDisposition::REQUIRES_REVIEW, $record->id() );
		}

		if ( EventProcessingStatus::PROCESSED === $record->processing_status() ) {
			return new RefundWebhookProcessingResult( RefundWebhookProcessingDisposition::DUPLICATE, $record->id() );
		}

		if ( EventProcessingStatus::REQUIRES_REVIEW === $record->processing_status() ) {
			return new RefundWebhookProcessingResult( RefundWebhookProcessingDisposition::REQUIRES_REVIEW, $record->id() );
		}

		if ( ! $this->events->claim_processing( $record->id() ) ) {
			return new RefundWebhookProcessingResult( RefundWebhookProcessingDisposition::IN_PROGRESS, $record->id() );
		}

		$claimed = $this->events->find_by_provider_event_id( $event->id() );

		if ( null === $claimed ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- First exception argument is an internal machine code, not rendered output.
			throw new WebhookProcessingException( WebhookProcessingException::CODE_EVENT_STORE_INCONSISTENT, 'Claimed refund webhook event could not be reloaded.' );
		}

		return $claimed;
	}

	/**
	 * Locate a local refund and repair a race where the provider ID was not persisted yet.
	 *
	 * @param ProviderRefund $provider_refund Authoritative provider refund.
	 * @return RefundRecord|null
	 */
	private function locate_refund( ProviderRefund $provider_refund ): ?RefundRecord {
		$refund = $this->refunds->find_by_provider_refund_id( $provider_refund->refund_id() );

		if ( null !== $refund ) {
			return $refund;
		}

		$refund = $this->refunds->find_by_charge_id( $provider_refund->charge_id() );

		if ( null === $refund || ! hash_equals( $refund->reference(), $provider_refund->reference() ) ) {
			return null;
		}

		if ( ! $this->refunds->attach_provider_refund( $refund->id(), $provider_refund->refund_id(), self::normalize_status( $provider_refund->status() ), $provider_refund->refunded_amount() ) ) {
			return null;
		}

		return $this->refunds->find_by_id( $refund->id() );
	}

	/**
	 * Confirm the authoritative refund belongs to the persisted logical operation.
	 *
	 * @param RefundRecord   $refund          Local refund record.
	 * @param ProviderRefund $provider_refund Authoritative provider refund.
	 * @return bool
	 */
	private function provider_refund_matches( RefundRecord $refund, ProviderRefund $provider_refund ): bool {
		if ( ! hash_equals( $refund->charge_id(), $provider_refund->charge_id() ) || ! hash_equals( $refund->reference(), $provider_refund->reference() ) ) {
			return false;
		}

		try {
			$requested = Money::from_decimal( $provider_refund->requested_amount(), $refund->requested_amount()->currency() );
		} catch ( InvalidArgumentException ) {
			return false;
		}

		return $requested->equals( $refund->requested_amount() );
	}

	/**
	 * Mark a non-fulfillment refund event processed.
	 *
	 * @param EventRecord  $record Event record.
	 * @param RefundRecord $refund Refund record.
	 * @return RefundWebhookProcessingResult
	 */
	private function finalize_status_event( EventRecord $record, RefundRecord $refund ): RefundWebhookProcessingResult {
		if ( ! $this->events->mark_processed( $record->id() ) ) {
			return $this->retryable_failure( $record, $refund, 'refund_event_finalize_failed' );
		}

		return new RefundWebhookProcessingResult( RefundWebhookProcessingDisposition::STATUS_UPDATED, $record->id(), $refund->id() );
	}

	/**
	 * Move a refund event into manual review.
	 *
	 * @param EventRecord       $record  Event record.
	 * @param RefundRecord|null $refund  Matched refund when available.
	 * @param string            $code    Diagnostic code.
	 * @param string            $message Redacted message.
	 * @return RefundWebhookProcessingResult
	 */
	private function requires_review( EventRecord $record, ?RefundRecord $refund, string $code, string $message ): RefundWebhookProcessingResult {
		if ( null !== $refund ) {
			$this->refunds->mark_request_failure( $refund->id(), RefundStatus::REQUIRES_REVIEW, $code, $message );
		}
		$this->events->mark_requires_review( $record->id(), $code, $message );

		return new RefundWebhookProcessingResult( RefundWebhookProcessingDisposition::REQUIRES_REVIEW, $record->id(), $refund?->id() );
	}

	/**
	 * Mark a refund webhook event failed so Bachs may retry it.
	 *
	 * @param EventRecord       $record Event record.
	 * @param RefundRecord|null $refund Matched refund when available.
	 * @param string            $code   Diagnostic code.
	 * @return RefundWebhookProcessingResult
	 */
	private function retryable_failure( EventRecord $record, ?RefundRecord $refund, string $code ): RefundWebhookProcessingResult {
		$this->events->mark_failed( $record->id(), $code, 'Refund webhook processing can be retried safely.' );

		return new RefundWebhookProcessingResult( RefundWebhookProcessingDisposition::RETRYABLE_FAILURE, $record->id(), $refund?->id() );
	}

	/**
	 * Check whether the event belongs to the refund lifecycle.
	 *
	 * @param string $type Event type.
	 * @return bool
	 */
	private static function is_refund_event( string $type ): bool {
		return in_array( $type, array( self::EVENT_CREATED, self::EVENT_PAID, self::EVENT_FAILED ), true );
	}

	/**
	 * Extract a refund identifier from compatible webhook payload shapes.
	 *
	 * @param WebhookEvent $event Event.
	 * @return string|null
	 */
	private static function refund_id_from_event( WebhookEvent $event ): ?string {
		$data = $event->data();

		foreach ( array( 'refund_id', 'id' ) as $key ) {
			$value = $data[ $key ] ?? null;
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		$nested = $data['refund'] ?? null;
		if ( is_array( $nested ) && isset( $nested['refund_id'] ) && is_string( $nested['refund_id'] ) && '' !== $nested['refund_id'] ) {
			return $nested['refund_id'];
		}

		return null;
	}

	/**
	 * Normalize Bachs refund status.
	 *
	 * @param string $status Raw status.
	 * @return RefundStatus
	 */
	private static function normalize_status( string $status ): RefundStatus {
		return match ( $status ) {
			'processing' => RefundStatus::PROCESSING,
			'success'    => RefundStatus::SUCCEEDED,
			'failed'     => RefundStatus::FAILED,
			default      => RefundStatus::REQUIRES_REVIEW,
		};
	}
}
