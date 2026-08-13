<?php
/**
 * WooCommerce order status ↔ transfer status bridge.
 *
 * Keeps the two state machines in sync without letting either one make a
 * compliance decision.
 *
 * The asymmetry here is deliberate and is the whole design:
 *
 *   Order → transfer: only for commercial facts. A refund cancels a transfer,
 *   because that is a business decision the store already made in WooCommerce.
 *
 *   Transfer → order: only advisory. Completing a transfer can mark the order
 *   complete (opt-in), but a NICS denial never touches the order — refunding a
 *   denied buyer is a judgement call with legal and financial consequences, and
 *   it belongs to a human.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Two-way, deliberately unequal status sync.
 */
class Status_Bridge {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
		add_action( 'fflcs_transfer_status_changed', array( $this, 'on_transfer_status_changed' ), 20, 3 );
	}

	/**
	 * Mirror commercially meaningful order changes onto transfers.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Previous WooCommerce status.
	 * @param string $new_status New WooCommerce status.
	 * @param mixed  $order      Order object.
	 */
	public function on_order_status_changed( int $order_id, string $old_status, string $new_status, $order ): void {
		$transfers = Transfer_Service::for_order( $order_id );
		if ( ! $transfers ) {
			return;
		}

		// Cancellation and refunds are handled in Checkout, which also revokes
		// tokens. Duplicating that here would double-log the same event.
		if ( in_array( $new_status, array( 'cancelled', 'refunded' ), true ) ) {
			return;
		}

		if ( 'failed' !== $new_status ) {
			// Keep the payment snapshot current for the admin list, without
			// touching the transfer's own lifecycle status.
			foreach ( $transfers as $transfer ) {
				Transfer_Service::update(
					(int) $transfer->id,
					array( 'payment_status' => $new_status ),
					'woocommerce'
				);
			}
			return;
		}

		// A failed payment is worth flagging loudly: the firearm may already be
		// in motion toward a dealer.
		foreach ( $transfers as $transfer ) {
			Transfer_Service::log_event(
				(int) $transfer->id,
				'payment_failed',
				array(
					'actor'     => 'woocommerce',
					'dealer_id' => (int) $transfer->dealer_id,
					'notes'     => sprintf(
						/* translators: %s: previous order status. */
						__( 'Payment for the linked order failed (previous status: %s). Staff review recommended before shipping.', 'ffl-checkout-solutions' ),
						$old_status
					),
				)
			);
		}

		Mailer::send_admin_alert(
			__( 'Payment failed on an order with an active FFL transfer', 'ffl-checkout-solutions' ),
			'<p>' . sprintf(
				/* translators: %d: order number. */
				esc_html__( 'Payment failed for order #%d, which has one or more active FFL transfers. Review before shipping anything to the dealer.', 'ffl-checkout-solutions' ),
				$order_id
			) . '</p>'
		);
	}

	/**
	 * Reflect a completed transfer back onto its order, when opted in.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $new_status  New transfer status.
	 * @param string $old_status  Previous transfer status.
	 */
	public function on_transfer_status_changed( int $transfer_id, string $new_status, string $old_status ): void {
		if ( 'transferred' !== $new_status ) {
			return;
		}

		if ( '1' !== (string) get_option( 'fflcs_complete_order_on_transfer', '0' ) ) {
			return;
		}

		$transfer = Transfer_Service::get( $transfer_id );
		if ( ! $transfer || ! $transfer->order_id ) {
			return;
		}

		$order = wc_get_order( (int) $transfer->order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// An order may carry several transfers. It is only complete when every
		// one of them is — otherwise a two-firearm order would be marked
		// complete as soon as the first was picked up.
		foreach ( Transfer_Service::for_order( (int) $transfer->order_id ) as $sibling ) {
			if ( 'transferred' !== (string) $sibling->status && 'cancelled' !== (string) $sibling->status ) {
				return;
			}
		}

		if ( $order->has_status( array( 'completed', 'cancelled', 'refunded' ) ) ) {
			return;
		}

		$order->update_status(
			'completed',
			__( 'All FFL transfers on this order are complete.', 'ffl-checkout-solutions' )
		);
	}
}
