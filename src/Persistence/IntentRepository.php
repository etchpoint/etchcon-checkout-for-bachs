<?php
/**
 * Payment-intent persistence repository.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Payment\ApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use Etchpoint\BachsIntegrations\Core\Payment\ProviderStatus;
use RuntimeException;

/**
 * Persists and atomically transitions payment intents using WordPress wpdb.
 */
final class IntentRepository implements IntentStore {
	/** Default stale-processing threshold in seconds. */
	private const DEFAULT_STALE_SECONDS = 300;

	/**
	 * WordPress database connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Fully qualified intent table name.
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
		$this->table = Schema::intents_table( $wpdb->prefix );
	}

	/**
	 * Persist a newly-created local payment intent.
	 *
	 * @param PaymentIntent $intent Intent to persist.
	 * @return int Database row identifier.
	 *
	 * @throws RuntimeException When the insert fails.
	 */
	public function create( PaymentIntent $intent ): int {
		$now = self::utc_now();

		$result = $this->wpdb->insert(
			$this->table,
			array(
				'uuid'               => $intent->uuid(),
				'integration'        => $intent->integration(),
				'local_object_type'  => $intent->local_object_type(),
				'local_object_id'    => $intent->local_object_id(),
				'environment'        => $intent->environment(),
				'reference'          => $intent->reference(),
				'idempotency_key'    => $intent->idempotency_key(),
				'expected_amount'    => $intent->expected_amount()->amount(),
				'expected_currency'  => $intent->expected_amount()->currency()->code(),
				'provider_status'    => $intent->provider_status()->value,
				'application_status' => $intent->application_status()->value,
				'attempt'            => $intent->attempt(),
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Unable to persist the payment intent.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Find an intent by its database row identifier.
	 *
	 * @param int $id Intent row identifier.
	 * @return IntentRecord|null
	 */
	public function find_by_id( int $id ): ?IntentRecord {
		$sql = (string) $this->wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d LIMIT 1',
			$this->table,
			$id
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Find an intent by its immutable UUID.
	 *
	 * @param string $uuid Intent UUID.
	 * @return IntentRecord|null
	 */
	public function find_by_uuid( string $uuid ): ?IntentRecord {
		return $this->find_one( 'uuid', $uuid );
	}

	/**
	 * Find an intent by its opaque correlation reference.
	 *
	 * @param string $reference Opaque correlation reference.
	 * @return IntentRecord|null
	 */
	public function find_by_reference( string $reference ): ?IntentRecord {
		return $this->find_one( 'reference', $reference );
	}

	/**
	 * Find an intent by its provider checkout identifier.
	 *
	 * @param string $checkout_id Provider checkout identifier.
	 * @return IntentRecord|null
	 */
	public function find_by_checkout_id( string $checkout_id ): ?IntentRecord {
		return $this->find_one( 'checkout_id', $checkout_id );
	}

	/**
	 * Attach the provider checkout identifier after checkout creation.
	 *
	 * @param int    $id          Intent row identifier.
	 * @param string $checkout_id Provider checkout identifier.
	 * @return bool Whether exactly one compatible intent was updated.
	 */
	public function attach_checkout( int $id, string $checkout_id ): bool {
		$sql = (string) $this->wpdb->prepare(
			'UPDATE %i
			SET checkout_id = %s, provider_status = %s, updated_at = %s
			WHERE id = %d AND checkout_id IS NULL',
			$this->table,
			$checkout_id,
			ProviderStatus::OPEN->value,
			self::utc_now(),
			$id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		return 1 === $this->wpdb->query( $sql );
	}

	/**
	 * Update the normalized provider state for an intent.
	 *
	 * @param int            $id     Intent row identifier.
	 * @param ProviderStatus $status New provider state.
	 * @return bool Whether the row was updated successfully.
	 */
	public function update_provider_status( int $id, ProviderStatus $status ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array(
				'provider_status' => $status->value,
				'updated_at'      => self::utc_now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Attach the authoritative successful provider charge identifier.
	 *
	 * The database unique key on charge_id is the final concurrency backstop:
	 * one successful provider charge cannot satisfy two local intents.
	 *
	 * @param int    $id        Intent row identifier.
	 * @param string $charge_id Authoritative successful charge/payment identifier.
	 * @return bool Whether exactly one intent was updated.
	 */
	public function attach_successful_charge( int $id, string $charge_id ): bool {
		$sql = (string) $this->wpdb->prepare(
			'UPDATE %i
			SET charge_id = %s, provider_status = %s, updated_at = %s
			WHERE id = %d AND charge_id IS NULL',
			$this->table,
			$charge_id,
			ProviderStatus::SUCCEEDED->value,
			self::utc_now(),
			$id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		return 1 === $this->wpdb->query( $sql );
	}

	/**
	 * Atomically claim an intent for application fulfillment.
	 *
	 * Pending and failed intents can be claimed immediately. A processing intent
	 * can be reclaimed only when its processing timestamp is stale.
	 *
	 * @param int $id            Intent row identifier.
	 * @param int $stale_seconds Age after which a processing claim is recoverable.
	 * @return bool Whether this caller acquired the processing claim.
	 */
	public function claim_application_processing( int $id, int $stale_seconds = self::DEFAULT_STALE_SECONDS ): bool {
		$stale_before = self::utc_timestamp_minus( $stale_seconds );
		$now          = self::utc_now();
		$sql          = (string) $this->wpdb->prepare(
			'UPDATE %i
			SET application_status = %s, processing_started_at = %s, updated_at = %s
			WHERE id = %d
			AND (
				application_status IN (%s, %s)
				OR (
					application_status = %s
					AND processing_started_at IS NOT NULL
					AND processing_started_at < %s
				)
			)',
			$this->table,
			ApplicationStatus::PROCESSING->value,
			$now,
			$now,
			$id,
			ApplicationStatus::PENDING->value,
			ApplicationStatus::FAILED->value,
			ApplicationStatus::PROCESSING->value,
			$stale_before
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		return 1 === $this->wpdb->query( $sql );
	}

	/**
	 * Mark application fulfillment as successfully applied.
	 *
	 * @param int $id Intent row identifier.
	 * @return bool Whether exactly one claimed intent was completed.
	 */
	public function mark_applied( int $id ): bool {
		$now = self::utc_now();
		$sql = (string) $this->wpdb->prepare(
			'UPDATE %i
			SET application_status = %s,
				processing_started_at = NULL,
				last_error_code = NULL,
				last_error_message = NULL,
				updated_at = %s,
				completed_at = %s
			WHERE id = %d AND application_status = %s',
			$this->table,
			ApplicationStatus::APPLIED->value,
			$now,
			$now,
			$id,
			ApplicationStatus::PROCESSING->value
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		return 1 === $this->wpdb->query( $sql );
	}

	/**
	 * Mark application fulfillment as failed and recoverable.
	 *
	 * @param int         $id      Intent row identifier.
	 * @param string|null $code    Normalized error code.
	 * @param string|null $message Redacted error message.
	 * @return bool Whether exactly one claimed intent was updated.
	 */
	public function mark_failed( int $id, ?string $code = null, ?string $message = null ): bool {
		return $this->update_application_failure_state( $id, ApplicationStatus::FAILED, $code, $message );
	}

	/**
	 * Mark an intent as requiring manual or reconciliation review.
	 *
	 * @param int         $id      Intent row identifier.
	 * @param string|null $code    Normalized review code.
	 * @param string|null $message Redacted review message.
	 * @return bool Whether exactly one claimed intent was updated.
	 */
	public function mark_requires_review( int $id, ?string $code = null, ?string $message = null ): bool {
		return $this->update_application_failure_state( $id, ApplicationStatus::REQUIRES_REVIEW, $code, $message );
	}

	/**
	 * Find one row using a fixed allow-listed column name.
	 *
	 * @param string $column Allow-listed database column.
	 * @param string $value  Lookup value.
	 * @return IntentRecord|null
	 *
	 * @throws RuntimeException When an unsupported lookup column is requested.
	 */
	private function find_one( string $column, string $value ): ?IntentRecord {
		$allowed_columns = array( 'uuid', 'reference', 'checkout_id' );

		if ( ! in_array( $column, $allowed_columns, true ) ) {
			throw new RuntimeException( 'Unsupported payment-intent lookup column.' );
		}

		$sql = (string) $this->wpdb->prepare(
			'SELECT * FROM %i WHERE %i = %s LIMIT 1',
			$this->table,
			$column,
			$value
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Hydrate a persisted row into a typed record.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return IntentRecord
	 */
	private function hydrate( array $row ): IntentRecord {
		$currency = Currency::from_code( (string) $row['expected_currency'] );
		$money    = Money::from_decimal( (string) $row['expected_amount'], $currency );
		$intent   = PaymentIntent::rehydrate(
			(string) $row['uuid'],
			(string) $row['integration'],
			(string) $row['local_object_type'],
			(string) $row['local_object_id'],
			(string) $row['environment'],
			(string) $row['reference'],
			(string) $row['idempotency_key'],
			$money,
			(int) $row['attempt'],
			ProviderStatus::from( (string) $row['provider_status'] ),
			ApplicationStatus::from( (string) $row['application_status'] )
		);

		return new IntentRecord(
			(int) $row['id'],
			$intent,
			self::nullable_string( $row['checkout_id'] ?? null ),
			self::nullable_string( $row['charge_id'] ?? null ),
			self::nullable_datetime( $row['processing_started_at'] ?? null ),
			self::nullable_string( $row['last_error_code'] ?? null ),
			self::nullable_string( $row['last_error_message'] ?? null ),
			self::required_datetime( $row['created_at'] ),
			self::required_datetime( $row['updated_at'] ),
			self::nullable_datetime( $row['completed_at'] ?? null )
		);
	}

	/**
	 * Update a non-success application state with optional diagnostic information.
	 *
	 * @param int               $id      Intent row identifier.
	 * @param ApplicationStatus $status  Target application state.
	 * @param string|null       $code    Normalized code.
	 * @param string|null       $message Redacted message.
	 * @return bool Whether exactly one claimed intent was updated.
	 */
	private function update_application_failure_state(
		int $id,
		ApplicationStatus $status,
		?string $code,
		?string $message
	): bool {
		$sql = (string) $this->wpdb->prepare(
			'UPDATE %i
			SET application_status = %s,
				processing_started_at = NULL,
				last_error_code = NULLIF(%s, \'\'),
				last_error_message = NULLIF(%s, \'\'),
				updated_at = %s
			WHERE id = %d AND application_status = %s',
			$this->table,
			$status->value,
			$code ?? '',
			$message ?? '',
			self::utc_now(),
			$id,
			ApplicationStatus::PROCESSING->value
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
