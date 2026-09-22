<?php
/**
 * Verified webhook signature value object.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

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
	 * Create verified signature evidence.
	 *
	 * @param int $timestamp Verified signed Unix timestamp.
	 */
	public function __construct( int $timestamp ) {
		$this->timestamp = $timestamp;
	}

	/**
	 * Get the signed Unix timestamp.
	 *
	 * @return int
	 */
	public function timestamp(): int {
		return $this->timestamp;
	}
}
