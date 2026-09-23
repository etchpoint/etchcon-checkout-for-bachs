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
	 * @param string $safe_code Stable safe error code.
	 * @param string $message   Internal non-secret message.
	 */
	public function __construct( string $safe_code, string $message ) {
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
