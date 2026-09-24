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
			add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
			return;
		}
	}

	/**
	 * Enqueue the lightweight Fluent Forms hosted-checkout UI.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$plugin_file = dirname( __DIR__, 3 ) . '/payment-integrations-for-bachs.php';

		wp_enqueue_style(
			'etchpoint-bachs-fluent-forms',
			plugins_url( 'assets/css/fluent-forms.css', $plugin_file ),
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'etchpoint-bachs-fluent-forms',
			plugins_url( 'assets/js/fluent-forms.js', $plugin_file ),
			array( 'jquery' ),
			'1.0.0',
			true
		);
	}
}
