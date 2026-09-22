<?php
/**
 * Webhook processing dispositions.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

/**
 * High-level outcomes returned by the webhook processor.
 */
enum WebhookProcessingDisposition: string {
	/** Unknown but valid event was safely recorded and ignored. */
	case IGNORED = 'ignored';

	/** Non-success event updated local provider state. */
	case STATUS_UPDATED = 'status_updated';

	/** Successful payment passed evidence verification and awaits host fulfillment. */
	case READY_FOR_FULFILLMENT = 'ready_for_fulfillment';

	/** Event had already been fully processed. */
	case DUPLICATE = 'duplicate';

	/** Another worker currently owns the event-processing claim. */
	case IN_PROGRESS = 'in_progress';

	/** Event is ambiguous and requires manual or reconciliation review. */
	case REQUIRES_REVIEW = 'requires_review';

	/** Processing failed in a way that can be retried safely later. */
	case RETRYABLE_FAILURE = 'retryable_failure';
}
