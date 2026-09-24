<?php
/**
 * Refund request exception.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Refunds;

use RuntimeException;

/**
 * Represents a safe refund validation or request failure.
 */
final class RefundRequestException extends RuntimeException {
	/** Stable invalid request code. */
	public const INVALID_REQUEST = 'invalid_request';

	/** Existing refund operation code. */
	public const ALREADY_REQUESTED = 'already_requested';

	/** Provider state requires manual review. */
	public const REQUIRES_REVIEW = 'requires_review';

	/** Provider payment evidence does not match the local charge. */
	public const PROVIDER_PAYMENT_MISMATCH = 'provider_payment_mismatch';

	/** Provider checkout correlation does not match the local charge. */
	public const PROVIDER_CHECKOUT_MISMATCH = 'provider_checkout_mismatch';

	/** Provider reference correlation does not match the local charge. */
	public const PROVIDER_REFERENCE_MISMATCH = 'provider_reference_mismatch';

	/** Partial refunds cannot be represented safely in the local currency. */
	public const PARTIAL_REFUND_CURRENCY_UNSUPPORTED = 'partial_refund_currency_unsupported';

	/** Provider refund response does not match the persisted operation. */
	public const PROVIDER_REFUND_MISMATCH = 'provider_refund_mismatch';

	/** Temporary provider failure code. */
	public const RETRYABLE = 'retryable_failure';

	/**
	 * Stable safe error code.
	 *
	 * @var string
	 */
	private string $safe_code;

	/**
	 * Create the exception.
	 *
	 * @param string $message   Internal non-secret message.
	 * @param string $safe_code Stable safe error code.
	 */
	public function __construct( string $message, string $safe_code ) {
		$this->safe_code = $safe_code;
		parent::__construct( $message );
	}

	/**
	 * Get the safe error code.
	 *
	 * @return string
	 */
	public function safe_code(): string {
		return $this->safe_code;
	}
}
