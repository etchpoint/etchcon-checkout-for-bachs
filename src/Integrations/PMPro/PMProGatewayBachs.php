<?php
/**
 * Paid Memberships Pro global Bachs gateway adapter.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

use Etchpoint\BachsIntegrations\Integrations\PMPro\PMProGatewayRuntime;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- PMPro resolves gateways using the global PMProGateway_{gateway} class naming convention.
/**
 * Exposes Bachs through Paid Memberships Pro's supported gateway class API.
 */
final class PMProGateway_Bachs extends PMProGateway {
	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
	/**
	 * Create the PMPro Bachs gateway object.
	 *
	 * @param string|null $gateway Optional gateway identifier supplied by PMPro.
	 */
	public function __construct( $gateway = null ) {
		$this->gateway             = $gateway ?? 'bachs';
		$this->gateway_environment = get_option( 'pmpro_gateway_environment', 'sandbox' );
	}

	/**
	 * Report supported Paid Memberships Pro gateway features.
	 *
	 * Recurring billing, billing updates, refunds, and token-order polling are
	 * intentionally not advertised by the initial one-time integration.
	 *
	 * @param string $feature Paid Memberships Pro feature identifier.
	 * @return bool
	 */
	public static function supports( $feature ) {
		unset( $feature );

		return false;
	}

	/**
	 * Describe the gateway on Paid Memberships Pro payment settings.
	 *
	 * @return string
	 */
	public static function get_description_for_gateway_settings() {
		return esc_html__(
			'Accept one-time membership payments through Bachs hosted checkout. Configure shared Bachs credentials under Bachs Payments > Settings.',
			'etchcon-checkout-for-bachs'
		);
	}

	/**
	 * Process the Paid Memberships Pro order through Bachs hosted checkout.
	 *
	 * @param MemberOrder $order Paid Memberships Pro order.
	 * @return bool
	 */
	public function process( &$order ) {
		if ( ! $order instanceof MemberOrder ) {
			return false;
		}

		return PMProGatewayRuntime::process( $order );
	}
}
