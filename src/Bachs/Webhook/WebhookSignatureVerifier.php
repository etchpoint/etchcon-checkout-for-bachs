<?php
/**
 * Bachs webhook signature verifier.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use Closure;
use InvalidArgumentException;

/**
 * Verifies current and legacy Bachs webhook signatures against the exact raw body.
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
	 * @throws WebhookVerificationException When the signing secret is missing or invalid.
 * @throws WebhookVerificationException When the signature timestamp is outside the freshness window.
 * @throws WebhookVerificationException When the signature does not match the request body.
	 */
	public function verify(
		string $raw_body,
		string $signature_v2_header,
		string $signing_secret
	): VerifiedWebhookSignature {
		if ( '' === $signing_secret ) {
			// Exception fields are internal verification data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new WebhookVerificationException(
				WebhookVerificationException::CODE_INVALID_SECRET,
				'Bachs webhook signing secret is not configured.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$parsed    = self::parse_v2_header( $signature_v2_header );
		$timestamp = $parsed['timestamp'];
		$now       = ( $this->clock )();

		if ( abs( $now - $timestamp ) > $this->tolerance_seconds ) {
			// Exception fields are internal verification data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new WebhookVerificationException(
				WebhookVerificationException::CODE_STALE_TIMESTAMP,
				'Bachs webhook signature timestamp is outside the accepted freshness window.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$expected = hash_hmac(
			'sha256',
			$parsed['timestamp_string'] . '.' . $raw_body,
			$signing_secret
		);

		foreach ( $parsed['signatures'] as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return new VerifiedWebhookSignature( $timestamp, hash( 'sha256', $raw_body ) );
			}
		}

		// Exception fields are internal verification data, not rendered output.
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new WebhookVerificationException(
			WebhookVerificationException::CODE_SIGNATURE_MISMATCH,
			'Bachs webhook signature did not match the request body.'
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Verify the legacy Bachs signature headers without parsing the JSON body.
	 *
	 * @param string $raw_body         Exact untouched HTTP request body.
	 * @param string $signature_header X-Bachs-Signature header value.
	 * @param string $timestamp_header X-Bachs-Timestamp header value.
	 * @param string $signing_secret   Endpoint signing secret exactly as configured by Bachs.
	 * @return VerifiedWebhookSignature
	 *
	 * @throws WebhookVerificationException When the signing secret is missing or invalid.
 * @throws WebhookVerificationException When the signature timestamp is outside the freshness window.
 * @throws WebhookVerificationException When the signature does not match the request body.
	 */
	public function verify_legacy(
		string $raw_body,
		string $signature_header,
		string $timestamp_header,
		string $signing_secret
	): VerifiedWebhookSignature {
		if ( '' === $signing_secret ) {
			// Exception fields are internal verification data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new WebhookVerificationException(
				WebhookVerificationException::CODE_INVALID_SECRET,
				'Bachs webhook signing secret is not configured.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$timestamp_string = trim( $timestamp_header );

		if ( ! self::is_canonical_timestamp( $timestamp_string ) ) {
			throw self::malformed_header_exception();
		}

		$timestamp = (int) $timestamp_string;
		$now       = ( $this->clock )();

		if ( abs( $now - $timestamp ) > $this->tolerance_seconds ) {
			// Exception fields are internal verification data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new WebhookVerificationException(
				WebhookVerificationException::CODE_STALE_TIMESTAMP,
				'Bachs webhook signature timestamp is outside the accepted freshness window.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$signature = strtolower( trim( $signature_header ) );

		if ( str_starts_with( $signature, 'v1=' ) ) {
			$signature = substr( $signature, 3 );
		} elseif ( str_starts_with( $signature, 'v1,' ) ) {
			$signature = substr( $signature, 3 );
		}

		if ( 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $signature ) ) {
			throw self::malformed_header_exception();
		}

		$expected = hash_hmac(
			'sha256',
			$timestamp_string . '.' . $raw_body,
			$signing_secret
		);

		if ( ! hash_equals( $expected, $signature ) ) {
			// Exception fields are internal verification data, not rendered output.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new WebhookVerificationException(
				WebhookVerificationException::CODE_SIGNATURE_MISMATCH,
				'Bachs webhook signature did not match the request body.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return new VerifiedWebhookSignature( $timestamp, hash( 'sha256', $raw_body ) );
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
			'Bachs webhook signature header is malformed.'
		);
	}
}
