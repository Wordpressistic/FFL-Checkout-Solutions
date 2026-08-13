<?php
/**
 * NMI gateway module.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the NMI gateway with WooCommerce.
 */
class Payment_Nmi {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register' ) );
	}

	/**
	 * Add the gateway.
	 *
	 * Returns the class name, not an instance: WooCommerce constructs gateways
	 * itself, once per request.
	 *
	 * @param array $gateways Registered gateways.
	 * @return array
	 */
	public function register( array $gateways ): array {
		$gateways[] = \FFLCS\Payments\Gateway_Nmi::class;

		return $gateways;
	}
}
