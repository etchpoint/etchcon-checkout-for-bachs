<?php
/**
 * Event processing states.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

/**
 * Processing states for a deduplicated provider webhook event.
 */
enum EventProcessingStatus: string {
	/** Event has been recorded but not yet claimed for processing. */
	case RECEIVED = 'received';

	/** Event has been atomically claimed by a worker. */
	case PROCESSING = 'processing';

	/** Event processing completed successfully. */
	case PROCESSED = 'processed';

	/** Event processing failed and may be retried safely. */
	case FAILED = 'failed';

	/** Event state is ambiguous and requires review. */
	case REQUIRES_REVIEW = 'requires_review';
}
