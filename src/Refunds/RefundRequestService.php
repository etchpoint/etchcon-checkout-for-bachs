<?php
/**
 * Bachs refund request service.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Refunds;

use Etchpoint\BachsIntegrations\Bachs\ApiException;
use Etchpoint\BachsIntegrations\Bachs\PaymentsApi;
use Etchpoint\BachsIntegrations\Bachs\ProviderRefund;
use Etchpoint\BachsIntegrations\Bachs\RefundsApi;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\ProviderStatus;
use Etchpoint\BachsIntegrations\Core\Refund\RefundStatus;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Etchpoint\BachsIntegrations\Persistence\RefundRecord;
use Etchpoint\BachsIntegrations\Persistence\RefundRepository;
use InvalidArgumentException;

/**
 * Validates and creates one idempotent Bachs refund operation per charge.
 */
final class RefundRequestService {
	/** Provider payment states that represent final successful collection. */
	private const SUCCESSFUL_PAYMENT_STATUSES = array( 'succeeded', 'accepted', 'overpaid' );

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
	 * Authoritative payments API.
	 *
	 * @var PaymentsApi
	 */
	private PaymentsApi $payments;

	/**
	 * Refunds API.
	 *
	 * @var RefundsApi
	 */
	private RefundsApi $api;

	/**
	 * Create the service.
	 *
	 * @param IntentRepository $intents  Payment intent repository.
	 * @param RefundRepository $refunds  Refund repository.
	 * @param PaymentsApi      $payments Authoritative payments API.
	 * @param RefundsApi       $api      Refunds API.
	 */
	public function __construct(
		IntentRepository $intents,
		RefundRepository $refunds,
		PaymentsApi $payments,
		RefundsApi $api
	) {
		$this->intents  = $intents;
		$this->refunds  = $refunds;
		$this->payments = $payments;
		$this->api      = $api;
	}

	/**
	 * Request a full or partial refund for one completed payment intent.
	 *
	 * @param int         $intent_id Intent row identifier.
	 * @param string      $amount    Refund amount as a plain decimal string.
	 * @param string|null $reason    Optional merchant reason.
	 * @return RefundRecord
	 *
	 * @throws RefundRequestException When validation or provider submission fails.
	 */
	public function request( int $intent_id, string $amount, ?string $reason = null ): RefundRecord {
		$intent_record = $this->intents->find_by_id( $intent_id );

		if ( null === $intent_record ) {
			throw new RefundRequestException( RefundRequestException::INVALID_REQUEST, 'Payment intent was not found.' );
		}

		$intent    = $intent_record->intent();
		$charge_id = $intent_record->charge_id();

		if (
			ProviderStatus::SUCCEEDED !== $intent->provider_status()
			|| ApplicationStatus::APPLIED !== $intent->application_status()
			|| null === $charge_id
		) {
			throw new RefundRequestException( RefundRequestException::INVALID_REQUEST, 'Only completed Bachs payments can be refunded.' );
		}

		$existing = $this->refunds->find_by_charge_id( $charge_id );

		if ( null !== $existing ) {
			if ( RefundStatus::REQUESTED === $existing->provider_status() && null === $existing->provider_refund_id() ) {
				return $this->submit_existing( $existing );
			}

			throw new RefundRequestException( RefundRequestException::ALREADY_REQUESTED, 'This Bachs charge already has a refund operation.' );
		}

		try {
			$refund_amount = Money::from_decimal( $amount, $intent->expected_amount()->currency() );
		} catch ( InvalidArgumentException ) {
			throw new RefundRequestException( RefundRequestException::INVALID_REQUEST, 'Refund amount is invalid.' );
		}

		if ( ! $refund_amount->is_positive() || ! $refund_amount->is_less_than_or_equal( $intent->expected_amount() ) ) {
			throw new RefundRequestException( RefundRequestException::INVALID_REQUEST, 'Refund amount must be positive and no greater than the original payment.' );
		}

		$provider_payment = $this->payments->get( $charge_id );

		if (
			! hash_equals( $charge_id, $provider_payment->payment_id() )
			|| ! in_array( $provider_payment->status(), self::SUCCESSFUL_PAYMENT_STATUSES, true )
			|| ! hash_equals( $intent->expected_amount()->currency()->code(), $provider_payment->currency() )
		) {
			throw new RefundRequestException( RefundRequestException::REQUIRES_REVIEW, 'Authoritative Bachs payment state does not match the local payment.' );
		}

		try {
			$provider_amount = Money::from_decimal( $provider_payment->amount(), $intent->expected_amount()->currency() );
		} catch ( InvalidArgumentException ) {
			throw new RefundRequestException( RefundRequestException::REQUIRES_REVIEW, 'Bachs returned an invalid original payment amount.' );
		}

		if ( ! $provider_amount->equals( $intent->expected_amount() ) ) {
			throw new RefundRequestException( RefundRequestException::REQUIRES_REVIEW, 'Authoritative Bachs payment amount does not match the local payment.' );
		}

		if ( null !== $intent_record->checkout_id() && null !== $provider_payment->checkout_id() && ! hash_equals( $intent_record->checkout_id(), $provider_payment->checkout_id() ) ) {
			throw new RefundRequestException( RefundRequestException::REQUIRES_REVIEW, 'Bachs payment checkout correlation does not match.' );
		}

		if ( null !== $provider_payment->reference() && ! hash_equals( $intent->reference(), $provider_payment->reference() ) ) {
			throw new RefundRequestException( RefundRequestException::REQUIRES_REVIEW, 'Bachs payment reference correlation does not match.' );
		}

		$uuid            = wp_generate_uuid4();
		$compact_uuid    = str_replace( '-', '', $uuid );
		$reference       = 'wp-refund-' . $compact_uuid;
		$idempotency_key = 'wp-refund-' . $compact_uuid;
		$reason          = self::normalize_reason( $reason );
		$refund_id       = $this->refunds->create(
			$uuid,
			$intent_record,
			$charge_id,
			$reference,
			$idempotency_key,
			$refund_amount,
			$reason
		);
		$record          = $this->refunds->find_by_id( $refund_id );

		if ( null === $record ) {
			throw new RefundRequestException( RefundRequestException::RETRYABLE, 'Refund request could not be reloaded after persistence.' );
		}

		return $this->submit_existing( $record );
	}

	/**
	 * Submit a pre-persisted logical refund using its stable idempotency key.
	 *
	 * @param RefundRecord $record Refund record.
	 * @return RefundRecord
	 *
	 * @throws RefundRequestException When Bachs rejects or cannot process the request.
	 */
	private function submit_existing( RefundRecord $record ): RefundRecord {
		$intent = $this->intents->find_by_id( $record->intent_id() );

		if ( null === $intent ) {
			throw new RefundRequestException( RefundRequestException::REQUIRES_REVIEW, 'Refund no longer has a valid original payment intent.' );
		}

		$full_refund = $record->requested_amount()->equals( $intent->intent()->expected_amount() );
		$amount      = $full_refund ? null : $record->requested_amount()->amount();

		try {
			$provider_refund = $this->api->create(
				$record->charge_id(),
				$record->reference(),
				$record->idempotency_key(),
				$amount,
				$record->reason()
			);
		} catch ( ApiException $exception ) {
			$status = $exception->is_retryable() ? RefundStatus::REQUESTED : RefundStatus::FAILED;
			$code   = $exception->is_retryable() ? 'provider_retryable' : 'provider_rejected';
			$this->refunds->mark_request_failure( $record->id(), $status, $code, 'Bachs could not accept the refund request.' );

			throw new RefundRequestException(
				$exception->is_retryable() ? RefundRequestException::RETRYABLE : RefundRequestException::INVALID_REQUEST,
				'Bachs could not accept the refund request.'
			);
		}

		$this->assert_provider_refund_matches( $record, $provider_refund );
		$status = self::provider_status( $provider_refund->status() );

		if ( ! $this->refunds->attach_provider_refund( $record->id(), $provider_refund->refund_id(), $status, $provider_refund->refunded_amount() ) ) {
			throw new RefundRequestException( RefundRequestException::RETRYABLE, 'Provider refund was created but local state could not be updated.' );
		}

		$updated = $this->refunds->find_by_id( $record->id() );

		if ( null === $updated ) {
			throw new RefundRequestException( RefundRequestException::RETRYABLE, 'Refund state could not be reloaded.' );
		}

		return $updated;
	}

	/**
	 * Validate create-response correlation before storing its provider identifier.
	 *
	 * @param RefundRecord   $record          Local refund record.
	 * @param ProviderRefund $provider_refund Provider response.
	 * @return void
	 *
	 * @throws RefundRequestException When the response does not match the request.
	 */
	private function assert_provider_refund_matches( RefundRecord $record, ProviderRefund $provider_refund ): void {
		if (
			! hash_equals( $record->charge_id(), $provider_refund->charge_id() )
			|| ! hash_equals( $record->reference(), $provider_refund->reference() )
			|| ! hash_equals( $record->requested_amount()->amount(), $provider_refund->requested_amount() )
		) {
			$this->refunds->mark_request_failure( $record->id(), RefundStatus::REQUIRES_REVIEW, 'provider_correlation_mismatch', 'Bachs refund response did not match the persisted request.' );
			throw new RefundRequestException( RefundRequestException::REQUIRES_REVIEW, 'Bachs refund response did not match the persisted request.' );
		}
	}

	/**
	 * Normalize provider refund states.
	 *
	 * @param string $status Raw Bachs refund status.
	 * @return RefundStatus
	 */
	private static function provider_status( string $status ): RefundStatus {
		return match ( $status ) {
			'processing' => RefundStatus::PROCESSING,
			'success'    => RefundStatus::SUCCEEDED,
			'failed'     => RefundStatus::FAILED,
			default      => RefundStatus::REQUIRES_REVIEW,
		};
	}

	/**
	 * Normalize and bound an administrator-entered refund reason.
	 *
	 * @param string|null $reason Raw reason.
	 * @return string|null
	 */
	private static function normalize_reason( ?string $reason ): ?string {
		if ( null === $reason ) {
			return null;
		}

		$reason = trim( $reason );

		if ( '' === $reason ) {
			return null;
		}

		return substr( $reason, 0, 500 );
	}
}
