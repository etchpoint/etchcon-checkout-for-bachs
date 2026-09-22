<?php
/**
 * Host fulfillment dispositions.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Payment;

/**
 * Outcomes returned by host integration payment fulfillment.
 */
enum FulfillmentDisposition: string {
	/** Verified payment was applied to the host application. */
	case APPLIED = 'applied';

	/** The same verified payment had already been applied safely. */
	case DUPLICATE = 'duplicate';

	/** Fulfillment failed in a recoverable way and should be retried. */
	case RETRYABLE_FAILURE = 'retryable_failure';

	/** State is ambiguous and requires manual or reconciliation review. */
	case REQUIRES_REVIEW = 'requires_review';
}
