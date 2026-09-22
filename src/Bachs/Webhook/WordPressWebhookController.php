<?php
/**
 * WordPress REST webhook controller.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentRegistry;
use RuntimeException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Verifies, processes, and dispatches Bachs webhook requests.
 */
final class WordPressWebhookController {
	/** Maximum accepted raw webhook body size in bytes. */
	private const MAX_BODY_BYTES = 262144;

	/**
	 * Signature verifier.
	 *
	 * @var WebhookSignatureVerifier
	 */
	private WebhookSignatureVerifier $verifier;

	/**
	 * Verified event processor.
	 *
	 * @var WebhookProcessor
	 */
	private WebhookProcessor $processor;

	/**
	 * Host fulfillment registry.
	 *
	 * @var FulfillmentRegistry
	 */
	private FulfillmentRegistry $fulfillment;

	/**
	 * Active webhook signing secrets.
	 *
	 * @var array<int, string>
	 */
	private array $signing_secrets;

	/**
	 * Create the webhook controller.
	 *
	 * @param WebhookSignatureVerifier $verifier        Signature verifier.
	 * @param WebhookProcessor         $processor       Verified event processor.
	 * @param FulfillmentRegistry      $fulfillment     Host fulfillment registry.
	 * @param array<int, string>       $signing_secrets Active signing secrets.
	 *
	 * @throws RuntimeException When no signing secret is configured.
	 */
	public function __construct(
		WebhookSignatureVerifier $verifier,
		WebhookProcessor $processor,
		FulfillmentRegistry $fulfillment,
		array $signing_secrets
	) {
		if ( array() === $signing_secrets ) {
			throw new RuntimeException( 'At least one Bachs webhook signing secret is required.' );
		}

		$this->verifier        = $verifier;
		$this->processor       = $processor;
		$this->fulfillment     = $fulfillment;
		$this->signing_secrets = $signing_secrets;
	}

	/**
	 * Process one WordPress REST webhook request.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$raw_body = $request->get_body();

		if ( self::MAX_BODY_BYTES < strlen( $raw_body ) ) {
			return self::response( 413, 'request_too_large' );
		}

		$signature_header = $request->get_header( 'x-bachs-signature-v2' );

		if ( '' === $signature_header ) {
			return self::response( 401, 'signature_required' );
		}

		$verified_signature = $this->verify_with_active_secret( $raw_body, $signature_header );

		if ( null === $verified_signature ) {
			return self::response( 401, 'signature_invalid' );
		}

		try {
			$result = $this->processor->process( $verified_signature, $raw_body );
		} catch ( WebhookProcessingException ) {
			return self::response( 400, 'webhook_invalid' );
		}

		if ( WebhookProcessingDisposition::READY_FOR_FULFILLMENT === $result->disposition() ) {
			$payment = $result->verified_payment();

			if ( null === $payment || null === $result->intent_id() ) {
				return self::response( 500, 'fulfillment_evidence_missing' );
			}

			$handler = $this->fulfillment->find( $payment->integration() );

			if ( null === $handler ) {
				return self::response( 503, 'fulfillment_handler_unavailable' );
			}

			$disposition = $handler->fulfill(
				$result->intent_id(),
				$result->event_id(),
				$payment
			);

			return match ( $disposition ) {
				FulfillmentDisposition::APPLIED,
				FulfillmentDisposition::DUPLICATE,
				FulfillmentDisposition::REQUIRES_REVIEW => self::response( 200, $disposition->value ),
				FulfillmentDisposition::RETRYABLE_FAILURE => self::response( 500, $disposition->value ),
			};
		}

		if ( WebhookProcessingDisposition::RETRYABLE_FAILURE === $result->disposition() ) {
			return self::response( 500, 'retryable_failure' );
		}

		return self::response( 200, $result->disposition()->value );
	}

	/**
	 * Verify against every active secret during signing-secret rotation.
	 *
	 * @param string $raw_body         Exact raw request body.
	 * @param string $signature_header Bachs V2 signature header.
	 * @return VerifiedWebhookSignature|null
	 */
	private function verify_with_active_secret(
		string $raw_body,
		string $signature_header
	): ?VerifiedWebhookSignature {
		foreach ( $this->signing_secrets as $secret ) {
			try {
				return $this->verifier->verify( $raw_body, $signature_header, $secret );
			} catch ( WebhookVerificationException ) {
				continue;
			}
		}

		return null;
	}

	/**
	 * Create a minimal JSON REST response without leaking provider details.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $code   Stable local response code.
	 * @return WP_REST_Response
	 */
	private static function response( int $status, string $code ): WP_REST_Response {
		return new WP_REST_Response(
			array( 'status' => $code ),
			$status
		);
	}
}
