<?php
/**
 * Compliance REST endpoints (Sections 12.1, 12.3).
 *
 * @package FFLCS
 */

namespace FFLCS\REST;

use FFLCS\REST_API;
use FFLCS\Services\Compliance;

defined( 'ABSPATH' ) || exit;

/**
 * State rules and audit routes.
 */
class Compliance_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// Public: the checkout widget shows state notices before an order
		// exists, so this must be reachable without authentication. It exposes
		// nothing but the store's own published compliance notices.
		register_rest_route(
			FFLCS_REST_NS,
			'/compliance/(?P<state>[A-Za-z]{2})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'state_rules' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/admin/compliance/audit',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'audit' ),
				'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/admin/compliance/rules',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_rules' ),
					'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_rule' ),
					'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
				),
			)
		);
	}

	/**
	 * Rate limit for the public route.
	 *
	 * @return true|\WP_Error
	 */
	public function check_public_permission() {
		return REST_API::check_public_rate_limit( 'compliance_rules', 60 );
	}

	/**
	 * Active rules for one state.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function state_rules( \WP_REST_Request $request ): \WP_REST_Response {
		$state = strtoupper( substr( sanitize_text_field( (string) $request->get_param( 'state' ) ), 0, 2 ) );
		$type  = sanitize_key( (string) $request->get_param( 'item_type' ) );

		return rest_ensure_response(
			array(
				'success' => true,
				'state'   => $state,
				'rules'   => Compliance::rules_for_state( $state, $type ),
			)
		);
	}

	/**
	 * Full compliance audit snapshot.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function audit( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'success' => true,
				'audit'   => Compliance::audit(),
			)
		);
	}

	/**
	 * Every state rule, for the admin CRUD screen.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function list_rules( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$table = fflcs_table( 'state_rules' );
		$state = strtoupper( substr( sanitize_text_field( (string) $request->get_param( 'state' ) ), 0, 2 ) );

		if ( '' !== $state ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE state = %s ORDER BY rule_key ASC",
					$state
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY state ASC, rule_key ASC" );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'rules'   => (array) $rows,
			)
		);
	}

	/**
	 * Create or update one state rule.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_rule( \WP_REST_Request $request ) {
		$params = (array) ( $request->get_json_params() ?: $request->get_params() );

		$result = Compliance::save_rule(
			array(
				'id'          => (int) ( $params['id'] ?? 0 ),
				'state'       => strtoupper( substr( sanitize_text_field( (string) ( $params['state'] ?? '' ) ), 0, 2 ) ),
				'rule_key'    => sanitize_key( (string) ( $params['rule_key'] ?? '' ) ),
				'rule_value'  => sanitize_textarea_field( (string) ( $params['rule_value'] ?? '' ) ),
				'rule_type'   => sanitize_key( (string) ( $params['rule_type'] ?? 'notice' ) ),
				'item_types'  => sanitize_text_field( (string) ( $params['item_types'] ?? '' ) ),
				'is_blocking' => ! empty( $params['is_blocking'] ) ? 1 : 0,
				'is_active'   => isset( $params['is_active'] ) ? (int) (bool) $params['is_active'] : 1,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'rule_id' => $result,
			)
		);
	}
}
