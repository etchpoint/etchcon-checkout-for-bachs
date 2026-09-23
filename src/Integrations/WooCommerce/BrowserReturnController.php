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
	/** Legacy WooCommerce API endpoint identifier kept for existing checkouts. */
	public const ENDPOINT = 'etchpoint_bachs_return';

	/** Query flag marking a Bachs browser return on the normal WooCommerce page. */
	private const RETURN_QUERY_ARG = 'bachs_return';

	/** Maximum automatic confirmation-page refreshes. */
	private const MAX_REFRESH_ATTEMPTS = 24;

	/** Delay between automatic status refreshes in milliseconds. */
	private const REFRESH_DELAY_MS = 2500;

	/**
	 * Build the Bachs success URL for a Woo order.
	 *
	 * The customer returns to WooCommerce's normal order-received page. Payment
	 * state is still granted only by verified webhook/reconciliation evidence.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	public static function success_url( WC_Order $order ): string {
		return add_query_arg(
			self::RETURN_QUERY_ARG,
			'1',
			$order->get_checkout_order_received_url()
		);
	}

	/**
	 * Handle the legacy browser-return endpoint used by already-created sessions.
	 *
	 * @return never
	 */
	public static function handle(): never {
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
		}

		wp_safe_redirect( self::success_url( $order ) );
		exit;
	}

	/**
	 * Replace WooCommerce's generic received text while Bachs is still confirming.
	 *
	 * @param string $text   Existing WooCommerce order-received text.
	 * @param mixed  $order  WooCommerce order when available.
	 * @return string
	 */
	public static function filter_order_received_text( string $text, mixed $order ): string {
		if ( ! self::is_pending_bachs_return( $order ) ) {
			return $text;
		}

		return esc_html__( 'Payment submitted. We are confirming it with Bachs.', 'payment-integrations-for-bachs' );
	}

	/**
	 * Render an automatic confirmation state on the normal WooCommerce thank-you page.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return void
	 */
	public static function render_confirmation_status( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! self::is_pending_bachs_return( $order ) ) {
			return;
		}

		$storage_key = 'etchpoint_bachs_confirm_' . $order_id;
		?>
		<section class="woocommerce-order bachs-payment-confirmation" aria-live="polite">
			<h2><?php echo esc_html__( 'Confirming your payment', 'payment-integrations-for-bachs' ); ?></h2>
			<p id="etchpoint-bachs-confirmation-message">
				<?php echo esc_html__( 'Bachs has returned you to the store. We are waiting for the secure payment confirmation. This page updates automatically, so you do not need to refresh it.', 'payment-integrations-for-bachs' ); ?>
			</p>
		</section>
		<script>
		(function () {
			'use strict';

			var storageKey = '<?php echo esc_js( $storage_key ); ?>';
			var maxAttempts = <?php echo absint( self::MAX_REFRESH_ATTEMPTS ); ?>;
			var delay = <?php echo absint( self::REFRESH_DELAY_MS ); ?>;
			var attempts = 0;
			var message = document.getElementById('etchpoint-bachs-confirmation-message');

			try {
				attempts = parseInt(window.sessionStorage.getItem(storageKey) || '0', 10);
			} catch (error) {
				attempts = 0;
			}

			if (attempts >= maxAttempts) {
				if (message) {
					message.textContent = '<?php echo esc_js( __( 'Confirmation is taking longer than usual. You can safely leave this page. Your order will update when Bachs confirms the payment.', 'payment-integrations-for-bachs' ) ); ?>';
				}
				return;
			}

			try {
				window.sessionStorage.setItem(storageKey, String(attempts + 1));
			} catch (error) {
				// Session storage is optional; automatic refresh still works without it.
			}

			window.setTimeout(function () {
				window.location.reload();
			}, delay);
		}());
		</script>
		<?php
	}

	/**
	 * Determine whether this request is the pending Bachs browser return.
	 *
	 * @param mixed $order WooCommerce order when available.
	 * @return bool
	 */
	private static function is_pending_bachs_return( mixed $order ): bool {
		if ( ! $order instanceof WC_Order || $order->is_paid() || Gateway::ID !== $order->get_payment_method() ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display flag on the WooCommerce thank-you page.
		if ( ! isset( $_GET[ self::RETURN_QUERY_ARG ] ) || is_array( $_GET[ self::RETURN_QUERY_ARG ] ) ) {
			return false;
		}

		$return_flag = sanitize_text_field( wp_unslash( $_GET[ self::RETURN_QUERY_ARG ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '1' === $return_flag;
	}
}
