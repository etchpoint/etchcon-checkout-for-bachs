<?php
/**
 * Minimum runtime compatibility checks.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bootstrap;

/**
 * Provides minimum runtime compatibility checks.
 */
final class Compatibility {
	public const MINIMUM_PHP       = '8.1';
	public const MINIMUM_WORDPRESS = '6.8';

	/**
	 * Determine whether a PHP version is supported.
	 *
	 * @param string $version PHP version to check.
	 * @return bool Whether the version meets the minimum requirement.
	 */
	public static function supports_php_version( string $version ): bool {
		return version_compare( $version, self::MINIMUM_PHP, '>=' );
	}

	/**
	 * Determine whether a WordPress version is supported.
	 *
	 * @param string $version WordPress version to check.
	 * @return bool Whether the version meets the minimum requirement.
	 */
	public static function supports_wordpress_version( string $version ): bool {
		return version_compare( $version, self::MINIMUM_WORDPRESS, '>=' );
	}

	/**
	 * Determine whether the current runtime environment is supported.
	 *
	 * @return bool Whether the current environment meets all minimum requirements.
	 */
	public static function is_current_environment_supported(): bool {
		return self::supports_php_version( PHP_VERSION )
			&& self::supports_wordpress_version( (string) get_bloginfo( 'version' ) );
	}

	/**
	 * Register an admin notice when the WordPress version is unsupported.
	 *
	 * @return void
	 */
	public static function register_admin_notice(): void {
		add_action(
			'admin_notices',
			static function (): void {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

				if ( null === $screen || 'plugins' !== $screen->id ) {
					return;
				}

				$wordpress_version = (string) get_bloginfo( 'version' );

				if ( ! self::supports_wordpress_version( $wordpress_version ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'EtchCon Checkout for Bachs requires WordPress 6.8 or newer.', 'etchcon-checkout-for-bachs' ) . '</p></div>';
				}
			}
		);
	}
}
