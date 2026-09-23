<?php
/**
 * Persistence schema tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Persistence;

use Etchpoint\BachsIntegrations\Persistence\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Tests security-critical persistence schema invariants.
 */
final class SchemaTest extends TestCase {
	/**
	 * Verify WordPress table prefixes are applied consistently.
	 *
	 * @return void
	 */
	public function test_table_names_use_wordpress_prefix(): void {
		self::assertSame( 'wp_etchpoint_bachs_intents', Schema::intents_table( 'wp_' ) );
		self::assertSame( 'client_etchpoint_bachs_events', Schema::events_table( 'client_' ) );
		self::assertSame( 'wp_etchpoint_bachs_refunds', Schema::refunds_table( 'wp_' ) );
	}

	/**
	 * Verify a successful provider charge can satisfy only one local intent.
	 *
	 * @return void
	 */
	public function test_intent_schema_enforces_unique_provider_charge(): void {
		$sql = Schema::intents_sql( 'wp_', 'DEFAULT CHARACTER SET utf8mb4' );

		self::assertStringContainsString( 'charge_id VARCHAR(191) NULL', $sql );
		self::assertStringContainsString( 'UNIQUE KEY provider_charge_id (charge_id)', $sql );
		self::assertStringContainsString( 'UNIQUE KEY idempotency_key (idempotency_key)', $sql );
		self::assertStringContainsString( 'UNIQUE KEY local_attempt (integration, local_object_type, local_object_id, attempt)', $sql );
	}

	/**
	 * Verify intent processing claims retain a recovery timestamp.
	 *
	 * @return void
	 */
	public function test_intent_schema_contains_processing_started_at(): void {
		$sql = Schema::intents_sql( 'wp_', '' );

		self::assertStringContainsString( 'processing_started_at DATETIME NULL', $sql );
	}

	/**
	 * Verify one refund operation is permitted per provider charge.
	 *
	 * @return void
	 */
	public function test_refund_schema_enforces_one_operation_per_charge(): void {
		$sql = Schema::refunds_sql( 'wp_', '' );

		self::assertStringContainsString( 'UNIQUE KEY refund_charge_id (charge_id)', $sql );
		self::assertStringContainsString( 'UNIQUE KEY provider_refund_id (provider_refund_id)', $sql );
		self::assertStringContainsString( 'UNIQUE KEY refund_reference (reference)', $sql );
		self::assertStringContainsString( 'UNIQUE KEY refund_idempotency_key (idempotency_key)', $sql );
	}

	/**
	 * Verify provider event IDs are deduplicated by the database.
	 *
	 * @return void
	 */
	public function test_event_schema_enforces_unique_provider_event_id(): void {
		$sql = Schema::events_sql( 'wp_', '' );

		self::assertStringContainsString( 'UNIQUE KEY provider_event_id (provider_event_id)', $sql );
		self::assertStringContainsString( 'processing_started_at DATETIME NULL', $sql );
	}
}
