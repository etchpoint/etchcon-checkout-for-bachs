<?php
/**
 * Bachs environment tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs;

use Etchpoint\BachsIntegrations\Bachs\Environment;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies fixed environments and strict key/environment separation.
 */
final class EnvironmentTest extends TestCase {
	/**
	 * Sandbox uses the fixed sandbox origin.
	 *
	 * @return void
	 */
	public function test_sandbox_has_fixed_origin(): void {
		self::assertSame( 'https://sandbox-api.bachs.io', Environment::SANDBOX->base_url() );
	}

	/**
	 * Live uses the fixed production origin.
	 *
	 * @return void
	 */
	public function test_live_has_fixed_origin(): void {
		self::assertSame( 'https://api.bachs.io', Environment::LIVE->base_url() );
	}

	/**
	 * A sandbox key is accepted only for sandbox.
	 *
	 * @return void
	 */
	public function test_sandbox_accepts_sandbox_key(): void {
		Environment::SANDBOX->assert_api_key( 'sk_sandbox_test_fixture_not_secret' );
		self::addToAssertionCount( 1 );
	}

	/**
	 * A live key cannot be used against sandbox.
	 *
	 * @return void
	 */
	public function test_sandbox_rejects_live_key(): void {
		$this->expectException( InvalidArgumentException::class );
		Environment::SANDBOX->assert_api_key( 'sk_live_test_fixture_not_secret' );
	}
}
