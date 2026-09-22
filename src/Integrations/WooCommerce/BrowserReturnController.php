<?php
/**
 * WooCommerce browser-return controller.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\WooCommerce;

use WC_Order;

/**
 * Handles the customer browser return without granting payment state.
 */
final class BrowserReturnController {
	/** WooCommerce API endpoint identifier. */
	public const ENDPOINT = 'etchpoint_bachs_return';

	/**
	 * Build the Bachs success URL for a Woo order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	public static function success_url( WC_Order $order ): string {
		return add_query_arg(
			array(
				'wc-api'   => self::ENDPOINT,
				'order_id' => $order->get_id(),
				'key'      => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	/**
	 * Handle the browser return as UX only.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$order_id = 0;
		$key      = '';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only provider return; no state is changed.
		if ( isset( $_GET['order_id'] ) && ! is_array( $_GET['order_id'] ) ) {
			$order_id = absint( sanitize_text_field( wp_unslash( $_GET['order_id'] ) ) );
		}

		if ( isset( $_GET['key'] ) && is_string( $_GET['key'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_die(
				esc_html__( 'This payment return link is invalid or has expired.', 'payment-integrations-for-bachs' ),
				esc_html__( 'Payment return unavailable', 'payment-integrations-for-bachs' ),
				array( 'response' => 400 )
			);

			return;
		}

		if ( $order->is_paid() ) {
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		wp_die(
			esc_html__( 'Your payment was submitted. We are confirming it with Bachs. Refresh this page in a moment.', 'payment-integrations-for-bachs' ),
			esc_html__( 'Confirming payment', 'payment-integrations-for-bachs' ),
			array( 'response' => 200 )
		);
	}
}
