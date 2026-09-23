<?php
/**
 * WordPress reconciliation runtime.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Reconciliation;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\CheckoutApi;
use Etchpoint\BachsIntegrations\Bachs\PaymentsApi;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Core\Contracts\PaymentFulfillmentHandler;
use Etchpoint\BachsIntegrations\Core\Payment\FulfillmentRegistry;
use Etchpoint\BachsIntegrations\Integrations\GravityForms\GravityFormsFulfillmentHandler;
use Etchpoint\BachsIntegrations\Integrations\PMPro\PMProFulfillmentHandler;
use Etchpoint\BachsIntegrations\Integrations\WooCommerce\WooCommerceFulfillmentHandler;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Throwable;

/**
 * Schedules bounded recovery work and builds reconciliation dependencies lazily.
 */
final class ReconciliationRuntime {
	/** Scheduled reconciliation hook. */
	public const CRON_HOOK = 'etchpoint_bachs_reconcile';

	/** Maximum intents handled in one scheduled pass. */
	private const BATCH_SIZE = 20;

	/**
	 * Register the scheduled recovery hook and ensure one hourly event exists.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run_scheduled' ) );

		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( current_datetime()->getTimestamp() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Remove scheduled recovery work on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Run one bounded scheduled reconciliation pass.
	 *
	 * @return void
	 */
	public static function run_scheduled(): void {
		try {
			$service = self::service();

			foreach ( self::candidates( self::BATCH_SIZE ) as $candidate ) {
				$service->reconcile( $candidate->id() );
			}
		} catch ( Throwable ) {
			// Recovery work must never break WordPress cron execution.
			return;
		}
	}

	/**
	 * Reconcile one intent immediately.
	 *
	 * @param int $intent_id Intent row identifier.
	 * @return ReconciliationResult
	 */
	public static function reconcile_now( int $intent_id ): ReconciliationResult {
		try {
			return self::service()->reconcile( $intent_id );
		} catch ( Throwable ) {
			return new ReconciliationResult(
				ReconciliationDisposition::RETRYABLE_FAILURE,
				$intent_id,
				'runtime_unavailable'
			);
		}
	}

	/**
	 * Return a bounded list of local intents eligible for reconciliation.
	 *
	 * @param int $limit Maximum intents to return.
	 * @return array<int, IntentRecord>
	 */
	public static function candidates( int $limit = self::BATCH_SIZE ): array {
		return self::intent_repository()->find_reconciliation_candidates( $limit );
	}

	/**
	 * Build the reconciliation service from current WordPress/Bachs state.
	 *
	 * @return ReconciliationService
	 */
	private static function service(): ReconciliationService {
		$configuration = RuntimeConfiguration::from_wordpress();
		$client        = new ApiClient( $configuration->environment(), $configuration->api_key() );
		$intents       = self::intent_repository();
		$events        = self::event_repository();

		return new ReconciliationService(
			$intents,
			$events,
			new CheckoutApi( $client ),
			new PaymentsApi( $client ),
			new FulfillmentRegistry( self::fulfillment_handlers( $intents, $events ) ),
			$configuration->environment()
		);
	}

	/**
	 * Build handlers for host integrations currently available.
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

		return $handlers;
	}

	/**
	 * Create the intent repository.
	 *
	 * @return IntentRepository
	 */
	private static function intent_repository(): IntentRepository {
		global $wpdb;

		return new IntentRepository( $wpdb );
	}

	/**
	 * Create the event repository.
	 *
	 * @return EventRepository
	 */
	private static function event_repository(): EventRepository {
		global $wpdb;

		return new EventRepository( $wpdb );
	}
}
