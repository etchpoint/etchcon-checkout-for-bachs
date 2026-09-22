<?php
/**
 * Failing HTTP transport test double.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Support\Bachs;

use Etchpoint\BachsIntegrations\Bachs\HttpTransport;
use Etchpoint\BachsIntegrations\Bachs\TransportResponse;
use RuntimeException;

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

		return throw new RuntimeException( 'Connection timed out.' );
	}
}
