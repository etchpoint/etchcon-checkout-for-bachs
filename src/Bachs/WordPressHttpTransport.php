<?php
/**
 * WordPress HTTP transport.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use RuntimeException;

/**
 * Sends Bachs requests through the native WordPress HTTP API.
 */
final class WordPressHttpTransport implements HttpTransport {
	/**
	 * Send a request through WordPress.
	 *
	 * @param string               $url  Absolute fixed-provider URL.
	 * @param array<string, mixed> $args WordPress HTTP arguments.
	 * @return TransportResponse
	 *
	 * @throws RuntimeException When WordPress reports a transport-layer failure.
	 */
	public function request( string $url, array $args ): TransportResponse {
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			// Transport errors are exception data, not rendered output.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new RuntimeException( $response->get_error_message() );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = (string) wp_remote_retrieve_body( $response );
		$retry_after = self::parse_retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );

		return new TransportResponse( $status_code, $body, $retry_after );
	}

	/**
	 * Normalize the Retry-After header to seconds when it is numeric.
	 *
	 * @param string|array<string>|null $header Retry-After header value.
	 * @return int|null
	 */
	private static function parse_retry_after( string|array|null $header ): ?int {
		if ( ! is_string( $header ) || '' === $header || ! ctype_digit( $header ) ) {
			return null;
		}

		return (int) $header;
	}
}
