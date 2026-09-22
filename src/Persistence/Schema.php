<?php
/**
 * Persistence schema definition.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

/**
 * Builds the plugin-owned database schema used by WordPress dbDelta().
 */
final class Schema {
	/** Current database schema version. */
	public const VERSION = '2';

	/** Option used to track the installed schema version. */
	public const VERSION_OPTION = 'etchpoint_bachs_schema_version';

	/** Intent table suffix without the WordPress table prefix. */
	private const INTENTS_TABLE = 'etchpoint_bachs_intents';

	/** Event table suffix without the WordPress table prefix. */
	private const EVENTS_TABLE = 'etchpoint_bachs_events';

	/**
	 * Build the fully qualified payment-intent table name.
	 *
	 * @param string $prefix WordPress database table prefix.
	 * @return string
	 */
	public static function intents_table( string $prefix ): string {
		return $prefix . self::INTENTS_TABLE;
	}

	/**
	 * Build the fully qualified event-inbox table name.
	 *
	 * @param string $prefix WordPress database table prefix.
	 * @return string
	 */
	public static function events_table( string $prefix ): string {
		return $prefix . self::EVENTS_TABLE;
	}

	/**
	 * Build the payment-intent dbDelta SQL statement.
	 *
	 * @param string $prefix          WordPress database table prefix.
	 * @param string $charset_collate WordPress charset/collation clause.
	 * @return string
	 */
	public static function intents_sql( string $prefix, string $charset_collate ): string {
		$table = self::intents_table( $prefix );

		return "CREATE TABLE {$table} (\n"
			. "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
			. "uuid CHAR(36) NOT NULL,\n"
			. "integration VARCHAR(32) NOT NULL,\n"
			. "local_object_type VARCHAR(64) NOT NULL,\n"
			. "local_object_id VARCHAR(191) NOT NULL,\n"
			. "environment VARCHAR(16) NOT NULL,\n"
			. "reference VARCHAR(128) NOT NULL,\n"
			. "idempotency_key VARCHAR(255) NOT NULL,\n"
			. "expected_amount VARCHAR(40) NOT NULL,\n"
			. "expected_currency VARCHAR(12) NOT NULL,\n"
			. "checkout_id VARCHAR(191) NULL,\n"
			. "charge_id VARCHAR(191) NULL,\n"
			. "processing_started_at DATETIME NULL,\n"
			. "provider_status VARCHAR(40) NOT NULL DEFAULT 'created',\n"
			. "application_status VARCHAR(40) NOT NULL DEFAULT 'pending',\n"
			. "attempt SMALLINT UNSIGNED NOT NULL DEFAULT 1,\n"
			. "last_error_code VARCHAR(100) NULL,\n"
			. "last_error_message TEXT NULL,\n"
			. "created_at DATETIME NOT NULL,\n"
			. "updated_at DATETIME NOT NULL,\n"
			. "completed_at DATETIME NULL,\n"
			. "PRIMARY KEY  (id),\n"
			. "UNIQUE KEY uuid (uuid),\n"
			. "UNIQUE KEY reference (reference),\n"
			. "UNIQUE KEY idempotency_key (idempotency_key),\n"
			. "UNIQUE KEY checkout_id (checkout_id),\n"
			. "UNIQUE KEY provider_charge_id (charge_id),\n"
			. "UNIQUE KEY local_attempt (integration, local_object_type, local_object_id, attempt),\n"
			. "KEY local_lookup (integration, local_object_type, local_object_id),\n"
			. "KEY state_lookup (provider_status, application_status)\n"
			. ") {$charset_collate};";
	}

	/**
	 * Build the webhook-event inbox dbDelta SQL statement.
	 *
	 * @param string $prefix          WordPress database table prefix.
	 * @param string $charset_collate WordPress charset/collation clause.
	 * @return string
	 */
	public static function events_sql( string $prefix, string $charset_collate ): string {
		$table = self::events_table( $prefix );

		return "CREATE TABLE {$table} (\n"
			. "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
			. "provider_event_id VARCHAR(191) NOT NULL,\n"
			. "event_type VARCHAR(100) NOT NULL,\n"
			. "organization_id VARCHAR(191) NULL,\n"
			. "intent_id BIGINT UNSIGNED NULL,\n"
			. "payload_hash CHAR(64) NOT NULL,\n"
			. "processing_status VARCHAR(32) NOT NULL DEFAULT 'received',\n"
			. "processing_started_at DATETIME NULL,\n"
			. "attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,\n"
			. "failure_code VARCHAR(100) NULL,\n"
			. "failure_message TEXT NULL,\n"
			. "received_at DATETIME NOT NULL,\n"
			. "processed_at DATETIME NULL,\n"
			. "PRIMARY KEY  (id),\n"
			. "UNIQUE KEY provider_event_id (provider_event_id),\n"
			. "KEY process_queue (processing_status, received_at),\n"
			. "KEY intent_lookup (intent_id)\n"
			. ") {$charset_collate};";
	}
}
