<?php
/**
 * Refund fulfillment disposition.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Refund;

/**
 * Outcomes from applying an authoritative provider refund to a host application.
 */
enum RefundFulfillmentDisposition: string {
	case APPLIED           = 'applied';
	case DUPLICATE         = 'duplicate';
	case RETRYABLE_FAILURE = 'retryable_failure';
	case REQUIRES_REVIEW   = 'requires_review';
}
