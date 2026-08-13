<?php
/**
 * Carrier REST endpoints (Sections 12.3, 12.4).
 *
 * @package FFLCS
 */

namespace FFLCS\REST;

use FFLCS\Carriers\Carrier_Registry;
use FFLCS\REST_API;

defined( 'ABSPATH' ) || exit;

/**
 * Carrier polling and webhook receipt.
 */
class Carrier_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			FFLCS_REST_NS,
			'/admin/carriers/check-now',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check_now' ),
				'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/webhooks/carrier',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'webhook' ),
				// Authentication is the HMAC signature, verified inside the
				// handler. A capability check is meaningless here: the caller
				// is a carrier's server, not a WordPress user.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Poll a transfer's tracking status on demand.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_now( \WP_REST_Request $request ) {
		$transfer_id = (int) $request->get_param( 'transfer_id' );

		if ( ! $transfer_id ) {
			return new \WP_Error(
				'fflcs_missing_transfer',
				__( 'A transfer ID is required.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$result = Carrier_Registry::poll_transfer( $transfer_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'result'  => $result,
			)
		);
	}

	/**
	 * Receive a carrier status push.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function webhook( \WP_REST_Request $request ) {
		$verified = Carrier_Registry::verify_webhook( $request );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$result = Carrier_Registry::handle_webhook( $request );

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
