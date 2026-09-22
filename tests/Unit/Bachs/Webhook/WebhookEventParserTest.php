<?php
/**
 * Webhook event parser tests.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs\Webhook;

use Etchpoint\BachsIntegrations\Bachs\Webhook\WebhookEventParser;
use Etchpoint\BachsIntegrations\Bachs\Webhook\WebhookProcessingException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies lenient event-envelope parsing and required-field validation.
 */
final class WebhookEventParserTest extends TestCase {
	/**
	 * Unknown additive fields are ignored while required fields are preserved.
	 *
	 * @return void
	 */
	public function test_valid_envelope_is_parsed_leniently(): void {
		$parser = new WebhookEventParser();
		// Pure PHPUnit fixture generation intentionally uses the native JSON encoder.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$body  = (string) json_encode(
			array(
				'id'              => 'evt_123',
				'type'            => 'collection.succeeded',
				'created_at'      => '2026-09-22T12:00:00Z',
				'organization_id' => 'acct_123',
				'future_field'    => true,
				'data'            => array(
					'charge_id'   => 'ch_123',
					'future_data' => 'ok',
				),
			),
			JSON_UNESCAPED_SLASHES
		);
		$event = $parser->parse( $body );

		self::assertSame( 'evt_123', $event->id() );
		self::assertSame( 'collection.succeeded', $event->type() );
		self::assertSame( 'acct_123', $event->organization_id() );
		self::assertSame( 'ch_123', $event->data()['charge_id'] );
	}

	/**
	 * Invalid JSON receives the stable malformed-event error code.
	 *
	 * @return void
	 */
	public function test_invalid_json_is_rejected(): void {
		$parser = new WebhookEventParser();

		try {
			$parser->parse( '{not-json}' );
			self::fail( 'Expected malformed webhook event exception.' );
		} catch ( WebhookProcessingException $exception ) {
			self::assertSame( WebhookProcessingException::CODE_MALFORMED_EVENT, $exception->error_code() );
		}
	}

	/**
	 * Required event data must remain a JSON object.
	 *
	 * @return void
	 */
	public function test_non_object_data_is_rejected(): void {
		$parser = new WebhookEventParser();
		// Pure PHPUnit fixture generation intentionally uses the native JSON encoder.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$body = (string) json_encode(
			array(
				'id'              => 'evt_123',
				'type'            => 'collection.succeeded',
				'created_at'      => '2026-09-22T12:00:00Z',
				'organization_id' => 'acct_123',
				'data'            => null,
			),
			JSON_UNESCAPED_SLASHES
		);

		$this->expectException( WebhookProcessingException::class );
		$parser->parse( $body );
	}
}
