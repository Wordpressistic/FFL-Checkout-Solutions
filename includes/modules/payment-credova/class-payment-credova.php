<?php
/**
 * Credova gateway module.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Payments\Gateway_Credova;
use FFLCS\Services\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Credova gateway and its reconciliation job.
 */
class Payment_Credova {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register' ) );

		// The webhook route and the sweep are wired here rather than from the
		// gateway constructor: WooCommerce only instantiates gateways when its
		// payment subsystem loads, which a bare REST or cron request does not
		// guarantee.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( Gateway_Credova::CRON_HOOK, array( Gateway_Credova::class, 'cron_check_pending' ) );

		Scheduler::recurring( DAY_IN_SECONDS, Gateway_Credova::CRON_HOOK );
	}

	/**
	 * Add the gateway.
	 *
	 * @param array $gateways Registered gateways.
	 * @return array
	 */
	public function register( array $gateways ): array {
		$gateways[] = Gateway_Credova::class;

		return $gateways;
	}

	/**
	 * Register the webhook route.
	 */
	public function register_routes(): void {
		( new Gateway_Credova() )->register_routes();
	}
}
