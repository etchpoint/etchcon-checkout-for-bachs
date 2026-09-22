<?php
/**
 * Bachs API client tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\ApiException;
use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Bachs\HttpTransport;
use Etchpoint\BachsIntegrations\Bachs\TransportResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies authentication, idempotency, URL pinning, and error normalization.
 */
final class ApiClientTest extends TestCase {
	/**
	 * GET requests use the selected fixed Bachs origin and Bearer authentication.
	 *
	 * @return void
	 */
	public function test_get_uses_fixed_origin_and_bearer_authentication(): void {
		$transport = new RecordingTransport( new TransportResponse( 200, '{"status":"open"}' ) );
		$client    = new ApiClient( Environment::SANDBOX, 'sk_sandbox_test_fixture_not_secret', $transport );

		$response = $client->get( '/v1/checkout-sessions/chk_123' );

		self::assertSame( 'open', $response['status'] );
		self::assertSame( 'https://sandbox-api.bachs.io/v1/checkout-sessions/chk_123', $transport->last_url() );
		self::assertSame( 'Bearer sk_sandbox_test_fixture_not_secret', $transport->last_header( 'Authorization' ) );
		self::assertSame( 'application/json', $transport->last_header( 'Accept' ) );
	}

	/**
	 * POST requests carry the caller's stable idempotency key and JSON body.
	 *
	 * @return void
	 */
	public function test_post_carries_idempotency_key_and_json_body(): void {
		$transport = new RecordingTransport( new TransportResponse( 201, '{"checkout_id":"chk_123","status":"open"}' ) );
		$client    = new ApiClient( Environment::LIVE, 'sk_live_test_fixture_not_secret', $transport );

		$client->post(
			'/v1/checkout-sessions',
			array( 'reference' => 'ref_123' ),
			'etp:site:woo:1:checkout:1'
		);

		self::assertSame( 'POST', $transport->last_argument( 'method' ) );
		self::assertSame( 'etp:site:woo:1:checkout:1', $transport->last_header( 'Idempotency-Key' ) );
		self::assertSame( 'application/json', $transport->last_header( 'Content-Type' ) );
		self::assertSame( '{"reference":"ref_123"}', $transport->last_argument( 'body' ) );
	}

	/**
	 * Provider errors branch on stable error_code and preserve validation details.
	 *
	 * @return void
	 */
	public function test_provider_error_is_normalized(): void {
		$body      = '{"detail":"Invalid API key","error_code":"UNAUTHORIZED"}';
		$transport = new RecordingTransport( new TransportResponse( 401, $body ) );
		$client    = new ApiClient( Environment::SANDBOX, 'sk_sandbox_test_fixture_not_secret', $transport );

		try {
			$client->get( '/v1/checkout-sessions/chk_123' );
			self::fail( 'Expected an ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( 'UNAUTHORIZED', $exception->error_code() );
			self::assertSame( 401, $exception->http_status() );
			self::assertFalse( $exception->is_retryable() );
		}
	}

	/**
	 * Rate-limit responses preserve Retry-After and are retryable.
	 *
	 * @return void
	 */
	public function test_rate_limit_preserves_retry_after(): void {
		$body      = '{"detail":"Rate limited","error_code":"TOO_MANY_REQUESTS"}';
		$transport = new RecordingTransport( new TransportResponse( 429, $body, 7 ) );
		$client    = new ApiClient( Environment::SANDBOX, 'sk_sandbox_test_fixture_not_secret', $transport );

		try {
			$client->get( '/v1/payments/pay_123' );
			self::fail( 'Expected an ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertTrue( $exception->is_retryable() );
			self::assertSame( 7, $exception->retry_after() );
		}
	}


	/**
	 * Absolute URLs are rejected so credentials cannot be redirected off-provider.
	 *
	 * @return void
	 */
	public function test_absolute_url_is_rejected(): void {
		$transport = new RecordingTransport( new TransportResponse( 200, '{}' ) );
		$client    = new ApiClient( Environment::SANDBOX, 'sk_sandbox_test_fixture_not_secret', $transport );

		$this->expectException( \InvalidArgumentException::class );
		$client->get( 'https://attacker.example/v1/payments/pay_123' );
	}

	/**
	 * Invalid JSON in a successful response is treated as a protocol failure.
	 *
	 * @return void
	 */
	public function test_invalid_json_response_is_rejected(): void {
		$transport = new RecordingTransport( new TransportResponse( 200, 'not-json' ) );
		$client    = new ApiClient( Environment::SANDBOX, 'sk_sandbox_test_fixture_not_secret', $transport );

		try {
			$client->get( '/v1/payments/pay_123' );
			self::fail( 'Expected an ApiException.' );
		} catch ( ApiException $exception ) {
			self::assertSame( ApiException::CODE_PROTOCOL, $exception->error_code() );
		}
	}

	/**
	 * Transport failures become retryable normalized exceptions.
	 *
	 * @return void
	 */
	public function test_transport_failure_is_normalized(): void {
		$client = new ApiClient( Environment::SANDBOX, 'sk_sandbox_test_fixture_not_secret', new FailingTransport() );

		$this->expectException( ApiException::class );
		$client->get( '/v1/payments/pay_123' );
	}
}

/**
 * Records request details while returning a predetermined response.
 */
final class RecordingTransport implements HttpTransport {
	/**
	 * Predetermined response.
	 *
	 * @var TransportResponse
	 */
	private TransportResponse $response;

	/**
	 * Last requested URL.
	 *
	 * @var string
	 */
	private string $url = '';

	/**
	 * Last request arguments.
	 *
	 * @var array<string, mixed>
	 */
	private array $args = array();

	/**
	 * Create the recording transport.
	 *
	 * @param TransportResponse $response Predetermined response.
	 */
	public function __construct( TransportResponse $response ) {
		$this->response = $response;
	}

	/**
	 * Record a request and return the predetermined response.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args Request arguments.
	 * @return TransportResponse
	 */
	public function request( string $url, array $args ): TransportResponse {
		$this->url  = $url;
		$this->args = $args;

		return $this->response;
	}

	/**
	 * Get the last requested URL.
	 *
	 * @return string
	 */
	public function last_url(): string {
		return $this->url;
	}

	/**
	 * Get one request header as a string.
	 *
	 * @param string $name Header name.
	 * @return string|null
	 */
	public function last_header( string $name ): ?string {
		$headers = $this->args['headers'] ?? null;

		if ( ! is_array( $headers ) || ! isset( $headers[ $name ] ) || ! is_string( $headers[ $name ] ) ) {
			return null;
		}

		return $headers[ $name ];
	}

	/**
	 * Get one request argument.
	 *
	 * @param string $name Argument name.
	 * @return mixed
	 */
	public function last_argument( string $name ): mixed {
		return $this->args[ $name ] ?? null;
	}
}

/**
 * Simulates a network/transport failure.
 */
final class FailingTransport implements HttpTransport {
	/**
	 * Always fail the request before an HTTP response exists.
	 *
	 * @param string               $url  Request URL.
	 * @param array<string, mixed> $args Request arguments.
	 * @return TransportResponse
	 *
	 * @throws RuntimeException Always.
	 */
	public function request( string $url, array $args ): TransportResponse {
		unset( $url, $args );
		throw new RuntimeException( 'Connection timed out.' );
	}
}
