<?php
/**
 * Fluent Forms integration bootstrap.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\FluentForms;

use Etchpoint\BachsIntegrations\Bootstrap\Compatibility;

/**
 * Registers Bachs only when Fluent Forms Pro payment APIs are available.
 */
final class Integration {
	/**
	 * Whether the Pro payment classes have been initialized.
	 *
	 * @var bool
	 */
	private static bool $loaded = false;

	/**
	 * Whether the Free-only admin notice has been registered.
	 *
	 * @var bool
	 */
	private static bool $notice_registered = false;

	/**
	 * Register Fluent Forms bootstrap hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'fluentform/loaded', array( self::class, 'load' ), 20 );
		self::load();
	}

	/**
	 * Load the Bachs payment method and processor when Pro payment APIs exist.
	 *
	 * @return void
	 */
	public static function load(): void {
		if ( self::$loaded || ! Compatibility::is_current_environment_supported() ) {
			return;
		}

		if ( ! class_exists( 'FluentFormPro\\Payments\\PaymentMethods\\BasePaymentMethod' ) || ! class_exists( 'FluentFormPro\\Payments\\PaymentMethods\\BaseProcessor' ) ) {
			self::register_pro_required_notice();
			return;
		}

		self::$loaded = true;

		$method = new FluentFormsPaymentMethod();
		$method->init();

		$processor = new FluentFormsProcessor();
		$processor->init();
	}

	/**
	 * Register a non-fatal notice when Fluent Forms Free is present without Pro payments.
	 *
	 * @return void
	 */
	private static function register_pro_required_notice(): void {
		if ( self::$notice_registered || ! defined( 'FLUENTFORM' ) ) {
			return;
		}

		self::$notice_registered = true;
		add_action( 'admin_notices', array( self::class, 'render_pro_required_notice' ) );
	}

	/**
	 * Explain the Fluent Forms Pro payment dependency without exposing secrets.
	 *
	 * @return void
	 */
	public static function render_pro_required_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = __( 'Fluent Forms detected. Bachs payment integration requires Fluent Forms Pro payment functionality.', 'payment-integrations-for-bachs' );

		echo '<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>';
	}
}
