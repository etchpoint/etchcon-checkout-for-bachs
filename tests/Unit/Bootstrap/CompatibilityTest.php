<?php
/**
 * Compatibility tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bootstrap;

use Etchpoint\BachsIntegrations\Bootstrap\Compatibility;
use PHPUnit\Framework\TestCase;

/**
 * Tests the minimum PHP and WordPress version checks.
 */
final class CompatibilityTest extends TestCase {
	/**
	 * Verify that PHP 8.1 is supported.
	 *
	 * @return void
	 */
	public function test_php_81_is_supported(): void {
		self::assertTrue( Compatibility::supports_php_version( '8.1.0' ) );
	}

	/**
	 * Verify that PHP 8.0 is not supported.
	 *
	 * @return void
	 */
	public function test_php_80_is_not_supported(): void {
		self::assertFalse( Compatibility::supports_php_version( '8.0.30' ) );
	}

	/**
	 * Verify that WordPress 6.8 is supported.
	 *
	 * @return void
	 */
	public function test_wordpress_68_is_supported(): void {
		self::assertTrue( Compatibility::supports_wordpress_version( '6.8' ) );
	}

	/**
	 * Verify that WordPress 6.7 is not supported.
	 *
	 * @return void
	 */
	public function test_wordpress_67_is_not_supported(): void {
		self::assertFalse( Compatibility::supports_wordpress_version( '6.7.2' ) );
	}
}
