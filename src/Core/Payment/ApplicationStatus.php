<?php
/**
 * Application payment states.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Payment;

/**
 * WordPress/application-side fulfillment states.
 */
enum ApplicationStatus: string {
	/** Application has not yet claimed payment fulfillment. */
	case PENDING = 'pending';

	/** Application fulfillment has been atomically claimed. */
	case PROCESSING = 'processing';

	/** Verified payment has been applied to the host application. */
	case APPLIED = 'applied';

	/** Application fulfillment failed and may be recoverable. */
	case FAILED = 'failed';

	/** State is ambiguous and requires manual or reconciliation review. */
	case REQUIRES_REVIEW = 'requires_review';
}
