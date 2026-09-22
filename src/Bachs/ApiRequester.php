<?php
/**
 * Bachs API request contract.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

/**
 * Minimal request surface used by Bachs resource APIs.
 */
interface ApiRequester {
	/**
	 * Get the Bachs environment used by this requester.
	 *
	 * @return Environment
	 */
	public function environment(): Environment;

	/**
	 * Send an authenticated GET request.
	 *
	 * @param string $path Bachs v1 API path.
	 * @return array<string, mixed>
	 */
	public function get( string $path ): array;

	/**
	 * Send an authenticated idempotent POST request.
	 *
	 * @param string               $path            Bachs v1 API path.
	 * @param array<string, mixed> $body            JSON request body.
	 * @param string               $idempotency_key Stable key for this logical operation.
	 * @return array<string, mixed>
	 */
	public function post( string $path, array $body, string $idempotency_key ): array;
}
