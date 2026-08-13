<?php
/**
 * Bill Hicks & Co. drop-ship client.
 *
 * HONESTY NOTE: catalog download, order upload and acknowledgement reading over
 * the dealer FTPS account. Directory and file naming vary per dealer account in
 * the Bill Hicks packet, so they are settings rather than constants — a
 * hardcoded path would work for one customer and fail for the next.
 *
 * @package FFLCS
 */

namespace FFLCS\Distributors;

defined( 'ABSPATH' ) || exit;

/**
 * Bill Hicks FTPS client.
 */
class Distributor_Bill_Hicks extends Distributor_Ftps_Base {

	/**
	 * Remote catalog path, overridable per account.
	 */
	private function catalog_path(): string {
		return (string) get_option( 'fflcs_bill_hicks_catalog_path', '/MSRP/inventory.csv' );
	}

	/**
	 * Remote order directory, overridable per account.
	 */
	private function order_path(): string {
		return (string) get_option( 'fflcs_bill_hicks_order_path', '/Orders' );
	}

	/**
	 * Download and cache the catalog.
	 *
	 * @return int|\WP_Error
	 */
	public function sync_catalog() {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}

		$connection = $this->connect();

		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$local = $this->download( $connection, $this->catalog_path() );

		$this->disconnect( $connection );

		if ( is_wp_error( $local ) ) {
			return $local;
		}

		$header_seen = false;

		$products = $this->parse_delimited(
			$local,
			',',
			static function ( array $row ) use ( &$header_seen ): ?array {
				// The first row is a header; detect it rather than assuming a
				// fixed offset, since the export can be regenerated without one.
				if ( ! $header_seen ) {
					$header_seen = true;

					if ( ! is_numeric( trim( (string) ( $row[3] ?? '' ) ) ) ) {
						return null;
					}
				}

				if ( count( $row ) < 5 || '' === trim( (string) $row[0] ) ) {
					return null;
				}

				return array(
					'item_no'      => trim( (string) $row[0] ),
					'upc'          => trim( (string) ( $row[1] ?? '' ) ),
					'description'  => trim( (string) ( $row[2] ?? '' ) ),
					'price'        => (float) ( $row[3] ?? 0 ),
					'quantity'     => (int) ( $row[4] ?? 0 ),
					'manufacturer' => trim( (string) ( $row[5] ?? '' ) ),
					'model'        => trim( (string) ( $row[6] ?? '' ) ),
					'msrp'         => (float) ( $row[7] ?? 0 ),
					'is_firearm'   => 'Y' === strtoupper( trim( (string) ( $row[8] ?? '' ) ) ),
				);
			}
		);

		wp_delete_file( $local );

		return $this->cache_products( $products );
	}

	/**
	 * Upload an order file.
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

		$connection = $this->connect();

		if ( is_wp_error( $connection ) ) {
			return $connection;
		}

		$po       = $this->purchase_order_number( (int) ( $order['transfer_id'] ?? 0 ) );
		$filename = trailingslashit( $this->order_path() ) . 'order-' . $po . '.csv';

		$contents = "account,po,item,quantity,ffl\r\n"
			. implode(
				',',
				array(
					$this->credential( 'account' ),
					$po,
					(string) ( $order['item_no'] ?? '' ),
					(string) max( 1, (int) ( $order['quantity'] ?? 1 ) ),
					$ffl,
				)
			) . "\r\n";

		$uploaded = $this->upload( $connection, $filename, $contents );

		$this->disconnect( $connection );

		if ( is_wp_error( $uploaded ) ) {
			return $uploaded;
		}

		$result = array(
			'status'    => 'awaiting_acknowledgement',
			'order_ref' => $po,
			'po_number' => $po,
			'raw'       => array( 'file' => $filename ),
		);

		$order['po_number'] = $po;

		$this->record_order( $order, $result );

		return $result;
	}
}
