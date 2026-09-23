<?php
/**
 * Shared Bachs webhook endpoint wiring.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs\Webhook;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\PaymentsApi;
use Etchpoint\BachsIntegrations\Bachs\RefundsApi;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Core\Contracts\PaymentFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Contracts\RefundFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentRegistry;
use Etchpoint\BachsIntegrations\Core\Refund\RefundFulfillmentRegistry;
use Etchpoint\BachsIntegrations\Integrations\FluentForms\FluentFormsFulfillmentHandler;
use Etchpoint\BachsIntegrations\Integrations\FluentForms\FluentFormsRefundFulfillmentHandler;
use Etchpoint\BachsIntegrations\Integrations\GiveWP\GiveWPFulfillmentHandler;
use Etchpoint\BachsIntegrations\Integrations\GiveWP\GiveWPRefundFulfillmentHandler;
use Etchpoint\BachsIntegrations\Integrations\GravityForms\GravityFormsFulfillmentHandler;
use Etchpoint\BachsIntegrations\Integrations\GravityForms\GravityFormsRefundFulfillmentHandler;
use Etchpoint\BachsIntegrations\Integrations\PMPro\PMProFulfillmentHandler;
use Etchpoint\BachsIntegrations\Integrations\PMPro\PMProRefundFulfillmentHandler;
use Etchpoint\BachsIntegrations\Integrations\WooCommerce\WooCommerceFulfillmentHandler;
use Etchpoint\BachsIntegrations\Integrations\WooCommerce\WooCommerceRefundFulfillmentHandler;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Etchpoint\BachsIntegrations\Persistence\RefundRepository;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers the public signed webhook once and dispatches to active adapters.
 */
final class SharedWebhookEndpoint {
	/** REST namespace. */
	private const NAMESPACE = 'etchpoint-bachs/v1';

	/** REST route. */
	private const ROUTE = '/webhook';

	/**
	 * Register the public Bachs webhook route.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle one webhook request with lazily-created runtime configuration.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		try {
			$configuration    = RuntimeConfiguration::from_wordpress();
			$client           = new ApiClient( $configuration->environment(), $configuration->api_key() );
			$intents          = self::intent_repository();
			$events           = self::event_repository();
			$processor        = new WebhookProcessor(
				$events,
				$intents,
				new PaymentsApi( $client ),
				new WebhookEventParser(),
				$configuration->environment(),
				$configuration->organization_id()
			);
			$refunds          = self::refund_repository();
			$refund_processor = new RefundWebhookProcessor(
				$events,
				$intents,
				$refunds,
				new RefundsApi( $client ),
				new WebhookEventParser(),
				$configuration->environment(),
				$configuration->organization_id()
			);
			$controller       = new WordPressWebhookController(
				new WebhookSignatureVerifier(),
				$processor,
				new FulfillmentRegistry( self::fulfillment_handlers( $intents, $events ) ),
				$configuration->webhook_secrets(),
				$refund_processor,
				new RefundFulfillmentRegistry( self::refund_fulfillment_handlers( $refunds, $events ) )
			);

			return $controller->handle( $request );
		} catch ( Throwable ) {
			return new WP_REST_Response( array( 'status' => 'integration_unavailable' ), 503 );
		}
	}

	/**
	 * Build fulfillment handlers for integrations available in this request.
	 *
	 * @param IntentRepository $intents Payment intent repository.
	 * @param EventRepository  $events  Event inbox repository.
	 * @return array<int, PaymentFulfillmentHandler>
	 */
	private static function fulfillment_handlers(
		IntentRepository $intents,
		EventRepository $events
	): array {
		$handlers = array();

		if ( class_exists( 'WC_Order' ) ) {
			$handlers[] = new WooCommerceFulfillmentHandler( $intents, $events );
		}

		if ( class_exists( 'MemberOrder' ) ) {
			$handlers[] = new PMProFulfillmentHandler( $intents, $events );
		}

		if ( class_exists( 'GFPaymentAddOn' ) ) {
			$handlers[] = new GravityFormsFulfillmentHandler( $intents, $events );
		}

		if ( class_exists( 'FluentFormPro\\Payments\\PaymentMethods\\BaseProcessor' ) ) {
			$handlers[] = new FluentFormsFulfillmentHandler( $intents, $events );
		}

		if ( class_exists( 'Give\\Donations\\Models\\Donation' ) ) {
			$handlers[] = new GiveWPFulfillmentHandler( $intents, $events );
		}

		return $handlers;
	}

	/**
	 * Build refund fulfillment handlers for integrations available in this request.
	 *
	 * @param RefundRepository $refunds Refund repository.
	 * @param EventRepository  $events  Event inbox repository.
	 * @return array<int, RefundFulfillmentHandler>
	 */
	private static function refund_fulfillment_handlers( RefundRepository $refunds, EventRepository $events ): array {
		$handlers = array();

		if ( class_exists( 'WC_Order' ) ) {
			$handlers[] = new WooCommerceRefundFulfillmentHandler( $refunds, $events );
		}
		if ( class_exists( 'MemberOrder' ) ) {
			$handlers[] = new PMProRefundFulfillmentHandler( $refunds, $events );
		}
		if ( class_exists( 'GFPaymentAddOn' ) ) {
			$handlers[] = new GravityFormsRefundFulfillmentHandler( $refunds, $events );
		}
		if ( class_exists( 'FluentFormPro\\Payments\\PaymentMethods\\BaseProcessor' ) ) {
			$handlers[] = new FluentFormsRefundFulfillmentHandler( $refunds, $events );
		}
		if ( class_exists( 'Give\\Donations\\Models\\Donation' ) ) {
			$handlers[] = new GiveWPRefundFulfillmentHandler( $refunds, $events );
		}

		return $handlers;
	}

	/**
	 * Create the payment-intent repository.
	 *
	 * @return IntentRepository
	 */
	private static function intent_repository(): IntentRepository {
		global $wpdb;

		return new IntentRepository( $wpdb );
	}

	/**
	 * Create the refund repository.
	 *
	 * @return RefundRepository
	 */
	private static function refund_repository(): RefundRepository {
		global $wpdb;

		return new RefundRepository( $wpdb );
	}

	/**
	 * Create the event-inbox repository.
	 *
	 * @return EventRepository
	 */
	private static function event_repository(): EventRepository {
		global $wpdb;

		return new EventRepository( $wpdb );
	}
}
