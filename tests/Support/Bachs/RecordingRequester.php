<?php
/**
 * Recording Bachs API requester test double.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Support\Bachs;

use Etchpoint\BachsIntegrations\Bachs\ApiRequester;
use Etchpoint\BachsIntegrations\Bachs\Environment;

/**
 * Records resource API requests and returns a predetermined payload.
 */
final class RecordingRequester implements ApiRequester {
	/**
	 * Predetermined response payload.
	 *
	 * @var array<string, mixed>
	 */
	private array $response;

	/**
	 * Last requested path.
	 *
	 * @var string
	 */
	private string $path = '';

	/**
	 * Last POST body.
	 *
	 * @var array<string, mixed>
	 */
	private array $body = array();

	/**
	 * Last idempotency key.
	 *
	 * @var string
	 */
	private string $idempotency_key = '';

	/**
	 * Request environment.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Create the recording requester.
	 *
	 * @param array<string, mixed> $response    Predetermined response payload.
	 * @param Environment          $environment Request environment.
	 */
	public function __construct( array $response, Environment $environment = Environment::SANDBOX ) {
		$this->response    = $response;
		$this->environment = $environment;
	}

	/**
	 * Get the request environment.
	 *
	 * @return Environment
	 */
	public function environment(): Environment {
		return $this->environment;
	}

	/**
	 * Record a GET request.
	 *
	 * @param string $path Bachs API path.
	 * @return array<string, mixed>
	 */
	public function get( string $path ): array {
		$this->path = $path;

		return $this->response;
	}

	/**
	 * Record a POST request.
	 *
	 * @param string               $path            Bachs API path.
	 * @param array<string, mixed> $body            Request body.
	 * @param string               $idempotency_key Idempotency key.
	 * @return array<string, mixed>
	 */
	public function post( string $path, array $body, string $idempotency_key ): array {
		$this->path            = $path;
		$this->body            = $body;
		$this->idempotency_key = $idempotency_key;

		return $this->response;
	}

	/**
	 * Get the last requested path.
	 *
	 * @return string
	 */
	public function last_path(): string {
		return $this->path;
	}

	/**
	 * Get the last POST body.
	 *
	 * @return array<string, mixed>
	 */
	public function last_body(): array {
		return $this->body;
	}

	/**
	 * Get the last idempotency key.
	 *
	 * @return string
	 */
	public function last_idempotency_key(): string {
		return $this->idempotency_key;
	}
}
