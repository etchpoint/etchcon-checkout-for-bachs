<?php
/**
 * Refund runtime wiring.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Refunds;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\PaymentsApi;
use Etchpoint\BachsIntegrations\Bachs\RefundsApi;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Persistence\IntentRecord;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Etchpoint\BachsIntegrations\Persistence\RefundRecord;
use Etchpoint\BachsIntegrations\Persistence\RefundRepository;

/**
 * Builds short-lived refund services from WordPress runtime configuration.
 */
final class RefundsRuntime {
	/**
	 * Request one refund immediately.
	 *
	 * @param int         $intent_id Intent row identifier.
	 * @param string      $amount    Decimal refund amount.
	 * @param string|null $reason    Optional reason.
	 * @return RefundRecord
	 */
	public static function request( int $intent_id, string $amount, ?string $reason ): RefundRecord {
		global $wpdb;

		$configuration = RuntimeConfiguration::from_wordpress();
		$client        = new ApiClient( $configuration->environment(), $configuration->api_key() );
		$service       = new RefundRequestService(
			new IntentRepository( $wpdb ),
			new RefundRepository( $wpdb ),
			new PaymentsApi( $client ),
			new RefundsApi( $client )
		);

		return $service->request( $intent_id, $amount, $reason );
	}

	/**
	 * List locally fulfilled payments that have not started a Bachs refund.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int, IntentRecord>
	 */
	public static function refundable_candidates( int $limit = 50 ): array {
		global $wpdb;

		$intents = new IntentRepository( $wpdb );
		$refunds = new RefundRepository( $wpdb );
		$result  = array();

		foreach ( $intents->find_refundable_candidates( $limit ) as $candidate ) {
			$charge_id = $candidate->charge_id();

			if ( null !== $charge_id && null === $refunds->find_by_charge_id( $charge_id ) ) {
				$result[] = $candidate;
			}
		}

		return $result;
	}

	/**
	 * List recent refund operations.
	 *
	 * @param int $limit Maximum rows to return.
	 * @return array<int, RefundRecord>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;

		return ( new RefundRepository( $wpdb ) )->find_recent( $limit );
	}
}
