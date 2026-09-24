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

	/** Provider rejected the request because the API key lacks permission. */
	public const PROVIDER_FORBIDDEN = 'provider_forbidden';

	/** Provider rejected the request because the API key is invalid for this environment. */
	public const PROVIDER_UNAUTHORIZED = 'provider_unauthorized';

	/** Provider rejected one or more refund request fields. */
	public const PROVIDER_VALIDATION_ERROR = 'provider_validation_error';

	/** Provider could not find the charge being refunded. */
	public const PROVIDER_NOT_FOUND = 'provider_not_found';

	/** Provider reported a duplicate or conflicting refund request. */
	public const PROVIDER_CONFLICT = 'provider_conflict';

	/** Provider rejected the request for another non-retryable reason. */
	public const PROVIDER_REJECTED = 'provider_rejected';

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
