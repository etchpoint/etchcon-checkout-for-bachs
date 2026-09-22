<?php
/**
 * Bachs payments API.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use InvalidArgumentException;

/**
 * Retrieves authoritative provider payment state.
 */
final class PaymentsApi implements PaymentRetriever {
	/**
	 * API requester.
	 *
	 * @var ApiRequester
	 */
	private ApiRequester $client;

	/**
	 * Create the payments API.
	 *
	 * @param ApiRequester $client Authenticated Bachs requester.
	 */
	public function __construct( ApiRequester $client ) {
		$this->client = $client;
	}

	/**
	 * Retrieve one provider payment by its opaque payment/charge identifier.
	 *
	 * @param string $payment_id Bachs payment/charge identifier.
	 * @return ProviderPayment
	 *
	 * @throws ApiException When Bachs rejects or cannot process the request.
	 */
	public function get( string $payment_id ): ProviderPayment {
		$data = $this->client->get( Endpoints::payment( $payment_id ) );

		try {
			return ProviderPayment::from_api_response( $data );
		} catch ( InvalidArgumentException ) {
			throw ApiException::protocol( 'Bachs payment response is malformed.' );
		}
	}
}
