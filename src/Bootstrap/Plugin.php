<?php
/**
 * Plugin bootstrap coordinator.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bootstrap;

final class Plugin {
	public const VERSION = '1.0.0';

	private static string $plugin_file = '';

	public static function boot( string $plugin_file ): void {
		self::$plugin_file = $plugin_file;

		add_action( 'plugins_loaded', array( self::class, 'initialize' ), 20 );
	}

	public static function initialize(): void {
		if ( ! Compatibility::is_current_environment_supported() ) {
			Compatibility::register_admin_notice();
			return;
		}

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
