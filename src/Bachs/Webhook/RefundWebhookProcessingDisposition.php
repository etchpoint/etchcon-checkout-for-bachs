<?php
/**
 * Refund webhook processing disposition.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

/**
 * Outcomes produced before or after host refund fulfillment.
 */
enum RefundWebhookProcessingDisposition: string {
	case STATUS_UPDATED        = 'refund_status_updated';
	case READY_FOR_FULFILLMENT = 'refund_ready_for_fulfillment';
	case DUPLICATE             = 'refund_duplicate';
	case IN_PROGRESS           = 'refund_in_progress';
	case REQUIRES_REVIEW       = 'refund_requires_review';
	case RETRYABLE_FAILURE     = 'refund_retryable_failure';
}
