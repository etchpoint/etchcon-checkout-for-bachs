<?php
/**
 * Bachs credential settings page.
 *
 * @package Etchpoint\BachsIntegrations
 */

declare(strict_types=1);

namespace Etchpoint\BachsIntegrations\Admin;

use Etchpoint\BachsIntegrations\Bachs\Environment;
use Etchpoint\BachsIntegrations\Bachs\RuntimeConfiguration;
use InvalidArgumentException;

/**
 * Stores shared Bachs credentials used by every supported integration.
 */
final class SettingsPage {
	/** Required capability for payment credential management. */
	private const CAPABILITY = 'manage_options';

	/** Top-level Bachs admin menu slug. */
	private const MENU_SLUG = 'etchpoint-bachs';

	/** Admin-post action for saving settings. */
	private const SAVE_ACTION = 'etchpoint_bachs_save_settings';

	/** Notice nonce action. */
	private const NOTICE_NONCE_ACTION = 'etchpoint_bachs_settings_notice';

	/**
	 * Register the settings save handler.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( self::class, 'handle_save' ) );
	}

	/**
	 * Render the shared Bachs settings page.
	 *
	 * @return void
	 */
	public static function render(): void {
		self::assert_capability();
		$settings    = self::stored_settings();
		$environment = self::setting( $settings, 'environment', Environment::SANDBOX->value );
		$notice      = self::notice();
		$webhook_url = rest_url( 'etchpoint-bachs/v1/webhook' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Bachs Settings', 'etchcon-checkout-for-bachs' ); ?></h1>
			<p><?php echo esc_html__( 'Configure the Bachs account used by WooCommerce, Paid Memberships Pro, Gravity Forms, Fluent Forms and GiveWP.', 'etchcon-checkout-for-bachs' ); ?></p>

			<?php if ( null !== $notice ) : ?>
				<div class="notice <?php echo esc_attr( $notice['class'] ); ?> inline"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( self::has_constant_overrides() ) : ?>
				<div class="notice notice-info inline"><p><?php echo esc_html__( 'One or more Bachs wp-config.php constants are defined. Constant values take precedence over the matching fields saved here.', 'etchcon-checkout-for-bachs' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="etchpoint-bachs-environment"><?php echo esc_html__( 'Environment', 'etchcon-checkout-for-bachs' ); ?></label></th>
							<td>
								<select id="etchpoint-bachs-environment" name="environment">
									<option value="sandbox" <?php selected( $environment, Environment::SANDBOX->value ); ?>><?php echo esc_html__( 'Sandbox', 'etchcon-checkout-for-bachs' ); ?></option>
									<option value="live" <?php selected( $environment, Environment::LIVE->value ); ?>><?php echo esc_html__( 'Live', 'etchcon-checkout-for-bachs' ); ?></option>
								</select>
								<p class="description"><?php echo esc_html__( 'Use sandbox while testing. Live mode requires HTTPS.', 'etchcon-checkout-for-bachs' ); ?></p>
							</td>
						</tr>

						<?php self::render_secret_row( 'sandbox_secret_key', __( 'Sandbox secret key', 'etchcon-checkout-for-bachs' ), 'sk_sandbox_', self::is_configured( $settings, 'sandbox_secret_key' ), 'ETCHPOINT_BACHS_SANDBOX_SECRET_KEY' ); ?>
						<?php self::render_secret_row( 'live_secret_key', __( 'Live secret key', 'etchcon-checkout-for-bachs' ), 'sk_live_', self::is_configured( $settings, 'live_secret_key' ), 'ETCHPOINT_BACHS_LIVE_SECRET_KEY' ); ?>
						<?php self::render_secret_row( 'webhook_secret', __( 'Webhook signing secret', 'etchcon-checkout-for-bachs' ), __( 'Paste the active webhook signing secret', 'etchcon-checkout-for-bachs' ), self::is_configured( $settings, 'webhook_secret' ), 'ETCHPOINT_BACHS_WEBHOOK_SECRET' ); ?>
						<?php self::render_secret_row( 'webhook_secret_previous', __( 'Previous webhook secret', 'etchcon-checkout-for-bachs' ), __( 'Optional, for secret rotation', 'etchcon-checkout-for-bachs' ), self::is_configured( $settings, 'webhook_secret_previous' ), 'ETCHPOINT_BACHS_WEBHOOK_SECRET_PREVIOUS' ); ?>

						<tr>
							<th scope="row"><label for="etchpoint-bachs-organization-id"><?php echo esc_html__( 'Organization ID', 'etchcon-checkout-for-bachs' ); ?></label></th>
							<td>
								<input id="etchpoint-bachs-organization-id" name="organization_id" type="text" class="regular-text" value="<?php echo esc_attr( self::setting( $settings, 'organization_id', '' ) ); ?>" autocomplete="off" />
								<p class="description"><?php echo esc_html__( 'Optional. Pins payment and webhook verification to one Bachs organization.', 'etchcon-checkout-for-bachs' ); ?></p>
								<?php self::render_constant_note( 'ETCHPOINT_BACHS_ORGANIZATION_ID' ); ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="etchpoint-bachs-webhook-url"><?php echo esc_html__( 'Webhook endpoint', 'etchcon-checkout-for-bachs' ); ?></label></th>
							<td>
								<input id="etchpoint-bachs-webhook-url" type="url" class="large-text code" value="<?php echo esc_attr( $webhook_url ); ?>" readonly />
								<p class="description"><?php echo esc_html__( 'Add this URL as the webhook endpoint in Bachs, then paste the webhook signing secret above.', 'etchcon-checkout-for-bachs' ); ?></p>
								<p class="description"><?php echo esc_html__( 'Subscribe to: collection.succeeded, collection.failed, collection.underpaid, checkout.expired, refund.paid and refund.failed.', 'etchcon-checkout-for-bachs' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Bachs settings', 'etchcon-checkout-for-bachs' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Save dashboard-managed Bachs settings.
	 *
	 * Blank secret inputs preserve the currently saved secret unless the
	 * corresponding clear checkbox is selected.
	 *
	 * @return never
	 */
	public static function handle_save(): never {
		self::assert_capability();
		check_admin_referer( self::SAVE_ACTION );

		$environment = isset( $_POST['environment'] ) ? sanitize_key( wp_unslash( $_POST['environment'] ) ) : Environment::SANDBOX->value;

		if ( ! in_array( $environment, array( Environment::SANDBOX->value, Environment::LIVE->value ), true ) ) {
			self::redirect_with_notice( 'invalid_environment' );
		}

		$settings                = self::stored_settings();
		$settings['environment'] = $environment;
		$secret_fields           = array(
			'sandbox_secret_key',
			'live_secret_key',
			'webhook_secret',
			'webhook_secret_previous',
		);

		foreach ( $secret_fields as $field ) {
			if ( isset( $_POST[ 'clear_' . $field ] ) ) {
				$settings[ $field ] = '';
				continue;
			}

			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';

			if ( '' !== $value ) {
				$settings[ $field ] = $value;
			}
		}

		if ( '' !== ( $settings['sandbox_secret_key'] ?? '' ) ) {
			try {
				Environment::assert_api_key( Environment::SANDBOX, $settings['sandbox_secret_key'] );
			} catch ( InvalidArgumentException ) {
				self::redirect_with_notice( 'invalid_sandbox_key' );
			}
		}

		if ( '' !== ( $settings['live_secret_key'] ?? '' ) ) {
			try {
				Environment::assert_api_key( Environment::LIVE, $settings['live_secret_key'] );
			} catch ( InvalidArgumentException ) {
				self::redirect_with_notice( 'invalid_live_key' );
			}
		}

		$organization_id = isset( $_POST['organization_id'] ) ? sanitize_text_field( wp_unslash( $_POST['organization_id'] ) ) : '';

		if ( trim( $organization_id ) !== $organization_id ) {
			self::redirect_with_notice( 'invalid_organization' );
		}

		$settings['organization_id'] = $organization_id;
		update_option( RuntimeConfiguration::OPTION_NAME, $settings, false );
		self::redirect_with_notice( 'saved' );
	}

	/**
	 * Render one password field without exposing a previously saved secret.
	 *
	 * @param string $name              Field name.
	 * @param string $label             Field label.
	 * @param string $placeholder       Placeholder shown when no stored value is rendered.
	 * @param bool   $configured        Whether a dashboard-managed value already exists.
	 * @param string $constant_name     Matching wp-config constant.
	 * @return void
	 */
	private static function render_secret_row( string $name, string $label, string $placeholder, bool $configured, string $constant_name ): void {
		$field_id         = 'etchpoint-bachs-' . str_replace( '_', '-', $name );
		$constant_defined = defined( $constant_name );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( $constant_defined ) : ?>
					<p><strong><?php echo esc_html__( 'Managed in wp-config.php', 'etchcon-checkout-for-bachs' ); ?></strong></p>
					<p class="description"><?php echo esc_html__( 'This value is already configured outside WordPress and does not need to be entered here.', 'etchcon-checkout-for-bachs' ); ?></p>
				<?php elseif ( $configured ) : ?>
					<p><strong><?php echo esc_html__( 'Configured', 'etchcon-checkout-for-bachs' ); ?></strong></p>
					<p class="description"><?php echo esc_html__( 'The saved value is hidden and will continue to be used. You do not need to enter it again.', 'etchcon-checkout-for-bachs' ); ?></p>
					<details>
						<summary><?php echo esc_html__( 'Replace or remove', 'etchcon-checkout-for-bachs' ); ?></summary>
						<p>
							<input id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" type="password" class="regular-text" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="new-password" />
						</p>
						<p class="description"><?php echo esc_html__( 'Enter a new value only if you want to replace the one currently saved.', 'etchcon-checkout-for-bachs' ); ?></p>
						<p><label><input type="checkbox" name="<?php echo esc_attr( 'clear_' . $name ); ?>" value="1" /> <?php echo esc_html__( 'Remove the saved value', 'etchcon-checkout-for-bachs' ); ?></label></p>
					</details>
				<?php else : ?>
					<input id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" type="password" class="regular-text" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="new-password" />
				<?php endif; ?>
				<?php self::render_constant_note( $constant_name ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Show when a wp-config constant overrides a dashboard field.
	 *
	 * @param string $constant_name Constant name.
	 * @return void
	 */
	private static function render_constant_note( string $constant_name ): void {
		if ( ! defined( $constant_name ) ) {
			return;
		}
		?>
		<p class="description"><strong><?php echo esc_html( $constant_name ); ?></strong> <?php echo esc_html__( 'is defined in wp-config.php and overrides this saved value.', 'etchcon-checkout-for-bachs' ); ?></p>
		<?php
	}

	/**
	 * Read the dashboard settings option safely.
	 *
	 * @return array<string, string>
	 */
	private static function stored_settings(): array {
		$value = get_option( RuntimeConfiguration::OPTION_NAME, array() );

		if ( ! is_array( $value ) ) {
			return array();
		}

		$settings = array();

		foreach ( $value as $key => $setting ) {
			if ( is_string( $key ) && is_string( $setting ) ) {
				$settings[ $key ] = $setting;
			}
		}

		return $settings;
	}

	/**
	 * Read one dashboard setting.
	 *
	 * @param array<string, string> $settings      Settings array.
	 * @param string                $key           Setting key.
	 * @param string                $default_value Default value.
	 * @return string
	 */
	private static function setting( array $settings, string $key, string $default_value ): string {
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default_value;
	}

	/**
	 * Determine whether a dashboard secret is configured.
	 *
	 * @param array<string, string> $settings Settings array.
	 * @param string                $key      Secret key.
	 * @return bool
	 */
	private static function is_configured( array $settings, string $key ): bool {
		return '' !== self::setting( $settings, $key, '' );
	}

	/**
	 * Determine whether any supported wp-config override is active.
	 *
	 * @return bool
	 */
	private static function has_constant_overrides(): bool {
		foreach (
			array(
				'ETCHPOINT_BACHS_ENVIRONMENT',
				'ETCHPOINT_BACHS_SANDBOX_SECRET_KEY',
				'ETCHPOINT_BACHS_LIVE_SECRET_KEY',
				'ETCHPOINT_BACHS_WEBHOOK_SECRET',
				'ETCHPOINT_BACHS_WEBHOOK_SECRET_PREVIOUS',
				'ETCHPOINT_BACHS_ORGANIZATION_ID',
			) as $constant_name
		) {
			if ( defined( $constant_name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a safe settings notice from the redirect query string.
	 *
	 * @return array{class: string, message: string}|null
	 */
	private static function notice(): ?array {
		if ( ! isset( $_GET['bachs_settings'], $_GET['bachs_settings_nonce'] ) ) {
			return null;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['bachs_settings_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, self::NOTICE_NONCE_ACTION ) ) {
			return null;
		}

		$status = sanitize_key( wp_unslash( $_GET['bachs_settings'] ) );

		return match ( $status ) {
			'saved'               => array(
				'class'   => 'notice-success',
				'message' => __( 'Bachs settings saved.', 'etchcon-checkout-for-bachs' ),
			),
			'invalid_sandbox_key' => array(
				'class'   => 'notice-error',
				'message' => __( 'The sandbox secret key must use the sk_sandbox_ prefix.', 'etchcon-checkout-for-bachs' ),
			),
			'invalid_live_key'    => array(
				'class'   => 'notice-error',
				'message' => __( 'The live secret key must use the sk_live_ prefix.', 'etchcon-checkout-for-bachs' ),
			),
			'invalid_environment' => array(
				'class'   => 'notice-error',
				'message' => __( 'Choose either the sandbox or live Bachs environment.', 'etchcon-checkout-for-bachs' ),
			),
			'invalid_organization' => array(
				'class'   => 'notice-error',
				'message' => __( 'The organization ID must not contain surrounding whitespace.', 'etchcon-checkout-for-bachs' ),
			),
			default               => null,
		};
	}

	/**
	 * Redirect back to the settings screen with a signed notice code.
	 *
	 * @param string $status Notice status.
	 * @return never
	 */
	private static function redirect_with_notice( string $status ): never {
		$url = add_query_arg(
			array(
				'bachs_settings'       => $status,
				'bachs_settings_nonce' => wp_create_nonce( self::NOTICE_NONCE_ACTION ),
			),
			admin_url( 'admin.php?page=' . self::MENU_SLUG )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Enforce administrator-level authorization for credential management.
	 *
	 * @return void
	 */
	private static function assert_capability(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Bachs settings.', 'etchcon-checkout-for-bachs' ) );
		}
	}
}
