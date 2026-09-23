<?php
/**
 * Refund provider status.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Refund;

/**
 * Normalized lifecycle states for a Bachs refund operation.
 */
enum RefundStatus: string {
	case REQUESTED       = 'requested';
	case PROCESSING      = 'processing';
	case SUCCEEDED       = 'succeeded';
	case FAILED          = 'failed';
	case REQUIRES_REVIEW = 'requires_review';
}
