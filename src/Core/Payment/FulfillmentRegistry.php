<?php
/**
 * Payment fulfillment registry.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Payment;

use Etchpoint\BachsIntegrations\Core\Contracts\PaymentFulfillmentHandler;
use InvalidArgumentException;

/**
 * Maps verified payment integration identifiers to host fulfillment handlers.
 */
final class FulfillmentRegistry {
	/**
	 * Registered handlers indexed by integration identifier.
	 *
	 * @var array<string, PaymentFulfillmentHandler>
	 */
	private array $handlers = array();

	/**
	 * Create a registry from fulfillment handlers.
	 *
	 * @param array<int, PaymentFulfillmentHandler> $handlers Fulfillment handlers.
	 *
	 * @throws InvalidArgumentException When two handlers claim the same integration.
	 */
	public function __construct( array $handlers ) {
		foreach ( $handlers as $handler ) {
			$integration = $handler->integration();

			if ( isset( $this->handlers[ $integration ] ) ) {
				throw new InvalidArgumentException( 'Duplicate payment fulfillment integration handler.' );
			}

			$this->handlers[ $integration ] = $handler;
		}
	}

	/**
	 * Find the handler for an integration.
	 *
	 * @param string $integration Integration identifier.
	 * @return PaymentFulfillmentHandler|null
	 */
	public function find( string $integration ): ?PaymentFulfillmentHandler {
		return $this->handlers[ $integration ] ?? null;
	}
}
