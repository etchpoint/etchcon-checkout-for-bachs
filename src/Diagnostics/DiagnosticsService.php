<?php
/**
 * Operational diagnostics service.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Diagnostics;

use Etchpoint\BachsIntegrations\Bachs\ApiClient;
use Etchpoint\BachsIntegrations\Bachs\ApiException;
use Etchpoint\BachsIntegrations\Bachs\Endpoints;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Etchpoint\BachsIntegrations\Persistence\Schema;
use RuntimeException;
use Throwable;

/**
 * Runs read-only diagnostics without exposing credentials or mutating payments.
 */
final class DiagnosticsService {
	/**
	 * Runtime configuration.
	 *
	 * @var RuntimeConfiguration
	 */
	private RuntimeConfiguration $configuration;

	/**
	 * Intent repository.
	 *
	 * @var IntentRepository
	 */
	private IntentRepository $intents;

	/**
	 * Event repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Create the diagnostics service.
	 *
	 * @param RuntimeConfiguration $configuration Runtime Bachs configuration.
	 * @param IntentRepository     $intents       Payment intent repository.
	 * @param EventRepository      $events        Event inbox repository.
	 */
	public function __construct(
		RuntimeConfiguration $configuration,
		IntentRepository $intents,
		EventRepository $events
	) {
		$this->configuration = $configuration;
		$this->intents       = $intents;
		$this->events        = $events;
	}

	/**
	 * Run safe operational checks.
	 *
	 * @return array<int, DiagnosticCheck>
	 */
	public function run(): array {
		$checks = array(
			$this->php_check(),
			$this->wordpress_check(),
			$this->https_check(),
			new DiagnosticCheck(
				'environment',
				__( 'Environment', 'payment-integrations-for-bachs' ),
				DiagnosticStatus::INFO,
				ucfirst( $this->configuration->environment()->value )
			),
			$this->api_key_check(),
			$this->webhook_secret_check(),
			$this->api_connectivity_check(),
			$this->cron_check(),
			$this->schema_check(),
			new DiagnosticCheck(
				'unresolved_mismatches',
				__( 'Unresolved payment mismatches', 'payment-integrations-for-bachs' ),
				0 === $this->intents->count_unresolved_mismatches() ? DiagnosticStatus::HEALTHY : DiagnosticStatus::WARNING,
				(string) $this->intents->count_unresolved_mismatches()
			),
			new DiagnosticCheck(
				'unresolved_events',
				__( 'Failed or review events', 'payment-integrations-for-bachs' ),
				0 === $this->events->count_unresolved_events() ? DiagnosticStatus::HEALTHY : DiagnosticStatus::WARNING,
				(string) $this->events->count_unresolved_events()
			),
			$this->integration_check(),
		);

		return $checks;
	}

	/**
	 * Build the PHP version check.
	 *
	 * @return DiagnosticCheck
	 */
	private function php_check(): DiagnosticCheck {
		$version   = phpversion();
		$supported = version_compare( $version, '8.1', '>=' );

		return new DiagnosticCheck(
			'php_version',
			__( 'PHP version', 'payment-integrations-for-bachs' ),
			$supported ? DiagnosticStatus::HEALTHY : DiagnosticStatus::ERROR,
			$version
		);
	}

	/**
	 * Build the WordPress version check.
	 *
	 * @return DiagnosticCheck
	 */
	private function wordpress_check(): DiagnosticCheck {
		$version   = (string) get_bloginfo( 'version' );
		$supported = version_compare( $version, '6.8', '>=' );

		return new DiagnosticCheck(
			'wordpress_version',
			__( 'WordPress version', 'payment-integrations-for-bachs' ),
			$supported ? DiagnosticStatus::HEALTHY : DiagnosticStatus::ERROR,
			$version
		);
	}

	/**
	 * Build the HTTPS check.
	 *
	 * @return DiagnosticCheck
	 */
	private function https_check(): DiagnosticCheck {
		$scheme = wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
		$secure = is_ssl() || 'https' === $scheme;

		return new DiagnosticCheck(
			'https',
			__( 'HTTPS', 'payment-integrations-for-bachs' ),
			$secure ? DiagnosticStatus::HEALTHY : DiagnosticStatus::ERROR,
			$secure ? __( 'Enabled', 'payment-integrations-for-bachs' ) : __( 'Required for live payments', 'payment-integrations-for-bachs' )
		);
	}

	/**
	 * Build the API key configuration check without displaying the key.
	 *
	 * @return DiagnosticCheck
	 */
	private function api_key_check(): DiagnosticCheck {
		try {
			$this->configuration->api_key();
			$status = DiagnosticStatus::HEALTHY;
			$detail = __( 'Configured', 'payment-integrations-for-bachs' );
		} catch ( RuntimeException ) {
			$status = DiagnosticStatus::ERROR;
			$detail = __( 'Missing or does not match the selected environment', 'payment-integrations-for-bachs' );
		}

		return new DiagnosticCheck( 'api_key', __( 'Bachs API key', 'payment-integrations-for-bachs' ), $status, $detail );
	}

	/**
	 * Build the webhook-secret configuration check without displaying the secret.
	 *
	 * @return DiagnosticCheck
	 */
	private function webhook_secret_check(): DiagnosticCheck {
		try {
			$this->configuration->webhook_secrets();
			$status = DiagnosticStatus::HEALTHY;
			$detail = __( 'Configured', 'payment-integrations-for-bachs' );
		} catch ( RuntimeException ) {
			$status = DiagnosticStatus::ERROR;
			$detail = __( 'Not configured', 'payment-integrations-for-bachs' );
		}

		return new DiagnosticCheck( 'webhook_secret', __( 'Webhook signing secret', 'payment-integrations-for-bachs' ), $status, $detail );
	}

	/**
	 * Perform a read-only Payments API request to validate connectivity/permission.
	 *
	 * @return DiagnosticCheck
	 */
	private function api_connectivity_check(): DiagnosticCheck {
		try {
			$client = new ApiClient( $this->configuration->environment(), $this->configuration->api_key() );
			$client->get( Endpoints::PAYMENTS );

			return new DiagnosticCheck(
				'api_connectivity',
				__( 'Bachs API', 'payment-integrations-for-bachs' ),
				DiagnosticStatus::HEALTHY,
				__( 'Healthy', 'payment-integrations-for-bachs' )
			);
		} catch ( ApiException $exception ) {
			if ( 401 === $exception->http_status() ) {
				$detail = __( 'Unauthorized. Check the API key.', 'payment-integrations-for-bachs' );
			} elseif ( 403 === $exception->http_status() ) {
				$detail = __( 'Forbidden. Payments permission is not available.', 'payment-integrations-for-bachs' );
			} else {
				$detail = __( 'Bachs could not be reached safely.', 'payment-integrations-for-bachs' );
			}

			return new DiagnosticCheck( 'api_connectivity', __( 'Bachs API', 'payment-integrations-for-bachs' ), DiagnosticStatus::ERROR, $detail );
		} catch ( Throwable ) {
			return new DiagnosticCheck(
				'api_connectivity',
				__( 'Bachs API', 'payment-integrations-for-bachs' ),
				DiagnosticStatus::ERROR,
				__( 'API configuration is incomplete.', 'payment-integrations-for-bachs' )
			);
		}
	}

	/**
	 * Build the WP-Cron health check.
	 *
	 * @return DiagnosticCheck
	 */
	private function cron_check(): DiagnosticCheck {
		$disabled = defined( 'DISABLE_WP_CRON' ) && true === constant( 'DISABLE_WP_CRON' );

		return new DiagnosticCheck(
			'wp_cron',
			__( 'WP-Cron recovery', 'payment-integrations-for-bachs' ),
			$disabled ? DiagnosticStatus::WARNING : DiagnosticStatus::HEALTHY,
			$disabled
				? __( 'Disabled. Configure a real cron runner for scheduled reconciliation.', 'payment-integrations-for-bachs' )
				: __( 'Available', 'payment-integrations-for-bachs' )
		);
	}

	/**
	 * Build the plugin database schema check.
	 *
	 * @return DiagnosticCheck
	 */
	private function schema_check(): DiagnosticCheck {
		$installed = (string) get_option( Schema::VERSION_OPTION, '' );
		$healthy   = Schema::VERSION === $installed;

		return new DiagnosticCheck(
			'database_schema',
			__( 'Database schema', 'payment-integrations-for-bachs' ),
			$healthy ? DiagnosticStatus::HEALTHY : DiagnosticStatus::ERROR,
			$healthy ? Schema::VERSION : __( 'Migration required', 'payment-integrations-for-bachs' )
		);
	}

	/**
	 * Build a compact installed-integration check.
	 *
	 * @return DiagnosticCheck
	 */
	private function integration_check(): DiagnosticCheck {
		$active = array();

		if ( class_exists( 'WC_Order' ) ) {
			$active[] = 'WooCommerce';
		}

		if ( class_exists( 'MemberOrder' ) ) {
			$active[] = 'Paid Memberships Pro';
		}

		return new DiagnosticCheck(
			'integrations',
			__( 'Detected integrations', 'payment-integrations-for-bachs' ),
			DiagnosticStatus::INFO,
			array() === $active ? __( 'None detected', 'payment-integrations-for-bachs' ) : implode( ', ', $active )
		);
	}
}
