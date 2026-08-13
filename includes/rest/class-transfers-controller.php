<?php
/**
 * Transfer REST endpoints (Sections 12.2, 12.3).
 *
 * @package FFLCS
 */

namespace FFLCS\REST;

use FFLCS\REST_API;
use FFLCS\Services\Tracking_Links;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Transfer routes.
 */
class Transfers_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		// Public, signature-protected status lookup.
		register_rest_route(
			FFLCS_REST_NS,
			'/transfers/(?P<ref>[A-Za-z0-9\-]+)/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'public_status' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/track/(?P<ref>[A-Za-z0-9\-]+)/(?P<sig>[a-f0-9]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tracking_data' ),
				'permission_callback' => array( $this, 'check_public_permission' ),
			)
		);

		// Staff routes.
		register_rest_route(
			FFLCS_REST_NS,
			'/admin/transfers',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'admin_list' ),
					'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'admin_create' ),
					'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
				),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/admin/transfers/(?P<id>\d+)',
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

		register_rest_route(
			FFLCS_REST_NS,
			'/admin/transfers/(?P<id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'admin_set_status' ),
				'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
				'args'                => array(
					'status' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/admin/activity',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_activity' ),
				'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/admin/activity/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_activity_summary' ),
				'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Rate limit for the public routes.
	 *
	 * @return true|\WP_Error
	 */
	public function check_public_permission() {
		return REST_API::check_public_rate_limit( 'transfer_status', 30 );
	}

	// ── Public ───────────────────────────────────────────────────────────────

	/**
	 * Status for one transfer, gated by the tracking signature.
	 *
	 * The signature is required even though the reference is random: a leaked
	 * reference alone must not be enough to read a customer's transfer.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function public_status( \WP_REST_Request $request ) {
		$reference = sanitize_text_field( (string) $request->get_param( 'ref' ) );
		$signature = sanitize_text_field( (string) $request->get_param( 'sig' ) );

		if ( ! Tracking_Links::verify( $reference, $signature ) ) {
			return new \WP_Error(
				'fflcs_invalid_signature',
				__( 'This tracking link is not valid.', 'ffl-checkout-solutions' ),
				array( 'status' => 403 )
			);
		}

		$transfer = Transfer_Service::get_by_reference( $reference );

		if ( ! $transfer ) {
			return new \WP_Error(
				'fflcs_not_found',
				__( 'Transfer not found.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'transfer' => Transfer_Service::to_public_array( $transfer ),
			)
		);
	}

	/**
	 * Tracking page payload: transfer plus dealer contact details.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function tracking_data( \WP_REST_Request $request ) {
		$reference = sanitize_text_field( (string) $request->get_param( 'ref' ) );
		$signature = sanitize_text_field( (string) $request->get_param( 'sig' ) );

		if ( ! Tracking_Links::verify( $reference, $signature ) ) {
			return new \WP_Error(
				'fflcs_invalid_signature',
				__( 'This tracking link is not valid.', 'ffl-checkout-solutions' ),
				array( 'status' => 403 )
			);
		}

		$transfer = Transfer_Service::get_by_reference( $reference );

		if ( ! $transfer ) {
			return new \WP_Error(
				'fflcs_not_found',
				__( 'Transfer not found.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		$dealer = \FFLCS\Services\Dealer_Service::get( (int) $transfer->dealer_id );

		return rest_ensure_response(
			array(
				'success'  => true,
				'transfer' => Transfer_Service::to_public_array( $transfer ),
				'dealer'   => $dealer ? \FFLCS\Services\Dealer_Service::to_public_array( $dealer ) : null,
			)
		);
	}

	// ── Staff ────────────────────────────────────────────────────────────────

	/**
	 * Paginated transfer list.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function admin_list( \WP_REST_Request $request ): \WP_REST_Response {
		$results = Transfer_Service::query(
			array(
				'status'    => sanitize_key( (string) $request->get_param( 'status' ) ),
				'dealer_id' => (int) $request->get_param( 'dealer_id' ),
				'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'from'      => sanitize_text_field( (string) $request->get_param( 'from' ) ),
				'to'        => sanitize_text_field( (string) $request->get_param( 'to' ) ),
				'orderby'   => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'order'     => sanitize_key( (string) $request->get_param( 'order' ) ),
				'page'      => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page'  => (int) ( $request->get_param( 'per_page' ) ?: 25 ),
			)
		);

		return rest_ensure_response(
			array(
				'success'   => true,
				'total'     => $results['total'],
				'pages'     => $results['pages'],
				'transfers' => array_map( array( Transfer_Service::class, 'to_admin_array' ), $results['items'] ),
			)
		);
	}

	/**
	 * Single transfer with its event history.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function admin_get( \WP_REST_Request $request ) {
		$id       = (int) $request->get_param( 'id' );
		$transfer = Transfer_Service::get( $id );

		if ( ! $transfer ) {
			return new \WP_Error(
				'fflcs_not_found',
				__( 'Transfer not found.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'transfer' => Transfer_Service::to_admin_array( $transfer ),
				'events'   => Transfer_Service::events( $id ),
			)
		);
	}

	/**
	 * Create a transfer manually — used for phone and in-store orders.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function admin_create( \WP_REST_Request $request ) {
		$params = (array) ( $request->get_json_params() ?: $request->get_params() );

		$dealer_id = (int) ( $params['dealer_id'] ?? 0 );

		if ( ! \FFLCS\Services\Dealer_Service::is_selectable( $dealer_id ) ) {
			return new \WP_Error(
				'fflcs_invalid_dealer',
				__( 'Select an active dealer for this transfer.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$result = Transfer_Service::create(
			array(
				'dealer_id'      => $dealer_id,
				'customer_name'  => sanitize_text_field( (string) ( $params['customer_name'] ?? '' ) ),
				'customer_email' => sanitize_email( (string) ( $params['customer_email'] ?? '' ) ),
				'customer_phone' => sanitize_text_field( (string) ( $params['customer_phone'] ?? '' ) ),
				'product_name'   => sanitize_text_field( (string) ( $params['product_name'] ?? '' ) ),
				'product_type'   => sanitize_key( (string) ( $params['product_type'] ?? '' ) ),
				'manufacturer'   => sanitize_text_field( (string) ( $params['manufacturer'] ?? '' ) ),
				'model'          => sanitize_text_field( (string) ( $params['model'] ?? '' ) ),
				'caliber'        => sanitize_text_field( (string) ( $params['caliber'] ?? '' ) ),
				'serial_number'  => sanitize_text_field( (string) ( $params['serial_number'] ?? '' ) ),
				'status'         => 'dealer_selected',
				'source'         => 'manual',
			)
		);

		if ( ! $result['id'] ) {
			return new \WP_Error(
				'fflcs_create_failed',
				__( 'Could not create the transfer record.', 'ffl-checkout-solutions' ),
				array( 'status' => 500 )
			);
		}

		Transfer_Service::log_event(
			$result['id'],
			'created',
			array(
				'new_status' => 'dealer_selected',
				'dealer_id'  => $dealer_id,
				'actor'      => wp_get_current_user()->user_login ?: 'admin',
				'notes'      => __( 'Transfer created manually by staff.', 'ffl-checkout-solutions' ),
			)
		);

		return rest_ensure_response(
			array(
				'success'  => true,
				'transfer' => Transfer_Service::get( $result['id'] ),
			)
		);
	}

	/**
	 * Update transfer fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function admin_update( \WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$params = (array) ( $request->get_json_params() ?: $request->get_params() );

		unset( $params['id'] );

		$result = Transfer_Service::update(
			$id,
			$this->sanitize_transfer_fields( $params ),
			wp_get_current_user()->user_login ?: 'admin'
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'transfer' => Transfer_Service::get( $id ),
			)
		);
	}

	/**
	 * Change a transfer's status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function admin_set_status( \WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		$notes  = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );

		$result = Transfer_Service::set_status(
			$id,
			$status,
			wp_get_current_user()->user_login ?: 'admin',
			$notes
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'changed'  => (bool) $result,
				'transfer' => Transfer_Service::get( $id ),
			)
		);
	}

	/**
	 * Paginated activity feed across all transfers.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function admin_activity( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$table    = fflcs_table( 'events' );
		$per_page = max( 1, min( 200, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$type     = sanitize_key( (string) $request->get_param( 'type' ) );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $type ) {
			$where[]  = 'event_type = %s';
			$params[] = $type;
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			: $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, ...array_merge( $params, array( $per_page, $offset ) ) ) );

		return rest_ensure_response(
			array(
				'success' => true,
				'total'   => $total,
				'pages'   => (int) ceil( $total / $per_page ),
				'events'  => (array) $rows,
			)
		);
	}

	/**
	 * Daily counts and funnel totals.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function admin_activity_summary( \WP_REST_Request $request ): \WP_REST_Response {
		$days = max( 1, min( 365, (int) ( $request->get_param( 'days' ) ?: 30 ) ) );

		return rest_ensure_response(
			array(
				'success'  => true,
				'kpis'     => \FFLCS\Services\Analytics::kpis(),
				'statuses' => \FFLCS\Services\Analytics::transfers_by_status(),
				'funnel'   => \FFLCS\Services\Analytics::portal_funnel( $days ),
				'series'   => \FFLCS\Services\Analytics::daily_series( 'status_payment_confirmed', $days ),
			)
		);
	}

	/**
	 * Sanitise incoming transfer fields by type.
	 *
	 * @param array<string,mixed> $params Raw parameters.
	 * @return array<string,mixed>
	 */
	private function sanitize_transfer_fields( array $params ): array {
		$clean = array();

		$text_fields = array(
			'customer_name',
			'customer_phone',
			'product_name',
			'product_sku',
			'manufacturer',
			'model',
			'caliber',
			'serial_number',
			'tracking_number',
			'nics_ntn',
		);

		foreach ( $text_fields as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$clean[ $field ] = sanitize_text_field( (string) $params[ $field ] );
			}
		}

		foreach ( array( 'status', 'product_type', 'carrier', 'nics_status', 'nics_source' ) as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$clean[ $field ] = sanitize_key( (string) $params[ $field ] );
			}
		}

		if ( isset( $params['customer_email'] ) ) {
			$clean['customer_email'] = sanitize_email( (string) $params['customer_email'] );
		}

		foreach ( array( 'staff_notes', 'nics_response' ) as $field ) {
			if ( isset( $params[ $field ] ) ) {
				$clean[ $field ] = sanitize_textarea_field( (string) $params[ $field ] );
			}
		}

		foreach ( array( 'shipped_date', 'received_date', 'transferred_date', 'nics_delay_expires', 'nics_check_date' ) as $field ) {
			if ( ! isset( $params[ $field ] ) ) {
				continue;
			}
			$value = trim( (string) $params[ $field ] );
			// Empty clears the date; anything else must be a real Y-m-d.
			$clean[ $field ] = ( '' === $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) ? null : $value;
		}

		if ( isset( $params['dealer_id'] ) ) {
			$dealer_id = (int) $params['dealer_id'];
			if ( \FFLCS\Services\Dealer_Service::is_selectable( $dealer_id ) ) {
				$clean['dealer_id'] = $dealer_id;
			}
		}

		return $clean;
	}
}
