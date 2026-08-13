<?php
/**
 * Inbound webhook endpoints (Section 12.4) and the outbound test route.
 *
 * @package FFLCS
 */

namespace FFLCS\REST;

use FFLCS\REST_API;
use FFLCS\Services\Nics_Tracker;
use FFLCS\Services\Token;
use FFLCS\Services\Webhook_Dispatcher;

defined( 'ABSPATH' ) || exit;

/**
 * NICS receipt and outbound webhook management.
 */
class Webhook_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			FFLCS_REST_NS,
			'/webhooks/nics',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'nics_webhook' ),
				// HMAC-verified inside the handler — the caller is a background
				// check provider's server, not a logged-in user.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/admin/webhooks/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_outbound' ),
				'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Receive a NICS result push.
	 *
	 * HONESTY NOTE: there is no single national NICS webhook standard. The FBI
	 * does not expose a push API to retailers. This endpoint implements a
	 * generic, HMAC-signed contract that a background-check *service provider*
	 * can be configured to call; the exact body field names of any given
	 * provider are unconfirmed and are mapped through the
	 * fflcs_nics_webhook_payload filter rather than assumed. Manual entry in
	 * the admin remains the supported path. See docs/STATUS.md.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function nics_webhook( \WP_REST_Request $request ) {
		$secret = \FFLCS\Crypto::get_option( 'fflcs_nics_webhook_secret', 'fflcs_webhook' );

		if ( '' === $secret ) {
			return new \WP_Error(
				'fflcs_webhook_not_configured',
				__( 'No NICS webhook secret is configured on this site.', 'ffl-checkout-solutions' ),
				array( 'status' => 503 )
			);
		}

		$body      = $request->get_body();
		$signature = (string) $request->get_header( 'x_fflcs_signature' );

		if ( '' === $signature ) {
			$signature = (string) $request->get_header( 'x_signature' );
		}

		$expected = hash_hmac( 'sha256', $body, $secret );

		// hash_equals, never ===: a signature comparison that short-circuits on
		// the first differing byte is measurably forgeable.
		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return new \WP_Error(
				'fflcs_invalid_signature',
				__( 'Invalid webhook signature.', 'ffl-checkout-solutions' ),
				array( 'status' => 403 )
			);
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'fflcs_invalid_payload',
				__( 'The webhook body was not valid JSON.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Filter the decoded NICS webhook payload before it is applied.
		 *
		 * The mapping seam for providers whose field names differ from the
		 * documented default contract.
		 *
		 * @param array            $payload Decoded body.
		 * @param \WP_REST_Request $request Request.
		 */
		$payload = (array) apply_filters( 'fflcs_nics_webhook_payload', $payload, $request );

		$result = Nics_Tracker::apply_webhook_result( $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'applied' => $result,
			)
		);
	}

	/**
	 * Fire a test delivery at a configured outbound endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test_outbound( \WP_REST_Request $request ) {
		$connection_id = (int) $request->get_param( 'connection_id' );

		if ( ! $connection_id ) {
			return new \WP_Error(
				'fflcs_missing_connection',
				__( 'A webhook connection ID is required.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$result = Webhook_Dispatcher::send_test( $connection_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'response' => $result,
			)
		);
	}
}
