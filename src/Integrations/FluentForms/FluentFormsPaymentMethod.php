<?php
/**
 * Fluent Forms payment-method registration.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\FluentForms;

use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use FluentFormPro\Payments\PaymentMethods\BasePaymentMethod;
use Throwable;

/**
 * Exposes Bachs in Fluent Forms Pro payment settings and form payment methods.
 */
final class FluentFormsPaymentMethod extends BasePaymentMethod {
	/** Payment method identifier used by Fluent Forms. */
	private const METHOD = 'bachs';

	/**
	 * Whether this instance has registered its hooks.
	 *
	 * @var bool
	 */
	private bool $hooks_registered = false;

	/**
	 * Create the Fluent Forms payment method.
	 */
	public function __construct() {
		parent::__construct( self::METHOD );
	}

	/**
	 * Register payment-method hooks once.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( $this->hooks_registered ) {
			return;
		}

		$this->hooks_registered = true;
		add_filter( 'fluentform/available_payment_methods', array( $this, 'pushPaymentMethodToForm' ) );
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Fluent Forms API method.
	/**
	 * Add Bachs to the form builder payment methods.
	 *
	 * @param array<string, mixed> $methods Existing payment methods.
	 * @return array<string, mixed>
	 */
	public function pushPaymentMethodToForm( $methods ) {
		// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$enabled = 'no';

		try {
			$settings      = $this->getGlobalSettings();
			$configuration = RuntimeConfiguration::from_wordpress();

			if ( 'yes' === ( $settings['is_active'] ?? 'no' ) && $configuration->is_payment_ready() ) {
				$enabled = 'yes';
			}
		} catch ( Throwable ) {
			$enabled = 'no';
		}

		$methods[ self::METHOD ] = array(
			'title'        => __( 'Bachs', 'etchcon-checkout-for-bachs' ),
			'enabled'      => $enabled,
			'method_value' => self::METHOD,
			'settings'     => array(
				'option_label' => array(
					'type'     => 'text',
					'template' => 'inputText',
					'value'    => __( 'Pay with Bachs', 'etchcon-checkout-for-bachs' ),
					'label'    => __( 'Method label', 'etchcon-checkout-for-bachs' ),
				),
			),
		);

		return $methods;
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Fluent Forms API method.
	/**
	 * Define Fluent Forms global settings for Bachs.
	 *
	 * Secrets remain in hardened plugin configuration rather than Fluent Forms.
	 *
	 * @return array<string, mixed>
	 */
	public function getGlobalFields() {
		// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return array(
			'label'  => __( 'Bachs Payment Settings', 'etchcon-checkout-for-bachs' ),
			'fields' => array(
				array(
					'settings_key'   => 'is_active',
					'type'           => 'yes-no-checkbox',
					'label'          => __( 'Status', 'etchcon-checkout-for-bachs' ),
					'checkbox_label' => __( 'Enable Bachs for Fluent Forms', 'etchcon-checkout-for-bachs' ),
				),
			),
		);
	}

	// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Fluent Forms API method.
	/**
	 * Get saved Fluent Forms settings for this payment method.
	 *
	 * @return array<string, mixed>
	 */
	public function getGlobalSettings() {
		// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		$settings = get_option( 'fluentform_payment_settings_' . self::METHOD, array() );

		return is_array( $settings ) ? $settings : array();
	}
}
