<?php
/**
 * Refund webhook processing result.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use Etchpoint\BachsIntegrations\Core\Refund\VerifiedRefund;

/**
 * Carries refund processing state and optional verified fulfillment evidence.
 */
final class RefundWebhookProcessingResult {
	/**
	 * Processing outcome.
	 *
	 * @var RefundWebhookProcessingDisposition
	 */
	private RefundWebhookProcessingDisposition $disposition;

	/**
	 * Local event row identifier.
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * Local refund row identifier.
	 *
	 * @var int|null
	 */
	private ?int $refund_id;

	/**
	 * Verified refund evidence.
	 *
	 * @var VerifiedRefund|null
	 */
	private ?VerifiedRefund $refund;

	/**
	 * Create the result.
	 *
	 * @param RefundWebhookProcessingDisposition $disposition Processing outcome.
	 * @param int                                $event_id    Local event row identifier.
	 * @param int|null                           $refund_id   Local refund row identifier.
	 * @param VerifiedRefund|null                $refund      Verified refund evidence.
	 */
	public function __construct(
		RefundWebhookProcessingDisposition $disposition,
		int $event_id,
		?int $refund_id = null,
		?VerifiedRefund $refund = null
	) {
		$this->disposition = $disposition;
		$this->event_id    = $event_id;
		$this->refund_id   = $refund_id;
		$this->refund      = $refund;
	}

	/**
	 * Get the processing outcome.
	 *
	 * @return RefundWebhookProcessingDisposition
	 */
	public function disposition(): RefundWebhookProcessingDisposition {
		return $this->disposition;
	}

	/**
	 * Get the local event identifier.
	 *
	 * @return int
	 */
	public function event_id(): int {
		return $this->event_id;
	}

	/**
	 * Get the local refund identifier.
	 *
	 * @return int|null
	 */
	public function refund_id(): ?int {
		return $this->refund_id;
	}

	/**
	 * Get verified refund evidence when ready.
	 *
	 * @return VerifiedRefund|null
	 */
	public function verified_refund(): ?VerifiedRefund {
		return $this->refund;
	}
}
