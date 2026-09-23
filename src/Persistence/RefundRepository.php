<?php
/**
 * Refund persistence repository.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Etchpoint\BachsIntegrations\Core\Money\Currency;
use Etchpoint\BachsIntegrations\Core\Money\Money;
use Etchpoint\BachsIntegrations\Core\Refund\RefundApplicationStatus;
use Etchpoint\BachsIntegrations\Core\Refund\RefundStatus;
use RuntimeException;

/**
 * Persists refund requests and protects provider/local state transitions.
 */
final class RefundRepository {
	/** Default stale application claim threshold in seconds. */
	private const DEFAULT_STALE_SECONDS = 300;

	/**
	 * WordPress database connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Fully qualified refund table name.
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
		$this->table = Schema::refunds_table( $wpdb->prefix );
	}

	/**
	 * Persist a refund before making the provider request.
	 *
	 * @param string       $uuid            Local refund UUID.
	 * @param IntentRecord $intent          Original payment intent.
	 * @param string       $charge_id       Bachs charge identifier.
	 * @param string       $reference       Merchant refund reference.
	 * @param string       $idempotency_key Stable provider idempotency key.
	 * @param Money        $amount          Requested refund amount.
	 * @param string|null  $reason          Optional merchant reason.
	 * @return int
	 *
	 * @throws RuntimeException When the insert fails.
	 */
	public function create(
		string $uuid,
		IntentRecord $intent,
		string $charge_id,
		string $reference,
		string $idempotency_key,
		Money $amount,
		?string $reason
	): int {
		$payment = $intent->intent();
		$now     = self::utc_now();
		$result  = $this->wpdb->insert(
			$this->table,
			array(
				'uuid'               => $uuid,
				'intent_id'          => $intent->id(),
				'integration'        => $payment->integration(),
				'local_object_type'  => $payment->local_object_type(),
				'local_object_id'    => $payment->local_object_id(),
				'environment'        => $payment->environment(),
				'charge_id'          => $charge_id,
				'reference'          => $reference,
				'idempotency_key'    => $idempotency_key,
				'requested_amount'   => $amount->amount(),
				'currency'           => $amount->currency()->code(),
				'provider_status'    => RefundStatus::REQUESTED->value,
				'application_status' => RefundApplicationStatus::PENDING->value,
				'reason'             => $reason,
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new RuntimeException( 'Unable to persist the refund request.' );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Find a refund by database identifier.
	 *
	 * @param int $id Refund row identifier.
	 * @return RefundRecord|null
	 */
	public function find_by_id( int $id ): ?RefundRecord {
		return $this->find_one( 'id', (string) $id, true );
	}

	/**
	 * Find a refund by charge identifier.
	 *
	 * @param string $charge_id Charge identifier.
	 * @return RefundRecord|null
	 */
	public function find_by_charge_id( string $charge_id ): ?RefundRecord {
		return $this->find_one( 'charge_id', $charge_id );
	}

	/**
	 * Find a refund by provider identifier.
	 *
	 * @param string $refund_id Provider refund identifier.
	 * @return RefundRecord|null
	 */
	public function find_by_provider_refund_id( string $refund_id ): ?RefundRecord {
		return $this->find_one( 'provider_refund_id', $refund_id );
	}

	/**
	 * Find a refund by merchant reference.
	 *
	 * @param string $reference Merchant reference.
	 * @return RefundRecord|null
	 */
	public function find_by_reference( string $reference ): ?RefundRecord {
		return $this->find_one( 'reference', $reference );
	}

	/**
	 * Attach the provider refund identifier and current state.
	 *
	 * @param int          $id                 Refund row identifier.
	 * @param string       $provider_refund_id Bachs refund identifier.
	 * @param RefundStatus $status             Normalized provider state.
	 * @param string|null  $refunded_amount    Confirmed amount when available.
	 * @return bool
	 */
	public function attach_provider_refund( int $id, string $provider_refund_id, RefundStatus $status, ?string $refunded_amount = null ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array(
				'provider_refund_id' => $provider_refund_id,
				'provider_status'    => $status->value,
				'refunded_amount'    => $refunded_amount,
				'last_error_code'    => null,
				'last_error_message' => null,
				'updated_at'         => self::utc_now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Update authoritative provider refund state.
	 *
	 * @param int          $id              Refund row identifier.
	 * @param RefundStatus $status          Normalized provider status.
	 * @param string|null  $refunded_amount Confirmed refunded amount.
	 * @return bool
	 */
	public function update_provider_status( int $id, RefundStatus $status, ?string $refunded_amount = null ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array(
				'provider_status' => $status->value,
				'refunded_amount' => $refunded_amount,
				'updated_at'      => self::utc_now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Record a provider request failure without losing the idempotent request row.
	 *
	 * @param int          $id      Refund row identifier.
	 * @param RefundStatus $status  Refund state after the failure.
	 * @param string       $code    Stable diagnostic code.
	 * @param string       $message Redacted diagnostic message.
	 * @return bool
	 */
	public function mark_request_failure( int $id, RefundStatus $status, string $code, string $message ): bool {
		$result = $this->wpdb->update(
			$this->table,
			array(
				'provider_status'    => $status->value,
				'last_error_code'    => $code,
				'last_error_message' => $message,
				'updated_at'         => self::utc_now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Atomically claim provider-confirmed refund application.
	 *
	 * @param int $id            Refund row identifier.
	 * @param int $stale_seconds Processing claim timeout.
	 * @return bool
	 */
	public function claim_application_processing( int $id, int $stale_seconds = self::DEFAULT_STALE_SECONDS ): bool {
		$now          = self::utc_now();
		$stale_before = self::utc_timestamp_minus( $stale_seconds );
		$sql          = (string) $this->wpdb->prepare(
			'UPDATE %i
			SET application_status = %s, processing_started_at = %s, updated_at = %s
			WHERE id = %d AND provider_status = %s
			AND (
				application_status IN (%s, %s)
				OR (application_status = %s AND processing_started_at IS NOT NULL AND processing_started_at < %s)
			)',
			$this->table,
			RefundApplicationStatus::PROCESSING->value,
			$now,
			$now,
			$id,
			RefundStatus::SUCCEEDED->value,
			RefundApplicationStatus::PENDING->value,
			RefundApplicationStatus::FAILED->value,
			RefundApplicationStatus::PROCESSING->value,
			$stale_before
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		return 1 === $this->wpdb->query( $sql );
	}

	/**
	 * Mark the confirmed refund as applied locally.
	 *
	 * @param int         $id              Refund row identifier.
	 * @param string|null $local_refund_id Optional host refund identifier.
	 * @return bool
	 */
	public function mark_applied( int $id, ?string $local_refund_id = null ): bool {
		$now = self::utc_now();
		$sql = (string) $this->wpdb->prepare(
			'UPDATE %i
			SET application_status = %s, processing_started_at = NULL,
				local_refund_id = NULLIF(%s, \'\'), last_error_code = NULL,
				last_error_message = NULL, updated_at = %s, completed_at = %s
			WHERE id = %d AND application_status = %s',
			$this->table,
			RefundApplicationStatus::APPLIED->value,
			$local_refund_id ?? '',
			$now,
			$now,
			$id,
			RefundApplicationStatus::PROCESSING->value
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		return 1 === $this->wpdb->query( $sql );
	}

	/**
	 * Mark local application as failed and retryable.
	 *
	 * @param int    $id      Refund row identifier.
	 * @param string $code    Stable diagnostic code.
	 * @param string $message Redacted diagnostic message.
	 * @return bool
	 */
	public function mark_application_failed( int $id, string $code, string $message ): bool {
		return $this->update_application_failure( $id, RefundApplicationStatus::FAILED, $code, $message );
	}

	/**
	 * Mark local application as requiring manual review.
	 *
	 * @param int    $id      Refund row identifier.
	 * @param string $code    Stable diagnostic code.
	 * @param string $message Redacted diagnostic message.
	 * @return bool
	 */
	public function mark_application_requires_review( int $id, string $code, string $message ): bool {
		return $this->update_application_failure( $id, RefundApplicationStatus::REQUIRES_REVIEW, $code, $message );
	}

	/**
	 * Return recent refund operations for the administrator screen.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int, RefundRecord>
	 */
	public function find_recent( int $limit = 50 ): array {
		$limit = max( 1, min( 100, $limit ) );
		$sql   = (string) $this->wpdb->prepare(
			'SELECT * FROM %i ORDER BY id DESC LIMIT %d',
			$this->table,
			$limit
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$records = array();

		foreach ( $rows as $row ) {
			$records[] = $this->hydrate( $row );
		}

		return $records;
	}

	/**
	 * Find one refund using an allow-listed unique field.
	 *
	 * @param string $field      Unique field name.
	 * @param string $value      Lookup value.
	 * @param bool   $is_numeric Whether the value is numeric.
	 * @return RefundRecord|null
	 */
	private function find_one( string $field, string $value, bool $is_numeric = false ): ?RefundRecord {
		$allowed = array( 'id', 'charge_id', 'provider_refund_id', 'reference' );

		if ( ! in_array( $field, $allowed, true ) ) {
			return null;
		}

		$sql = $is_numeric
			? (string) $this->wpdb->prepare( 'SELECT * FROM %i WHERE %i = %d LIMIT 1', $this->table, $field, (int) $value )
			: (string) $this->wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s LIMIT 1', $this->table, $field, $value );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Update a non-successful local application state.
	 *
	 * @param int                     $id      Refund row identifier.
	 * @param RefundApplicationStatus $status  Target local status.
	 * @param string                  $code    Stable diagnostic code.
	 * @param string                  $message Redacted diagnostic message.
	 * @return bool
	 */
	private function update_application_failure( int $id, RefundApplicationStatus $status, string $code, string $message ): bool {
		$sql = (string) $this->wpdb->prepare(
			'UPDATE %i SET application_status = %s, processing_started_at = NULL,
			last_error_code = %s, last_error_message = %s, updated_at = %s
			WHERE id = %d AND application_status IN (%s, %s, %s)',
			$this->table,
			$status->value,
			$code,
			$message,
			self::utc_now(),
			$id,
			RefundApplicationStatus::PENDING->value,
			RefundApplicationStatus::PROCESSING->value,
			RefundApplicationStatus::FAILED->value
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is prepared with wpdb::prepare() immediately above.
		return 1 === $this->wpdb->query( $sql );
	}

	/**
	 * Hydrate a database row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return RefundRecord
	 */
	private function hydrate( array $row ): RefundRecord {
		$currency = Currency::from_code( (string) $row['currency'] );

		return new RefundRecord(
			(int) $row['id'],
			(string) $row['uuid'],
			(int) $row['intent_id'],
			(string) $row['integration'],
			(string) $row['local_object_type'],
			(string) $row['local_object_id'],
			(string) $row['environment'],
			(string) $row['charge_id'],
			self::nullable_string( $row['provider_refund_id'] ?? null ),
			(string) $row['reference'],
			(string) $row['idempotency_key'],
			Money::from_decimal( (string) $row['requested_amount'], $currency ),
			self::nullable_money( $row['refunded_amount'] ?? null, $currency ),
			RefundStatus::from( (string) $row['provider_status'] ),
			RefundApplicationStatus::from( (string) $row['application_status'] ),
			self::nullable_string( $row['reason'] ?? null ),
			self::nullable_string( $row['local_refund_id'] ?? null ),
			self::nullable_string( $row['last_error_code'] ?? null ),
			self::nullable_string( $row['last_error_message'] ?? null ),
			self::required_datetime( $row['created_at'] ),
			self::required_datetime( $row['updated_at'] ),
			self::nullable_datetime( $row['completed_at'] ?? null )
		);
	}

	/**
	 * Normalize a nullable string.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function nullable_string( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Normalize a nullable money amount.
	 *
	 * @param mixed    $value    Value.
	 * @param Currency $currency Currency.
	 * @return Money|null
	 */
	private static function nullable_money( mixed $value, Currency $currency ): ?Money {
		return is_string( $value ) && '' !== $value ? Money::from_decimal( $value, $currency ) : null;
	}

	/**
	 * Parse a required database datetime.
	 *
	 * @param mixed $value Value.
	 * @return DateTimeImmutable
	 */
	private static function required_datetime( mixed $value ): DateTimeImmutable {
		return new DateTimeImmutable( (string) $value, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Parse a nullable database datetime.
	 *
	 * @param mixed $value Value.
	 * @return DateTimeImmutable|null
	 */
	private static function nullable_datetime( mixed $value ): ?DateTimeImmutable {
		return is_string( $value ) && '' !== $value ? self::required_datetime( $value ) : null;
	}

	/**
	 * Return the current UTC database timestamp.
	 *
	 * @return string
	 */
	private static function utc_now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Return a UTC timestamp before now.
	 *
	 * @param int $seconds Seconds to subtract.
	 * @return string
	 */
	private static function utc_timestamp_minus( int $seconds ): string {
		return gmdate( 'Y-m-d H:i:s', time() - max( 1, $seconds ) );
	}
}
