<?php
/**
 * Bachs HTTP/API client.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Small authenticated Bachs v1 client using fixed provider origins.
 */
final class ApiClient implements ApiRequester {
	/** Default WordPress HTTP timeout in seconds. */
	private const DEFAULT_TIMEOUT_SECONDS = 15;

	/**
	 * Bachs environment.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Secret Bachs API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * HTTP transport.
	 *
	 * @var HttpTransport
	 */
	private HttpTransport $transport;

	/**
	 * Request timeout.
	 *
	 * @var int
	 */
	private int $timeout_seconds;

	/**
	 * Create the Bachs API client.
	 *
	 * @param Environment        $environment     Sandbox or live environment.
	 * @param string             $api_key         Secret Bachs API key.
	 * @param HttpTransport|null $transport       HTTP transport override for testing.
	 * @param int                $timeout_seconds Request timeout in seconds.
	 *
	 * @throws InvalidArgumentException When configuration is invalid.
	 */
	public function __construct(
		Environment $environment,
		string $api_key,
		?HttpTransport $transport = null,
		int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS
	) {
		Environment::assert_api_key( $environment, $api_key );

		if ( $timeout_seconds < 1 || $timeout_seconds > 60 ) {
			throw new InvalidArgumentException( 'Bachs API timeout must be between 1 and 60 seconds.' );
		}

		$this->environment     = $environment;
		$this->api_key         = $api_key;
		$this->transport       = $transport ?? new WordPressHttpTransport();
		$this->timeout_seconds = $timeout_seconds;
	}

	/**
	 * Get the configured Bachs environment.
	 *
	 * @return Environment
	 */
	public function environment(): Environment {
		return $this->environment;
	}

	/**
	 * Send an authenticated GET request.
	 *
	 * @param string $path Bachs v1 path.
	 * @return array<string, mixed>
	 *
	 * @throws ApiException When transport or provider processing fails.
	 */
	public function get( string $path ): array {
		return $this->request( 'GET', $path, null, null );
	}

	/**
	 * Send an authenticated idempotent POST request.
	 *
	 * @param string               $path            Bachs v1 path.
	 * @param array<string, mixed> $body            JSON request body.
	 * @param string               $idempotency_key Stable idempotency key for the logical operation.
	 * @return array<string, mixed>
	 *
	 * @throws ApiException|InvalidArgumentException When transport/provider processing or idempotency validation fails.
	 */
	public function post( string $path, array $body, string $idempotency_key ): array {
		if ( '' === $idempotency_key || trim( $idempotency_key ) !== $idempotency_key ) {
			throw new InvalidArgumentException( 'Idempotency key must be a non-empty value without surrounding whitespace.' );
		}

		return $this->request( 'POST', $path, $body, $idempotency_key );
	}

	/**
	 * Send one request and normalize its response.
	 *
	 * @param string                    $method          HTTP method.
	 * @param string                    $path            Bachs v1 path.
	 * @param array<string, mixed>|null $body            Request body.
	 * @param string|null               $idempotency_key Idempotency key for mutating operations.
	 * @return array<string, mixed>
	 *
	 * @throws ApiException When transport, encoding, provider, or response processing fails.
	 */
	private function request( string $method, string $path, ?array $body, ?string $idempotency_key ): array {
		self::assert_api_path( $path );

		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Accept'        => 'application/json',
		);
		$args    = array(
			'method'      => $method,
			'timeout'     => $this->timeout_seconds,
			'redirection' => 0,
			'headers'     => $headers,
		);

		if ( null !== $body ) {
			try {
				// JSON_THROW_ON_ERROR gives deterministic low-level API encoding.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				$encoded_body = json_encode( $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			} catch ( JsonException $exception ) {
				throw ApiException::protocol( 'Unable to encode the Bachs request body as JSON.' );
			}

			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $encoded_body;
		}

		if ( null !== $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = $idempotency_key;
		}

		try {
			$response = $this->transport->request( Environment::base_url( $this->environment ) . $path, $args );
		} catch ( RuntimeException $exception ) {
			// Transport errors are exception data, not rendered output.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw ApiException::transport( $exception->getMessage(), $exception );
		}

		return self::decode_response( $response );
	}

	/**
	 * Decode a Bachs response and raise normalized provider failures.
	 *
	 * @param TransportResponse $response HTTP transport response.
	 * @return array<string, mixed>
	 *
	 * @throws ApiException When a provider or protocol error occurs.
	 */
	private static function decode_response( TransportResponse $response ): array {
		$body        = $response->body();
		$status_code = $response->status_code();
		$decoded     = array();

		if ( '' !== $body ) {
			try {
				$value = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
			} catch ( JsonException $exception ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status code is exception context, not rendered output.
				throw ApiException::protocol( 'Bachs returned a response that was not valid JSON.', $status_code );
			}

			if ( ! is_array( $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status code is exception context, not rendered output.
				throw ApiException::protocol( 'Bachs returned an unexpected JSON response shape.', $status_code );
			}

			$decoded = self::string_keyed_array( $value );
		}

		if ( $status_code >= 200 && $status_code < 300 ) {
			return $decoded;
		}

		$error_code = self::array_string( $decoded, 'error_code' ) ?? 'HTTP_ERROR';
		$detail     = self::array_string( $decoded, 'detail' ) ?? 'Bachs API request failed.';
		$errors     = self::validation_errors( $decoded['errors'] ?? null );

		// Provider error details are exception data, not rendered output.
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		throw new ApiException(
			$error_code,
			$detail,
			$status_code,
			$response->retry_after(),
			$errors
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}

	/**
	 * Assert that callers cannot redirect credentials to another host.
	 *
	 * @param string $path Bachs API path.
	 * @return void
	 *
	 * @throws InvalidArgumentException When the path is not a local v1 path.
	 */
	private static function assert_api_path( string $path ): void {
		if (
			! str_starts_with( $path, '/v1/' )
			|| str_contains( $path, '://' )
			|| str_contains( $path, "\r" )
			|| str_contains( $path, "\n" )
		) {
			throw new InvalidArgumentException( 'Bachs API path must be a local /v1/ path.' );
		}
	}

	/**
	 * Read a string field from an associative response array.
	 *
	 * @param array<string, mixed> $data Response data.
	 * @param string               $key  Field key.
	 * @return string|null
	 */
	private static function array_string( array $data, string $key ): ?string {
		$value = $data[ $key ] ?? null;

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Normalize a decoded JSON object to string keys.
	 *
	 * @param array<mixed, mixed> $value Decoded JSON object.
	 * @return array<string, mixed>
	 */
	private static function string_keyed_array( array $value ): array {
		$normalized = array();

		foreach ( $value as $key => $entry ) {
			if ( is_string( $key ) ) {
				$normalized[ $key ] = $entry;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize field-level validation errors.
	 *
	 * @param mixed $value Provider errors field.
	 * @return array<int, array<string, mixed>>
	 */
	private static function validation_errors( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$errors = array();

		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$normalized_item = array();

			foreach ( $item as $key => $entry ) {
				if ( is_string( $key ) ) {
					$normalized_item[ $key ] = $entry;
				}
			}

			$errors[] = $normalized_item;
		}

		return $errors;
	}
}
