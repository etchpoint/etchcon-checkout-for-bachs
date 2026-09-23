<?php
/**
 * Refund fulfillment registry.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Refund;

use Etchpoint\BachsIntegrations\Core\Contracts\RefundFulfillmentHandler;
use InvalidArgumentException;

/**
 * Maps refund integration identifiers to host fulfillment handlers.
 */
final class RefundFulfillmentRegistry {
	/**
	 * Registered handlers indexed by integration identifier.
	 *
	 * @var array<string, RefundFulfillmentHandler>
	 */
	private array $handlers = array();

	/**
	 * Create the registry.
	 *
	 * @param array<int, RefundFulfillmentHandler> $handlers Refund handlers.
	 *
	 * @throws InvalidArgumentException When duplicate integrations are registered.
	 */
	public function __construct( array $handlers ) {
		foreach ( $handlers as $handler ) {
			$integration = $handler->integration();

			if ( isset( $this->handlers[ $integration ] ) ) {
				throw new InvalidArgumentException( 'Duplicate refund fulfillment integration handler.' );
			}

			$this->handlers[ $integration ] = $handler;
		}
	}

	/**
	 * Find the handler for an integration.
	 *
	 * @param string $integration Integration identifier.
	 * @return RefundFulfillmentHandler|null
	 */
	public function find( string $integration ): ?RefundFulfillmentHandler {
		return $this->handlers[ $integration ] ?? null;
	}
}
