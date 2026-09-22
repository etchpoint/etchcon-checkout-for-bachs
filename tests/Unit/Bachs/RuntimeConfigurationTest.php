<?php
/**
 * Runtime configuration tests.
 *
 * @package Etchpoint\BachsIntegrations\Tests
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Tests\Unit\Bachs;

use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies hardened runtime configuration behavior.
 */
final class RuntimeConfigurationTest extends TestCase {
	/**
	 * Payment readiness requires both a matching API key and webhook secret.
	 *
	 * @return void
	 */
	public function test_payment_readiness_requires_key_and_webhook_secret(): void {
		$ready = new RuntimeConfiguration(
			Environment::SANDBOX,
			'sk_sandbox_example',
			array( 'whsec_primary' )
		);
		$missing_secret = new RuntimeConfiguration(
			Environment::SANDBOX,
			'sk_sandbox_example',
			array()
		);

		self::assertTrue( $ready->is_payment_ready() );
		self::assertFalse( $missing_secret->is_payment_ready() );
	}

	/**
	 * Wrong-environment API keys are never considered checkout ready.
	 *
	 * @return void
	 */
	public function test_wrong_environment_key_is_not_ready(): void {
		$configuration = new RuntimeConfiguration(
			Environment::LIVE,
			'sk_sandbox_example',
			array( 'whsec_primary' )
		);

		self::assertFalse( $configuration->is_payment_ready() );
	}

	/**
	 * Missing API keys fail when a caller asks for the secret.
	 *
	 * @return void
	 */
	public function test_missing_api_key_throws(): void {
		$configuration = new RuntimeConfiguration( Environment::SANDBOX, '', array( 'whsec_primary' ) );

		$this->expectException( RuntimeException::class );
		$configuration->api_key();
	}

	/**
	 * Duplicate rotation secrets are collapsed without altering their bytes.
	 *
	 * @return void
	 */
	public function test_duplicate_webhook_secrets_are_collapsed(): void {
		$configuration = new RuntimeConfiguration(
			Environment::SANDBOX,
			'sk_sandbox_example',
			array( 'whsec_one', 'whsec_two', 'whsec_one' )
		);

		self::assertSame( array( 'whsec_one', 'whsec_two' ), $configuration->webhook_secrets() );
	}

	/**
	 * Webhook secret whitespace is rejected rather than silently trimmed.
	 *
	 * @return void
	 */
	public function test_webhook_secret_whitespace_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		new RuntimeConfiguration(
			Environment::SANDBOX,
			'sk_sandbox_example',
			array( ' whsec_one' )
		);
	}
}
