<?php
/**
 * Verified webhook signature value object.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use InvalidArgumentException;

/**
 * Evidence that a Bachs V2 webhook signature passed freshness and HMAC checks.
 */
final class VerifiedWebhookSignature {
	/**
	 * Signed Unix timestamp from the verified V2 header.
	 *
	 * @var int
	 */
	private int $timestamp;

	/**
	 * SHA-256 hash of the exact raw body that passed verification.
	 *
	 * @var string
	 */
	private string $payload_hash;

	/**
	 * Create verified signature evidence.
	 *
	 * @param int    $timestamp    Verified signed Unix timestamp.
	 * @param string $payload_hash SHA-256 hash of the exact verified raw body.
	 *
	 * @throws InvalidArgumentException When the payload hash is malformed.
	 */
	public function __construct( int $timestamp, string $payload_hash ) {
		if ( 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $payload_hash ) ) {
			throw new InvalidArgumentException( 'Verified webhook payload hash must be lowercase SHA-256 hex.' );
		}

		$this->timestamp    = $timestamp;
		$this->payload_hash = $payload_hash;
	}

	/**
	 * Get the signed Unix timestamp.
	 *
	 * @return int
	 */
	public function timestamp(): int {
		return $this->timestamp;
	}

	/**
	 * Get the SHA-256 hash of the verified raw body.
	 *
	 * @return string
	 */
	public function payload_hash(): string {
		return $this->payload_hash;
	}
}
