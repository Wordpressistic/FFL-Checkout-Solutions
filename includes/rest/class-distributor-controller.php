<?php
/**
 * Distributor REST endpoints (Sections 12.3, 12.4).
 *
 * Order submission is deliberately a POST that a human triggers from the admin
 * screen. There is no automatic ordering path anywhere in this plugin: placing
 * a wholesale order spends the store's money and ships a firearm, and that is
 * always an explicit, logged, attributable click.
 *
 * @package FFLCS
 */

namespace FFLCS\REST;

use FFLCS\Distributors\Distributor_Registry;
use FFLCS\License\Entitlement;
use FFLCS\REST_API;

defined( 'ABSPATH' ) || exit;

/**
 * Distributor catalog and ordering routes.
 */
class Distributor_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			FFLCS_REST_NS,
			'/admin/distributors/(?P<name>[a-z0-9\-_]+)/catalog',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'catalog' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/admin/distributors/(?P<name>[a-z0-9\-_]+)/order',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit_order' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/webhooks/distributor/(?P<name>[a-z0-9\-_]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Staff capability plus a business-tier entitlement.
	 *
	 * @return true|\WP_Error
	 */
	public function check_permission() {
		if ( ! REST_API::check_admin_permission() ) {
			return new \WP_Error(
				'fflcs_forbidden',
				__( 'You do not have permission to manage distributors.', 'ffl-checkout-solutions' ),
				array( 'status' => 403 )
			);
		}

		if ( ! Entitlement::plan_at_least( 'business' ) ) {
			return new \WP_Error(
				'fflcs_not_entitled',
				__( 'Distributor integrations require an active Business licence.', 'ffl-checkout-solutions' ),
				array( 'status' => 402 )
			);
		}

		return true;
	}

	/**
	 * Cached catalog rows for one distributor.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function catalog( \WP_REST_Request $request ) {
		$slug   = sanitize_key( (string) $request->get_param( 'name' ) );
		$client = Distributor_Registry::client( $slug );

		if ( ! $client ) {
			return new \WP_Error(
				'fflcs_unknown_distributor',
				__( 'Unknown distributor.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'products' => $client->catalog(
					array(
						'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
						'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
						'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 50 ),
					)
				),
			)
		);
	}

	/**
	 * Submit a real wholesale order.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function submit_order( \WP_REST_Request $request ) {
		$slug   = sanitize_key( (string) $request->get_param( 'name' ) );
		$client = Distributor_Registry::client( $slug );

		if ( ! $client ) {
			return new \WP_Error(
				'fflcs_unknown_distributor',
				__( 'Unknown distributor.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		// A dedicated nonce on top of the REST cookie check: this action spends
		// money, and it should not be reachable by a request that merely
		// happens to carry a valid session.
		$nonce = (string) $request->get_param( 'fflcs_order_nonce' );

		if ( ! wp_verify_nonce( $nonce, 'fflcs_distributor_order' ) ) {
			return new \WP_Error(
				'fflcs_invalid_nonce',
				__( 'This request could not be verified. Reload the page and try again.', 'ffl-checkout-solutions' ),
				array( 'status' => 403 )
			);
		}

		$result = $client->submit_order(
			array(
				'transfer_id' => (int) $request->get_param( 'transfer_id' ),
				'item_no'     => sanitize_text_field( (string) $request->get_param( 'item_no' ) ),
				'quantity'    => max( 1, (int) ( $request->get_param( 'quantity' ) ?: 1 ) ),
				'ship_to_ffl' => sanitize_text_field( (string) $request->get_param( 'ship_to_ffl' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'order'   => $result,
			)
		);
	}

	/**
	 * Receive an asynchronous distributor callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function webhook( \WP_REST_Request $request ) {
		$slug   = sanitize_key( (string) $request->get_param( 'name' ) );
		$client = Distributor_Registry::client( $slug );

		if ( ! $client ) {
			return new \WP_Error(
				'fflcs_unknown_distributor',
				__( 'Unknown distributor.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		$result = $client->handle_webhook( $request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'handled' => $result,
			)
		);
	}
}
