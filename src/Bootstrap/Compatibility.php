<?php
/**
 * Minimum runtime compatibility checks.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bootstrap;

final class Compatibility {
	public const MINIMUM_PHP = '8.1';
	public const MINIMUM_WORDPRESS = '6.8';

	public static function supports_php_version( string $version ): bool {
		return version_compare( $version, self::MINIMUM_PHP, '>=' );
	}

	public static function supports_wordpress_version( string $version ): bool {
		return version_compare( $version, self::MINIMUM_WORDPRESS, '>=' );
	}

	public static function is_current_environment_supported(): bool {
		return self::supports_php_version( PHP_VERSION )
			&& self::supports_wordpress_version( (string) get_bloginfo( 'version' ) );
	}

	public static function register_admin_notice(): void {
		add_action(
			'admin_notices',
			static function (): void {
				$wordpress_version = (string) get_bloginfo( 'version' );

				if ( ! self::supports_wordpress_version( $wordpress_version ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Payment Integrations for Bachs requires WordPress 6.8 or newer.', 'payment-integrations-for-bachs' ) . '</p></div>';
				}
			}
		);
	}
}
