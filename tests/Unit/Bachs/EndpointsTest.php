<?php
/**
 * Bachs endpoint tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs;

use Etchpoint\BachsIntegrations\Bachs\Endpoints;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies fixed v1 resource paths and safe opaque identifiers.
 */
final class EndpointsTest extends TestCase {
	/**
	 * Checkout IDs are encoded as one path segment.
	 *
	 * @return void
	 */
	public function test_checkout_id_is_url_encoded(): void {
		self::assertSame( '/v1/checkout-sessions/chk_test%2Fsegment', Endpoints::checkout_session( 'chk_test/segment' ) );
	}

	/**
	 * Payment IDs are encoded as one path segment.
	 *
	 * @return void
	 */
	public function test_payment_id_is_url_encoded(): void {
		self::assertSame( '/v1/payments/pay_test%3Fquery', Endpoints::payment( 'pay_test?query' ) );
	}

	/**
	 * Empty provider identifiers are rejected.
	 *
	 * @return void
	 */
	public function test_empty_identifier_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		Endpoints::payment( '' );
	}
}
