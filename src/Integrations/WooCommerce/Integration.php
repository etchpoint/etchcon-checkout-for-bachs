<?php
/**
 * WooCommerce integration bootstrap.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Registers WooCommerce gateway, Blocks, return, and compatibility hooks.
 */
final class Integration {
	/**
	 * Absolute main plugin file path.
	 *
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Register WooCommerce feature compatibility declarations early.
	 *
	 * @param string $plugin_file Absolute main plugin file path.
	 * @return void
	 */
	public static function register_compatibility( string $plugin_file ): void {
		self::$plugin_file = $plugin_file;

		add_action(
			'before_woocommerce_init',
			array( self::class, 'declare_compatibility' )
		);
	}

	/**
	 * Register WooCommerce runtime hooks when the host plugin is available.
	 *
	 * @param string $plugin_file Absolute main plugin file path.
	 * @return void
	 */
	public static function register( string $plugin_file ): void {
		self::$plugin_file = $plugin_file;

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		add_filter( 'woocommerce_payment_gateways', array( self::class, 'add_gateway' ) );
		add_action( 'woocommerce_api_' . BrowserReturnController::ENDPOINT, array( BrowserReturnController::class, 'handle' ) );
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			array( self::class, 'register_blocks_payment_method' )
		);
	}

	/**
	 * Declare HPOS and Cart/Checkout Blocks compatibility.
	 *
	 * @return void
	 */
	public static function declare_compatibility(): void {
		if ( '' === self::$plugin_file || ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', self::$plugin_file, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', self::$plugin_file, true );
	}

	/**
	 * Add the Bachs gateway to WooCommerce.
	 *
	 * @param array<int, string> $methods Existing gateway class names.
	 * @return array<int, string>
	 */
	public static function add_gateway( array $methods ): array {
		$methods[] = Gateway::class;

		return $methods;
	}

	/**
	 * Register the Bachs server-side Checkout Blocks payment method type.
	 *
	 * @param PaymentMethodRegistry $registry WooCommerce payment method registry.
	 * @return void
	 */
	public static function register_blocks_payment_method( PaymentMethodRegistry $registry ): void {
		if (
			'' === self::$plugin_file
			|| ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class )
		) {
			return;
		}

		$registry->register( new BlocksPaymentMethod( self::$plugin_file ) );
	}
}
