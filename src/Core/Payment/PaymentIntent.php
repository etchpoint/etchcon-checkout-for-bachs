<?php
/**
 * Payment intent value object.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Payment;

use Etchpoint\BachsIntegrations\Core\Money\Money;
use InvalidArgumentException;

/**
 * Immutable snapshot of the trusted payment expectation created before checkout.
 */
final class PaymentIntent {
	/** Sandbox environment identifier. */
	public const ENVIRONMENT_SANDBOX = 'sandbox';

	/** Live environment identifier. */
	public const ENVIRONMENT_LIVE = 'live';

	/** @var string Intent UUID. */
	private string $uuid;

	/** @var string Integration identifier. */
	private string $integration;

	/** @var string Host application's object type. */
	private string $local_object_type;

	/** @var string Host application's object identifier. */
	private string $local_object_id;

	/** @var string Bachs environment. */
	private string $environment;

	/** @var string Opaque checkout correlation reference. */
	private string $reference;

	/** @var string Bachs idempotency key for this logical attempt. */
	private string $idempotency_key;

	/** @var Money Immutable trusted amount and currency. */
	private Money $expected_amount;

	/** @var int Logical checkout attempt number. */
	private int $attempt;

	/** @var ProviderStatus Initial provider state. */
	private ProviderStatus $provider_status;

	/** @var ApplicationStatus Initial application state. */
	private ApplicationStatus $application_status;

	/**
	 * Create an immutable payment intent snapshot.
	 *
	 * @param string            $uuid               Intent UUID.
	 * @param string            $integration        Integration identifier.
	 * @param string            $local_object_type  Host object type.
	 * @param string            $local_object_id    Host object identifier.
	 * @param string            $environment        Bachs environment.
	 * @param string            $reference          Opaque checkout reference.
	 * @param string            $idempotency_key    Bachs idempotency key.
	 * @param Money             $expected_amount    Trusted amount and currency.
	 * @param int               $attempt            Logical checkout attempt.
	 * @param ProviderStatus    $provider_status    Provider state.
	 * @param ApplicationStatus $application_status Application state.
	 */
	private function __construct(
		string $uuid,
		string $integration,
		string $local_object_type,
		string $local_object_id,
		string $environment,
		string $reference,
		string $idempotency_key,
		Money $expected_amount,
		int $attempt,
		ProviderStatus $provider_status,
		ApplicationStatus $application_status
	) {
		$this->uuid               = $uuid;
		$this->integration        = $integration;
		$this->local_object_type  = $local_object_type;
		$this->local_object_id    = $local_object_id;
		$this->environment        = $environment;
		$this->reference          = $reference;
		$this->idempotency_key    = $idempotency_key;
		$this->expected_amount    = $expected_amount;
		$this->attempt            = $attempt;
		$this->provider_status    = $provider_status;
		$this->application_status = $application_status;
	}

	/**
	 * Create a new payment intent before provider checkout creation.
	 *
	 * @param string $uuid              Intent UUID.
	 * @param string $integration       Integration identifier.
	 * @param string $local_object_type Host object type.
	 * @param string $local_object_id   Host object identifier.
	 * @param string $environment       Bachs environment.
	 * @param string $reference         Opaque checkout reference.
	 * @param string $idempotency_key   Bachs idempotency key.
	 * @param Money  $expected_amount   Trusted amount and currency.
	 * @param int    $attempt           Logical checkout attempt.
	 * @return self
	 *
	 * @throws InvalidArgumentException When an invariant is violated.
	 */
	public static function create(
		string $uuid,
		string $integration,
		string $local_object_type,
		string $local_object_id,
		string $environment,
		string $reference,
		string $idempotency_key,
		Money $expected_amount,
		int $attempt = 1
	): self {
		self::assert_uuid( $uuid );
		self::assert_identifier( $integration, 'Integration' );
		self::assert_identifier( $local_object_type, 'Local object type' );
		self::assert_non_empty( $local_object_id, 'Local object ID' );
		self::assert_environment( $environment );
		self::assert_non_empty( $reference, 'Reference' );
		self::assert_non_empty( $idempotency_key, 'Idempotency key' );

		if ( ! $expected_amount->is_positive() ) {
			throw new InvalidArgumentException( 'Payment intent amount must be greater than zero.' );
		}

		if ( $attempt < 1 ) {
			throw new InvalidArgumentException( 'Payment intent attempt must be at least 1.' );
		}

		return new self(
			$uuid,
			$integration,
			$local_object_type,
			$local_object_id,
			$environment,
			$reference,
			$idempotency_key,
			$expected_amount,
			$attempt,
			ProviderStatus::CREATED,
			ApplicationStatus::PENDING
		);
	}

	/**
	 * Get the intent UUID.
	 *
	 * @return string
	 */
	public function uuid(): string {
		return $this->uuid;
	}

	/**
	 * Get the integration identifier.
	 *
	 * @return string
	 */
	public function integration(): string {
		return $this->integration;
	}

	/**
	 * Get the host object type.
	 *
	 * @return string
	 */
	public function local_object_type(): string {
		return $this->local_object_type;
	}

	/**
	 * Get the host object identifier.
	 *
	 * @return string
	 */
	public function local_object_id(): string {
		return $this->local_object_id;
	}

	/**
	 * Get the Bachs environment.
	 *
	 * @return string
	 */
	public function environment(): string {
		return $this->environment;
	}

	/**
	 * Get the opaque checkout reference.
	 *
	 * @return string
	 */
	public function reference(): string {
		return $this->reference;
	}

	/**
	 * Get the Bachs idempotency key.
	 *
	 * @return string
	 */
	public function idempotency_key(): string {
		return $this->idempotency_key;
	}

	/**
	 * Get the immutable trusted amount and currency.
	 *
	 * @return Money
	 */
	public function expected_amount(): Money {
		return $this->expected_amount;
	}

	/**
	 * Get the logical checkout attempt number.
	 *
	 * @return int
	 */
	public function attempt(): int {
		return $this->attempt;
	}

	/**
	 * Get the initial provider state.
	 *
	 * @return ProviderStatus
	 */
	public function provider_status(): ProviderStatus {
		return $this->provider_status;
	}

	/**
	 * Get the initial application state.
	 *
	 * @return ApplicationStatus
	 */
	public function application_status(): ApplicationStatus {
		return $this->application_status;
	}

	/**
	 * Validate an RFC 4122 version 4 UUID.
	 *
	 * @param string $uuid UUID to validate.
	 * @return void
	 *
	 * @throws InvalidArgumentException When the UUID is malformed.
	 */
	private static function assert_uuid( string $uuid ): void {
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $uuid ) ) {
			throw new InvalidArgumentException( 'Payment intent UUID must be an RFC 4122 version 4 UUID.' );
		}
	}

	/**
	 * Validate a normalized internal identifier.
	 *
	 * @param string $value Identifier value.
	 * @param string $label Human-readable label.
	 * @return void
	 *
	 * @throws InvalidArgumentException When the identifier is malformed.
	 */
	private static function assert_identifier( string $value, string $label ): void {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]*$/D', $value ) ) {
			throw new InvalidArgumentException( $label . ' must use lowercase letters, numbers, underscores or hyphens.' );
		}
	}

	/**
	 * Require a non-empty string without surrounding whitespace.
	 *
	 * @param string $value Value to validate.
	 * @param string $label Human-readable label.
	 * @return void
	 *
	 * @throws InvalidArgumentException When the value is empty or padded.
	 */
	private static function assert_non_empty( string $value, string $label ): void {
		if ( '' === $value || trim( $value ) !== $value ) {
			throw new InvalidArgumentException( $label . ' must be non-empty and must not contain surrounding whitespace.' );
		}
	}

	/**
	 * Validate the payment environment.
	 *
	 * @param string $environment Environment to validate.
	 * @return void
	 *
	 * @throws InvalidArgumentException When the environment is unsupported.
	 */
	private static function assert_environment( string $environment ): void {
		if ( ! in_array( $environment, array( self::ENVIRONMENT_SANDBOX, self::ENVIRONMENT_LIVE ), true ) ) {
			throw new InvalidArgumentException( 'Payment environment must be sandbox or live.' );
		}
	}
}
