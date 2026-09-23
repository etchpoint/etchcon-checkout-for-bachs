<?php
/**
 * Fluent Forms verified-refund fulfillment.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\FluentForms;

use Etchpoint\BachsIntegrations\Core\Contracts\RefundFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Refund\RefundApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Refund\RefundFulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Refund\VerifiedRefund;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\RefundRepository;
use Throwable;

/**
 * Applies a provider-confirmed refund through Fluent Forms' payment processor API.
 */
final class FluentFormsRefundFulfillmentHandler implements RefundFulfillmentHandler {
	/** Fluent Forms integration identifier. */
	private const INTEGRATION = 'fluent_forms';

	/**
	 * Refund repository.
	 *
	 * @var RefundRepository
	 */
	private RefundRepository $refunds;

	/**
	 * Event repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Create the handler.
	 *
	 * @param RefundRepository $refunds Refund repository.
	 * @param EventRepository  $events  Event repository.
	 */
	public function __construct( RefundRepository $refunds, EventRepository $events ) {
		$this->refunds = $refunds;
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
	 * Apply the refund to a Fluent Forms submission.
	 *
	 * @param int            $refund_id Refund row identifier.
	 * @param int            $event_id  Event row identifier.
	 * @param VerifiedRefund $refund    Verified refund evidence.
	 * @return RefundFulfillmentDisposition
	 */
	public function fulfill( int $refund_id, int $event_id, VerifiedRefund $refund ): RefundFulfillmentDisposition {
		if ( self::INTEGRATION !== $refund->integration() || 'submission' !== $refund->local_object_type() ) {
			return $this->review( $refund_id, $event_id, 'fluent_forms_refund_target_mismatch', 'Verified refund does not target a Fluent Forms submission.' );
		}
		$record = $this->refunds->find_by_id( $refund_id );
		if ( null === $record || null === $record->provider_refund_id() || ! hash_equals( $record->provider_refund_id(), $refund->provider_refund_id() ) ) {
			return $this->review( $refund_id, $event_id, 'fluent_forms_refund_correlation_failed', 'Fluent Forms refund correlation failed.' );
		}
		if ( RefundApplicationStatus::APPLIED === $record->application_status() ) {
			$this->events->mark_processed( $event_id );
			return RefundFulfillmentDisposition::DUPLICATE;
		}
		if ( ! $this->refunds->claim_application_processing( $refund_id ) ) {
			$this->events->mark_failed( $event_id, 'fluent_forms_refund_claim_busy', 'Fluent Forms refund application could not be claimed.' );
			return RefundFulfillmentDisposition::RETRYABLE_FAILURE;
		}

		try {
			$applied = ( new FluentFormsProcessor() )->fulfill_verified_refund( (int) $refund->local_object_id(), $refund );
		} catch ( Throwable ) {
			$this->refunds->mark_application_requires_review( $refund_id, 'fluent_forms_refund_apply_failed', 'Fluent Forms could not apply the provider-confirmed refund.' );
			$this->events->mark_requires_review( $event_id, 'fluent_forms_refund_apply_failed', 'Fluent Forms could not apply the provider-confirmed refund.' );
			return RefundFulfillmentDisposition::REQUIRES_REVIEW;
		}

		if ( ! $this->refunds->mark_applied( $refund_id ) || ! $this->events->mark_processed( $event_id ) ) {
			return RefundFulfillmentDisposition::RETRYABLE_FAILURE;
		}

		return $applied ? RefundFulfillmentDisposition::APPLIED : RefundFulfillmentDisposition::DUPLICATE;
	}

	/**
	 * Put local application in review.
	 *
	 * @param int    $refund_id Refund ID.
	 * @param int    $event_id  Event ID.
	 * @param string $code      Code.
	 * @param string $message   Message.
	 * @return RefundFulfillmentDisposition
	 */
	private function review( int $refund_id, int $event_id, string $code, string $message ): RefundFulfillmentDisposition {
		$this->refunds->mark_application_requires_review( $refund_id, $code, $message );
		$this->events->mark_requires_review( $event_id, $code, $message );
		return RefundFulfillmentDisposition::REQUIRES_REVIEW;
	}
}
