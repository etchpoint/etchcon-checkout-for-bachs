<?php
/**
 * Diagnostic value object tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Diagnostics;

use Etchpoint\BachsIntegrations\Diagnostics\DiagnosticCheck;
use Etchpoint\BachsIntegrations\Diagnostics\DiagnosticStatus;
use PHPUnit\Framework\TestCase;

/**
 * Verifies safe diagnostic result data remains explicit and typed.
 */
final class DiagnosticCheckTest extends TestCase {
	/**
	 * Diagnostic values are exposed without hidden transformation.
	 *
	 * @return void
	 */
	public function test_check_exposes_safe_fields(): void {
		$check = new DiagnosticCheck( 'api', 'Bachs API', DiagnosticStatus::HEALTHY, 'Healthy' );

		self::assertSame( 'api', $check->key() );
		self::assertSame( 'Bachs API', $check->label() );
		self::assertSame( DiagnosticStatus::HEALTHY, $check->status() );
		self::assertSame( 'Healthy', $check->detail() );
	}
}
