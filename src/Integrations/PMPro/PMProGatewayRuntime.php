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
				__( 'Bachs currently supports one-time Paid Memberships Pro checkouts only.', 'etchcon-checkout-for-bachs' )
			);

			return false;
		}

		try {
			$configuration = RuntimeConfiguration::from_wordpress();

			if ( ! $configuration->is_payment_ready() ) {
				self::set_error(
					$order,
					__( 'Bachs payment configuration is incomplete.', 'etchcon-checkout-for-bachs' )
				);

				return false;
			}

			$order->gateway             = 'bachs';
			$order->gateway_environment = $configuration->environment()->value;
			$order->status              = 'token';

			if ( ! $order->saveOrder() || 1 > (int) $order->id ) {
				self::set_error(
					$order,
					__( 'Unable to prepare the membership order for payment.', 'etchcon-checkout-for-bachs' )
				);

				return false;
			}

			pmpro_save_checkout_data_to_order( $order );

			$membership_id = (int) $order->membership_id;
			$success_url   = pmpro_url( 'confirmation', '?pmpro_level=' . $membership_id );
			$success_url   = (string) apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reuse PMPro's existing confirmation URL filter for compatibility with its integrations.
				'pmpro_confirmation_url',
				$success_url,
				(int) $order->user_id,
				$membership_level
			);
			$cancel_url  = pmpro_url( 'checkout', '?pmpro_level=' . $membership_id );
			$client      = new ApiClient( $configuration->environment(), $configuration->api_key() );
			$coordinator = new PMProCheckoutCoordinator(
				self::intent_repository(),
				new CheckoutApi( $client ),
				$configuration->environment(),
				substr( hash( 'sha256', home_url( '/' ) ), 0, 12 )
			);
			$result      = $coordinator->start(
				(int) $order->id,
				$membership_id,
				(string) $order->total,
				(string) get_option( 'pmpro_currency', 'USD' ),
				$success_url,
				$cancel_url
			);

			update_pmpro_membership_order_meta( (int) $order->id, '_etchpoint_bachs_intent_uuid', $result->intent_uuid() );
			update_pmpro_membership_order_meta( (int) $order->id, '_etchpoint_bachs_checkout_id', $result->checkout_id() );

			self::redirect_to_bachs_checkout( $result->redirect_url() );
		} catch ( Throwable ) {
			self::set_error(
				$order,
				__( 'Bachs checkout could not be started. Please try again.', 'etchcon-checkout-for-bachs' )
			);

			return false;
		}
	}

	/**
	 * Redirect to the validated Bachs hosted checkout origin.
	 *
	 * CheckoutApi rejects any hosted checkout URL outside Bachs-owned HTTPS
	 * subdomains before this method is reached. The allowlist is added only for the
	 * duration of this redirect so WordPress can use wp_safe_redirect().
	 *
	 * @param string $url Validated Bachs hosted checkout URL.
	 * @return never
	 */
	private static function redirect_to_bachs_checkout( string $url ): never {
		add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_bachs_checkout_host' ), 10, 2 );
		wp_safe_redirect( $url );
		remove_filter( 'allowed_redirect_hosts', array( self::class, 'allow_bachs_checkout_host' ), 10 );
		exit;
	}

	/**
	 * Allow a validated Bachs hosted checkout origin for a safe redirect.
	 *
	 * @param array<int, string> $hosts WordPress redirect host allowlist.
	 * @param string             $host  Redirect host WordPress is validating.
	 * @return array<int, string>
	 */
	public static function allow_bachs_checkout_host( array $hosts, string $host = '' ): array {
		$host = strtolower( rtrim( $host, '.' ) );

		if ( '' !== $host && str_ends_with( $host, '.bachs.io' ) && ! in_array( $host, $hosts, true ) ) {
			$hosts[] = $host;
		}

		return $hosts;
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
