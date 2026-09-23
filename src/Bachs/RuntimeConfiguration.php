<?php
/**
 * Runtime Bachs configuration.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Bachs;

use InvalidArgumentException;
use RuntimeException;

/**
 * Immutable runtime configuration with wp-config constant overrides.
 */
final class RuntimeConfiguration {
	/** WordPress option containing dashboard-managed Bachs settings. */
	public const OPTION_NAME = 'etchpoint_bachs_settings';

	/**
	 * Active environment.
	 *
	 * @var Environment
	 */
	private Environment $environment;

	/**
	 * Active API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Active webhook signing secrets during rotation.
	 *
	 * @var array<int, string>
	 */
	private array $webhook_secrets;

	/**
	 * Optional pinned organization identifier.
	 *
	 * @var string|null
	 */
	private ?string $organization_id;

	/**
	 * Create runtime configuration.
	 *
	 * @param Environment        $environment     Active Bachs environment.
	 * @param string             $api_key         Bachs secret API key, or an empty string when not configured.
	 * @param array<int, string> $webhook_secrets Active webhook signing secrets.
	 * @param string|null        $organization_id Optional pinned Bachs organization identifier.
	 */
	public function __construct(
		Environment $environment,
		string $api_key,
		array $webhook_secrets,
		?string $organization_id = null
	) {
		$this->environment     = $environment;
		$this->api_key         = $api_key;
		$this->webhook_secrets = self::normalize_secrets( $webhook_secrets );
		$this->organization_id = self::normalize_optional_identifier( $organization_id );
	}

	/**
	 * Build configuration from WordPress settings with optional wp-config overrides.
	 *
	 * ETCHPOINT_BACHS_ENVIRONMENT defaults to the saved dashboard setting and then
	 * sandbox. Secret constants override dashboard-managed values when present.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When the configured environment is invalid.
	 */
	public static function from_wordpress(): self {
		$settings          = self::stored_settings();
		$environment_value = self::constant_string( 'ETCHPOINT_BACHS_ENVIRONMENT' )
			?? self::setting_string( $settings, 'environment' )
			?? Environment::SANDBOX->value;
		$environment       = Environment::tryFrom( $environment_value );

		if ( null === $environment ) {
			throw new InvalidArgumentException( 'ETCHPOINT_BACHS_ENVIRONMENT must be either sandbox or live.' );
		}

		if ( Environment::SANDBOX === $environment ) {
			$api_key = self::constant_string( 'ETCHPOINT_BACHS_SANDBOX_SECRET_KEY' )
				?? self::setting_string( $settings, 'sandbox_secret_key' )
				?? '';
		} else {
			$api_key = self::constant_string( 'ETCHPOINT_BACHS_LIVE_SECRET_KEY' )
				?? self::setting_string( $settings, 'live_secret_key' )
				?? '';
		}

		$primary  = self::constant_string( 'ETCHPOINT_BACHS_WEBHOOK_SECRET' )
			?? self::setting_string( $settings, 'webhook_secret' );
		$previous = self::constant_string( 'ETCHPOINT_BACHS_WEBHOOK_SECRET_PREVIOUS' )
			?? self::setting_string( $settings, 'webhook_secret_previous' );
		$secrets  = array();

		if ( null !== $primary && '' !== $primary ) {
			$secrets[] = $primary;
		}

		if ( null !== $previous && '' !== $previous ) {
			$secrets[] = $previous;
		}

		return new self(
			$environment,
			$api_key,
			$secrets,
			self::constant_string( 'ETCHPOINT_BACHS_ORGANIZATION_ID' )
				?? self::setting_string( $settings, 'organization_id' )
		);
	}

	/**
	 * Get the active environment.
	 *
	 * @return Environment
	 */
	public function environment(): Environment {
		return $this->environment;
	}

	/**
	 * Get the configured API key after validating its environment prefix.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When the API key is missing or does not match the environment.
	 */
	public function api_key(): string {
		if ( '' === $this->api_key ) {
			throw new RuntimeException( 'Bachs API key is not configured.' );
		}

		try {
			Environment::assert_api_key( $this->environment, $this->api_key );
		} catch ( InvalidArgumentException ) {
			throw new RuntimeException( 'Bachs API key does not match the configured environment.' );
		}

		return $this->api_key;
	}

	/**
	 * Get active webhook signing secrets.
	 *
	 * @return array<int, string>
	 *
	 * @throws RuntimeException When no webhook signing secret is configured.
	 */
	public function webhook_secrets(): array {
		if ( array() === $this->webhook_secrets ) {
			throw new RuntimeException( 'Bachs webhook signing secret is not configured.' );
		}

		return $this->webhook_secrets;
	}

	/**
	 * Get the optional pinned organization identifier.
	 *
	 * @return string|null
	 */
	public function organization_id(): ?string {
		return $this->organization_id;
	}

	/**
	 * Determine whether checkout and webhook processing are both configured.
	 *
	 * @return bool
	 */
	public function is_payment_ready(): bool {
		if ( '' === $this->api_key || array() === $this->webhook_secrets ) {
			return false;
		}

		try {
			Environment::assert_api_key( $this->environment, $this->api_key );
		} catch ( InvalidArgumentException ) {
			return false;
		}

		return true;
	}

	/**
	 * Normalize configured webhook secrets without altering their bytes.
	 *
	 * @param array<int, string> $secrets Raw secrets.
	 * @return array<int, string>
	 *
	 * @throws InvalidArgumentException When a secret contains surrounding whitespace.
	 */
	private static function normalize_secrets( array $secrets ): array {
		$normalized = array();

		foreach ( $secrets as $secret ) {
			if ( '' === $secret ) {
				continue;
			}

			if ( trim( $secret ) !== $secret ) {
				throw new InvalidArgumentException( 'Bachs webhook signing secrets must not contain surrounding whitespace.' );
			}

			if ( ! in_array( $secret, $normalized, true ) ) {
				$normalized[] = $secret;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize an optional provider identifier.
	 *
	 * @param string|null $value Identifier value.
	 * @return string|null
	 *
	 * @throws InvalidArgumentException When the identifier is padded.
	 */
	private static function normalize_optional_identifier( ?string $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( trim( $value ) !== $value ) {
			throw new InvalidArgumentException( 'Bachs organization ID must not contain surrounding whitespace.' );
		}

		return $value;
	}

	/**
	 * Read normalized dashboard-managed settings.
	 *
	 * @return array<string, string>
	 */
	private static function stored_settings(): array {
		$value = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $value ) ) {
			return array();
		}

		$settings = array();

		foreach (
			array(
				'environment',
				'sandbox_secret_key',
				'live_secret_key',
				'webhook_secret',
				'webhook_secret_previous',
				'organization_id',
			) as $key
		) {
			if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) ) {
				$settings[ $key ] = $value[ $key ];
			}
		}

		return $settings;
	}

	/**
	 * Read one normalized setting string.
	 *
	 * @param array<string, string> $settings Settings array.
	 * @param string                $key      Setting key.
	 * @return string|null
	 */
	private static function setting_string( array $settings, string $key ): ?string {
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Read a string constant without coercing unexpected types.
	 *
	 * @param string $name Constant name.
	 * @return string|null
	 */
	private static function constant_string( string $name ): ?string {
		if ( ! defined( $name ) ) {
			return null;
		}

		$value = constant( $name );

		return is_string( $value ) ? $value : null;
	}
}
