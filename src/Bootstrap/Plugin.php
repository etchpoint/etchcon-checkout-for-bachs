<?php
/**
 * Plugin bootstrap coordinator.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bootstrap;

use Etchpoint\BachsIntegrations\Admin\AdminPages;
use Etchpoint\BachsIntegrations\Bachs\Webhook\SharedWebhookEndpoint;
use Etchpoint\BachsIntegrations\Integrations\GravityForms\Integration as GravityFormsIntegration;
use Etchpoint\BachsIntegrations\Integrations\PMPro\Integration as PMProIntegration;
use Etchpoint\BachsIntegrations\Integrations\WooCommerce\Integration as WooCommerceIntegration;
use Etchpoint\BachsIntegrations\Persistence\Migrator;
use Etchpoint\BachsIntegrations\Reconciliation\ReconciliationRuntime;

/**
 * Coordinates plugin bootstrap after WordPress loads plugins.
 */
final class Plugin {
	public const VERSION = '1.0.0';

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private static string $plugin_file = '';

	/**
	 * Register the plugin bootstrap callback.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @return void
	 */
	public static function boot( string $plugin_file ): void {
		self::$plugin_file = $plugin_file;
		WooCommerceIntegration::register_compatibility( $plugin_file );
		GravityFormsIntegration::register();

		add_action( 'plugins_loaded', array( self::class, 'initialize' ), 20 );
	}

	/**
	 * Initialize the plugin after minimum environment checks pass.
	 *
	 * @return void
	 */
	public static function initialize(): void {
		if ( ! Compatibility::is_current_environment_supported() ) {
			Compatibility::register_admin_notice();
			return;
		}

		Migrator::maybe_migrate();
		add_action( 'rest_api_init', array( SharedWebhookEndpoint::class, 'register_route' ) );
		WooCommerceIntegration::register( self::$plugin_file );
		PMProIntegration::register();
		ReconciliationRuntime::register();
		AdminPages::register();

		/**
		 * Fires after the Bachs integration core has passed minimum environment checks.
		 *
		 * Integration discovery and service registration are added in later build steps.
		 *
		 * @param string $plugin_file Absolute path to the main plugin file.
		 */
		do_action( 'etchpoint_bachs_loaded', self::$plugin_file );
	}
}
