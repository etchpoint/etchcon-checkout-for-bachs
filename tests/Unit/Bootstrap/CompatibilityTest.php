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

final class CompatibilityTest extends TestCase {
	public function test_php_81_is_supported(): void {
		self::assertTrue( Compatibility::supports_php_version( '8.1.0' ) );
	}

	public function test_php_80_is_not_supported(): void {
		self::assertFalse( Compatibility::supports_php_version( '8.0.30' ) );
	}

	public function test_wordpress_68_is_supported(): void {
		self::assertTrue( Compatibility::supports_wordpress_version( '6.8' ) );
	}

	public function test_wordpress_67_is_not_supported(): void {
		self::assertFalse( Compatibility::supports_wordpress_version( '6.7.2' ) );
	}
}
