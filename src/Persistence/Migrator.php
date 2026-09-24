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

		if ( self::schema_is_current() ) {
			update_option( Schema::VERSION_OPTION, Schema::VERSION, false );
		} else {
			delete_option( Schema::VERSION_OPTION );
		}
	}

	/**
	 * Verify that the required persistence tables and columns actually exist.
	 *
	 * @return bool
	 */
	public static function schema_is_current(): bool {
		global $wpdb;

		$requirements = array(
			Schema::intents_table( $wpdb->prefix ) => array(
				'id',
				'uuid',
				'integration',
				'local_object_type',
				'local_object_id',
				'environment',
				'reference',
				'idempotency_key',
				'expected_amount',
				'expected_currency',
				'checkout_id',
				'charge_id',
				'provider_status',
				'application_status',
				'attempt',
				'created_at',
				'updated_at',
			),
			Schema::events_table( $wpdb->prefix )  => array(
				'id',
				'provider_event_id',
				'event_type',
				'payload_hash',
				'processing_status',
				'received_at',
			),
			Schema::refunds_table( $wpdb->prefix ) => array(
				'id',
				'uuid',
				'intent_id',
				'charge_id',
				'reference',
				'idempotency_key',
				'provider_status',
				'application_status',
				'created_at',
				'updated_at',
			),
		);

		foreach ( $requirements as $table => $required_columns ) {
			$sql = (string) $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table );
			// Inspect the current custom-table schema directly; cached columns could hide an incomplete migration.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared immediately above; schema verification requires a fresh database read.
			$columns = $wpdb->get_col( $sql );

			if ( '' !== trim( (string) $wpdb->last_error ) || ! is_array( $columns ) ) {
				return false;
			}

			foreach ( $required_columns as $required_column ) {
				if ( ! in_array( $required_column, $columns, true ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
