<?php
/**
 * Refund application status.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Refund;

/**
 * Tracks whether a confirmed provider refund has been reflected in WordPress.
 */
enum RefundApplicationStatus: string {
	case PENDING         = 'pending';
	case PROCESSING      = 'processing';
	case APPLIED         = 'applied';
	case FAILED          = 'failed';
	case REQUIRES_REVIEW = 'requires_review';
}
