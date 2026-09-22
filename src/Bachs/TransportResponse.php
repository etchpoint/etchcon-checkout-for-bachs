<?php
/**
 * HTTP transport response.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use InvalidArgumentException;

/**
 * Small immutable response object returned by the HTTP transport.
 */
final class TransportResponse {
	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	private int $status_code;

	/**
	 * Raw response body.
	 *
	 * @var string
	 */
	private string $body;

	/**
	 * Retry-After value in seconds when present.
	 *
	 * @var int|null
	 */
	private ?int $retry_after;

	/**
	 * Create a transport response.
	 *
	 * @param int      $status_code HTTP status code.
	 * @param string   $body        Raw response body.
	 * @param int|null $retry_after Retry delay in seconds.
	 *
	 * @throws InvalidArgumentException When response metadata is invalid.
	 */
	public function __construct( int $status_code, string $body, ?int $retry_after = null ) {
		if ( $status_code < 100 || $status_code > 599 ) {
			throw new InvalidArgumentException( 'HTTP status code must be between 100 and 599.' );
		}

		if ( null !== $retry_after && $retry_after < 0 ) {
			throw new InvalidArgumentException( 'Retry-After cannot be negative.' );
		}

		$this->status_code = $status_code;
		$this->body        = $body;
		$this->retry_after = $retry_after;
	}

	/**
	 * Get the HTTP status code.
	 *
	 * @return int
	 */
	public function status_code(): int {
		return $this->status_code;
	}

	/**
	 * Get the raw body.
	 *
	 * @return string
	 */
	public function body(): string {
		return $this->body;
	}

	/**
	 * Get the retry delay when supplied by the provider.
	 *
	 * @return int|null
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}
}
