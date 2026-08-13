<?php
/**
 * Lipsey's drop-ship client.
 *
 * HONESTY NOTE: built against Lipsey's published dealer API documentation.
 * Authentication (token exchange) and the catalog feed follow that guide. The
 * order-submission response shape is handled defensively — parsed for the
 * fields the guide names, and recorded in full either way — because it could
 * not be verified against a live dealer account during development. Confirm
 * against a test order before relying on it in production.
 *
 * @package FFLCS
 */

namespace FFLCS\Distributors;

defined( 'ABSPATH' ) || exit;

/**
 * Lipsey's REST client.
 */
class Distributor_Lipseys extends Distributor_Support {

	/**
	 * API base.
	 */
	const API_BASE = 'https://api.lipseys.com/api/Integration';

	/**
	 * Authenticate and return a bearer token.
	 *
	 * Cached for the session: their token is short-lived, and re-authenticating
	 * on every call would burn rate limit for no benefit.
	 *
	 * @return string|\WP_Error
	 */
	private function token() {
		$cached = get_transient( 'fflcs_lipseys_token' );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = $this->request_json(
			'POST',
			self::API_BASE . '/Authentication',
			array(
				'Email'    => $this->credential( 'email' ),
				'Password' => $this->credential( 'password' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$token = (string) ( $response['token'] ?? $response['Token'] ?? '' );

		if ( '' === $token ) {
			return new \WP_Error(
				'fflcs_lipseys_auth_failed',
				__( "Lipsey's did not return an authentication token. Check the dealer credentials.", 'ffl-checkout-solutions' ),
				array( 'status' => 401 )
			);
		}

		set_transient( 'fflcs_lipseys_token', $token, 20 * MINUTE_IN_SECONDS );

		return $token;
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

		$token = $this->token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = $this->request_json(
			'GET',
			self::API_BASE . '/Items/CatalogFeed',
			array(),
			array( 'Token' => $token )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$items = (array) ( $response['data'] ?? $response['Data'] ?? array() );

		$products = array();

		foreach ( $items as $item ) {
			$item_no = (string) ( $item['itemNo'] ?? $item['ItemNo'] ?? '' );

			if ( '' === $item_no ) {
				continue;
			}

			$products[] = array(
				'item_no'      => $item_no,
				'description'  => (string) ( $item['description1'] ?? $item['Description1'] ?? '' ),
				'manufacturer' => (string) ( $item['manufacturer'] ?? $item['Manufacturer'] ?? '' ),
				'model'        => (string) ( $item['model'] ?? $item['Model'] ?? '' ),
				'upc'          => (string) ( $item['upc'] ?? $item['UPC'] ?? '' ),
				'price'        => (float) ( $item['price'] ?? $item['Price'] ?? 0 ),
				'msrp'         => (float) ( $item['msrp'] ?? $item['MSRP'] ?? 0 ),
				'quantity'     => (int) ( $item['quantity'] ?? $item['Quantity'] ?? 0 ),
				// Lipsey's flags FFL-required items explicitly; treat a missing
				// flag as "not a firearm" rather than assuming it is one.
				'is_firearm'   => ! empty( $item['fflRequired'] ?? $item['FflRequired'] ?? false ),
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

		$token = $this->token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$po = $this->purchase_order_number( (int) ( $order['transfer_id'] ?? 0 ) );

		$response = $this->request_json(
			'POST',
			self::API_BASE . '/Order/APIOrder',
			array(
				'PONum'      => $po,
				'FFLNumber'  => $ffl,
				'Items'      => array(
					array(
						'ItemNo'   => (string) ( $order['item_no'] ?? '' ),
						'Quantity' => max( 1, (int) ( $order['quantity'] ?? 1 ) ),
					),
				),
			),
			array( 'Token' => $token )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$result = array(
			'status'    => ! empty( $response['success'] ?? $response['Success'] ?? false ) ? 'submitted' : 'rejected',
			'order_ref' => (string) ( $response['orderNumber'] ?? $response['OrderNumber'] ?? '' ),
			'po_number' => $po,
			// The full response is kept so a shape we did not anticipate is
			// still recoverable from the order record.
			'raw'       => $response,
		);

		$order['po_number'] = $po;

		$this->record_order( $order, $result );

		return $result;
	}
}
