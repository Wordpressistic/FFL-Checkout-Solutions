<?php
/**
 * Analytics REST endpoints (Section 12.3).
 *
 * Reporting is a pro-tier feature, so these routes check entitlement in
 * addition to capability. A staff member on the free tier is authenticated but
 * not entitled — that must return 402, not 403, so the caller can tell the
 * difference between "you may not" and "your plan does not include this".
 *
 * @package FFLCS
 */

namespace FFLCS\REST;

use FFLCS\License\Entitlement;
use FFLCS\REST_API;
use FFLCS\Services\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Analytics routes.
 */
class Analytics_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			FFLCS_REST_NS,
			'/admin/analytics/transfers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'transfers' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/admin/analytics/woocommerce',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'woocommerce' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Capability first, then entitlement.
	 *
	 * @return true|\WP_Error
	 */
	public function check_permission() {
		if ( ! REST_API::check_admin_permission() ) {
			return new \WP_Error(
				'fflcs_forbidden',
				__( 'You do not have permission to view analytics.', 'ffl-checkout-solutions' ),
				array( 'status' => 403 )
			);
		}

		if ( ! Entitlement::can( 'analytics', 'pro' ) ) {
			return new \WP_Error(
				'fflcs_not_entitled',
				__( 'Analytics requires an active Pro licence.', 'ffl-checkout-solutions' ),
				array( 'status' => 402 )
			);
		}

		return true;
	}

	/**
	 * Transfer volume, status mix and funnel.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function transfers( \WP_REST_Request $request ): \WP_REST_Response {
		$days = max( 1, min( 365, (int) ( $request->get_param( 'days' ) ?: 30 ) ) );

		return rest_ensure_response(
			array(
				'success'  => true,
				'kpis'     => Analytics::kpis(),
				'statuses' => Analytics::transfers_by_status(),
				'funnel'   => Analytics::portal_funnel( $days ),
				'series'   => array(
					'created'   => Analytics::daily_series( 'status_payment_confirmed', $days ),
					'shipped'   => Analytics::daily_series( 'status_shipped_to_dealer', $days ),
					'completed' => Analytics::daily_series( 'status_transferred', $days ),
				),
			)
		);
	}

	/**
	 * Revenue attributable to orders that carried an FFL transfer.
	 *
	 * Scoped to FFL orders on purpose: a store's overall WooCommerce revenue is
	 * already reported by WooCommerce, and repeating it here would invite
	 * someone to read this number as total store revenue.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function woocommerce( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$days      = max( 1, min( 365, (int) ( $request->get_param( 'days' ) ?: 30 ) ) );
		$transfers = fflcs_table( 'transfers' );
		$since     = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		$order_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT order_id FROM {$transfers} WHERE order_id > 0 AND created_at >= %s",
				$since
			)
		);

		$revenue = 0.0;
		$counted = 0;

		foreach ( array_map( 'intval', (array) $order_ids ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( ! $order->is_paid() ) {
				continue;
			}
			$revenue += (float) $order->get_total();
			++$counted;
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'days'     => $days,
				'orders'   => $counted,
				'revenue'  => round( $revenue, 2 ),
				'aov'      => $counted > 0 ? round( $revenue / $counted, 2 ) : 0.0,
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			)
		);
	}
}
