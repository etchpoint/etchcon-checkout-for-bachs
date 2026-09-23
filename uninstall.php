<?php
/**
 * Uninstall handler.
 *
 * Payment intents, webhook event records, and refund records are intentionally
 * retained on uninstall because they may form part of a site's financial and
 * operational audit trail. The retention policy is disclosed in readme.txt.
 *
 * @package Etchpoint\BachsIntegrations
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove plugin runtime housekeeping while retaining financial/audit tables.
wp_clear_scheduled_hook( 'etchpoint_bachs_reconcile' );
delete_option( 'etchpoint_bachs_schema_version' );
