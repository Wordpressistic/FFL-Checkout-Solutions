<?php
/**
 * ATF dealer sync module — wires the monthly cron to the sync service.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Services\ATF_Sync as Sync_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled ATF licensee import.
 */
class Atf_Sync {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_atf_sync', array( Sync_Service::class, 'start_full_sync' ) );
		add_action( 'fflcs_atf_sync_chunk', array( Sync_Service::class, 'process_chunk' ) );
	}
}
