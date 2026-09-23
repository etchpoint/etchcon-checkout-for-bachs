<?php
/**
 * Plugin Name: Payment Integrations for Bachs
 * Description: Accept Bachs payments through supported WordPress commerce, membership, form and donation plugins.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * WC requires at least: 8.3
 * Author: Etchpoint
 * Author URI: https://etchpoint.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: payment-integrations-for-bachs
 *
 * @package Etchpoint\BachsIntegrations
 */

use Etchpoint\BachsIntegrations\Bootstrap\Activation;
use Etchpoint\BachsIntegrations\Bootstrap\Plugin;
use Etchpoint\BachsIntegrations\Reconciliation\ReconciliationRuntime;

defined( 'ABSPATH' ) || exit;

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Payment Integrations for Bachs requires PHP 8.1 or newer.', 'payment-integrations-for-bachs' ) . '</p></div>';
		}
	);

	return;
}

require __DIR__ . '/vendor/autoload.php';

register_activation_hook( __FILE__, array( Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( ReconciliationRuntime::class, 'deactivate' ) );

Plugin::boot( __FILE__ );
