<?php
/**
 * HTTP transport contract.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

/**
 * Sends HTTP requests for the Bachs API client.
 */
interface HttpTransport {
	/**
	 * Send an HTTP request.
	 *
	 * @param string               $url  Absolute fixed-provider URL.
	 * @param array<string, mixed> $args WordPress-style HTTP request arguments.
	 * @return TransportResponse
	 */
	public function request( string $url, array $args ): TransportResponse;
}
