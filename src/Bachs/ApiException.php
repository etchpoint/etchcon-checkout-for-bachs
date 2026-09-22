<?php
/**
 * Normalized Bachs API exception.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use RuntimeException;
use Throwable;

/**
 * Represents transport, protocol, and provider errors without exposing secrets.
 */
final class ApiException extends RuntimeException {
	/** Transport failure code. */
	public const CODE_TRANSPORT = 'TRANSPORT_ERROR';

	/** Invalid provider response code. */
	public const CODE_PROTOCOL = 'PROTOCOL_ERROR';

	/**
	 * Stable Bachs error code or local normalization code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * HTTP status code, or zero when no HTTP response was received.
	 *
	 * @var int
	 */
	private int $http_status;

	/**
	 * Retry-After seconds when provided.
	 *
	 * @var int|null
	 */
	private ?int $retry_after;

	/**
	 * Provider field-level validation errors.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $validation_errors;

	/**
	 * Create a normalized API exception.
	 *
	 * @param string                           $error_code        Stable provider/local error code.
	 * @param string                           $detail            Human-readable error detail.
	 * @param int                              $http_status       HTTP status or zero.
	 * @param int|null                         $retry_after       Retry delay in seconds.
	 * @param array<int, array<string, mixed>> $validation_errors Field-level validation errors.
	 * @param Throwable|null                   $previous          Previous exception.
	 */
	public function __construct(
		string $error_code,
		string $detail,
		int $http_status = 0,
		?int $retry_after = null,
		array $validation_errors = array(),
		?Throwable $previous = null
	) {
		parent::__construct( $detail, 0, $previous );

		$this->error_code        = $error_code;
		$this->http_status       = $http_status;
		$this->retry_after       = $retry_after;
		$this->validation_errors = $validation_errors;
	}

	/**
	 * Create a transport-layer failure.
	 *
	 * @param string         $detail   Transport error message.
	 * @param Throwable|null $previous Previous exception.
	 * @return self
	 */
	public static function transport( string $detail, ?Throwable $previous = null ): self {
		return new self( self::CODE_TRANSPORT, $detail, 0, null, array(), $previous );
	}

	/**
	 * Create an invalid-response failure.
	 *
	 * @param string $detail      Protocol error detail.
	 * @param int    $http_status HTTP status when available.
	 * @return self
	 */
	public static function protocol( string $detail, int $http_status = 0 ): self {
		return new self( self::CODE_PROTOCOL, $detail, $http_status );
	}

	/**
	 * Get the stable provider/local error code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Get the HTTP status code.
	 *
	 * @return int
	 */
	public function http_status(): int {
		return $this->http_status;
	}

	/**
	 * Get Retry-After seconds when supplied.
	 *
	 * @return int|null
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * Get provider validation errors.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function validation_errors(): array {
		return $this->validation_errors;
	}

	/**
	 * Determine whether retrying later can be appropriate.
	 *
	 * Retrying a POST must always reuse the same logical Idempotency-Key.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		if ( self::CODE_TRANSPORT === $this->error_code || 'IDEMPOTENCY_IN_PROGRESS' === $this->error_code ) {
			return true;
		}

		return 429 === $this->http_status || $this->http_status >= 500;
	}
}
