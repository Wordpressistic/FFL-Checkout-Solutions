<?php
/**
 * Deactivation cleanup.
 *
 * Deactivation is not uninstallation: scheduled work stops, but no customer
 * data, settings, credentials or compliance records are touched. A dealer who
 * deactivates to troubleshoot must get their bound book back intact on
 * reactivation.
 *
 * @package FFLCS
 */

namespace FFLCS;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on register_deactivation_hook.
 */
class Deactivator {

	/**
	 * Every recurring hook this plugin can schedule.
	 *
	 * Kept as one list so deactivation cannot silently leave an orphaned event
	 * firing against classes that no longer load.
	 *
	 * @var string[]
	 */
	const CRON_HOOKS = array(
		'fflcs_atf_sync',
		'fflcs_atf_sync_chunk',
		'fflcs_carrier_poll',
		'fflcs_nics_delay_alert',
		'fflcs_compliance_reminder',
		'fflcs_dealer_health_check',
		'fflcs_regulatory_watch',
		'fflcs_daily_runner',
		'fflcs_webhook_retry',
		'fflcs_license_validate',
		'fflcs_daily_license_check',
		'fflcs_update_check',
		'fflcs_async_issue_dealer_token',
		'fflcs_gunbroker_pull_orders',
		'fflcs_distributor_catalog_sync',
		'fflcs_credova_status_sweep',
	);

	/**
	 * Clear schedules and rewrite rules.
	 */
	public static function deactivate(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );

			// Action Scheduler keeps its own queue; clearing WP-Cron alone would
			// leave AS actions to fire against unloaded classes.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), 'fflcs' );
			}
		}

		// Transient state for in-flight imports — stale progress would otherwise
		// make the next activation resume a run whose temp files are gone.
		delete_transient( 'fflcs_activation_redirect' );
		delete_option( 'fflcs_atf_sync_status' );

		flush_rewrite_rules();
	}
}
