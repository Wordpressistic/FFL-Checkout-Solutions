<?php
/**
 * Dealer REST endpoints (Sections 12.1, 12.3).
 *
 * The public search route is the busiest endpoint in the plugin — it runs on
 * every checkout — and it exposes a database of 80,000 licensed dealers. Both
 * facts shape it: it is rate-limited per IP, it refuses to run unfiltered, and
 * it returns a strict column allow-list that excludes internal staff notes.
 *
 * @package FFLCS
 */

namespace FFLCS\REST;

use FFLCS\REST_API;
use FFLCS\Services\Dealer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Dealer routes.
 */
class Dealers_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$namespaces = array( FFLCS_REST_NS, 'wpistic-ffl/v1' );

		foreach ( $namespaces as $namespace ) {
			register_rest_route(
				$namespace,
				'/dealers/search',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'search' ),
					'permission_callback' => array( $this, 'check_search_permission' ),
					'args'                => $this->search_args(),
				)
			);

			register_rest_route(
				$namespace,
				'/dealers/(?P<id>\d+)',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_detail_permission' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);
		}

		register_rest_route(
			FFLCS_REST_NS,
			'/admin/dealers',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'admin_list' ),
					'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'admin_update' ),
					'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/admin/dealers/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'admin_get' ),
					'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'admin_update' ),
					'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
				),
			)
		);
	}

	/**
	 * Search argument schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function search_args(): array {
		return array(
			'zip'     => array(
				'sanitize_callback' => static fn( $value ) => preg_replace( '/\D/', '', (string) $value ),
			),
			'name'    => array( 'sanitize_callback' => 'sanitize_text_field' ),
			'license' => array( 'sanitize_callback' => 'sanitize_text_field' ),
			'phone'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
			'state'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
			'city'    => array( 'sanitize_callback' => 'sanitize_text_field' ),
			'type'    => array( 'sanitize_callback' => 'sanitize_text_field' ),
			'radius'  => array(
				'default'           => 50,
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn( $value ) => $value >= 1 && $value <= 200,
			),
			'limit'   => array(
				'default'           => 25,
				'sanitize_callback' => 'absint',
			),
		);
	}

	// ── Permissions ──────────────────────────────────────────────────────────

	/**
	 * Public, rate-limited at 30/min/IP (Section 13.2).
	 *
	 * @return true|\WP_Error
	 */
	public function check_search_permission() {
		return REST_API::check_public_rate_limit( 'dealer_search', 30 );
	}

	/**
	 * Public detail route, rate-limited to blunt enumeration.
	 *
	 * @return true|\WP_Error
	 */
	public function check_detail_permission() {
		return REST_API::check_public_rate_limit( 'dealer_detail', 30 );
	}

	// ── Public endpoints ─────────────────────────────────────────────────────

	/**
	 * ZIP-radius and attribute dealer search.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function search( \WP_REST_Request $request ): \WP_REST_Response {
		$results = Dealer_Service::search(
			array(
				'zip'     => (string) $request->get_param( 'zip' ),
				'name'    => (string) $request->get_param( 'name' ),
				'license' => (string) $request->get_param( 'license' ),
				'phone'   => (string) $request->get_param( 'phone' ),
				'state'   => (string) $request->get_param( 'state' ),
				'city'    => (string) $request->get_param( 'city' ),
				'type'    => (string) $request->get_param( 'type' ),
				'radius'  => (int) $request->get_param( 'radius' ),
				'limit'   => (int) $request->get_param( 'limit' ),
			)
		);

		$dealers = array_map(
			array( Dealer_Service::class, 'to_public_array' ),
			$results['dealers']
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'origin'  => $results['origin'],
				'radius'  => $results['radius'],
				'count'   => count( $dealers ),
				'dealers' => $dealers,
			)
		);
	}

	/**
	 * Public dealer detail.
	 *
	 * Returns the same allow-listed columns as search. Internal notes are not
	 * in that list, so this route cannot leak them however it is called.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request ) {
		$dealer = Dealer_Service::get( (int) $request->get_param( 'id' ) );

		if ( ! $dealer || ! (int) $dealer->is_active ) {
			return new \WP_Error(
				'fflcs_dealer_not_found',
				__( 'Dealer not found.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'dealer'  => Dealer_Service::to_public_array( $dealer ),
			)
		);
	}

	// ── Admin endpoints ──────────────────────────────────────────────────────

	/**
	 * Paginated dealer list for staff.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function admin_list( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$table    = fflcs_table( 'dealers' );
		$per_page = max( 1, min( 200, (int) ( $request->get_param( 'per_page' ) ?: 25 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$state    = sanitize_text_field( (string) $request->get_param( 'state' ) );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( business_name LIKE %s OR license_number LIKE %s OR premise_city LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== $state ) {
			$where[]  = 'premise_state = %s';
			$params[] = strtoupper( $state );
		}

		if ( $request->get_param( 'preferred_only' ) ) {
			$where[] = 'is_preferred = 1';
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			: $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY is_preferred DESC, business_name ASC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, ...array_merge( $params, array( $per_page, $offset ) ) ) );

		return rest_ensure_response(
			array(
				'success'  => true,
				'total'    => $total,
				'pages'    => (int) ceil( $total / $per_page ),
				'page'     => $page,
				'dealers'  => (array) $rows,
			)
		);
	}

	/**
	 * Full dealer record for staff.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function admin_get( \WP_REST_Request $request ) {
		$dealer = Dealer_Service::get( (int) $request->get_param( 'id' ) );

		if ( ! $dealer ) {
			return new \WP_Error(
				'fflcs_dealer_not_found',
				__( 'Dealer not found.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'dealer'  => $dealer,
			)
		);
	}

	/**
	 * Update store-curated dealer fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function admin_update( \WP_REST_Request $request ) {
		$id = (int) ( $request->get_param( 'id' ) ?: $request->get_param( 'dealer_id' ) );

		if ( ! $id ) {
			return new \WP_Error(
				'fflcs_missing_id',
				__( 'A dealer ID is required.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$result = Dealer_Service::update( $id, (array) $request->get_json_params() ?: $request->get_params() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'dealer'  => Dealer_Service::get( $id ),
			)
		);
	}
}
