<?php
/**
 * Bachs V2 webhook signature verifier tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs\Webhook;

use Etchpoint\BachsIntegrations\Bachs\Webhook\WebhookSignatureVerifier;
use Etchpoint\BachsIntegrations\Bachs\Webhook\WebhookVerificationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies raw-body HMAC validation, freshness, and secret rotation support.
 */
final class WebhookSignatureVerifierTest extends TestCase {
	/** Deterministic Unix timestamp used by the test vector. */
	private const NOW = 1750000000;

	/** Deterministic signing secret used only in tests. */
	private const SECRET = 'whsec_test_secret_123';

	/** Exact raw body used by the fixed test vector. */
	private const RAW_BODY = '{"id":"evt_test","type":"collection.succeeded","data":{"amount":"42.00"}}';

	/** Fixed HMAC-SHA256 digest for NOW + RAW_BODY using SECRET. */
	private const SIGNATURE = 'd524c44d3cb636df36df44f82aba4c54b68bc720374aa6b824a93cc3dd2740ed';

	/**
	 * A valid V2 signature returns verified evidence with the signed timestamp.
	 *
	 * @return void
	 */
	public function test_valid_v2_signature_is_accepted(): void {
		$verifier = $this->verifier();
		$verified = $verifier->verify(
			self::RAW_BODY,
			't=1750000000,v1=' . self::SIGNATURE,
			self::SECRET
		);

		self::assertSame( self::NOW, $verified->timestamp() );
		self::assertSame( hash( 'sha256', self::RAW_BODY ), $verified->payload_hash() );
	}

	/**
	 * Rotation headers accept a match against any repeated v1 signature.
	 *
	 * @return void
	 */
	public function test_any_matching_v1_signature_is_accepted(): void {
		$verifier = $this->verifier();
		$verified = $verifier->verify(
			self::RAW_BODY,
			't=1750000000,v1=' . str_repeat( '0', 64 ) . ',v1=' . self::SIGNATURE,
			self::SECRET
		);

		self::assertSame( self::NOW, $verified->timestamp() );
	}

	/**
	 * Unknown future signature schemes do not break a valid v1 verification.
	 *
	 * @return void
	 */
	public function test_unknown_signature_scheme_is_ignored(): void {
		$verifier = $this->verifier();
		$verified = $verifier->verify(
			self::RAW_BODY,
			't=1750000000,v2=future-scheme,v1=' . self::SIGNATURE,
			self::SECRET
		);

		self::assertSame( self::NOW, $verified->timestamp() );
	}

	/**
	 * Changing even insignificant-looking raw bytes invalidates the signature.
	 *
	 * @return void
	 */
	public function test_raw_body_mutation_is_rejected(): void {
		$verifier = $this->verifier();

		try {
			$verifier->verify(
				self::RAW_BODY . "\n",
				't=1750000000,v1=' . self::SIGNATURE,
				self::SECRET
			);
			self::fail( 'Expected a webhook verification exception.' );
		} catch ( WebhookVerificationException $exception ) {
			self::assertSame( WebhookVerificationException::CODE_SIGNATURE_MISMATCH, $exception->reason() );
		}
	}

	/**
	 * A stale timestamp is rejected before payload processing.
	 *
	 * @return void
	 */
	public function test_stale_timestamp_is_rejected(): void {
		$verifier  = $this->verifier();
		$timestamp = self::NOW - 301;
		$signature = hash_hmac( 'sha256', $timestamp . '.' . self::RAW_BODY, self::SECRET );

		try {
			$verifier->verify(
				self::RAW_BODY,
				't=' . $timestamp . ',v1=' . $signature,
				self::SECRET
			);
			self::fail( 'Expected a webhook verification exception.' );
		} catch ( WebhookVerificationException $exception ) {
			self::assertSame( WebhookVerificationException::CODE_STALE_TIMESTAMP, $exception->reason() );
		}
	}

	/**
	 * A timestamp too far in the future is rejected as the same replay risk.
	 *
	 * @return void
	 */
	public function test_future_timestamp_outside_tolerance_is_rejected(): void {
		$verifier  = $this->verifier();
		$timestamp = self::NOW + 301;
		$signature = hash_hmac( 'sha256', $timestamp . '.' . self::RAW_BODY, self::SECRET );

		try {
			$verifier->verify(
				self::RAW_BODY,
				't=' . $timestamp . ',v1=' . $signature,
				self::SECRET
			);
			self::fail( 'Expected a webhook verification exception.' );
		} catch ( WebhookVerificationException $exception ) {
			self::assertSame( WebhookVerificationException::CODE_STALE_TIMESTAMP, $exception->reason() );
		}
	}

	/**
	 * The exact freshness boundary remains valid.
	 *
	 * @return void
	 */
	public function test_exact_tolerance_boundary_is_accepted(): void {
		$verifier  = $this->verifier();
		$timestamp = self::NOW - 300;
		$signature = hash_hmac( 'sha256', $timestamp . '.' . self::RAW_BODY, self::SECRET );
		$verified  = $verifier->verify(
			self::RAW_BODY,
			't=' . $timestamp . ',v1=' . $signature,
			self::SECRET
		);

		self::assertSame( $timestamp, $verified->timestamp() );
	}

	/**
	 * Missing V2 timestamp metadata is rejected as malformed.
	 *
	 * @return void
	 */
	public function test_missing_timestamp_is_rejected(): void {
		$this->assert_malformed_header( 'v1=' . self::SIGNATURE );
	}

	/**
	 * Missing v1 signatures are rejected as malformed.
	 *
	 * @return void
	 */
	public function test_missing_v1_signature_is_rejected(): void {
		$this->assert_malformed_header( 't=1750000000,v2=future-scheme' );
	}

	/**
	 * Duplicate timestamps are rejected rather than ambiguously selecting one.
	 *
	 * @return void
	 */
	public function test_duplicate_timestamp_is_rejected(): void {
		$this->assert_malformed_header(
			't=1750000000,t=1750000001,v1=' . self::SIGNATURE
		);
	}

	/**
	 * Non-numeric timestamps are rejected.
	 *
	 * @return void
	 */
	public function test_non_numeric_timestamp_is_rejected(): void {
		$this->assert_malformed_header( 't=not-a-time,v1=' . self::SIGNATURE );
	}

	/**
	 * An empty signing secret fails before HMAC verification.
	 *
	 * @return void
	 */
	public function test_empty_secret_is_rejected(): void {
		$verifier = $this->verifier();

		try {
			$verifier->verify(
				self::RAW_BODY,
				't=1750000000,v1=' . self::SIGNATURE,
				''
			);
			self::fail( 'Expected a webhook verification exception.' );
		} catch ( WebhookVerificationException $exception ) {
			self::assertSame( WebhookVerificationException::CODE_INVALID_SECRET, $exception->reason() );
		}
	}

	/**
	 * A non-positive tolerance is invalid configuration.
	 *
	 * @return void
	 */
	public function test_non_positive_tolerance_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		new WebhookSignatureVerifier( 0 );
	}

	/**
	 * Build a verifier with a deterministic clock.
	 *
	 * @return WebhookSignatureVerifier
	 */
	private function verifier(): WebhookSignatureVerifier {
		return new WebhookSignatureVerifier(
			300,
			static fn (): int => self::NOW
		);
	}

	/**
	 * Assert that a malformed header receives the stable malformed reason.
	 *
	 * @param string $header V2 header under test.
	 * @return void
	 */
	private function assert_malformed_header( string $header ): void {
		$verifier = $this->verifier();

		try {
			$verifier->verify( self::RAW_BODY, $header, self::SECRET );
			self::fail( 'Expected a webhook verification exception.' );
		} catch ( WebhookVerificationException $exception ) {
			self::assertSame( WebhookVerificationException::CODE_MALFORMED_HEADER, $exception->reason() );
		}
	}
}
