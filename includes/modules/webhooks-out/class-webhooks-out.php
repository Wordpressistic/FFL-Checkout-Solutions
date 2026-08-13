<?php
/**
 * Outbound webhooks module (Section 10.11).
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Services\Transfer_Service;
use FFLCS\Services\Webhook_Dispatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Event fan-out to store-configured endpoints.
 */
class Webhooks_Out {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_webhook_retry', array( Webhook_Dispatcher::class, 'process_retries' ) );
		add_action( 'fflcs_webhook_deliver', array( Webhook_Dispatcher::class, 'deliver' ), 10, 1 );

		add_action( 'fflcs_transfer_created', array( $this, 'on_created' ), 10, 2 );
		add_action( 'fflcs_transfer_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
		add_action( 'fflcs_dealer_confirmed', array( $this, 'on_dealer_confirmed' ), 10, 2 );
	}

	/**
	 * Emit transfer.created.
	 *
	 * @param int   $transfer_id Transfer ID.
	 * @param array $context     Creation context.
	 */
	public function on_created( int $transfer_id, array $context ): void {
		$this->send( 'transfer.created', $transfer_id );
	}

	/**
	 * Emit transfer.status_changed.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $new_status  New status.
	 * @param string $old_status  Previous status.
	 */
	public function on_status_changed( int $transfer_id, string $new_status, string $old_status ): void {
		$this->send(
			'transfer.status_changed',
			$transfer_id,
			array(
				'old_status' => $old_status,
				'new_status' => $new_status,
			)
		);
	}

	/**
	 * Emit dealer.confirmed.
	 *
	 * @param int $transfer_id Transfer ID.
	 * @param int $dealer_id   Dealer ID.
	 */
	public function on_dealer_confirmed( int $transfer_id, int $dealer_id ): void {
		$this->send( 'dealer.confirmed', $transfer_id, array( 'dealer_id' => $dealer_id ) );
	}

	/**
	 * Build and dispatch a payload.
	 *
	 * Deliberately narrow: transfer state and the dealer, never identity
	 * documents, staff notes or the customer's IP.
	 *
	 * @param string              $event       Event name.
	 * @param int                 $transfer_id Transfer ID.
	 * @param array<string,mixed> $extra       Extra fields.
	 */
	private function send( string $event, int $transfer_id, array $extra = array() ): void {
		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer ) {
			return;
		}

		Webhook_Dispatcher::dispatch(
			$event,
			array_merge(
				array(
					'event'     => $event,
					'site'      => home_url( '/' ),
					'sent_at'   => fflcs_mysql_now(),
					'transfer'  => Transfer_Service::to_public_array( $transfer ),
					'dealer_id' => (int) $transfer->dealer_id,
					'order_id'  => (int) $transfer->order_id,
				),
				$extra
			)
		);
	}
}
