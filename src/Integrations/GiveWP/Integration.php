<?php
/**
 * GiveWP integration bootstrap.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\GiveWP;

use Etchpoint\BachsIntegrations\Bootstrap\Compatibility;
use Give\Framework\PaymentGateways\PaymentGatewayRegister;

/**
 * Registers the Bachs gateway through GiveWP's supported gateway registry.
 */
final class Integration {
	/**
	 * Register GiveWP gateway discovery before GiveWP builds its registry.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'givewp_register_payment_gateway', array( self::class, 'register_gateway' ) );
	}

	/**
	 * Add the Bachs hosted checkout gateway to GiveWP.
	 *
	 * @param PaymentGatewayRegister $registry GiveWP payment gateway registry.
	 * @return void
	 */
	public static function register_gateway( PaymentGatewayRegister $registry ): void {
		if ( ! Compatibility::is_current_environment_supported() ) {
			return;
		}

		$registry->registerGateway( GiveWPGateway::class );
	}
}
