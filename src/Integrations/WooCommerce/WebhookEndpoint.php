<?php
/**
 * WooCommerce webhook endpoint compatibility wrapper.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Integrations\WooCommerce;

use Etchpoint\BachsIntegrations\Bachs\Webhook\SharedWebhookEndpoint;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Preserves the previous class surface while the shared endpoint owns routing.
 */
final class WebhookEndpoint {
	/**
	 * Delegate route registration to the shared webhook endpoint.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		SharedWebhookEndpoint::register_route();
	}

	/**
	 * Delegate webhook handling to the shared webhook endpoint.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		return SharedWebhookEndpoint::handle( $request );
	}
}
