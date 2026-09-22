<?php
/**
 * Provider payment states.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Payment;

/**
 * Normalized Bachs/provider-side payment states used by the plugin core.
 */
enum ProviderStatus: string {
	/** Payment intent exists locally but no provider checkout has been completed. */
	case CREATED = 'created';

	/** Provider checkout is open. */
	case OPEN = 'open';

	/** Provider payment is still processing. */
	case PROCESSING = 'processing';

	/** Provider has authoritatively confirmed successful collection. */
	case SUCCEEDED = 'succeeded';

	/** Provider payment failed. */
	case FAILED = 'failed';

	/** Provider received less than the immutable expected amount. */
	case UNDERPAID = 'underpaid';

	/** Provider checkout expired. */
	case EXPIRED = 'expired';

	/** Provider checkout or collection was cancelled. */
	case CANCELLED = 'cancelled';

	/** Provider payment was fully refunded. */
	case REFUNDED = 'refunded';

	/** Provider payment was partially refunded. */
	case PARTIALLY_REFUNDED = 'partially_refunded';
}
