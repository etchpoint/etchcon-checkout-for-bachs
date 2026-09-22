<?php
/**
 * Paid Memberships Pro integration bootstrap.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\PMPro;

/**
 * Registers the Bachs gateway only when Paid Memberships Pro is available.
 */
final class Integration {
	/** Bachs gateway identifier used by Paid Memberships Pro. */
	private const GATEWAY = 'bachs';

	/**
	 * Register Paid Memberships Pro runtime hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! class_exists( 'PMProGateway' ) || ! class_exists( 'MemberOrder' ) ) {
			return;
		}

		require_once __DIR__ . '/PMProGatewayBachs.php';

		add_filter( 'pmpro_gateways', array( self::class, 'add_gateway' ) );
		add_filter( 'pmpro_include_payment_information_fields', array( self::class, 'include_payment_information_fields' ) );
		add_filter( 'pmpro_required_billing_fields', array( self::class, 'required_billing_fields' ) );
	}

	/**
	 * Add Bachs to Paid Memberships Pro's gateway selector.
	 *
	 * @param array<string, string> $gateways Existing gateway labels.
	 * @return array<string, string>
	 */
	public static function add_gateway( array $gateways ): array {
		$gateways[ self::GATEWAY ] = __( 'Bachs', 'payment-integrations-for-bachs' );

		return $gateways;
	}

	/**
	 * Hide local card fields because Bachs uses hosted checkout.
	 *
	 * @param bool $include Whether PMPro would normally render payment fields.
	 * @return bool
	 */
	public static function include_payment_information_fields( bool $include ): bool {
		return self::is_active_gateway() ? false : $include;
	}

	/**
	 * Remove card fields from PMPro's required-field validation for Bachs.
	 *
	 * Billing address fields are preserved because sites may use them for tax or
	 * membership data independently of the hosted payment page.
	 *
	 * @param array<string, mixed> $fields Required PMPro billing fields.
	 * @return array<string, mixed>
	 */
	public static function required_billing_fields( array $fields ): array {
		if ( ! self::is_active_gateway() ) {
			return $fields;
		}

		foreach ( array( 'CardType', 'AccountNumber', 'ExpirationMonth', 'ExpirationYear', 'CVV' ) as $field ) {
			unset( $fields[ $field ] );
		}

		return $fields;
	}

	/**
	 * Determine whether the current PMPro checkout is using Bachs.
	 *
	 * @return bool
	 */
	private static function is_active_gateway(): bool {
		return self::GATEWAY === pmpro_getGateway();
	}
}
