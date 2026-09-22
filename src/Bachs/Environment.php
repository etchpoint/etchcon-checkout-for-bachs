<?php
/**
 * Bachs API environment.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use InvalidArgumentException;

/**
 * Fixed Bachs API environments and their credential prefixes.
 */
enum Environment: string {
	/** Sandbox/test environment. */
	case SANDBOX = 'sandbox';

	/** Live/production environment. */
	case LIVE = 'live';

	/**
	 * Get the fixed API origin for this environment.
	 *
	 * @return string
	 */
	public function base_url(): string {
		return match ( $this ) {
			self::SANDBOX => 'https://sandbox-api.bachs.io',
			self::LIVE => 'https://api.bachs.io',
		};
	}

	/**
	 * Get the required API-key prefix for this environment.
	 *
	 * @return string
	 */
	public function key_prefix(): string {
		return match ( $this ) {
			self::SANDBOX => 'sk_sandbox_',
			self::LIVE => 'sk_live_',
		};
	}

	/**
	 * Assert that an API key belongs to this environment.
	 *
	 * @param string $api_key Bachs secret API key.
	 * @return void
	 *
	 * @throws InvalidArgumentException When the key is empty, padded, or belongs to another environment.
	 */
	public function assert_api_key( string $api_key ): void {
		if ( '' === $api_key || $api_key !== trim( $api_key ) ) {
			throw new InvalidArgumentException( 'Bachs API key must be a non-empty value without surrounding whitespace.' );
		}

		if ( ! str_starts_with( $api_key, $this->key_prefix() ) ) {
			throw new InvalidArgumentException( 'Bachs API key does not match the selected environment.' );
		}
	}
}
