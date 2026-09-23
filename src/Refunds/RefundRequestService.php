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

	// These exception messages are internal diagnostics and are never rendered as HTML output.
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

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
			throw self::request_exception( 'Payment intent was not found.', RefundRequestException::INVALID_REQUEST );
		}

		$intent    = $intent_record->intent();
		$charge_id = $intent_record->charge_id();

		if (
			ProviderStatus::SUCCEEDED !== $intent->provider_status()
			|| ApplicationStatus::APPLIED !== $intent->application_status()
			|| null === $charge_id
		) {
			throw self::request_exception( 'Only completed Bachs payments can be refunded.', RefundRequestException::INVALID_REQUEST );
		}

		$existing = $this->refunds->find_by_charge_id( $charge_id );

		if ( null !== $existing ) {
			if ( RefundStatus::REQUESTED === $existing->provider_status() && null === $existing->provider_refund_id() ) {
				return $this->submit_existing( $existing );
			}

			throw self::request_exception( 'This Bachs charge already has a refund operation.', RefundRequestException::ALREADY_REQUESTED );
		}

		try {
			$refund_amount = Money::from_decimal( $amount, $intent->expected_amount()->currency() );
		} catch ( InvalidArgumentException ) {
			throw self::request_exception( 'Refund amount is invalid.', RefundRequestException::INVALID_REQUEST );
		}

		if ( ! $refund_amount->is_positive() || ! $refund_amount->is_less_than_or_equal( $intent->expected_amount() ) ) {
			throw self::request_exception( 'Refund amount must be positive and no greater than the original payment.', RefundRequestException::INVALID_REQUEST );
		}

		$provider_payment = $this->payments->get( $charge_id );

		if (
			! hash_equals( $charge_id, $provider_payment->payment_id() )
			|| ! in_array( $provider_payment->status(), self::SUCCESSFUL_PAYMENT_STATUSES, true )
			|| ! hash_equals( $intent->expected_amount()->currency()->code(), $provider_payment->currency() )
		) {
			throw self::request_exception( 'Authoritative Bachs payment state does not match the local payment.', RefundRequestException::REQUIRES_REVIEW );
		}

		if ( true !== $provider_payment->is_refundable() ) {
			throw self::request_exception( 'Bachs reports that this payment is not currently refundable.', RefundRequestException::INVALID_REQUEST );
		}

		try {
			$provider_amount = Money::from_decimal( $provider_payment->amount(), $intent->expected_amount()->currency() );
		} catch ( InvalidArgumentException ) {
			throw self::request_exception( 'Bachs returned an invalid original payment amount.', RefundRequestException::REQUIRES_REVIEW );
		}

		if ( ! $provider_amount->equals( $intent->expected_amount() ) ) {
			throw self::request_exception( 'Authoritative Bachs payment amount does not match the local payment.', RefundRequestException::REQUIRES_REVIEW );
		}

		if ( null !== $intent_record->checkout_id() && null !== $provider_payment->checkout_id() && ! hash_equals( $intent_record->checkout_id(), $provider_payment->checkout_id() ) ) {
			throw self::request_exception( 'Bachs payment checkout correlation does not match.', RefundRequestException::REQUIRES_REVIEW );
		}

		if ( null !== $provider_payment->reference() && ! hash_equals( $intent->reference(), $provider_payment->reference() ) ) {
			throw self::request_exception( 'Bachs payment reference correlation does not match.', RefundRequestException::REQUIRES_REVIEW );
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
			throw self::request_exception( 'Refund request could not be reloaded after persistence.', RefundRequestException::RETRYABLE );
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
			throw self::request_exception( 'Refund no longer has a valid original payment intent.', RefundRequestException::REQUIRES_REVIEW );
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

			throw self::request_exception(
				'Bachs could not accept the refund request.',
				$exception->is_retryable() ? RefundRequestException::RETRYABLE : RefundRequestException::INVALID_REQUEST
			);
		}

		$this->assert_provider_refund_matches( $record, $provider_refund );
		$status = self::provider_status( $provider_refund->status() );

		if ( ! $this->refunds->attach_provider_refund( $record->id(), $provider_refund->refund_id(), $status, $provider_refund->refunded_amount() ) ) {
			throw self::request_exception( 'Provider refund was created but local state could not be updated.', RefundRequestException::RETRYABLE );
		}

		$updated = $this->refunds->find_by_id( $record->id() );

		if ( null === $updated ) {
			throw self::request_exception( 'Refund state could not be reloaded.', RefundRequestException::RETRYABLE );
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
			throw self::request_exception( 'Bachs refund response did not match the persisted request.', RefundRequestException::REQUIRES_REVIEW );
		}
	}

	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

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
	 * Create an internal refund request exception.
	 *
	 * @param string $message  Safe diagnostic message.
	 * @param string $category Stable exception category.
	 * @return RefundRequestException
	 */
	private static function request_exception( string $message, string $category ): RefundRequestException {
		// Refund diagnostics are internal exception data, not rendered output.
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		$exception = new RefundRequestException( $message, $category );
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

		return $exception;
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
