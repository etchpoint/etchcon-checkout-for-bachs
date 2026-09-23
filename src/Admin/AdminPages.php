<?php
/**
 * Bachs operational admin pages.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Admin;

use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use Etchpoint\BachsIntegrations\Diagnostics\DiagnosticCheck;
use Etchpoint\BachsIntegrations\Diagnostics\DiagnosticsService;
use Etchpoint\BachsIntegrations\Diagnostics\DiagnosticStatus;
use Etchpoint\BachsIntegrations\Persistence\EventRepository;
use Etchpoint\BachsIntegrations\Persistence\IntentRepository;
use Etchpoint\BachsIntegrations\Reconciliation\ReconciliationRuntime;
use Throwable;

/**
 * Provides small, operationally-focused reconciliation and diagnostics screens.
 */
final class AdminPages {
	/** Required capability for global payment integration operations. */
	private const CAPABILITY = 'manage_options';

	/** Top-level admin menu slug. */
	private const MENU_SLUG = 'etchpoint-bachs';

	/** Reconciliation page slug. */
	private const RECONCILIATION_SLUG = 'etchpoint-bachs-reconciliation';

	/** Diagnostics page slug. */
	private const DIAGNOSTICS_SLUG = 'etchpoint-bachs-diagnostics';

	/** Manual reconciliation action. */
	private const RECONCILE_ACTION = 'etchpoint_bachs_reconcile_intent';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_post_' . self::RECONCILE_ACTION, array( self::class, 'handle_reconcile' ) );
	}

	/**
	 * Register operational menu pages without dashboard hijacking.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'Bachs Payments', 'payment-integrations-for-bachs' ),
			__( 'Bachs Payments', 'payment-integrations-for-bachs' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( self::class, 'render_diagnostics' ),
			'dashicons-money-alt'
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Reconciliation', 'payment-integrations-for-bachs' ),
			__( 'Reconciliation', 'payment-integrations-for-bachs' ),
			self::CAPABILITY,
			self::RECONCILIATION_SLUG,
			array( self::class, 'render_reconciliation' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Diagnostics', 'payment-integrations-for-bachs' ),
			__( 'Diagnostics', 'payment-integrations-for-bachs' ),
			self::CAPABILITY,
			self::DIAGNOSTICS_SLUG,
			array( self::class, 'render_diagnostics' )
		);
	}

	/**
	 * Render reconciliation candidates and manual recovery actions.
	 *
	 * @return void
	 */
	public static function render_reconciliation(): void {
		self::assert_capability();
		$candidates = ReconciliationRuntime::candidates( 50 );
		$result     = self::reconciliation_notice_code();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Bachs Reconciliation', 'payment-integrations-for-bachs' ); ?></h1>
			<p><?php echo esc_html__( 'Re-verify Bachs state and safely retry incomplete WordPress fulfillment.', 'payment-integrations-for-bachs' ); ?></p>
			<?php if ( null !== $result ) : ?>
				<div class="notice notice-info inline"><p><?php echo esc_html( $result ); ?></p></div>
			<?php endif; ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Intent', 'payment-integrations-for-bachs' ); ?></th>
						<th><?php echo esc_html__( 'Integration', 'payment-integrations-for-bachs' ); ?></th>
						<th><?php echo esc_html__( 'Local record', 'payment-integrations-for-bachs' ); ?></th>
						<th><?php echo esc_html__( 'Amount', 'payment-integrations-for-bachs' ); ?></th>
						<th><?php echo esc_html__( 'Provider state', 'payment-integrations-for-bachs' ); ?></th>
						<th><?php echo esc_html__( 'Application state', 'payment-integrations-for-bachs' ); ?></th>
						<th><?php echo esc_html__( 'Action', 'payment-integrations-for-bachs' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( array() === $candidates ) : ?>
					<tr><td colspan="7"><?php echo esc_html__( 'No reconciliation candidates found.', 'payment-integrations-for-bachs' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $candidates as $candidate ) : ?>
						<?php $intent = $candidate->intent(); ?>
						<tr>
							<td><?php echo esc_html( (string) $candidate->id() ); ?></td>
							<td><?php echo esc_html( $intent->integration() ); ?></td>
							<td><?php echo esc_html( $intent->local_object_type() . ' #' . $intent->local_object_id() ); ?></td>
							<td><?php echo esc_html( $intent->expected_amount()->currency()->code() . ' ' . $intent->expected_amount()->amount() ); ?></td>
							<td><?php echo esc_html( $intent->provider_status()->value ); ?></td>
							<td><?php echo esc_html( $intent->application_status()->value ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="<?php echo esc_attr( self::RECONCILE_ACTION ); ?>" />
									<input type="hidden" name="intent_id" value="<?php echo esc_attr( (string) $candidate->id() ); ?>" />
									<?php wp_nonce_field( self::RECONCILE_ACTION . '_' . $candidate->id() ); ?>
									<?php submit_button( __( 'Reconcile', 'payment-integrations-for-bachs' ), 'secondary small', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle one authorized manual reconciliation request.
	 *
	 * @return void
	 */
	public static function handle_reconcile(): void {
		self::assert_capability();
		$intent_id = isset( $_POST['intent_id'] ) ? absint( wp_unslash( $_POST['intent_id'] ) ) : 0;

		if ( $intent_id < 1 ) {
			wp_die( esc_html__( 'Invalid payment intent.', 'payment-integrations-for-bachs' ) );
		}

		check_admin_referer( self::RECONCILE_ACTION . '_' . $intent_id );
		$result = ReconciliationRuntime::reconcile_now( $intent_id );
		$url    = add_query_arg(
			array( 'bachs_reconcile' => $result->disposition()->value ),
			admin_url( 'admin.php?page=' . self::RECONCILIATION_SLUG )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render safe operational diagnostics.
	 *
	 * @return void
	 */
	public static function render_diagnostics(): void {
		self::assert_capability();
		$checks = self::diagnostic_checks();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Bachs Diagnostics', 'payment-integrations-for-bachs' ); ?></h1>
			<p><?php echo esc_html__( 'Read-only checks. Secrets and full provider responses are never displayed.', 'payment-integrations-for-bachs' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Check', 'payment-integrations-for-bachs' ); ?></th><th><?php echo esc_html__( 'Status', 'payment-integrations-for-bachs' ); ?></th><th><?php echo esc_html__( 'Detail', 'payment-integrations-for-bachs' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<tr>
						<td><?php echo esc_html( $check->label() ); ?></td>
						<td><?php echo esc_html( ucfirst( $check->status()->value ) ); ?></td>
						<td><?php echo esc_html( $check->detail() ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Build diagnostic checks or one safe configuration failure result.
	 *
	 * @return array<int, DiagnosticCheck>
	 */
	private static function diagnostic_checks(): array {
		global $wpdb;

		try {
			$service = new DiagnosticsService(
				RuntimeConfiguration::from_wordpress(),
				new IntentRepository( $wpdb ),
				new EventRepository( $wpdb )
			);

			return $service->run();
		} catch ( Throwable ) {
			return array(
				new DiagnosticCheck(
					'configuration',
					__( 'Bachs configuration', 'payment-integrations-for-bachs' ),
					DiagnosticStatus::ERROR,
					__( 'Configuration could not be loaded safely.', 'payment-integrations-for-bachs' )
				),
			);
		}
	}

	/**
	 * Read and normalize the reconciliation result query parameter.
	 *
	 * @return string|null
	 */
	private static function reconciliation_notice_code(): ?string {
		if ( ! isset( $_GET['bachs_reconcile'] ) ) {
			return null;
		}

		$status = sanitize_key( wp_unslash( $_GET['bachs_reconcile'] ) );

		return sprintf(
			/* translators: %s: reconciliation result code. */
			__( 'Reconciliation result: %s', 'payment-integrations-for-bachs' ),
			$status
		);
	}

	/**
	 * Enforce administrator-level authorization for financial operations.
	 *
	 * @return void
	 */
	private static function assert_capability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Bachs payments.', 'payment-integrations-for-bachs' ) );
		}
	}
}
