<?php
/**
 * Paid Memberships Pro verified-refund fulfillment.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\PMPro;

use Etchpoint\BachsIntegrations\Core\Contracts\RefundFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Refund\RefundApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Refund\RefundFulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Refund\VerifiedRefund;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\RefundRepository;
use MemberOrder;
use Throwable;

/**
 * Reflects a confirmed Bachs refund on its Paid Memberships Pro order.
 */
final class PMProRefundFulfillmentHandler implements RefundFulfillmentHandler {
	/** PMPro integration identifier. */
	private const INTEGRATION = 'pmpro';

	/** Provider refund marker key. */
	private const REFUND_META = '_etchpoint_bachs_refund_id';

	/** Provider refund amount key. */
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
	 * Apply the refund to the PMPro order.
	 *
	 * @param int            $refund_id Refund row identifier.
	 * @param int            $event_id  Event row identifier.
	 * @param VerifiedRefund $refund    Verified refund evidence.
	 * @return RefundFulfillmentDisposition
	 */
	public function fulfill( int $refund_id, int $event_id, VerifiedRefund $refund ): RefundFulfillmentDisposition {
		if ( self::INTEGRATION !== $refund->integration() || 'order' !== $refund->local_object_type() ) {
			return $this->review( $refund_id, $event_id, 'pmpro_refund_target_mismatch', 'Verified refund does not target a PMPro order.' );
		}

		$record = $this->refunds->find_by_id( $refund_id );
		if ( null === $record || null === $record->provider_refund_id() || ! hash_equals( $record->provider_refund_id(), $refund->provider_refund_id() ) ) {
			return $this->review( $refund_id, $event_id, 'pmpro_refund_correlation_failed', 'PMPro refund correlation failed.' );
		}
		if ( RefundApplicationStatus::APPLIED === $record->application_status() ) {
			$this->events->mark_processed( $event_id );
			return RefundFulfillmentDisposition::DUPLICATE;
		}
		if ( ! $this->refunds->claim_application_processing( $refund_id ) ) {
			$this->events->mark_failed( $event_id, 'pmpro_refund_claim_busy', 'PMPro refund application could not be claimed.' );
			return RefundFulfillmentDisposition::RETRYABLE_FAILURE;
		}

		$order = new MemberOrder( (int) $refund->local_object_id() );
		if ( 1 > (int) $order->id || 'bachs' !== (string) $order->gateway || ! hash_equals( (string) $order->payment_transaction_id, $refund->charge_id() ) ) {
			return $this->review( $refund_id, $event_id, 'pmpro_refund_order_mismatch', 'PMPro order or transaction does not match the Bachs refund.' );
		}

		$existing = (string) get_pmpro_membership_order_meta( (int) $order->id, self::REFUND_META, true );
		if ( '' !== $existing ) {
			if ( ! hash_equals( $existing, $refund->provider_refund_id() ) ) {
				return $this->review( $refund_id, $event_id, 'pmpro_refund_marker_conflict', 'PMPro order already contains a different refund marker.' );
			}
			return $this->finalize( $refund_id, $event_id, RefundFulfillmentDisposition::DUPLICATE );
		}

		try {
			if ( $refund->is_full_refund() ) {
				$order->status = 'refunded';
				if ( ! $order->saveOrder() ) {
					return $this->failed( $refund_id, $event_id, 'pmpro_refund_save_failed', 'PMPro could not save the refunded order status.' );
				}
			}
			if ( false === update_pmpro_membership_order_meta( (int) $order->id, self::REFUND_META, $refund->provider_refund_id() ) ) {
				return $this->review( $refund_id, $event_id, 'pmpro_refund_marker_failed', 'PMPro refund marker could not be stored.' );
			}
			update_pmpro_membership_order_meta( (int) $order->id, self::REFUND_AMOUNT_META, $refund->refunded_amount()->amount() );
		} catch ( Throwable ) {
			return $this->failed( $refund_id, $event_id, 'pmpro_refund_apply_failed', 'PMPro could not apply the provider-confirmed refund.' );
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
