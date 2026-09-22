<?php
/**
 * Fulfillment registry tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Core\Payment;

use Etchpoint\BachsIntegrations\Core\Contracts\PaymentFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies deterministic integration handler lookup.
 */
final class FulfillmentRegistryTest extends TestCase {
	/**
	 * Registry returns the handler that owns an integration identifier.
	 *
	 * @return void
	 */
	public function test_registry_returns_matching_handler(): void {
		$handler = $this->createMock( PaymentFulfillmentHandler::class );
		$handler->method( 'integration' )->willReturn( 'woocommerce' );

		$registry = new FulfillmentRegistry( array( $handler ) );

		self::assertSame( $handler, $registry->find( 'woocommerce' ) );
		self::assertNull( $registry->find( 'pmpro' ) );
	}

	/**
	 * Duplicate integration handlers are rejected.
	 *
	 * @return void
	 */
	public function test_duplicate_integration_handlers_are_rejected(): void {
		$first = $this->createMock( PaymentFulfillmentHandler::class );
		$first->method( 'integration' )->willReturn( 'woocommerce' );
		$second = $this->createMock( PaymentFulfillmentHandler::class );
		$second->method( 'integration' )->willReturn( 'woocommerce' );

		$this->expectException( InvalidArgumentException::class );

		new FulfillmentRegistry( array( $first, $second ) );
	}
}
