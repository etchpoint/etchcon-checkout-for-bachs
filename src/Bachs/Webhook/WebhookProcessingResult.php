<?php
/**
 * Webhook processing result.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use Etchpoint\BachsIntegrations\Core\Payment\VerifiedPayment;

/**
 * Immutable handoff from webhook verification to later host fulfillment.
 */
final class WebhookProcessingResult {
	/**
	 * Processing disposition.
	 *
	 * @var WebhookProcessingDisposition
	 */
	private WebhookProcessingDisposition $disposition;

	/**
	 * Event inbox row identifier.
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * Matched intent row identifier when available.
	 *
	 * @var int|null
	 */
	private ?int $intent_id;

	/**
	 * Verified payment ready for adapter fulfillment when successful.
	 *
	 * @var VerifiedPayment|null
	 */
	private ?VerifiedPayment $verified_payment;

	/**
	 * Create a processing result.
	 *
	 * @param WebhookProcessingDisposition $disposition     Processing disposition.
	 * @param int                          $event_id        Event inbox row identifier.
	 * @param int|null                     $intent_id       Matched intent row identifier.
	 * @param VerifiedPayment|null         $verified_payment Verified payment evidence.
	 */
	public function __construct(
		WebhookProcessingDisposition $disposition,
		int $event_id,
		?int $intent_id = null,
		?VerifiedPayment $verified_payment = null
	) {
		$this->disposition      = $disposition;
		$this->event_id         = $event_id;
		$this->intent_id        = $intent_id;
		$this->verified_payment = $verified_payment;
	}

	/**
	 * Get the processing disposition.
	 *
	 * @return WebhookProcessingDisposition
	 */
	public function disposition(): WebhookProcessingDisposition {
		return $this->disposition;
	}

	/**
	 * Get the event inbox row identifier.
	 *
	 * @return int
	 */
	public function event_id(): int {
		return $this->event_id;
	}

	/**
	 * Get the matched intent row identifier.
	 *
	 * @return int|null
	 */
	public function intent_id(): ?int {
		return $this->intent_id;
	}

	/**
	 * Get verified payment evidence when ready for fulfillment.
	 *
	 * @return VerifiedPayment|null
	 */
	public function verified_payment(): ?VerifiedPayment {
		return $this->verified_payment;
	}
}
