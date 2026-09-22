<?php
/**
 * Recording HTTP transport test double.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Support\Bachs;

use Etchpoint\BachsIntegrations\Bachs\HttpTransport;
use Etchpoint\BachsIntegrations\Bachs\TransportResponse;

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
