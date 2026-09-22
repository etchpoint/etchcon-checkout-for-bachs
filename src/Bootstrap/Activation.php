<?php
/**
 * Plugin activation checks.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bootstrap;

use Etchpoint\BachsIntegrations\Persistence\Migrator;

/**
 * Handles plugin activation requirements.
 */
final class Activation {
	/**
	 * Verify requirements and install the current database schema.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$wordpress_version = (string) get_bloginfo( 'version' );

		if ( ! Compatibility::supports_wordpress_version( $wordpress_version ) ) {
			wp_die(
				esc_html__( 'Payment Integrations for Bachs requires WordPress 6.8 or newer.', 'payment-integrations-for-bachs' ),
				esc_html__( 'Plugin requirements not met', 'payment-integrations-for-bachs' ),
				array( 'back_link' => true )
			);
		}

		Migrator::migrate();
	}
}
