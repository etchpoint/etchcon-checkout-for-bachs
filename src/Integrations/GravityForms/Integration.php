<?php
/**
 * Gravity Forms integration bootstrap.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\GravityForms;

use Etchpoint\BachsIntegrations\Bootstrap\Compatibility;
use GFAddOn;
use GFForms;

/**
 * Registers the Bachs payment add-on through Gravity Forms' official framework.
 */
final class Integration {
	/**
	 * Register the early Gravity Forms bootstrap hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'gform_loaded', array( self::class, 'load' ), 5 );
	}

	/**
	 * Load and register the payment add-on once Gravity Forms is ready.
	 *
	 * @return void
	 */
	public static function load(): void {
		if ( ! Compatibility::is_current_environment_supported() ) {
			return;
		}

		if ( ! method_exists( GFForms::class, 'include_payment_addon_framework' ) ) {
			return;
		}

		GFForms::include_payment_addon_framework();

		if ( ! class_exists( 'GFPaymentAddOn' ) || ! class_exists( GFAddOn::class ) ) {
			return;
		}

		GFAddOn::register( GravityFormsAddOn::class );
	}
}
