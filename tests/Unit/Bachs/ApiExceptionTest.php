<?php
/**
 * Bachs API exception tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs;

use Etchpoint\BachsIntegrations\Bachs\ApiException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies retry classification for normalized provider failures.
 */
final class ApiExceptionTest extends TestCase {
	/**
	 * Transport failures are retryable.
	 *
	 * @return void
	 */
	public function test_transport_failure_is_retryable(): void {
		self::assertTrue( ApiException::transport( 'Timed out.' )->is_retryable() );
	}

	/**
	 * Rate limits are retryable and preserve Retry-After.
	 *
	 * @return void
	 */
	public function test_rate_limit_is_retryable(): void {
		$exception = new ApiException( 'TOO_MANY_REQUESTS', 'Slow down.', 429, 5 );

		self::assertTrue( $exception->is_retryable() );
		self::assertSame( 5, $exception->retry_after() );
	}

	/**
	 * Validation failures are not automatically retryable.
	 *
	 * @return void
	 */
	public function test_validation_failure_is_not_retryable(): void {
		$exception = new ApiException( 'VALIDATION_ERROR', 'Invalid request.', 400 );

		self::assertFalse( $exception->is_retryable() );
	}
}
