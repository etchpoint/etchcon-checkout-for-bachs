<?php
/**
 * Gravity Forms verified-refund fulfillment.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\GravityForms;

use Etchpoint\BachsIntegrations\Core\Contracts\RefundFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Refund\RefundApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Refund\RefundFulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Refund\VerifiedRefund;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\RefundRepository;
use GFAPI;
use Throwable;
use WP_Error;

/**
 * Reflects a confirmed Bachs refund on a Gravity Forms entry.
 */
final class GravityFormsRefundFulfillmentHandler implements RefundFulfillmentHandler {
	/** Gravity Forms integration identifier. */
	private const INTEGRATION = 'gravity_forms';

	/** Refund marker entry meta key. */
	private const REFUND_META = '_etchpoint_bachs_refund_id';

	/** Refund amount entry meta key. */
	private const REFUND_AMOUNT_META = '_etchpoint_bachs_refund_amount';

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
	 * Apply the refund to a Gravity Forms entry.
	 *
	 * @param int            $refund_id Refund row identifier.
	 * @param int            $event_id  Event row identifier.
	 * @param VerifiedRefund $refund    Verified refund evidence.
	 * @return RefundFulfillmentDisposition
	 */
	public function fulfill( int $refund_id, int $event_id, VerifiedRefund $refund ): RefundFulfillmentDisposition {
		if ( self::INTEGRATION !== $refund->integration() || 'entry' !== $refund->local_object_type() ) {
			return $this->review( $refund_id, $event_id, 'gravity_forms_refund_target_mismatch', 'Verified refund does not target a Gravity Forms entry.' );
		}
		$record = $this->refunds->find_by_id( $refund_id );
		if ( null === $record || null === $record->provider_refund_id() || ! hash_equals( $record->provider_refund_id(), $refund->provider_refund_id() ) ) {
			return $this->review( $refund_id, $event_id, 'gravity_forms_refund_correlation_failed', 'Gravity Forms refund correlation failed.' );
		}
		if ( RefundApplicationStatus::APPLIED === $record->application_status() ) {
			$this->events->mark_processed( $event_id );
			return RefundFulfillmentDisposition::DUPLICATE;
		}
		if ( ! $this->refunds->claim_application_processing( $refund_id ) ) {
			$this->events->mark_failed( $event_id, 'gravity_forms_refund_claim_busy', 'Gravity Forms refund application could not be claimed.' );
			return RefundFulfillmentDisposition::RETRYABLE_FAILURE;
		}

		$entry_id = (int) $refund->local_object_id();
		$entry    = GFAPI::get_entry( $entry_id );
		if ( $entry instanceof WP_Error || (int) ( $entry['id'] ?? 0 ) !== $entry_id ) {
			return $this->review( $refund_id, $event_id, 'gravity_forms_refund_entry_missing', 'Gravity Forms entry could not be found.' );
		}
		$transaction_id = $entry['transaction_id'] ?? null;
		if ( ! is_string( $transaction_id ) || ! hash_equals( $transaction_id, $refund->charge_id() ) ) {
			return $this->review( $refund_id, $event_id, 'gravity_forms_refund_transaction_mismatch', 'Gravity Forms transaction does not match the Bachs refund.' );
		}

		$existing = gform_get_meta( $entry_id, self::REFUND_META );
		if ( is_string( $existing ) && '' !== $existing ) {
			if ( ! hash_equals( $existing, $refund->provider_refund_id() ) ) {
				return $this->review( $refund_id, $event_id, 'gravity_forms_refund_marker_conflict', 'Gravity Forms entry already contains a different refund marker.' );
			}
			return $this->finalize( $refund_id, $event_id, RefundFulfillmentDisposition::DUPLICATE );
		}

		try {
			if ( $refund->is_full_refund() && ! GFAPI::update_entry_property( $entry_id, 'payment_status', 'Refunded' ) ) {
				return $this->failed( $refund_id, $event_id, 'gravity_forms_refund_status_failed', 'Gravity Forms could not update the entry refund status.' );
			}

			$form_id = (int) ( $entry['form_id'] ?? 0 );
			gform_update_meta( $entry_id, self::REFUND_META, $refund->provider_refund_id(), $form_id );
			gform_update_meta( $entry_id, self::REFUND_AMOUNT_META, $refund->refunded_amount()->amount(), $form_id );
			GFAPI::add_note(
				$entry_id,
				0,
				'Bachs',
				sprintf(
					/* translators: 1: refund amount, 2: currency code, 3: Bachs refund ID. */
					__( 'Bachs refund confirmed: %1$s %2$s (%3$s).', 'payment-integrations-for-bachs' ),
					$refund->refunded_amount()->amount(),
					$refund->refunded_amount()->currency()->code(),
					$refund->provider_refund_id()
				),
				'bachs'
			);
		} catch ( Throwable ) {
			return $this->failed( $refund_id, $event_id, 'gravity_forms_refund_apply_failed', 'Gravity Forms could not apply the provider-confirmed refund.' );
		}

		return $this->finalize( $refund_id, $event_id, RefundFulfillmentDisposition::APPLIED );
	}

	/**
	 * Finalize local refund state.
	 *
	 * @param int                          $refund_id Refund ID.
	 * @param int                          $event_id  Event ID.
	 * @param RefundFulfillmentDisposition $result    Outcome.
	 * @return RefundFulfillmentDisposition
	 */
	private function finalize( int $refund_id, int $event_id, RefundFulfillmentDisposition $result ): RefundFulfillmentDisposition {
		if ( ! $this->refunds->mark_applied( $refund_id ) || ! $this->events->mark_processed( $event_id ) ) {
			return RefundFulfillmentDisposition::RETRYABLE_FAILURE;
		}
		return $result;
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

	/**
	 * Put local application in retryable failure.
	 *
	 * @param int    $refund_id Refund ID.
	 * @param int    $event_id  Event ID.
	 * @param string $code      Code.
	 * @param string $message   Message.
	 * @return RefundFulfillmentDisposition
	 */
	private function failed( int $refund_id, int $event_id, string $code, string $message ): RefundFulfillmentDisposition {
		$this->refunds->mark_application_failed( $refund_id, $code, $message );
		$this->events->mark_failed( $event_id, $code, $message );
		return RefundFulfillmentDisposition::RETRYABLE_FAILURE;
	}
}
