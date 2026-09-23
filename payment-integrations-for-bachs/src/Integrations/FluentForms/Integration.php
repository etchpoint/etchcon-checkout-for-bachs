<?php
/**
 * Fluent Forms integration bootstrap.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\FluentForms;

use Etchpoint\BachsIntegrations\Bootstrap\Compatibility;
use FluentFormPro\Payments\PaymentMethods\BasePaymentMethod;
use FluentFormPro\Payments\PaymentMethods\BaseProcessor;

/**
 * Loads Bachs only when Fluent Forms Pro payment extension APIs are available.
 */
final class Integration {
	/**
	 * Register delayed Fluent Forms discovery.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'load' ), 20 );
	}

	/**
	 * Register the Fluent Forms method and processor when Pro payments are ready.
	 *
	 * @return void
	 */
	public static function load(): void {
		if ( ! Compatibility::is_current_environment_supported() ) {
			return;
		}

		if ( class_exists( BasePaymentMethod::class ) && class_exists( BaseProcessor::class ) ) {
			$payment_method = new FluentFormsPaymentMethod();
			$processor      = new FluentFormsProcessor();

			$payment_method->init();
			$processor->init();
			return;
		}

		if ( defined( 'FLUENTFORM' ) ) {
			add_action( 'admin_notices', array( self::class, 'pro_required_notice' ) );
		}
	}

	/**
	 * Explain that Fluent Forms Free alone does not expose payment extension APIs.
	 *
	 * @return void
	 */
	public static function pro_required_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-info"><p>'
			. esc_html__( 'Fluent Forms detected. Bachs payment integration requires Fluent Forms Pro payment functionality.', 'payment-integrations-for-bachs' )
			. '</p></div>';
	}
}
