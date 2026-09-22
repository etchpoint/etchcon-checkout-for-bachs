<?php
/**
 * Bachs webhook event parser.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

/**
 * Parses the verified raw JSON body leniently while validating required fields.
 */
final class WebhookEventParser {
	/**
	 * Parse a verified Bachs webhook envelope.
	 *
	 * Unknown fields are intentionally ignored because Bachs documents additive
	 * envelope and payload fields as backwards-compatible changes.
	 *
	 * @param string $raw_body Exact verified request body.
	 * @return WebhookEvent
	 *
	 * @throws WebhookProcessingException When required envelope fields are malformed.
	 */
	public function parse( string $raw_body ): WebhookEvent {
		try {
			$decoded = json_decode( $raw_body, true, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw self::malformed_event( 'Bachs webhook body is not valid JSON.' );
		}

		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
			throw self::malformed_event( 'Bachs webhook envelope must be a JSON object.' );
		}

		/** @var array<string, mixed> $decoded */
		$id              = self::required_string( $decoded, 'id' );
		$type            = self::required_string( $decoded, 'type' );
		$created_at_raw  = self::required_string( $decoded, 'created_at' );
		$organization_id = self::required_string( $decoded, 'organization_id' );
		$account         = self::optional_string( $decoded, 'account' );
		$data            = $decoded['data'] ?? null;

		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			throw self::malformed_event( 'Bachs webhook data field must be a JSON object.' );
		}

		/** @var array<string, mixed> $data */
		try {
			$created_at = new DateTimeImmutable( $created_at_raw, new DateTimeZone( 'UTC' ) );
		} catch ( Throwable ) {
			throw self::malformed_event( 'Bachs webhook created_at field is invalid.' );
		}

		return new WebhookEvent( $id, $type, $created_at, $organization_id, $account, $data );
	}

	/**
	 * Read a required non-empty string field.
	 *
	 * @param array<string, mixed> $data Envelope data.
	 * @param string               $key  Field key.
	 * @return string
	 *
	 * @throws WebhookProcessingException When the field is missing or malformed.
	 */
	private static function required_string( array $data, string $key ): string {
		$value = self::optional_string( $data, $key );

		if ( null === $value || '' === $value || trim( $value ) !== $value ) {
			throw self::malformed_event( 'Bachs webhook envelope is missing a required string field.' );
		}

		return $value;
	}

	/**
	 * Read an optional string field.
	 *
	 * @param array<string, mixed> $data Envelope data.
	 * @param string               $key  Field key.
	 * @return string|null
	 */
	private static function optional_string( array $data, string $key ): ?string {
		$value = $data[ $key ] ?? null;

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Create a consistent malformed-event exception.
	 *
	 * @param string $message Diagnostic message.
	 * @return WebhookProcessingException
	 */
	private static function malformed_event( string $message ): WebhookProcessingException {
		// Parser diagnostics are internal exception data, not rendered output.
		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		$exception = new WebhookProcessingException(
			WebhookProcessingException::CODE_MALFORMED_EVENT,
			$message
		);
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

		return $exception;
	}
}
