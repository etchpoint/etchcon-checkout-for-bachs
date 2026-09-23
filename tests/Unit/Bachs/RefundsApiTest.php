<?php
/**
 * Bachs refunds API tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs;

use Etchpoint\BachsIntegrations\Bachs\RefundsApi;
use Etchpoint\BachsIntegrations\Tests\Support\Bachs\RecordingRequester;
use PHPUnit\Framework\TestCase;

/**
 * Verifies refund creation and retrieval contracts.
 */
final class RefundsApiTest extends TestCase {
	/**
	 * Partial refunds send the exact amount and stable idempotency evidence.
	 *
	 * @return void
	 */
	public function test_create_partial_refund_sends_amount_and_idempotency_key(): void {
		$requester = new RecordingRequester( self::refund_response() );
		$api       = new RefundsApi( $requester );
		$refund    = $api->create( 'pay_123', 'wp-refund-123', 'idem_123', '12.50', 'Customer request' );

		self::assertSame( '/v1/refunds', $requester->last_path() );
		self::assertSame( 'idem_123', $requester->last_idempotency_key() );
		self::assertSame(
			array(
				'charge_id'       => 'pay_123',
				'reference'       => 'wp-refund-123',
				'idempotency_key' => 'idem_123',
				'amount'          => '12.50',
				'reason'          => 'Customer request',
			),
			$requester->last_body()
		);
		self::assertSame( 'refund_123', $refund->refund_id() );
		self::assertSame( 'processing', $refund->status() );
	}

	/**
	 * Full refunds omit the optional amount field.
	 *
	 * @return void
	 */
	public function test_create_full_refund_omits_amount(): void {
		$requester = new RecordingRequester( self::refund_response() );
		$api       = new RefundsApi( $requester );

		$api->create( 'pay_123', 'wp-refund-123', 'idem_123' );

		self::assertArrayNotHasKey( 'amount', $requester->last_body() );
		self::assertArrayNotHasKey( 'reason', $requester->last_body() );
	}

	/**
	 * Refund retrieval paths use opaque provider identifiers safely.
	 *
	 * @return void
	 */
	public function test_refund_retrieval_paths(): void {
		$requester = new RecordingRequester( self::refund_response() );
		$api       = new RefundsApi( $requester );

		$api->get( 'refund_123' );
		self::assertSame( '/v1/refunds/refund_123', $requester->last_path() );

		$api->get_by_charge( 'pay_123' );
		self::assertSame( '/v1/refunds/by-charge/pay_123', $requester->last_path() );
	}

	/**
	 * Build one deterministic Bachs refund response.
	 *
	 * @return array<string, mixed>
	 */
	private static function refund_response(): array {
		return array(
			'refund_id'       => 'refund_123',
			'charge_id'       => 'pay_123',
			'reference'       => 'wp-refund-123',
			'status'          => 'processing',
			'requested_amount' => '12.50',
			'refunded_amount' => null,
			'reason'          => 'Customer request',
		);
	}
}
