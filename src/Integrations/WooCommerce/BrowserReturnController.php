<?php
/**
 * WooCommerce browser-return controller.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\WooCommerce;

use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Etchpoint\BachsIntegrations\Reconciliation\ReconciliationRuntime;
use Throwable;
use WC_Order;

/**
 * Handles the customer browser return without granting payment state.
 */
final class BrowserReturnController {
	/** Legacy WooCommerce API endpoint identifier kept for existing checkouts. */
	public const ENDPOINT = 'etchpoint_bachs_return';

	/** AJAX action used to check one returned WooCommerce order. */
	public const STATUS_ACTION = 'etchpoint_bachs_order_status';

	/** Query flag marking a Bachs browser return on the normal WooCommerce page. */
	private const RETURN_QUERY_ARG = 'bachs_return';

	/** Nonce action prefix for browser-return status requests. */
	private const STATUS_NONCE_ACTION = 'etchpoint_bachs_order_status';

	/** Maximum automatic confirmation status checks. */
	private const MAX_STATUS_ATTEMPTS = 24;

	/** Delay between automatic status checks in milliseconds. */
	private const STATUS_DELAY_MS = 2500;

	/** Minimum delay between authoritative Bachs verification calls. */
	private const RECONCILIATION_THROTTLE_SECONDS = 8;

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
	 * Return the current payment state for one authenticated browser-return order.
	 *
	 * This is read-only. The order key and AJAX nonce prevent the endpoint from
	 * exposing arbitrary WooCommerce order status information.
	 *
	 * @return never
	 */
	public static function status(): never {
		check_ajax_referer( self::STATUS_NONCE_ACTION, 'nonce' );

		$order_id = isset( $_POST['order_id'] ) && ! is_array( $_POST['order_id'] )
			? absint( wp_unslash( $_POST['order_id'] ) )
			: 0;
		$key      = isset( $_POST['order_key'] ) && is_string( $_POST['order_key'] )
			? sanitize_text_field( wp_unslash( $_POST['order_key'] ) )
			: '';

		if ( $order_id < 1 ) {
			wp_send_json_error( array( 'state' => 'invalid' ), 400 );
		}

		$order = wc_get_order( $order_id );

		if (
			! $order instanceof WC_Order
			|| '' === $key
			|| ! hash_equals( $order->get_order_key(), $key )
			|| Gateway::ID !== $order->get_payment_method()
		) {
			wp_send_json_error( array( 'state' => 'invalid' ), 403 );
		}

		if ( ! $order->is_paid() ) {
			self::maybe_reconcile_pending_order( $order );
			$order = wc_get_order( $order_id );
		}

		if ( $order instanceof WC_Order && $order->is_paid() ) {
			wp_send_json_success(
				array(
					'state'    => 'paid',
					'redirect' => $order->get_checkout_order_received_url(),
				)
			);
		}

		if ( $order instanceof WC_Order && $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ) ) {
			wp_send_json_success( array( 'state' => 'failed' ) );
		}

		wp_send_json_success( array( 'state' => 'pending' ) );
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
	 * The page polls quietly in the background while the order is pending. It only
	 * navigates once, after WooCommerce records the payment as paid.
	 *
	 * @param int $order_id WooCommerce order identifier.
	 * @return void
	 */
	public static function render_confirmation_status( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! self::is_pending_bachs_return( $order ) || ! $order instanceof WC_Order ) {
			return;
		}

		$storage_key = 'etchpoint_bachs_confirm_' . $order_id;
		$nonce       = wp_create_nonce( self::STATUS_NONCE_ACTION );
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
			var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var orderId = <?php echo absint( $order_id ); ?>;
			var orderKey = '<?php echo esc_js( $order->get_order_key() ); ?>';
			var nonce = '<?php echo esc_js( $nonce ); ?>';
			var maxAttempts = <?php echo absint( self::MAX_STATUS_ATTEMPTS ); ?>;
			var delay = <?php echo absint( self::STATUS_DELAY_MS ); ?>;
			var attempts = 0;
			var message = document.getElementById('etchpoint-bachs-confirmation-message');

			try {
				attempts = parseInt(window.sessionStorage.getItem(storageKey) || '0', 10);
			} catch (error) {
				attempts = 0;
			}

			function setAttempts(value) {
				attempts = value;
				try {
					window.sessionStorage.setItem(storageKey, String(value));
				} catch (error) {
					// Session storage is optional.
				}
			}

			function stopWaiting() {
				if (message) {
					message.textContent = '<?php echo esc_js( __( 'Confirmation is taking longer than usual. You can safely leave this page. Your order will update when Bachs confirms the payment.', 'payment-integrations-for-bachs' ) ); ?>';
				}
			}

			function scheduleNext() {
				if (attempts >= maxAttempts) {
					stopWaiting();
					return;
				}

				window.setTimeout(checkStatus, delay);
			}

			function checkStatus() {
				var body = new URLSearchParams();
				body.set('action', '<?php echo esc_js( self::STATUS_ACTION ); ?>');
				body.set('order_id', String(orderId));
				body.set('order_key', orderKey);
				body.set('nonce', nonce);

				setAttempts(attempts + 1);

				window.fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
					body: body.toString()
				}).then(function (response) {
					return response.json();
				}).then(function (payload) {
					if (!payload || !payload.success || !payload.data) {
						scheduleNext();
						return;
					}

					if ('paid' === payload.data.state && payload.data.redirect) {
						try {
							window.sessionStorage.removeItem(storageKey);
						} catch (error) {
							// Session storage is optional.
						}

						if (message) {
							message.textContent = '<?php echo esc_js( __( 'Payment confirmed. Loading your order confirmation…', 'payment-integrations-for-bachs' ) ); ?>';
						}

						window.location.replace(payload.data.redirect);
						return;
					}

					if ('failed' === payload.data.state) {
						if (message) {
							message.textContent = '<?php echo esc_js( __( 'Bachs could not confirm this payment. Please contact the store if you believe you were charged.', 'payment-integrations-for-bachs' ) ); ?>';
						}
						return;
					}

					scheduleNext();
				}).catch(function () {
					scheduleNext();
				});
			}

			if (attempts >= maxAttempts) {
				stopWaiting();
				return;
			}

			checkStatus();
		}());
		</script>
		<?php
	}

	/**
	 * Hide WooCommerce retry/cancel actions while a returned Bachs payment is being confirmed.
	 *
	 * The normal WooCommerce order-details template exposes Pay and Cancel for unpaid
	 * orders. On a Bachs success return those actions are misleading because the
	 * provider checkout has already been submitted and is awaiting authoritative
	 * confirmation.
	 *
	 * @param array<string, array<string, mixed>> $actions Existing WooCommerce order actions.
	 * @param WC_Order                           $order   WooCommerce order.
	 * @return array<string, array<string, mixed>>
	 */
	public static function filter_order_actions( array $actions, WC_Order $order ): array {
		if ( ! self::is_pending_bachs_return( $order ) ) {
			return $actions;
		}

		unset( $actions['pay'], $actions['cancel'] );

		return $actions;
	}

	/**
	 * Re-check authoritative Bachs state as a browser-return recovery path.
	 *
	 * Signed webhooks remain the primary fulfillment path. This bounded fallback
	 * verifies Bachs server-to-server and then uses the same reconciliation and
	 * fulfillment code if webhook delivery is delayed.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	private static function maybe_reconcile_pending_order( WC_Order $order ): void {
		$intent_uuid = trim( (string) $order->get_meta( '_etchpoint_bachs_intent_uuid', true ) );

		if ( '' === $intent_uuid ) {
			return;
		}

		$throttle_key = 'etchpoint_bachs_return_verify_' . $order->get_id();

		if ( false !== get_transient( $throttle_key ) ) {
			return;
		}

		set_transient( $throttle_key, '1', self::RECONCILIATION_THROTTLE_SECONDS );

		global $wpdb;

		try {
			$record = ( new IntentRepository( $wpdb ) )->find_by_uuid( $intent_uuid );

			if ( null !== $record ) {
				ReconciliationRuntime::reconcile_now( $record->id() );
			}
		} catch ( Throwable ) {
			// Browser-return recovery is best effort; the signed webhook remains authoritative.
		}
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
