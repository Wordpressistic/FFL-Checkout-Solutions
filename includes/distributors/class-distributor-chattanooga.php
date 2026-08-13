<?php
/**
 * Chattanooga Shooting Supplies drop-ship client.
 *
 * HONESTY NOTE: built against Chattanooga's published dealer API. Their
 * documented precondition is enforced here rather than worked around: an order
 * for a firearm is refused unless the receiving dealer's FFL is already on file
 * with Chattanooga. This plugin cannot put a licence on file on the store's
 * behalf, and pretending otherwise would produce a rejected order and a
 * confused customer.
 *
 * @package FFLCS
 */

namespace FFLCS\Distributors;

defined( 'ABSPATH' ) || exit;

/**
 * Chattanooga REST client.
 */
class Distributor_Chattanooga extends Distributor_Support {

	/**
	 * API base.
	 */
	const API_BASE = 'https://api.chattanoogashooting.com/rest/v5';

	/**
	 * Authorization header value.
	 *
	 * Their scheme is the SID plus an MD5 of the token — MD5 is their choice,
	 * not ours; it is a request-identity token, not a password hash.
	 */
	private function auth_header(): string {
		return 'Basic ' . $this->credential( 'sid' ) . ':' . md5( $this->credential( 'token' ) );
	}

	/**
	 * Pull the catalog.
	 *
	 * @return int|\WP_Error
	 */
	public function sync_catalog() {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}

		$response = $this->request_json(
			'GET',
			self::API_BASE . '/items?limit=500',
			array(),
			array( 'Authorization' => $this->auth_header() )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items    = (array) ( $response['items'] ?? array() );
		$products = array();

		foreach ( $items as $item ) {
			$item_no = (string) ( $item['cssi_id'] ?? $item['item_number'] ?? '' );

			if ( '' === $item_no ) {
				continue;
			}

			$products[] = array(
				'item_no'      => $item_no,
				'description'  => (string) ( $item['name'] ?? '' ),
				'manufacturer' => (string) ( $item['manufacturer'] ?? '' ),
				'model'        => (string) ( $item['model'] ?? '' ),
				'upc'          => (string) ( $item['upc'] ?? '' ),
				'price'        => (float) ( $item['custom_price'] ?? $item['price'] ?? 0 ),
				'msrp'         => (float) ( $item['msrp'] ?? 0 ),
				'quantity'     => (int) ( $item['inventory'] ?? 0 ),
				'is_firearm'   => ! empty( $item['serialized'] ) || ! empty( $item['ffl_required'] ),
			);
		}

		return $this->cache_products( $products );
	}

	/**
	 * Submit a drop-ship order.
	 *
	 * @param array<string,mixed> $order Order details.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function submit_order( array $order ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}

		$ffl = trim( (string) ( $order['ship_to_ffl'] ?? '' ) );

		if ( '' === $ffl ) {
			return new \WP_Error(
				'fflcs_missing_ffl',
				__( 'A receiving dealer FFL number is required before an order can be placed.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		// The documented precondition, checked before spending money on a call
		// that would be rejected anyway.
		$on_file = $this->ffl_is_on_file( $ffl );

		if ( is_wp_error( $on_file ) ) {
			return $on_file;
		}

		if ( ! $on_file ) {
			return new \WP_Error(
				'fflcs_ffl_not_on_file',
				sprintf(
					/* translators: %s: FFL licence number. */
					__( 'Chattanooga does not have FFL %s on file. Send them a copy of the licence first — this plugin cannot do that for you.', 'ffl-checkout-solutions' ),
					$ffl
				),
				array( 'status' => 409 )
			);
		}

		$po = $this->purchase_order_number( (int) ( $order['transfer_id'] ?? 0 ) );

		$response = $this->request_json(
			'POST',
			self::API_BASE . '/orders',
			array(
				'order_number' => $po,
				'ffl_number'   => $ffl,
				'items'        => array(
					array(
						'item_number' => (string) ( $order['item_no'] ?? '' ),
						'quantity'    => max( 1, (int) ( $order['quantity'] ?? 1 ) ),
					),
				),
			),
			array( 'Authorization' => $this->auth_header() )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = array(
			'status'    => 'submitted',
			'order_ref' => (string) ( $response['order_number'] ?? $response['id'] ?? '' ),
			'po_number' => $po,
			'raw'       => $response,
		);

		$order['po_number'] = $po;

		$this->record_order( $order, $result );

		return $result;
	}

	/**
	 * Whether Chattanooga has this FFL on file.
	 *
	 * @param string $ffl FFL licence number.
	 * @return bool|\WP_Error
	 */
	private function ffl_is_on_file( string $ffl ) {
		$response = $this->request_json(
			'GET',
			self::API_BASE . '/ffls/' . rawurlencode( $ffl ),
			array(),
			array( 'Authorization' => $this->auth_header() )
		);

		if ( is_wp_error( $response ) ) {
			$status = (int) ( $response->get_error_data()['status'] ?? 0 );

			// A 404 here is a real answer, not a failure: they simply do not
			// have it. Anything else is a genuine error worth surfacing.
			if ( 502 === $status && false !== strpos( $response->get_error_message(), '404' ) ) {
				return false;
			}

			return $response;
		}

		return ! empty( $response['ffl_number'] ?? $response['id'] ?? '' );
	}
}
