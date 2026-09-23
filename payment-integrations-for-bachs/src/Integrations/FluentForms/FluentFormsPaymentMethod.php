<?php
/**
 * Fluent Forms Bachs payment method registration.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\FluentForms;

use FluentFormPro\Payments\PaymentMethods\BasePaymentMethod;

/**
 * Exposes Bachs as a Fluent Forms Pro payment method.
 */
final class FluentFormsPaymentMethod extends BasePaymentMethod {
	/**
	 * Register the Bachs payment-method key with Fluent Forms Pro.
	 */
	public function __construct() {
		parent::__construct( 'bachs' );
	}

	/**
	 * Register Fluent Forms payment-method filters.
	 *
	 * @return void
	 */
	public function init(): void {
		if ( ! $this->isEnabled() ) {
			return;
		}

		add_filter( 'fluentform/available_payment_methods', array( $this, 'pushPaymentMethodToForm' ) );
		add_filter( 'fluentform/transaction_data_' . $this->key, array( $this, 'modifyTransaction' ) );
	}

	/**
	 * Add Bachs to the payment methods available in the form editor.
	 *
	 * @param array<string, mixed> $methods Existing payment methods.
	 * @return array<string, mixed>
	 */
	public function pushPaymentMethodToForm( $methods ): array {
		$methods[ $this->key ] = array(
			'title'        => __( 'Bachs', 'payment-integrations-for-bachs' ),
			'enabled'      => 'yes',
			'method_value' => $this->key,
			'settings'     => array(
				'option_label' => array(
					'type'     => 'text',
					'template' => 'inputText',
					'value'    => __( 'Pay securely with Bachs', 'payment-integrations-for-bachs' ),
					'label'    => __( 'Method label', 'payment-integrations-for-bachs' ),
				),
			),
		);

		return $methods;
	}

	/**
	 * Leave Fluent Forms transaction presentation unchanged.
	 *
	 * @param object $transaction Fluent Forms transaction object.
	 * @return object
	 */
	public function modifyTransaction( $transaction ): object {
		return $transaction;
	}

	/**
	 * Return the payment-method settings shown by Fluent Forms Pro.
	 *
	 * Bachs credentials remain centralized in this plugin's settings rather than
	 * duplicated inside Fluent Forms.
	 *
	 * @return array<string, mixed>
	 */
	public function getGlobalFields(): array {
		return array(
			'label'  => __( 'Bachs', 'payment-integrations-for-bachs' ),
			'fields' => array(
				array(
					'settings_key'   => 'is_active',
					'type'           => 'yes-no-checkbox',
					'label'          => __( 'Status', 'payment-integrations-for-bachs' ),
					'checkbox_label' => __( 'Enable Bachs payments', 'payment-integrations-for-bachs' ),
				),
			),
		);
	}

	/**
	 * Return saved Fluent Forms payment-method settings.
	 *
	 * @return array<string, mixed>
	 */
	public function getGlobalSettings(): array {
		$settings = get_option( 'fluentform_payment_settings_bachs', array() );

		return is_array( $settings ) ? $settings : array();
	}
}
