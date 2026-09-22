<?php
/**
 * Bachs checkout-session API.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use Etchpoint\BachsIntegrations\Core\Payment\PaymentIntent;
use InvalidArgumentException;

/**
 * Creates and retrieves hosted Bachs checkout sessions.
 */
final class CheckoutApi {
	/** Maximum provider metadata keys. */
	private const MAX_METADATA_KEYS = 20;

	/** Maximum encoded provider metadata size in bytes. */
	private const MAX_METADATA_BYTES = 10240;

	/**
	 * API requester.
	 *
	 * @var ApiRequester
	 */
	private ApiRequester $client;

	/**
	 * Create the checkout API.
	 *
	 * @param ApiRequester $client Authenticated Bachs requester.
	 */
	public function __construct( ApiRequester $client ) {
		$this->client = $client;
	}

	/**
	 * Create a product-less checkout from immutable local intent data.
	 *
	 * The trusted amount, currency, reference, and idempotency key always come
	 * from the local PaymentIntent. Caller metadata cannot override correlation
	 * fields added by the plugin.
	 *
	 * @param PaymentIntent         $intent      Trusted local payment intent.
	 * @param string                $success_url Browser success destination.
	 * @param string                $cancel_url  Browser cancellation destination.
	 * @param array<string, string> $metadata    Optional non-sensitive metadata.
	 * @return CheckoutSession
	 *
	 * @throws InvalidArgumentException When checkout input or environment validation fails.
	 */
	public function create_raw_checkout(
		PaymentIntent $intent,
		string $success_url,
		string $cancel_url,
		array $metadata = array()
	): CheckoutSession {
		self::assert_http_url( $success_url, 'Success URL' );
		self::assert_http_url( $cancel_url, 'Cancel URL' );

		if ( $intent->environment() !== $this->client->environment()->value ) {
			throw new InvalidArgumentException( 'Payment intent environment does not match the Bachs API client environment.' );
		}

		$metadata = self::normalize_metadata( $metadata );
		$metadata = array_merge(
			$metadata,
			array(
				'integration' => $intent->integration(),
				'intent_uuid' => $intent->uuid(),
			)
		);

		if ( count( $metadata ) > self::MAX_METADATA_KEYS ) {
			throw new InvalidArgumentException( 'Bachs checkout metadata cannot contain more than 20 keys.' );
		}

		self::assert_metadata_size( $metadata );

		$body = array(
			'pricing'     => array(
				'currency' => $intent->expected_amount()->currency()->code(),
				'amount'   => $intent->expected_amount()->amount(),
			),
			'success_url' => $success_url,
			'cancel_url'  => $cancel_url,
			'reference'   => $intent->reference(),
			'metadata'    => $metadata,
		);
		$data = $this->client->post( Endpoints::CHECKOUT_SESSIONS, $body, $intent->idempotency_key() );

		return CheckoutSession::from_api_response( $data );
	}

	/**
	 * Retrieve a checkout session by its Bachs identifier.
	 *
	 * @param string $checkout_id Opaque checkout identifier.
	 * @return CheckoutSession
	 *
	 * @throws ApiException When Bachs rejects or cannot process the request.
	 */
	public function get( string $checkout_id ): CheckoutSession {
		$data = $this->client->get( Endpoints::checkout_session( $checkout_id ) );

		return CheckoutSession::from_api_response( $data );
	}

	/**
	 * Validate a browser return URL without allowing arbitrary schemes.
	 *
	 * @param string $url   URL to validate.
	 * @param string $label Human-readable field label.
	 * @return void
	 *
	 * @throws InvalidArgumentException When the URL is invalid.
	 */
	private static function assert_http_url( string $url, string $label ): void {
		$has_http_scheme = str_starts_with( $url, 'http://' ) || str_starts_with( $url, 'https://' );

		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) || ! $has_http_scheme ) {
			// Exception text is not rendered output.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( $label . ' must be a valid HTTP or HTTPS URL.' );
		}
	}

	/**
	 * Normalize optional metadata to simple scalar values.
	 *
	 * @param array<string, string> $metadata Caller metadata.
	 * @return array<string, string>
	 *
	 * @throws InvalidArgumentException When a metadata key is empty.
	 */
	private static function normalize_metadata( array $metadata ): array {
		$normalized = array();

		foreach ( $metadata as $key => $value ) {
			if ( '' === $key || trim( $key ) !== $key ) {
				throw new InvalidArgumentException(
					'Bachs metadata keys must be non-empty strings without surrounding whitespace.'
				);
			}

			$normalized[ $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * Enforce the provider's encoded metadata size limit.
	 *
	 * @param array<string, string> $metadata Normalized metadata.
	 * @return void
	 *
	 * @throws InvalidArgumentException When metadata cannot be encoded or is too large.
	 */
	private static function assert_metadata_size( array $metadata ): void {
		try {
			// Match the provider payload size rather than PHP serialization size.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			$encoded = json_encode( $metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		} catch ( \JsonException $exception ) {
			throw new InvalidArgumentException( 'Bachs checkout metadata must contain valid UTF-8 strings.' );
		}

		if ( strlen( $encoded ) > self::MAX_METADATA_BYTES ) {
			throw new InvalidArgumentException( 'Bachs checkout metadata cannot exceed 10 KB when encoded.' );
		}
	}
}
