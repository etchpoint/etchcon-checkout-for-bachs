<?php
/**
 * Bachs API endpoint paths.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use InvalidArgumentException;

/**
 * Builds only the Bachs v1 endpoint paths used by this plugin.
 */
final class Endpoints {
	/** Checkout-session collection endpoint. */
	public const CHECKOUT_SESSIONS = '/v1/checkout-sessions';

	/** Payment collection endpoint. */
	public const PAYMENTS = '/v1/payments';

	/**
	 * Build the checkout-session retrieval endpoint.
	 *
	 * @param string $checkout_id Opaque Bachs checkout identifier.
	 * @return string
	 */
	public static function checkout_session( string $checkout_id ): string {
		return self::resource_path( self::CHECKOUT_SESSIONS, $checkout_id, 'Checkout ID' );
	}

	/**
	 * Build the payment retrieval endpoint.
	 *
	 * @param string $payment_id Opaque Bachs payment/charge identifier.
	 * @return string
	 */
	public static function payment( string $payment_id ): string {
		return self::resource_path( self::PAYMENTS, $payment_id, 'Payment ID' );
	}

	/**
	 * Build a resource endpoint from an opaque identifier.
	 *
	 * @param string $collection Collection endpoint.
	 * @param string $identifier Opaque provider identifier.
	 * @param string $label      Human-readable identifier label.
	 * @return string
	 *
	 * @throws InvalidArgumentException When the identifier is empty or padded.
	 */
	private static function resource_path( string $collection, string $identifier, string $label ): string {
		if ( '' === $identifier || $identifier !== trim( $identifier ) ) {
			// Exception text is not rendered output.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $label . ' must be a non-empty value without surrounding whitespace.' );
		}

		return $collection . '/' . rawurlencode( $identifier );
	}
}
