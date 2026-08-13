<?php
/**
 * Shared base for distributor clients.
 *
 * Owns catalog caching, order recording and the guard rails every distributor
 * integration must honour, so a subclass only implements its own wire protocol.
 *
 * The rule the base class enforces, and subclasses cannot opt out of: an order
 * is only ever submitted from an explicit, attributable human action. There is
 * no scheduled job in this plugin that places a wholesale order.
 *
 * @package FFLCS
 */

namespace FFLCS\Distributors;

use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Base distributor client.
 */
abstract class Distributor_Support {

	/**
	 * Distributor slug.
	 *
	 * @var string
	 */
	protected string $slug;

	/**
	 * Constructor.
	 *
	 * @param string $slug Distributor slug.
	 */
	public function __construct( string $slug ) {
		$this->slug = $slug;
	}

	// ── Contract ─────────────────────────────────────────────────────────────

	/**
	 * Pull the catalog into the local cache.
	 *
	 * @return int|\WP_Error Number of products cached.
	 */
	abstract public function sync_catalog();

	/**
	 * Submit one order line to the distributor.
	 *
	 * @param array<string,mixed> $order Order details.
	 * @return array<string,mixed>|\WP_Error
	 */
	abstract public function submit_order( array $order );

	// ── Shared behaviour ─────────────────────────────────────────────────────

	/**
	 * Read a credential.
	 *
	 * @param string $field Field key.
	 */
	protected function credential( string $field ): string {
		return Distributor_Registry::credential( $this->slug, $field );
	}

	/**
	 * Whether this distributor is fully configured.
	 */
	public function is_configured(): bool {
		return Distributor_Registry::is_configured( $this->slug );
	}

	/**
	 * Error returned when credentials are missing.
	 */
	protected function not_configured(): \WP_Error {
		return new \WP_Error(
			'fflcs_distributor_not_configured',
			__( 'This distributor is missing credentials. Add them under FFL Checkout → Distributors.', 'ffl-checkout-solutions' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Status summary for the admin screen.
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		global $wpdb;

		$products = fflcs_table( 'distributor_products' );
		$orders   = fflcs_table( 'distributor_orders' );

		$product_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$products} WHERE distributor = %s",
				$this->slug
			)
		);

		$order_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$orders} WHERE distributor = %s",
				$this->slug
			)
		);

		$last_sync = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT MAX(last_synced) FROM {$products} WHERE distributor = %s",
				$this->slug
			)
		);

		return array(
			'slug'          => $this->slug,
			'configured'    => $this->is_configured(),
			'product_count' => $product_count,
			'order_count'   => $order_count,
			'last_sync'     => $last_sync,
		);
	}

	/**
	 * Cached catalog rows.
	 *
	 * @param array<string,mixed> $args search, page, per_page.
	 * @return array<int,object>
	 */
	public function catalog( array $args = array() ): array {
		global $wpdb;

		$table    = fflcs_table( 'distributor_products' );
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$search   = trim( (string) ( $args['search'] ?? '' ) );

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table}
					 WHERE distributor = %s AND ( description LIKE %s OR item_no LIKE %s OR upc LIKE %s )
					 ORDER BY description ASC
					 LIMIT %d OFFSET %d",
					$this->slug,
					$like,
					$like,
					$like,
					$per_page,
					$offset
				)
			);
		}

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE distributor = %s ORDER BY description ASC LIMIT %d OFFSET %d",
				$this->slug,
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Upsert catalog rows.
	 *
	 * @param array<int,array<string,mixed>> $products Normalised products.
	 * @return int Rows written.
	 */
	protected function cache_products( array $products ): int {
		global $wpdb;

		if ( ! $products ) {
			return 0;
		}

		$table  = fflcs_table( 'distributor_products' );
		$now    = fflcs_mysql_now();
		$written = 0;

		foreach ( array_chunk( $products, 100 ) as $chunk ) {
			$rows   = array();
			$values = array();

			foreach ( $chunk as $product ) {
				$rows[]   = '(%s,%s,%s,%s,%s,%s,%f,%f,%d,%d,%s)';
				$values[] = $this->slug;
				$values[] = (string) ( $product['item_no'] ?? '' );
				$values[] = substr( (string) ( $product['description'] ?? '' ), 0, 500 );
				$values[] = substr( (string) ( $product['manufacturer'] ?? '' ), 0, 150 );
				$values[] = substr( (string) ( $product['model'] ?? '' ), 0, 150 );
				$values[] = substr( (string) ( $product['upc'] ?? '' ), 0, 50 );
				$values[] = (float) ( $product['price'] ?? 0 );
				$values[] = (float) ( $product['msrp'] ?? 0 );
				$values[] = (int) ( $product['quantity'] ?? 0 );
				$values[] = ! empty( $product['is_firearm'] ) ? 1 : 0;
				$values[] = $now;
			}

			$sql = "INSERT INTO {$table}
				(distributor, item_no, description, manufacturer, model, upc, price, msrp, quantity, is_firearm, last_synced)
				VALUES " . implode( ',', $rows ) . '
				ON DUPLICATE KEY UPDATE
					description  = VALUES(description),
					manufacturer = VALUES(manufacturer),
					model        = VALUES(model),
					upc          = VALUES(upc),
					price        = VALUES(price),
					msrp         = VALUES(msrp),
					quantity     = VALUES(quantity),
					is_firearm   = VALUES(is_firearm),
					last_synced  = VALUES(last_synced)';

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( $sql, ...$values ) );

			$written += count( $chunk );
		}

		return $written;
	}

	/**
	 * Record a submitted order and write the audit trail.
	 *
	 * Called by subclasses after the distributor accepts an order. Recording
	 * happens whatever the response shape, so a submission is never invisible
	 * just because a distributor returned something unexpected.
	 *
	 * @param array<string,mixed> $order    Submitted order.
	 * @param array<string,mixed> $response Distributor response.
	 * @return int Row ID.
	 */
	protected function record_order( array $order, array $response ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'distributor_orders' ),
			array(
				'distributor'           => $this->slug,
				'transfer_id'           => (int) ( $order['transfer_id'] ?? 0 ) ?: null,
				'po_number'             => (string) ( $order['po_number'] ?? '' ),
				'item_no'               => (string) ( $order['item_no'] ?? '' ),
				'quantity'              => max( 1, (int) ( $order['quantity'] ?? 1 ) ),
				'ship_to_ffl'           => (string) ( $order['ship_to_ffl'] ?? '' ),
				'status'                => (string) ( $response['status'] ?? 'submitted' ),
				'distributor_order_ref' => (string) ( $response['order_ref'] ?? '' ),
				'response_json'         => wp_json_encode( $response ),
				'submitted_by'          => get_current_user_id(),
				'created_at'            => fflcs_mysql_now(),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		$row_id = (int) $wpdb->insert_id;

		if ( ! empty( $order['transfer_id'] ) ) {
			Transfer_Service::log_event(
				(int) $order['transfer_id'],
				'distributor_order_submitted',
				array(
					'actor' => wp_get_current_user()->user_login ?: 'admin',
					'notes' => sprintf(
						/* translators: 1: distributor name, 2: item number. */
						__( 'Wholesale order submitted to %1$s for item %2$s.', 'ffl-checkout-solutions' ),
						$this->slug,
						(string) ( $order['item_no'] ?? '' )
					),
					'data'  => array(
						'distributor' => $this->slug,
						'order_ref'   => (string) ( $response['order_ref'] ?? '' ),
					),
				)
			);
		}

		return $row_id;
	}

	/**
	 * A purchase-order number for this submission.
	 *
	 * Includes the transfer reference where there is one, so a distributor's
	 * packing slip can be matched back to the right customer without a lookup.
	 *
	 * @param int $transfer_id Transfer ID.
	 */
	protected function purchase_order_number( int $transfer_id ): string {
		$transfer = $transfer_id ? Transfer_Service::get( $transfer_id ) : null;

		return $transfer
			? (string) $transfer->transfer_ref
			: 'FFLCS-' . strtoupper( bin2hex( random_bytes( 3 ) ) );
	}

	/**
	 * Handle an asynchronous callback.
	 *
	 * Most distributors do not offer webhooks; those subclasses inherit this
	 * honest refusal rather than pretending to support one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error
	 */
	public function handle_webhook( \WP_REST_Request $request ) {
		return new \WP_Error(
			'fflcs_no_webhook',
			__( 'This distributor does not send callbacks. Order status is polled instead.', 'ffl-checkout-solutions' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * JSON request helper.
	 *
	 * @param string              $method  HTTP method.
	 * @param string              $url     Absolute URL.
	 * @param array<string,mixed> $body    Request body.
	 * @param array<string,string> $headers Extra headers.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function request_json( string $method, string $url, array $body = array(), array $headers = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array_merge(
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => 'FFLCheckoutSolutions/' . FFLCS_VERSION,
				),
				$headers
			),
		);

		if ( $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'fflcs_distributor_unreachable',
				sprintf(
					/* translators: %s: distributor name. */
					__( 'Could not reach %s.', 'ffl-checkout-solutions' ),
					$this->slug
				),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'fflcs_distributor_error',
				sprintf(
					/* translators: 1: distributor name, 2: HTTP status code. */
					__( '%1$s returned HTTP %2$d.', 'ffl-checkout-solutions' ),
					$this->slug,
					$code
				),
				array(
					'status' => 502,
					'body'   => is_array( $data ) ? $data : null,
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}
}
