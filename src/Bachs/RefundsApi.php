<?php
/**
 * Bachs refunds API.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use InvalidArgumentException;

/**
 * Creates and retrieves Bachs refunds.
 */
final class RefundsApi {
	/**
	 * API requester.
	 *
	 * @var ApiRequester
	 */
	private ApiRequester $client;

	/**
	 * Create the refunds API.
	 *
	 * @param ApiRequester $client Authenticated Bachs requester.
	 */
	public function __construct( ApiRequester $client ) {
		$this->client = $client;
	}

	/**
	 * Create one refund operation for a charge.
	 *
	 * @param string      $charge_id       Original Bachs charge identifier.
	 * @param string      $reference       Unique merchant refund reference.
	 * @param string      $idempotency_key Stable refund idempotency key.
	 * @param string|null $amount          Optional partial refund amount; null requests the full remaining amount.
	 * @param string|null $reason          Optional merchant reason.
	 * @return ProviderRefund
	 */
	public function create(
		string $charge_id,
		string $reference,
		string $idempotency_key,
		?string $amount = null,
		?string $reason = null
	): ProviderRefund {
		$body = array(
			'charge_id'       => $charge_id,
			'reference'       => $reference,
			'fee_bearer'      => 'org',
			'idempotency_key' => $idempotency_key,
		);

		if ( null !== $amount ) {
			$body['amount'] = $amount;
		}

		if ( null !== $reason && '' !== $reason ) {
			$body['reason'] = $reason;
		}

		$data = $this->client->post( Endpoints::REFUNDS, $body, $idempotency_key );

		return self::hydrate( $data );
	}

	/**
	 * Retrieve one refund by provider identifier.
	 *
	 * @param string $refund_id Bachs refund identifier.
	 * @return ProviderRefund
	 */
	public function get( string $refund_id ): ProviderRefund {
		return self::hydrate( $this->client->get( Endpoints::refund( $refund_id ) ) );
	}

	/**
	 * Retrieve the refund associated with a charge.
	 *
	 * @param string $charge_id Bachs charge identifier.
	 * @return ProviderRefund
	 */
	public function get_by_charge( string $charge_id ): ProviderRefund {
		return self::hydrate( $this->client->get( Endpoints::refund_by_charge( $charge_id ) ) );
	}

	/**
	 * Hydrate a provider refund while converting malformed responses to protocol failures.
	 *
	 * @param array<string, mixed> $data Bachs response data.
	 * @return ProviderRefund
	 *
	 * @throws ApiException When Bachs returns malformed refund evidence.
	 */
	private static function hydrate( array $data ): ProviderRefund {
		try {
			return ProviderRefund::from_api_response( $data );
		} catch ( InvalidArgumentException ) {
			throw ApiException::protocol( 'Bachs returned malformed refund data.', 200 );
		}
	}
}
