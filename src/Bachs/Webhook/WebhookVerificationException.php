<?php
/**
 * Webhook verification exception.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use RuntimeException;

/**
 * Represents a rejected Bachs webhook signature without exposing secrets.
 */
final class WebhookVerificationException extends RuntimeException {
	/** Invalid or missing signing secret. */
	public const CODE_INVALID_SECRET = 'invalid_secret';

	/** Malformed V2 signature header. */
	public const CODE_MALFORMED_HEADER = 'malformed_header';

	/** Signature timestamp is outside the accepted freshness window. */
	public const CODE_STALE_TIMESTAMP = 'stale_timestamp';

	/** No supplied v1 signature matched the expected HMAC. */
	public const CODE_SIGNATURE_MISMATCH = 'signature_mismatch';

	/**
	 * Stable machine-readable reason.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Create a webhook verification exception.
	 *
	 * @param string $reason  Stable failure reason.
	 * @param string $message Safe diagnostic message.
	 */
	public function __construct( string $reason, string $message ) {
		parent::__construct( $message );
		$this->reason = $reason;
	}

	/**
	 * Get the stable failure reason.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
