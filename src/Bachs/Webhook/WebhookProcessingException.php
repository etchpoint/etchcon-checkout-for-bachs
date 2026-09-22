<?php
/**
 * Webhook processing exception.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use RuntimeException;

/**
 * Represents a malformed or internally inconsistent webhook-processing input.
 */
final class WebhookProcessingException extends RuntimeException {
	/** Malformed JSON or event envelope. */
	public const CODE_MALFORMED_EVENT = 'malformed_event';

	/** Signature evidence was created for different raw bytes. */
	public const CODE_PAYLOAD_HASH_MISMATCH = 'payload_hash_mismatch';

	/** Deduplication insert and subsequent lookup disagreed. */
	public const CODE_EVENT_STORE_INCONSISTENT = 'event_store_inconsistent';

	/** Signed event belongs to a different Bachs organization. */
	public const CODE_ORGANIZATION_MISMATCH = 'organization_mismatch';

	/** Connected-account events are outside the current plugin scope. */
	public const CODE_UNSUPPORTED_CONNECT_EVENT = 'unsupported_connect_event';

	/**
	 * Stable machine-readable failure code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Create a processing exception.
	 *
	 * @param string $error_code Stable error code.
	 * @param string $message    Safe diagnostic message.
	 */
	public function __construct( string $error_code, string $message ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
	}

	/**
	 * Get the stable machine-readable failure code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}
}
