<?php
/**
 * WooCommerce Checkout Blocks payment method integration.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Etchpoint\BachsIntegrations\Bootstrap\Plugin;

/**
 * Exposes the Bachs gateway to WooCommerce Cart and Checkout Blocks.
 */
final class BlocksPaymentMethod extends AbstractPaymentMethodType {
	/** Payment method name matching the gateway identifier. */
	protected $name = Gateway::ID;

	/**
	 * Absolute main plugin file path.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Create the Blocks integration.
	 *
	 * @param string $plugin_file Absolute main plugin file path.
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * Load gateway settings.
	 *
	 * @return void
	 */
	public function initialize(): void {
		$settings = get_option( 'woocommerce_' . $this->name . '_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	/**
	 * Determine whether the payment method should be exposed to Blocks.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( 'yes' !== $this->get_setting( 'enabled', 'no' ) ) {
			return false;
		}

		try {
			return \Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration::from_wordpress()->is_payment_ready();
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Register the lightweight Blocks payment method script.
	 *
	 * @return array<int, string>
	 */
	public function get_payment_method_script_handles(): array {
		$handle = 'etchpoint-bachs-woocommerce-blocks';

		wp_register_script(
			$handle,
			plugins_url( 'assets/js/woocommerce-blocks.js', $this->plugin_file ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n' ),
			Plugin::VERSION,
			true
		);

		return array( $handle );
	}

	/**
	 * Expose non-sensitive payment method settings to Checkout Blocks.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return array(
			'title'       => $this->get_setting( 'title', __( 'Bachs', 'etchcon-checkout-for-bachs' ) ),
			'description' => $this->get_setting(
				'description',
				__( 'Pay securely using Bachs.', 'etchcon-checkout-for-bachs' )
			),
			'supports'    => $this->get_supported_features(),
		);
	}
}
