<?php
/**
 * Verified payment value object.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Core\Payment;

use Etchpoint\BachsIntegrations\Core\Money\Money;
use InvalidArgumentException;

/**
 * Immutable payment evidence that has passed the core verification pipeline.
 *
 * The webhook processor is responsible for signature, freshness, event
 * deduplication, environment and provider-state verification before calling
 * this factory. This value object rechecks immutable intent correlation so an
 * adapter never needs to trust browser or provider metadata directly.
 */
final class VerifiedPayment {
	/**
	 * Local payment intent UUID.
	 *
	 * @var string
	 */
	private string $intent_uuid;

	/**
	 * Integration identifier.
	 *
	 * @var string
	 */
	private string $integration;

	/**
	 * Host object type.
	 *
	 * @var string
	 */
	private string $local_object_type;

	/**
	 * Host object identifier.
	 *
	 * @var string
	 */
	private string $local_object_id;

	/**
	 * Opaque local/provider correlation reference.
	 *
	 * @var string
	 */
	private string $reference;

	/**
	 * Provider event identifier.
	 *
	 * @var string
	 */
	private string $provider_event_id;

	/**
	 * Provider checkout identifier.
	 *
	 * @var string
	 */
	private string $checkout_id;

	/**
	 * Authoritative successful provider charge/payment identifier.
	 *
	 * @var string
	 */
	private string $charge_id;

	/**
	 * Authoritatively paid amount and currency.
	 *
	 * @var Money
	 */
	private Money $amount;

	/**
	 * Create verified payment evidence from already-validated data.
	 *
	 * @param PaymentIntent $intent            Known local payment intent.
	 * @param string        $reference         Provider-returned correlation reference.
	 * @param string        $provider_event_id Provider event identifier.
	 * @param string        $checkout_id       Provider checkout identifier.
	 * @param string        $charge_id         Successful provider charge/payment identifier.
	 * @param Money         $paid_amount       Authoritatively paid amount and currency.
	 */
	private function __construct(
		PaymentIntent $intent,
		string $reference,
		string $provider_event_id,
		string $checkout_id,
		string $charge_id,
		Money $paid_amount
	) {
		$this->intent_uuid       = $intent->uuid();
		$this->integration       = $intent->integration();
		$this->local_object_type = $intent->local_object_type();
		$this->local_object_id   = $intent->local_object_id();
		$this->reference         = $reference;
		$this->provider_event_id = $provider_event_id;
		$this->checkout_id       = $checkout_id;
		$this->charge_id         = $charge_id;
		$this->amount            = $paid_amount;
	}

	/**
	 * Issue verified payment evidence after the verification pipeline succeeds.
	 *
	 * This method deliberately rechecks the immutable amount, currency and local
	 * reference. Signature verification, event deduplication, provider-state
	 * verification and checkout-to-intent lookup remain responsibilities of the
	 * webhook processor implemented later in the build.
	 *
	 * @param PaymentIntent $intent            Known local payment intent.
	 * @param string        $reference         Provider-returned correlation reference.
	 * @param string        $provider_event_id Provider event identifier.
	 * @param string        $checkout_id       Provider checkout identifier.
	 * @param string        $charge_id         Successful provider charge/payment identifier.
	 * @param Money         $paid_amount       Authoritatively paid amount and currency.
	 * @return self
	 *
	 * @throws InvalidArgumentException When immutable intent correlation fails.
	 */
	public static function from_verified_evidence(
		PaymentIntent $intent,
		string $reference,
		string $provider_event_id,
		string $checkout_id,
		string $charge_id,
		Money $paid_amount
	): self {
		self::assert_non_empty( $provider_event_id, 'Provider event ID' );
		self::assert_non_empty( $checkout_id, 'Checkout ID' );
		self::assert_non_empty( $charge_id, 'Charge ID' );

		if ( ! hash_equals( $intent->reference(), $reference ) ) {
			throw new InvalidArgumentException( 'Verified payment reference does not match the local payment intent.' );
		}

		if ( ! $intent->expected_amount()->equals( $paid_amount ) ) {
			throw new InvalidArgumentException( 'Verified payment amount or currency does not match the local payment intent.' );
		}

		return new self(
			$intent,
			$reference,
			$provider_event_id,
			$checkout_id,
			$charge_id,
			$paid_amount
		);
	}

	/**
	 * Get the local payment intent UUID.
	 *
	 * @return string
	 */
	public function intent_uuid(): string {
		return $this->intent_uuid;
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
	 * Get the opaque correlation reference.
	 *
	 * @return string
	 */
	public function reference(): string {
		return $this->reference;
	}

	/**
	 * Get the provider event identifier.
	 *
	 * @return string
	 */
	public function provider_event_id(): string {
		return $this->provider_event_id;
	}

	/**
	 * Get the provider checkout identifier.
	 *
	 * @return string
	 */
	public function checkout_id(): string {
		return $this->checkout_id;
	}

	/**
	 * Get the authoritative successful provider charge/payment identifier.
	 *
	 * @return string
	 */
	public function charge_id(): string {
		return $this->charge_id;
	}

	/**
	 * Get the exact paid amount and currency.
	 *
	 * @return Money
	 */
	public function amount(): Money {
		return $this->amount;
	}

	/**
	 * Require a non-empty identifier without surrounding whitespace.
	 *
	 * @param string $value Identifier value.
	 * @param string $label Human-readable label.
	 * @return void
	 *
	 * @throws InvalidArgumentException When the value is empty or padded.
	 */
	private static function assert_non_empty( string $value, string $label ): void {
		if ( '' === $value || trim( $value ) !== $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Domain exception text is not rendered output.
			throw new InvalidArgumentException( $label . ' must be non-empty and must not contain surrounding whitespace.' );
		}
	}
}
