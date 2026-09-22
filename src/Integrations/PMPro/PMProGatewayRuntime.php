<?php
/**
 * Paid Memberships Pro Bachs gateway runtime.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\PMPro;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\CheckoutApi;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use MemberOrder;
use Throwable;

/**
 * Starts Bachs hosted checkout from the current PMPro order.
 */
final class PMProGatewayRuntime {
	/**
	 * Process a one-time Paid Memberships Pro order.
	 *
	 * Successful execution redirects to Bachs hosted checkout and terminates the
	 * request. A false return means PMPro should keep checkout in an error state.
	 *
	 * @param MemberOrder $order Paid Memberships Pro order being processed.
	 * @return bool
	 */
	public static function process( MemberOrder $order ): bool {
		$membership_level = $order->membership_level;

		if ( ! is_object( $membership_level ) || pmpro_isLevelRecurring( $membership_level ) ) {
			self::set_error(
				$order,
				__( 'Bachs currently supports one-time Paid Memberships Pro checkouts only.', 'payment-integrations-for-bachs' )
			);

			return false;
		}

		try {
			$configuration = RuntimeConfiguration::from_wordpress();

			if ( ! $configuration->is_payment_ready() ) {
				self::set_error(
					$order,
					__( 'Bachs payment configuration is incomplete.', 'payment-integrations-for-bachs' )
				);

				return false;
			}

			$order->gateway             = 'bachs';
			$order->gateway_environment = $configuration->environment()->value;
			$order->status              = 'token';

			if ( ! $order->saveOrder() || 1 > (int) $order->id ) {
				self::set_error(
					$order,
					__( 'Unable to prepare the membership order for payment.', 'payment-integrations-for-bachs' )
				);

				return false;
			}

			pmpro_save_checkout_data_to_order( $order );

			$membership_id = (int) $order->membership_id;
			$success_url   = pmpro_url( 'confirmation', '?pmpro_level=' . $membership_id );
			$success_url   = (string) apply_filters(
				'pmpro_confirmation_url',
				$success_url,
				(int) $order->user_id,
				$membership_level
			);
			$cancel_url    = pmpro_url( 'checkout', '?pmpro_level=' . $membership_id );
			$client        = new ApiClient( $configuration->environment(), $configuration->api_key() );
			$coordinator   = new PMProCheckoutCoordinator(
				self::intent_repository(),
				new CheckoutApi( $client ),
				$configuration->environment(),
				substr( hash( 'sha256', home_url( '/' ) ), 0, 12 )
			);
			$result        = $coordinator->start(
				(int) $order->id,
				$membership_id,
				(string) $order->total,
				(string) get_option( 'pmpro_currency', 'USD' ),
				$success_url,
				$cancel_url
			);

			update_pmpro_membership_order_meta( (int) $order->id, '_etchpoint_bachs_intent_uuid', $result->intent_uuid() );
			update_pmpro_membership_order_meta( (int) $order->id, '_etchpoint_bachs_checkout_id', $result->checkout_id() );

			// External redirect is intentional. The URL comes from a checkout session
			// created through the fixed-host Bachs API client.
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			wp_redirect( $result->redirect_url() );
			exit;
		} catch ( Throwable ) {
			self::set_error(
				$order,
				__( 'Bachs checkout could not be started. Please try again.', 'payment-integrations-for-bachs' )
			);

			return false;
		}
	}

	/**
	 * Store a user-safe checkout error on the PMPro order.
	 *
	 * @param MemberOrder $order   Paid Memberships Pro order.
	 * @param string      $message User-safe error message.
	 * @return void
	 */
	private static function set_error( MemberOrder $order, string $message ): void {
		$order->error      = $message;
		$order->shorterror = $message;
	}

	/**
	 * Create the payment-intent repository from the active WordPress database.
	 *
	 * @return IntentRepository
	 */
	private static function intent_repository(): IntentRepository {
		global $wpdb;

		return new IntentRepository( $wpdb );
	}
}
