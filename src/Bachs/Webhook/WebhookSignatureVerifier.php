<?php
/**
 * Bachs V2 webhook signature verifier.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use Closure;
use InvalidArgumentException;

/**
 * Verifies X-Bachs-Signature-V2 against the exact raw request body.
 */
final class WebhookSignatureVerifier {
	/** Default replay-protection tolerance in seconds. */
	private const DEFAULT_TOLERANCE_SECONDS = 300;

	/**
	 * Accepted absolute timestamp drift in seconds.
	 *
	 * @var int
	 */
	private int $tolerance_seconds;

	/**
	 * Clock used to obtain the current Unix timestamp.
	 *
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * Create the verifier.
	 *
	 * @param int          $tolerance_seconds Accepted absolute timestamp drift in seconds.
	 * @param Closure|null $clock             Optional test clock returning a Unix timestamp.
	 *
	 * @throws InvalidArgumentException When tolerance is less than one second.
	 */
	public function __construct(
		int $tolerance_seconds = self::DEFAULT_TOLERANCE_SECONDS,
		?Closure $clock = null
	) {
		if ( 1 > $tolerance_seconds ) {
			throw new InvalidArgumentException( 'Webhook signature tolerance must be at least one second.' );
		}

		$this->tolerance_seconds = $tolerance_seconds;
		$this->clock             = $clock ?? static fn (): int => time();
	}

	/**
	 * Verify a Bachs V2 signature header without parsing the JSON body.
	 *
	 * @param string $raw_body            Exact untouched HTTP request body.
	 * @param string $signature_v2_header X-Bachs-Signature-V2 header value.
	 * @param string $signing_secret      Endpoint signing secret exactly as configured by Bachs.
	 * @return VerifiedWebhookSignature
	 *
	 * @throws WebhookVerificationException When the header, freshness, secret, or HMAC check fails.
	 */
	public function verify(
		string $raw_body,
		string $signature_v2_header,
		string $signing_secret
	): VerifiedWebhookSignature {
		if ( '' === $signing_secret ) {
			throw new WebhookVerificationException(
				WebhookVerificationException::CODE_INVALID_SECRET,
				'Bachs webhook signing secret is not configured.'
			);
		}

		$parsed    = self::parse_v2_header( $signature_v2_header );
		$timestamp = $parsed['timestamp'];
		$now       = ( $this->clock )();

		if ( abs( $now - $timestamp ) > $this->tolerance_seconds ) {
			throw new WebhookVerificationException(
				WebhookVerificationException::CODE_STALE_TIMESTAMP,
				'Bachs webhook signature timestamp is outside the accepted freshness window.'
			);
		}

		$expected = hash_hmac(
			'sha256',
			$parsed['timestamp_string'] . '.' . $raw_body,
			$signing_secret
		);

		foreach ( $parsed['signatures'] as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return new VerifiedWebhookSignature( $timestamp );
			}
		}

		throw new WebhookVerificationException(
			WebhookVerificationException::CODE_SIGNATURE_MISMATCH,
			'Bachs webhook signature did not match the request body.'
		);
	}

	/**
	 * Parse a V2 signature header while preserving repeated v1 values.
	 *
	 * @param string $header X-Bachs-Signature-V2 header value.
	 * @return array{timestamp: int, timestamp_string: string, signatures: array<int, string>}
	 *
	 * @throws WebhookVerificationException When the V2 header is malformed.
	 */
	private static function parse_v2_header( string $header ): array {
		if ( '' === trim( $header ) ) {
			throw self::malformed_header_exception();
		}

		$timestamp_string = null;
		$signatures       = array();

		foreach ( explode( ',', $header ) as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				throw self::malformed_header_exception();
			}

			$pair = explode( '=', $part, 2 );

			if ( 2 !== count( $pair ) ) {
				throw self::malformed_header_exception();
			}

			$key   = trim( $pair[0] );
			$value = trim( $pair[1] );

			if ( 't' === $key ) {
				if ( null !== $timestamp_string || ! self::is_canonical_timestamp( $value ) ) {
					throw self::malformed_header_exception();
				}

				$timestamp_string = $value;
				continue;
			}

			if ( 'v1' === $key && 1 === preg_match( '/\A[0-9a-fA-F]{64}\z/', $value ) ) {
				$signatures[] = strtolower( $value );
			}
		}

		if ( null === $timestamp_string || array() === $signatures ) {
			throw self::malformed_header_exception();
		}

		return array(
			'timestamp'        => (int) $timestamp_string,
			'timestamp_string' => $timestamp_string,
			'signatures'       => $signatures,
		);
	}

	/**
	 * Check for an unambiguous non-negative integer timestamp.
	 *
	 * @param string $value Header timestamp value.
	 * @return bool
	 */
	private static function is_canonical_timestamp( string $value ): bool {
		if ( 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ) {
			return false;
		}

		$timestamp = (int) $value;

		return (string) $timestamp === $value;
	}

	/**
	 * Build a safe malformed-header exception.
	 *
	 * @return WebhookVerificationException
	 */
	private static function malformed_header_exception(): WebhookVerificationException {
		return new WebhookVerificationException(
			WebhookVerificationException::CODE_MALFORMED_HEADER,
			'Bachs V2 webhook signature header is malformed.'
		);
	}
}
