<?php
/**
 * RSR Group drop-ship client.
 *
 * HONESTY NOTE: RSR exchanges a delimited inventory file and a fixed-format
 * order file over FTPS, and acknowledges orders asynchronously with ECONF
 * (confirmation) and EERR (error) files that a later poll picks up. The field
 * positions used below follow RSR's published dealer layout. That layout is
 * versioned by RSR and cannot be discovered at runtime, so confirm it against
 * your current dealer packet before enabling live ordering — a shifted column
 * would silently order the wrong item.
 *
 * @package FFLCS
 */

namespace FFLCS\Distributors;

defined( 'ABSPATH' ) || exit;

/**
 * RSR Group FTPS client.
 */
class Distributor_Rsr extends Distributor_Ftps_Base {

	/**
	 * Remote inventory file.
	 */
	const INVENTORY_FILE = '/ftpdownloads/rsrinventory-new.txt';

	/**
	 * Remote order directory.
	 */
	const ORDER_DIR = '/ftpdownloads';

	/**
	 * Download and cache the inventory file.
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

		$local = $this->download( $connection, self::INVENTORY_FILE );

		$this->disconnect( $connection );

		if ( is_wp_error( $local ) ) {
			return $local;
		}

		$products = $this->parse_delimited(
			$local,
			';',
			static function ( array $row ): ?array {
				// RSR's layout: 0 stock number, 1 UPC, 2 description,
				// 3 department, 4 manufacturer id, 6 dealer price,
				// 8 quantity, 11 model, 69 FFL-required flag.
				if ( count( $row ) < 12 || '' === trim( (string) $row[0] ) ) {
					return null;
				}

				return array(
					'item_no'      => trim( (string) $row[0] ),
					'upc'          => trim( (string) ( $row[1] ?? '' ) ),
					'description'  => trim( (string) ( $row[2] ?? '' ) ),
					'manufacturer' => trim( (string) ( $row[4] ?? '' ) ),
					'model'        => trim( (string) ( $row[11] ?? '' ) ),
					'price'        => (float) ( $row[6] ?? 0 ),
					'msrp'         => (float) ( $row[5] ?? 0 ),
					'quantity'     => (int) ( $row[8] ?? 0 ),
					'is_firearm'   => 'Y' === strtoupper( trim( (string) ( $row[69] ?? '' ) ) ),
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
		$filename = self::ORDER_DIR . '/EORD' . gmdate( 'YmdHis' ) . '.txt';

		$line = implode(
			';',
			array(
				$this->credential( 'account' ),
				$po,
				(string) ( $order['item_no'] ?? '' ),
				(string) max( 1, (int) ( $order['quantity'] ?? 1 ) ),
				$ffl,
			)
		) . "\r\n";

		$uploaded = $this->upload( $connection, $filename, $line );

		$this->disconnect( $connection );

		if ( is_wp_error( $uploaded ) ) {
			return $uploaded;
		}

		// RSR acknowledges asynchronously, so the honest status is "sent",
		// not "accepted". The acknowledgement poll upgrades it later.
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
