<?php
/**
 * Bill_Hicks distributor module.
 *
 * Registers the catalog sync schedule for this distributor. Ordering is never
 * scheduled — it happens only from an explicit admin action, so there is no
 * cron hook for it here or anywhere else.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Distributors\Distributor_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Bill_Hicks drop-ship integration.
 */
class Distributor_Bill_Hicks {

	/**
	 * Distributor slug.
	 */
	const SLUG = 'bill-hicks';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_distributor_catalog_sync_' . str_replace( '-', '_', self::SLUG ), array( $this, 'sync' ) );

		// Daily catalog refresh, only once credentials exist — scheduling a job
		// that will fail on every run just fills the log with noise.
		if ( Distributor_Registry::is_configured( self::SLUG ) ) {
			\FFLCS\Services\Scheduler::recurring(
				DAY_IN_SECONDS,
				'fflcs_distributor_catalog_sync_' . str_replace( '-', '_', self::SLUG )
			);
		}
	}

	/**
	 * Refresh the cached catalog.
	 */
	public function sync(): void {
		$client = Distributor_Registry::client( self::SLUG );

		if ( ! $client ) {
			return;
		}

		$result = $client->sync_catalog();

		if ( is_wp_error( $result ) ) {
			fflcs_log( 'Distributor catalog sync failed: ' . self::SLUG, $result->get_error_message() );
		}
	}
}
