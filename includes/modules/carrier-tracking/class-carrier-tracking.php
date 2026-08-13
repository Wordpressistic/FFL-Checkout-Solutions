<?php
/**
 * Carrier tracking module (Section 10.1).
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Carriers\Carrier_Registry;
use FFLCS\Services\Carrier_Links;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Shipment status automation.
 */
class Carrier_Tracking {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_carrier_poll', array( Carrier_Registry::class, 'poll_all' ) );
		add_action( 'fflcs_transfer_updated_tracking', array( $this, 'on_tracking_added' ), 10, 2 );
	}

	/**
	 * Advance a transfer to shipped when a tracking number first appears.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $tracking    Tracking number.
	 */
	public function on_tracking_added( int $transfer_id, string $tracking ): void {
		if ( '' === $tracking ) {
			return;
		}

		if ( '1' !== (string) get_option( 'fflcs_carrier_auto_advance', '1' ) ) {
			return;
		}

		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer || 'payment_confirmed' !== (string) $transfer->status ) {
			return;
		}

		// Fill in the carrier when staff pasted a bare number.
		if ( '' === (string) $transfer->carrier ) {
			$guess = Carrier_Links::guess_carrier( $tracking );

			if ( '' !== $guess ) {
				Transfer_Service::update( $transfer_id, array( 'carrier' => $guess ), 'system' );
			}
		}

		Transfer_Service::set_status(
			$transfer_id,
			'shipped_to_dealer',
			'system',
			__( 'A tracking number was recorded.', 'ffl-checkout-solutions' )
		);
	}
}
