<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * Section 9.0.1 of the spec: "HPOS compatible" is a claim made in the product
 * copy, and claims require code. This class is that code.
 *
 * Two features are declared:
 *   custom_order_tables  — High-Performance Order Storage. Every order read or
 *                          write in this plugin goes through wc_get_order() and
 *                          $order->get_meta()/update_meta_data(), never through
 *                          get_post_meta() against an order ID, which is what
 *                          the declaration asserts.
 *   cart_checkout_blocks — The block checkout. The dealer selector renders via
 *                          the classic hook and via the block integration in
 *                          Frontend\Checkout_Widget.
 *
 * @package FFLCS
 */

namespace FFLCS;

defined( 'ABSPATH' ) || exit;

/**
 * Declares this plugin's compatibility with optional WooCommerce features.
 */
class HPOS_Compat {

	/**
	 * Hooked on before_woocommerce_init from the bootstrap.
	 */
	public static function declare_compatibility(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			FFLCS_PLUGIN_FILE,
			true
		);

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			FFLCS_PLUGIN_FILE,
			true
		);
	}

	/**
	 * Whether HPOS is the active order storage on this site.
	 *
	 * Used by the diagnostics screen to report what was actually tested
	 * against, rather than reporting the declaration alone.
	 */
	public static function hpos_enabled(): bool {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
