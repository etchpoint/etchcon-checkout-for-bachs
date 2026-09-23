<?php
/**
 * GiveWP verified-refund fulfillment.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\GiveWP;

use Etchpoint\BachsIntegrations\Core\Contracts\RefundFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Refund\RefundApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Refund\RefundFulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Refund\VerifiedRefund;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\RefundRepository;
use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Throwable;

/**
 * Reflects a provider-confirmed refund on a GiveWP donation.
 */
final class GiveWPRefundFulfillmentHandler implements RefundFulfillmentHandler {
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- GiveWP model properties are camelCase.
	/** GiveWP integration identifier. */
	private const INTEGRATION = 'givewp';

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
	 * Apply the refund to a GiveWP donation.
	 *
	 * @param int            $refund_id Refund row identifier.
	 * @param int            $event_id  Event row identifier.
	 * @param VerifiedRefund $refund    Verified refund evidence.
	 * @return RefundFulfillmentDisposition
	 */
	public function fulfill( int $refund_id, int $event_id, VerifiedRefund $refund ): RefundFulfillmentDisposition {
		if ( self::INTEGRATION !== $refund->integration() || 'donation' !== $refund->local_object_type() ) {
			return $this->review( $refund_id, $event_id, 'givewp_refund_target_mismatch', 'Verified refund does not target a GiveWP donation.' );
		}
		$record = $this->refunds->find_by_id( $refund_id );
		if ( null === $record || null === $record->provider_refund_id() || ! hash_equals( $record->provider_refund_id(), $refund->provider_refund_id() ) ) {
			return $this->review( $refund_id, $event_id, 'givewp_refund_correlation_failed', 'GiveWP refund correlation failed.' );
		}
		if ( RefundApplicationStatus::APPLIED === $record->application_status() ) {
			$this->events->mark_processed( $event_id );
			return RefundFulfillmentDisposition::DUPLICATE;
		}
		if ( ! $this->refunds->claim_application_processing( $refund_id ) ) {
			$this->events->mark_failed( $event_id, 'givewp_refund_claim_busy', 'GiveWP refund application could not be claimed.' );
			return RefundFulfillmentDisposition::RETRYABLE_FAILURE;
		}

		try {
			$donation = Donation::find( (int) $refund->local_object_id() );
			if ( ! $donation instanceof Donation || GiveWPGateway::id() !== $donation->gatewayId || ! hash_equals( $donation->gatewayTransactionId, $refund->charge_id() ) ) {
				return $this->review( $refund_id, $event_id, 'givewp_refund_donation_mismatch', 'GiveWP donation or transaction does not match the Bachs refund.' );
			}

			if ( $refund->is_full_refund() ) {
				if ( $donation->status->isRefunded() ) {
					return $this->finalize( $refund_id, $event_id, RefundFulfillmentDisposition::DUPLICATE );
				}
				$donation->status = DonationStatus::REFUNDED();
				$donation->save();
			}

			DonationNote::create(
				array(
					'donationId' => (int) $donation->id,
					'content'    => sprintf(
						/* translators: 1: refund amount, 2: currency, 3: provider refund identifier. */
						__( 'Bachs refund confirmed: %1$s %2$s (%3$s).', 'payment-integrations-for-bachs' ),
						$refund->refunded_amount()->amount(),
						$refund->refunded_amount()->currency()->code(),
						$refund->provider_refund_id()
					),
				)
			);
		} catch ( Throwable ) {
			return $this->failed( $refund_id, $event_id, 'givewp_refund_apply_failed', 'GiveWP could not apply the provider-confirmed refund.' );
		}

		return $this->finalize( $refund_id, $event_id, RefundFulfillmentDisposition::APPLIED );
	}

	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

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
