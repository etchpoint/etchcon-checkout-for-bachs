<?php
/**
 * Bachs webhook processor tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use Etchpoint\BachsIntegrations\Bachs\ApiException;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Bachs\ProviderPayment;
use Etchpoint\BachsIntegrations\Bachs\Webhook\VerifiedWebhookSignature;
use Etchpoint\BachsIntegrations\Bachs\Webhook\WebhookEventParser;
use Etchpoint\BachsIntegrations\Bachs\Webhook\WebhookProcessingDisposition;
use Etchpoint\BachsIntegrations\Bachs\Webhook\WebhookProcessingException;
use Etchpoint\BachsIntegrations\Bachs\Webhook\WebhookProcessor;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use Etchpoint\BachsIntegrations\Core\Payment\ProviderStatus;
use Etchpoint\BachsIntegrations\Persistence\EventProcessingStatus;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use Etchpoint\BachsIntegrations\Tests\Support\Bachs\Webhook\FakePaymentRetriever;
use Etchpoint\BachsIntegrations\Tests\Support\Bachs\Webhook\InMemoryEventStore;
use Etchpoint\BachsIntegrations\Tests\Support\Bachs\Webhook\InMemoryIntentStore;
use PHPUnit\Framework\TestCase;

/**
 * Verifies deduplication, intent correlation, provider evidence, and safe states.
 */
final class WebhookProcessorTest extends TestCase {
	/**
	 * A successful collection becomes verified payment evidence only after API retrieval.
	 *
	 * @return void
	 */
	public function test_success_event_becomes_ready_for_fulfillment(): void {
		$event_store  = new InMemoryEventStore();
		$intent_store = new InMemoryIntentStore( array( $this->intent_record() ) );
		$retriever    = new FakePaymentRetriever( $this->provider_payment() );
		$body         = $this->event_body( 'collection.succeeded' );
		$result       = $this->processor( $event_store, $intent_store, $retriever )->process(
			$this->signature_for( $body ),
			$body
		);

		self::assertSame( WebhookProcessingDisposition::READY_FOR_FULFILLMENT, $result->disposition() );
		self::assertNotNull( $result->verified_payment() );
		self::assertSame( 'ch_123', $result->verified_payment()?->charge_id() );
		self::assertSame( 'ch_123', $retriever->requested_id() );
		self::assertSame( 'ch_123', $intent_store->find_by_id( 7 )?->charge_id() );
		self::assertSame(
			EventProcessingStatus::PROCESSING,
			$event_store->find_by_provider_event_id( 'evt_123' )?->processing_status()
		);
	}

	/**
	 * Reusing verified signature evidence with different raw bytes is rejected.
	 *
	 * @return void
	 */
	public function test_signature_evidence_is_bound_to_exact_raw_body(): void {
		$body      = $this->event_body( 'collection.succeeded' );
		$processor = $this->processor(
			new InMemoryEventStore(),
			new InMemoryIntentStore( array( $this->intent_record() ) ),
			new FakePaymentRetriever( $this->provider_payment() )
		);

		try {
			$processor->process( $this->signature_for( $body ), $body . "\n" );
			self::fail( 'Expected payload hash mismatch.' );
		} catch ( WebhookProcessingException $exception ) {
			self::assertSame( WebhookProcessingException::CODE_PAYLOAD_HASH_MISMATCH, $exception->error_code() );
		}
	}

	/**
	 * Provider amount mismatch is held for review and never creates payment evidence.
	 *
	 * @return void
	 */
	public function test_provider_amount_mismatch_requires_review(): void {
		$payment = ProviderPayment::from_api_response(
			array(
				'payment_id'       => 'ch_123',
				'status'           => 'succeeded',
				'amount'           => '99.00',
				'currency'         => 'USD',
				'amount_paid'      => '99.00',
				'amount_remaining' => '0.00',
				'reference'        => 'ref_123',
				'checkout_id'      => 'chk_123',
			)
		);
		$events  = new InMemoryEventStore();
		$body    = $this->event_body( 'collection.succeeded' );
		$result  = $this->processor(
			$events,
			new InMemoryIntentStore( array( $this->intent_record() ) ),
			new FakePaymentRetriever( $payment )
		)->process( $this->signature_for( $body ), $body );

		self::assertSame( WebhookProcessingDisposition::REQUIRES_REVIEW, $result->disposition() );
		self::assertNull( $result->verified_payment() );
		self::assertSame(
			EventProcessingStatus::REQUIRES_REVIEW,
			$events->find_by_provider_event_id( 'evt_123' )?->processing_status()
		);
	}

	/**
	 * A transient provider retrieval failure leaves the event safely retryable.
	 *
	 * @return void
	 */
	public function test_provider_retrieval_failure_is_retryable(): void {
		$events = new InMemoryEventStore();
		$body   = $this->event_body( 'collection.succeeded' );
		$result = $this->processor(
			$events,
			new InMemoryIntentStore( array( $this->intent_record() ) ),
			new FakePaymentRetriever( null, ApiException::transport( 'Synthetic network failure.' ) )
		)->process( $this->signature_for( $body ), $body );

		self::assertSame( WebhookProcessingDisposition::RETRYABLE_FAILURE, $result->disposition() );
		self::assertSame(
			EventProcessingStatus::FAILED,
			$events->find_by_provider_event_id( 'evt_123' )?->processing_status()
		);
	}

	/**
	 * Failed collections update provider state without creating fulfillment evidence.
	 *
	 * @return void
	 */
	public function test_failed_collection_updates_provider_state(): void {
		$events  = new InMemoryEventStore();
		$intents = new InMemoryIntentStore( array( $this->intent_record() ) );
		$body    = $this->event_body( 'collection.failed', array( 'status' => 'failed' ) );
		$result  = $this->processor( $events, $intents, new FakePaymentRetriever() )->process(
			$this->signature_for( $body ),
			$body
		);

		self::assertSame( WebhookProcessingDisposition::STATUS_UPDATED, $result->disposition() );
		self::assertSame( ProviderStatus::FAILED, $intents->find_by_id( 7 )?->intent()->provider_status() );
		self::assertSame(
			EventProcessingStatus::PROCESSED,
			$events->find_by_provider_event_id( 'evt_123' )?->processing_status()
		);
	}

	/**
	 * Underpayment is recorded but never treated as successful fulfillment.
	 *
	 * @return void
	 */
	public function test_underpaid_collection_never_fulfills(): void {
		$events  = new InMemoryEventStore();
		$intents = new InMemoryIntentStore( array( $this->intent_record() ) );
		$body    = $this->event_body(
			'collection.underpaid',
			array(
				'status'           => 'underpaid',
				'amount_paid'      => '30.00',
				'amount_expected'  => '42.00',
				'amount_remaining' => '12.00',
			)
		);
		$result  = $this->processor( $events, $intents, new FakePaymentRetriever() )->process(
			$this->signature_for( $body ),
			$body
		);

		self::assertSame( WebhookProcessingDisposition::STATUS_UPDATED, $result->disposition() );
		self::assertNull( $result->verified_payment() );
		self::assertSame( ProviderStatus::UNDERPAID, $intents->find_by_id( 7 )?->intent()->provider_status() );
	}

	/**
	 * Expired checkout updates provider state without payment retrieval.
	 *
	 * @return void
	 */
	public function test_expired_checkout_updates_provider_state(): void {
		$intents = new InMemoryIntentStore( array( $this->intent_record() ) );
		$body    = $this->event_body( 'checkout.expired', array( 'status' => 'expired' ) );
		$result  = $this->processor(
			new InMemoryEventStore(),
			$intents,
			new FakePaymentRetriever()
		)->process( $this->signature_for( $body ), $body );

		self::assertSame( WebhookProcessingDisposition::STATUS_UPDATED, $result->disposition() );
		self::assertSame( ProviderStatus::EXPIRED, $intents->find_by_id( 7 )?->intent()->provider_status() );
	}

	/**
	 * Unknown additive event types are safely recorded and ignored.
	 *
	 * @return void
	 */
	public function test_unknown_event_type_is_ignored(): void {
		$events = new InMemoryEventStore();
		$body   = $this->event_body( 'future.event', array( 'status' => 'future' ) );
		$result = $this->processor(
			$events,
			new InMemoryIntentStore( array( $this->intent_record() ) ),
			new FakePaymentRetriever()
		)->process( $this->signature_for( $body ), $body );

		self::assertSame( WebhookProcessingDisposition::IGNORED, $result->disposition() );
		self::assertSame(
			EventProcessingStatus::PROCESSED,
			$events->find_by_provider_event_id( 'evt_123' )?->processing_status()
		);
	}

	/**
	 * Organization binding is validated before an event enters the local inbox.
	 *
	 * @return void
	 */
	public function test_organization_mismatch_is_rejected_before_deduplication(): void {
		$events    = new InMemoryEventStore();
		$body      = $this->event_body( 'collection.succeeded' );
		$processor = $this->processor(
			$events,
			new InMemoryIntentStore( array( $this->intent_record() ) ),
			new FakePaymentRetriever( $this->provider_payment() ),
			'acct_expected'
		);

		try {
			$processor->process( $this->signature_for( $body ), $body );
			self::fail( 'Expected organization mismatch.' );
		} catch ( WebhookProcessingException $exception ) {
			self::assertSame( WebhookProcessingException::CODE_ORGANIZATION_MISMATCH, $exception->error_code() );
		}

		self::assertNull( $events->find_by_provider_event_id( 'evt_123' ) );
	}

	/**
	 * Connected-account events are rejected because Bachs Connect is outside 1.0 scope.
	 *
	 * @return void
	 */
	public function test_connected_account_event_is_rejected_before_deduplication(): void {
		$events = new InMemoryEventStore();
		$body   = $this->event_body( 'collection.succeeded', array(), 'acct_123' );

		try {
			$this->processor(
				$events,
				new InMemoryIntentStore( array( $this->intent_record() ) ),
				new FakePaymentRetriever( $this->provider_payment() )
			)->process( $this->signature_for( $body ), $body );
			self::fail( 'Expected connected-account event rejection.' );
		} catch ( WebhookProcessingException $exception ) {
			self::assertSame( WebhookProcessingException::CODE_UNSUPPORTED_CONNECT_EVENT, $exception->error_code() );
		}

		self::assertNull( $events->find_by_provider_event_id( 'evt_123' ) );
	}

	/**
	 * A second delivery cannot produce payment evidence while the first owns processing.
	 *
	 * @return void
	 */
	public function test_duplicate_delivery_does_not_fulfill_twice(): void {
		$events    = new InMemoryEventStore();
		$intents   = new InMemoryIntentStore( array( $this->intent_record() ) );
		$retriever = new FakePaymentRetriever( $this->provider_payment() );
		$processor = $this->processor( $events, $intents, $retriever );
		$body      = $this->event_body( 'collection.succeeded' );

		$first  = $processor->process( $this->signature_for( $body ), $body );
		$second = $processor->process( $this->signature_for( $body ), $body );

		self::assertSame( WebhookProcessingDisposition::READY_FOR_FULFILLMENT, $first->disposition() );
		self::assertSame( WebhookProcessingDisposition::IN_PROGRESS, $second->disposition() );
		self::assertNull( $second->verified_payment() );
	}

	/**
	 * A provider charge already attached to another intent cannot be reused.
	 *
	 * @return void
	 */
	public function test_charge_reuse_conflict_requires_review(): void {
		$events  = new InMemoryEventStore();
		$intents = new InMemoryIntentStore(
			array(
				$this->intent_record(),
				$this->occupied_intent_record(),
			)
		);
		$body    = $this->event_body( 'collection.succeeded' );
		$result  = $this->processor( $events, $intents, new FakePaymentRetriever( $this->provider_payment() ) )->process(
			$this->signature_for( $body ),
			$body
		);

		self::assertSame( WebhookProcessingDisposition::REQUIRES_REVIEW, $result->disposition() );
		self::assertNull( $result->verified_payment() );
		self::assertNull( $intents->find_by_id( 7 )?->charge_id() );
	}

	/**
	 * Signed failed-state evidence must still match the immutable intent amount.
	 *
	 * @return void
	 */
	public function test_failed_event_amount_mismatch_requires_review(): void {
		$intents = new InMemoryIntentStore( array( $this->intent_record() ) );
		$body    = $this->event_body(
			'collection.failed',
			array(
				'status' => 'failed',
				'amount' => '99.00',
			)
		);
		$result  = $this->processor(
			new InMemoryEventStore(),
			$intents,
			new FakePaymentRetriever()
		)->process( $this->signature_for( $body ), $body );

		self::assertSame( WebhookProcessingDisposition::REQUIRES_REVIEW, $result->disposition() );
		self::assertSame( ProviderStatus::CREATED, $intents->find_by_id( 7 )?->intent()->provider_status() );
	}

	/**
	 * A sandbox endpoint cannot process an otherwise valid live intent.
	 *
	 * @return void
	 */
	public function test_environment_mismatch_requires_review(): void {
		$body   = $this->event_body( 'collection.succeeded' );
		$result = $this->processor(
			new InMemoryEventStore(),
			new InMemoryIntentStore( array( $this->intent_record( 'live' ) ) ),
			new FakePaymentRetriever( $this->provider_payment() )
		)->process( $this->signature_for( $body ), $body );

		self::assertSame( WebhookProcessingDisposition::REQUIRES_REVIEW, $result->disposition() );
	}

	/**
	 * Missing charge ID on a success event is held for review rather than fulfilled.
	 *
	 * @return void
	 */
	public function test_success_without_charge_id_requires_review(): void {
		$body   = $this->event_body( 'collection.succeeded', array( 'charge_id' => null ) );
		$result = $this->processor(
			new InMemoryEventStore(),
			new InMemoryIntentStore( array( $this->intent_record() ) ),
			new FakePaymentRetriever( $this->provider_payment() )
		)->process( $this->signature_for( $body ), $body );

		self::assertSame( WebhookProcessingDisposition::REQUIRES_REVIEW, $result->disposition() );
		self::assertNull( $result->verified_payment() );
	}

	/**
	 * Build the processor under test.
	 *
	 * @param InMemoryEventStore   $events          Event store.
	 * @param InMemoryIntentStore  $intents         Intent store.
	 * @param FakePaymentRetriever $retriever       Provider payment retriever.
	 * @param string|null          $organization_id Optional pinned organization ID.
	 * @return WebhookProcessor
	 */
	private function processor(
		InMemoryEventStore $events,
		InMemoryIntentStore $intents,
		FakePaymentRetriever $retriever,
		?string $organization_id = 'acct_123'
	): WebhookProcessor {
		return new WebhookProcessor(
			$events,
			$intents,
			$retriever,
			new WebhookEventParser(),
			Environment::SANDBOX,
			$organization_id
		);
	}

	/**
	 * Build a persisted payment intent fixture.
	 *
	 * @param string $environment Intent environment.
	 * @return IntentRecord
	 */
	private function intent_record( string $environment = 'sandbox' ): IntentRecord {
		$currency = Currency::from_code( 'USD' );
		$intent   = PaymentIntent::create(
			'123e4567-e89b-42d3-a456-426614174000',
			'woocommerce',
			'order',
			'1001',
			$environment,
			'ref_123',
			'etp:test:woo:1001:checkout:1',
			Money::from_decimal( '42.00', $currency )
		);
		$now      = new DateTimeImmutable( '2026-09-22T12:00:00+00:00', new DateTimeZone( 'UTC' ) );

		return new IntentRecord( 7, $intent, 'chk_123', null, null, null, null, $now, $now, null );
	}

	/**
	 * Build another persisted intent that already owns the test provider charge.
	 *
	 * @return IntentRecord
	 */
	private function occupied_intent_record(): IntentRecord {
		$currency = Currency::from_code( 'USD' );
		$intent   = PaymentIntent::rehydrate(
			'223e4567-e89b-42d3-a456-426614174001',
			'woocommerce',
			'order',
			'1002',
			'sandbox',
			'ref_other',
			'etp:test:woo:1002:checkout:1',
			Money::from_decimal( '42.00', $currency ),
			1,
			ProviderStatus::SUCCEEDED,
			ApplicationStatus::APPLIED
		);
		$now      = new DateTimeImmutable( '2026-09-22T12:00:00+00:00', new DateTimeZone( 'UTC' ) );

		return new IntentRecord( 8, $intent, 'chk_other', 'ch_123', null, null, null, $now, $now, $now );
	}

	/**
	 * Build authoritative Bachs payment evidence.
	 *
	 * @return ProviderPayment
	 */
	private function provider_payment(): ProviderPayment {
		return ProviderPayment::from_api_response(
			array(
				'payment_id'       => 'ch_123',
				'status'           => 'succeeded',
				'amount'           => '42.00',
				'currency'         => 'USD',
				'amount_paid'      => '42.00',
				'amount_remaining' => '0.00',
				'reference'        => 'ref_123',
				'checkout_id'      => 'chk_123',
			)
		);
	}

	/**
	 * Build a Bachs webhook body with optional event-data overrides.
	 *
	 * @param string               $type      Provider event type.
	 * @param array<string, mixed> $overrides Event data overrides.
	 * @param string|null          $account   Optional connected-account field.
	 * @return string
	 */
	private function event_body( string $type, array $overrides = array(), ?string $account = null ): string {
		$data = array_merge(
			array(
				'charge_id'   => 'ch_123',
				'checkout_id' => 'chk_123',
				'reference'   => 'ref_123',
				'status'      => 'succeeded',
				'amount'      => '42.00',
				'currency'    => 'USD',
			),
			$overrides
		);

		$envelope = array(
			'id'              => 'evt_123',
			'type'            => $type,
			'created_at'      => '2026-09-22T12:00:00Z',
			'organization_id' => 'acct_123',
			'data'            => $data,
		);

		if ( null !== $account ) {
			$envelope['account'] = $account;
		}

		// Pure PHPUnit fixture generation intentionally uses the native JSON encoder.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return (string) json_encode( $envelope, JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Build signature evidence bound to a raw body.
	 *
	 * @param string $body Raw webhook body.
	 * @return VerifiedWebhookSignature
	 */
	private function signature_for( string $body ): VerifiedWebhookSignature {
		return new VerifiedWebhookSignature( 1750000000, hash( 'sha256', $body ) );
	}
}
