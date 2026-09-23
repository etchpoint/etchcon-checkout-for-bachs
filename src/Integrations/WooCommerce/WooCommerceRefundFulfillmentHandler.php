<?php
/**
 * WooCommerce verified-refund fulfillment.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\WooCommerce;

use Etchpoint\BachsIntegrations\Core\Contracts\RefundFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Refund\RefundApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Refund\RefundFulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Refund\VerifiedRefund;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\RefundRepository;
use Throwable;
use WC_Order;
use WC_Order_Refund;
use WP_Error;

/**
 * Records provider-confirmed refunds through WooCommerce without refunding twice at the gateway.
 */
final class WooCommerceRefundFulfillmentHandler implements RefundFulfillmentHandler {
	/** WooCommerce integration identifier. */
	private const INTEGRATION = 'woocommerce';

	/**
	 * Refund repository.
	 *
	 * @var RefundRepository
	 */
	private RefundRepository $refunds;

	/**
	 * Event inbox repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Create the handler.
	 *
	 * @param RefundRepository $refunds Refund repository.
	 * @param EventRepository  $events  Event inbox repository.
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
	 * Apply a provider-confirmed refund to a WooCommerce order.
	 *
	 * @param int            $refund_id Refund row identifier.
	 * @param int            $event_id  Event row identifier.
	 * @param VerifiedRefund $refund    Verified refund evidence.
	 * @return RefundFulfillmentDisposition
	 */
	public function fulfill( int $refund_id, int $event_id, VerifiedRefund $refund ): RefundFulfillmentDisposition {
		if ( self::INTEGRATION !== $refund->integration() || 'order' !== $refund->local_object_type() ) {
			return $this->requires_review( $refund_id, $event_id, 'woo_refund_target_mismatch', 'Verified refund does not target a WooCommerce order.' );
		}

		$record = $this->refunds->find_by_id( $refund_id );
		if ( null === $record || null === $record->provider_refund_id() || ! hash_equals( $record->provider_refund_id(), $refund->provider_refund_id() ) ) {
			return $this->requires_review( $refund_id, $event_id, 'woo_refund_correlation_failed', 'WooCommerce refund correlation failed.' );
		}

		if ( RefundApplicationStatus::APPLIED === $record->application_status() ) {
			$this->events->mark_processed( $event_id );
			return RefundFulfillmentDisposition::DUPLICATE;
		}

		if ( ! $this->refunds->claim_application_processing( $refund_id ) ) {
			$record = $this->refunds->find_by_id( $refund_id );
			if ( null !== $record && RefundApplicationStatus::APPLIED === $record->application_status() ) {
				$this->events->mark_processed( $event_id );
				return RefundFulfillmentDisposition::DUPLICATE;
			}
			$this->events->mark_failed( $event_id, 'woo_refund_claim_busy', 'WooCommerce refund application could not be claimed.' );
			return RefundFulfillmentDisposition::RETRYABLE_FAILURE;
		}

		$order = wc_get_order( (int) $refund->local_object_id() );
		if ( ! $order instanceof WC_Order || ! hash_equals( $order->get_transaction_id(), $refund->charge_id() ) ) {
			return $this->requires_review( $refund_id, $event_id, 'woo_refund_order_mismatch', 'WooCommerce order or transaction does not match the Bachs refund.' );
		}

		$marker = 'Bachs refund ' . $refund->provider_refund_id();
		foreach ( $order->get_refunds() as $existing_refund ) {
			if ( $existing_refund instanceof WC_Order_Refund && str_contains( $existing_refund->get_reason(), $marker ) ) {
				return $this->finalize( $refund_id, $event_id, (string) $existing_refund->get_id(), RefundFulfillmentDisposition::DUPLICATE );
			}
		}

		$reason = $marker;
		if ( null !== $refund->reason() && '' !== $refund->reason() ) {
			$reason .= ': ' . $refund->reason();
		}

		try {
			$local_refund = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => $refund->refunded_amount()->amount(),
					'reason'         => $reason,
					'refund_payment' => false,
					'restock_items'  => false,
				)
			);
		} catch ( Throwable ) {
			return $this->retryable_failure( $refund_id, $event_id, 'woo_refund_create_failed', 'WooCommerce could not record the provider-confirmed refund.' );
		}

		if ( $local_refund instanceof WP_Error ) {
			return $this->retryable_failure( $refund_id, $event_id, 'woo_refund_create_failed', 'WooCommerce could not record the provider-confirmed refund.' );
		}

		$order->add_order_note( __( 'Bachs refund confirmed by signed webhook and recorded locally.', 'payment-integrations-for-bachs' ) );

		return $this->finalize( $refund_id, $event_id, (string) $local_refund->get_id(), RefundFulfillmentDisposition::APPLIED );
	}

	/**
	 * Finalize a successful or duplicate local refund.
	 *
	 * @param int                          $refund_id       Refund ID.
	 * @param int                          $event_id        Event ID.
	 * @param string                       $local_refund_id Host refund ID.
	 * @param RefundFulfillmentDisposition $disposition     Outcome.
	 * @return RefundFulfillmentDisposition
	 */
	private function finalize( int $refund_id, int $event_id, string $local_refund_id, RefundFulfillmentDisposition $disposition ): RefundFulfillmentDisposition {
		if ( ! $this->refunds->mark_applied( $refund_id, $local_refund_id ) ) {
			$this->events->mark_failed( $event_id, 'woo_refund_finalize_failed', 'WooCommerce refund exists but local refund state could not be finalized.' );
			return RefundFulfillmentDisposition::RETRYABLE_FAILURE;
		}
		if ( ! $this->events->mark_processed( $event_id ) ) {
			$this->events->mark_failed( $event_id, 'woo_refund_event_finalize_failed', 'WooCommerce refund was recorded but webhook finalization failed.' );
			return RefundFulfillmentDisposition::RETRYABLE_FAILURE;
		}
		return $disposition;
	}

	/**
	 * Put a claimed refund into manual review.
	 *
	 * @param int    $refund_id Refund ID.
	 * @param int    $event_id  Event ID.
	 * @param string $code      Code.
	 * @param string $message   Message.
	 * @return RefundFulfillmentDisposition
	 */
	private function requires_review( int $refund_id, int $event_id, string $code, string $message ): RefundFulfillmentDisposition {
		$this->refunds->mark_application_requires_review( $refund_id, $code, $message );
		$this->events->mark_requires_review( $event_id, $code, $message );
		return RefundFulfillmentDisposition::REQUIRES_REVIEW;
	}

	/**
	 * Mark a claimed refund retryable.
	 *
	 * @param int    $refund_id Refund ID.
	 * @param int    $event_id  Event ID.
	 * @param string $code      Code.
	 * @param string $message   Message.
	 * @return RefundFulfillmentDisposition
	 */
	private function retryable_failure( int $refund_id, int $event_id, string $code, string $message ): RefundFulfillmentDisposition {
		$this->refunds->mark_application_failed( $refund_id, $code, $message );
		$this->events->mark_failed( $event_id, $code, $message );
		return RefundFulfillmentDisposition::RETRYABLE_FAILURE;
	}
}
