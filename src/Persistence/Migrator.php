<?php
/**
 * Database migration coordinator.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

/**
 * Creates and upgrades the plugin-owned persistence tables.
 */
final class Migrator {
	/**
	 * Run migrations only when the stored schema version is outdated.
	 *
	 * @return void
	 */
	public static function maybe_migrate(): void {
		$installed_version = (string) get_option( Schema::VERSION_OPTION, '' );

		if ( Schema::VERSION === $installed_version ) {
			return;
		}

		self::migrate();
	}

	/**
	 * Create or upgrade the plugin-owned database tables.
	 *
	 * @return void
	 */
	public static function migrate(): void {
		global $wpdb;

		/**
		 * WordPress database abstraction instance.
		 *
		 * @var \wpdb $wpdb
		 */
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		dbDelta( Schema::intents_sql( $wpdb->prefix, $charset_collate ) );
		dbDelta( Schema::events_sql( $wpdb->prefix, $charset_collate ) );
		dbDelta( Schema::refunds_sql( $wpdb->prefix, $charset_collate ) );

		update_option( Schema::VERSION_OPTION, Schema::VERSION, false );
	}
}
