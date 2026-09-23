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
use Etchpoint\BachsIntegrations\Core\Refund\RefundFulfillmentDisposition;
use Etchpoint\BachsIntegrations\Core\Refund\RefundFulfillmentRegistry;
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
	 * Optional refund webhook processor.
	 *
	 * @var RefundWebhookProcessor|null
	 */
	private ?RefundWebhookProcessor $refund_processor;

	/**
	 * Optional refund fulfillment registry.
	 *
	 * @var RefundFulfillmentRegistry|null
	 */
	private ?RefundFulfillmentRegistry $refund_fulfillment;

	/**
	 * Create the webhook controller.
	 *
	 * @param WebhookSignatureVerifier       $verifier           Signature verifier.
	 * @param WebhookProcessor               $processor          Verified event processor.
	 * @param FulfillmentRegistry            $fulfillment        Host fulfillment registry.
	 * @param array<int, string>             $signing_secrets    Active signing secrets.
	 * @param RefundWebhookProcessor|null    $refund_processor   Optional refund event processor.
	 * @param RefundFulfillmentRegistry|null $refund_fulfillment Optional refund fulfillment registry.
	 *
	 * @throws RuntimeException When no signing secret is configured.
	 */
	public function __construct(
		WebhookSignatureVerifier $verifier,
		WebhookProcessor $processor,
		FulfillmentRegistry $fulfillment,
		array $signing_secrets,
		?RefundWebhookProcessor $refund_processor = null,
		?RefundFulfillmentRegistry $refund_fulfillment = null
	) {
		if ( array() === $signing_secrets ) {
			throw new RuntimeException( 'At least one Bachs webhook signing secret is required.' );
		}

		$this->verifier           = $verifier;
		$this->processor          = $processor;
		$this->fulfillment        = $fulfillment;
		$this->signing_secrets    = $signing_secrets;
		$this->refund_processor   = $refund_processor;
		$this->refund_fulfillment = $refund_fulfillment;
	}

	/**
	 * Process one WordPress REST webhook request.
	 *
	 * @param WP_REST_Request    $request Incoming REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$raw_body = $request->get_body();

		if ( self::MAX_BODY_BYTES < strlen( $raw_body ) ) {
			return self::response( 413, 'request_too_large' );
		}

		$signature_v2 = self::first_header( $request, array( 'x-bachs-signature-v2', 'bachs-signature-v2' ) );

		$signature          = self::first_header( $request, array( 'x-bachs-signature', 'bachs-signature' ) );
		$timestamp          = self::first_header( $request, array( 'x-bachs-timestamp', 'bachs-timestamp' ) );
		$verified_signature = null;

		if ( null !== $signature_v2 ) {
			$verified_signature = $this->verify_v2_with_active_secret( $raw_body, $signature_v2 );
		}

		if ( null === $verified_signature && null !== $signature && null !== $timestamp ) {
			$verified_signature = $this->verify_legacy_with_active_secret( $raw_body, $signature, $timestamp );
		}

		if ( null === $signature_v2 && ( null === $signature || null === $timestamp ) ) {
			return self::response( 401, 'signature_required' );
		}

		if ( null === $verified_signature ) {
			return self::response( 401, 'signature_invalid' );
		}

		try {
			if ( null !== $this->refund_processor ) {
				$refund_result = $this->refund_processor->process_if_refund( $verified_signature, $raw_body );

				if ( null !== $refund_result ) {
					return $this->handle_refund_result( $refund_result );
				}
			}

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
	 * Dispatch a processed refund event to the matching host integration.
	 *
	 * @param RefundWebhookProcessingResult $result Refund processing result.
	 * @return WP_REST_Response
	 */
	private function handle_refund_result( RefundWebhookProcessingResult $result ): WP_REST_Response {
		if ( RefundWebhookProcessingDisposition::READY_FOR_FULFILLMENT === $result->disposition() ) {
			$refund = $result->verified_refund();

			if ( null === $refund || null === $result->refund_id() ) {
				return self::response( 500, 'refund_fulfillment_evidence_missing' );
			}

			if ( null === $this->refund_fulfillment ) {
				return self::response( 503, 'refund_fulfillment_unavailable' );
			}

			$handler = $this->refund_fulfillment->find( $refund->integration() );

			if ( null === $handler ) {
				return self::response( 503, 'refund_handler_unavailable' );
			}

			$disposition = $handler->fulfill( $result->refund_id(), $result->event_id(), $refund );

			return match ( $disposition ) {
				RefundFulfillmentDisposition::APPLIED,
				RefundFulfillmentDisposition::DUPLICATE,
				RefundFulfillmentDisposition::REQUIRES_REVIEW => self::response( 200, 'refund_' . $disposition->value ),
				RefundFulfillmentDisposition::RETRYABLE_FAILURE => self::response( 500, 'refund_' . $disposition->value ),
			};
		}

		if ( RefundWebhookProcessingDisposition::RETRYABLE_FAILURE === $result->disposition() ) {
			return self::response( 500, $result->disposition()->value );
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
	private function verify_v2_with_active_secret(
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
	 * Verify a legacy signature against every active secret during rotation.
	 *
	 * @param string $raw_body         Exact raw request body.
	 * @param string $signature_header Legacy signature header.
	 * @param string $timestamp_header Legacy timestamp header.
	 * @return VerifiedWebhookSignature|null
	 */
	private function verify_legacy_with_active_secret(
		string $raw_body,
		string $signature_header,
		string $timestamp_header
	): ?VerifiedWebhookSignature {
		foreach ( $this->signing_secrets as $secret ) {
			try {
				return $this->verifier->verify_legacy( $raw_body, $signature_header, $timestamp_header, $secret );
			} catch ( WebhookVerificationException ) {
				continue;
			}
		}

		return null;
	}

	/**
	 * Return the first non-empty request header from a list of compatible names.
	 *
	 * @param WP_REST_Request    $request Incoming REST request.
	 * @param array<int, string> $names   Candidate header names.
	 * @return string|null
	 */
	private static function first_header( WP_REST_Request $request, array $names ): ?string {
		foreach ( $names as $name ) {
			$value = $request->get_header( $name );

			if ( null !== $value && '' !== trim( $value ) ) {
				return trim( $value );
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
