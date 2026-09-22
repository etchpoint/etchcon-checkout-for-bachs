<?php
/**
 * Provider-event inbox repository.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Persists provider events and provides atomic deduplication/processing claims.
 */
final class EventRepository {
	/** Default stale-processing threshold in seconds. */
	private const DEFAULT_STALE_SECONDS = 300;

	/**
	 * WordPress database connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Fully qualified event table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Create the repository.
	 *
	 * @param \wpdb $wpdb WordPress database connection.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb  = $wpdb;
		$this->table = Schema::events_table( $wpdb->prefix );
	}

	/**
	 * Record a verified provider event only when its event ID is new.
	 *
	 * Database uniqueness on provider_event_id is the concurrency backstop. The
	 * method returns false for duplicates without overwriting the original event.
	 *
	 * @param string      $provider_event_id Provider event identifier.
	 * @param string      $event_type        Provider event type.
	 * @param string|null $organization_id   Provider organization/account identifier.
	 * @param string      $payload_hash      SHA-256 hash of the verified raw payload.
	 * @return bool Whether a new event row was inserted.
	 */
	public function record_received(
		string $provider_event_id,
		string $event_type,
		?string $organization_id,
		string $payload_hash
	): bool {
		$sql = (string) $this->wpdb->prepare(
			'INSERT IGNORE INTO %i
			(provider_event_id, event_type, organization_id, payload_hash, processing_status, attempts, received_at)
			VALUES (%s, %s, NULLIF(%s, \'\'), %s, %s, 0, %s)',
			$this->table,
			$provider_event_id,
			$event_type,
			$organization_id ?? '',
			$payload_hash,
			EventProcessingStatus::RECEIVED->value,
			self::utc_now()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		return 1 === $this->wpdb->query( $sql );
	}

	/**
	 * Find an event by its database row identifier.
	 *
	 * @param int $event_id Event row identifier.
	 * @return EventRecord|null
	 */
	public function find_by_id( int $event_id ): ?EventRecord {
		$sql = (string) $this->wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d LIMIT 1',
			$this->table,
			$event_id
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Find an event by its provider event identifier.
	 *
	 * @param string $provider_event_id Provider event identifier.
	 * @return EventRecord|null
	 */
	public function find_by_provider_event_id( string $provider_event_id ): ?EventRecord {
		$sql = (string) $this->wpdb->prepare(
			'SELECT * FROM %i WHERE provider_event_id = %s LIMIT 1',
			$this->table,
			$provider_event_id
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Associate a recorded provider event with its matched local intent.
	 *
	 * @param int $event_id  Event row identifier.
	 * @param int $intent_id Intent row identifier.
	 * @return bool Whether the update succeeded.
	 */
	public function link_intent( int $event_id, int $intent_id ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array( 'intent_id' => $intent_id ),
			array( 'id' => $event_id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Atomically claim a recorded event for processing.
	 *
	 * Received and failed events can be claimed immediately. A processing event
	 * can be reclaimed only after its processing timestamp becomes stale.
	 *
	 * @param int $event_id      Event row identifier.
	 * @param int $stale_seconds Age after which a processing claim is recoverable.
	 * @return bool Whether this caller acquired the processing claim.
	 */
	public function claim_processing( int $event_id, int $stale_seconds = self::DEFAULT_STALE_SECONDS ): bool {
		$stale_before = self::utc_timestamp_minus( $stale_seconds );
		$now          = self::utc_now();
		$sql          = (string) $this->wpdb->prepare(
			'UPDATE %i
			SET processing_status = %s,
				processing_started_at = %s,
				attempts = attempts + 1
			WHERE id = %d
			AND (
				processing_status IN (%s, %s)
				OR (
					processing_status = %s
					AND processing_started_at IS NOT NULL
					AND processing_started_at < %s
				)
			)',
			$this->table,
			EventProcessingStatus::PROCESSING->value,
			$now,
			$event_id,
			EventProcessingStatus::RECEIVED->value,
			EventProcessingStatus::FAILED->value,
			EventProcessingStatus::PROCESSING->value,
			$stale_before
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		return 1 === $this->wpdb->query( $sql );
	}

	/**
	 * Mark a provider event as processed successfully.
	 *
	 * @param int $event_id Event row identifier.
	 * @return bool Whether exactly one claimed event was completed.
	 */
	public function mark_processed( int $event_id ): bool {
		$sql = (string) $this->wpdb->prepare(
			'UPDATE %i
			SET processing_status = %s,
				processing_started_at = NULL,
				failure_code = NULL,
				failure_message = NULL,
				processed_at = %s
			WHERE id = %d AND processing_status = %s',
			$this->table,
			EventProcessingStatus::PROCESSED->value,
			self::utc_now(),
			$event_id,
			EventProcessingStatus::PROCESSING->value
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		return 1 === $this->wpdb->query( $sql );
	}

	/**
	 * Mark event processing as failed and recoverable.
	 *
	 * @param int         $event_id Event row identifier.
	 * @param string|null $code     Normalized failure code.
	 * @param string|null $message  Redacted failure message.
	 * @return bool Whether exactly one claimed event was updated.
	 */
	public function mark_failed( int $event_id, ?string $code = null, ?string $message = null ): bool {
		return $this->update_failure_state( $event_id, EventProcessingStatus::FAILED, $code, $message );
	}

	/**
	 * Mark an event as requiring manual or reconciliation review.
	 *
	 * @param int         $event_id Event row identifier.
	 * @param string|null $code     Normalized review code.
	 * @param string|null $message  Redacted review message.
	 * @return bool Whether exactly one claimed event was updated.
	 */
	public function mark_requires_review( int $event_id, ?string $code = null, ?string $message = null ): bool {
		return $this->update_failure_state( $event_id, EventProcessingStatus::REQUIRES_REVIEW, $code, $message );
	}

	/**
	 * Hydrate a database row into a typed event record.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return EventRecord
	 */
	private function hydrate( array $row ): EventRecord {
		return new EventRecord(
			(int) $row['id'],
			(string) $row['provider_event_id'],
			(string) $row['event_type'],
			self::nullable_string( $row['organization_id'] ?? null ),
			self::nullable_int( $row['intent_id'] ?? null ),
			(string) $row['payload_hash'],
			EventProcessingStatus::from( (string) $row['processing_status'] ),
			self::nullable_datetime( $row['processing_started_at'] ?? null ),
			(int) $row['attempts'],
			self::nullable_string( $row['failure_code'] ?? null ),
			self::nullable_string( $row['failure_message'] ?? null ),
			self::required_datetime( $row['received_at'] ),
			self::nullable_datetime( $row['processed_at'] ?? null )
		);
	}

	/**
	 * Update a non-success event state with optional diagnostic information.
	 *
	 * @param int                   $event_id Event row identifier.
	 * @param EventProcessingStatus $status   Target processing state.
	 * @param string|null           $code     Normalized code.
	 * @param string|null           $message  Redacted message.
	 * @return bool Whether exactly one claimed event was updated.
	 */
	private function update_failure_state(
		int $event_id,
		EventProcessingStatus $status,
		?string $code,
		?string $message
	): bool {
		$sql = (string) $this->wpdb->prepare(
			'UPDATE %i
			SET processing_status = %s,
				processing_started_at = NULL,
				failure_code = NULLIF(%s, \'\'),
				failure_message = NULLIF(%s, \'\')
			WHERE id = %d AND processing_status = %s',
			$this->table,
			$status->value,
			$code ?? '',
			$message ?? '',
			$event_id,
			EventProcessingStatus::PROCESSING->value
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		return 1 === $this->wpdb->query( $sql );
	}

	/**
	 * Return the current UTC timestamp in WordPress DATETIME format.
	 *
	 * @return string
	 */
	private static function utc_now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Return a UTC timestamp a number of seconds before now.
	 *
	 * @param int $seconds Seconds to subtract.
	 * @return string
	 */
	private static function utc_timestamp_minus( int $seconds ): string {
		$seconds = max( 1, $seconds );

		return gmdate( 'Y-m-d H:i:s', time() - $seconds );
	}

	/**
	 * Convert a nullable database value to a nullable string.
	 *
	 * @param mixed $value Database value.
	 * @return string|null
	 */
	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = (string) $value;

		return '' === $value ? null : $value;
	}

	/**
	 * Convert a nullable database value to a nullable integer.
	 *
	 * @param mixed $value Database value.
	 * @return int|null
	 */
	private static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === (string) $value ) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Convert a required database DATETIME to an immutable UTC value.
	 *
	 * @param mixed $value Database DATETIME value.
	 * @return DateTimeImmutable
	 */
	private static function required_datetime( mixed $value ): DateTimeImmutable {
		return new DateTimeImmutable( (string) $value, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Convert a nullable database DATETIME to an immutable UTC value.
	 *
	 * @param mixed $value Database DATETIME value.
	 * @return DateTimeImmutable|null
	 */
	private static function nullable_datetime( mixed $value ): ?DateTimeImmutable {
		if ( null === $value || '' === (string) $value ) {
			return null;
		}

		return self::required_datetime( $value );
	}
}
