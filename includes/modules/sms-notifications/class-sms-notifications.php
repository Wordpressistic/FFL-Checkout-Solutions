<?php
/**
 * SMS notifications (Section 10.13).
 *
 * Sends through the store's own Twilio or Verifyistic account. No messages are
 * routed through WordPressistic infrastructure, so the customer's phone number
 * goes to the provider the store chose and nobody else.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Crypto;
use FFLCS\Services\Scheduler;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Customer SMS at key milestones.
 */
class Sms_Notifications {

	/**
	 * Statuses worth a text message.
	 *
	 * Deliberately short. A text is interruptive, and a customer who gets one
	 * for every internal state change stops reading them.
	 *
	 * @var string[]
	 */
	const NOTIFY_ON = array(
		'shipped_to_dealer',
		'received_by_dealer',
		'nics_delayed',
		'nics_approved',
		'transferred',
	);

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_transfer_status_changed', array( $this, 'on_status_changed' ), 40, 3 );
		add_action( 'fflcs_send_sms', array( $this, 'send' ), 10, 2 );
	}

	/**
	 * Queue a message on a notable status change.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $new_status  New status.
	 * @param string $old_status  Previous status.
	 */
	public function on_status_changed( int $transfer_id, string $new_status, string $old_status ): void {
		if ( ! in_array( $new_status, self::NOTIFY_ON, true ) ) {
			return;
		}

		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer || '' === (string) $transfer->customer_phone ) {
			return;
		}

		$message = $this->message_for( $new_status, $transfer );

		if ( '' === $message ) {
			return;
		}

		Scheduler::async( 'fflcs_send_sms', array( (string) $transfer->customer_phone, $message ) );
	}

	/**
	 * Message copy for a status.
	 *
	 * @param string $status   Status.
	 * @param object $transfer Transfer row.
	 */
	private function message_for( string $status, object $transfer ): string {
		$store = (string) get_option( 'fflcs_store_name', get_option( 'blogname' ) );

		$copy = array(
			'shipped_to_dealer'  => sprintf(
				/* translators: 1: store name, 2: transfer reference. */
				__( '%1$s: your firearm (%2$s) has shipped to your FFL dealer.', 'ffl-checkout-solutions' ),
				$store,
				(string) $transfer->transfer_ref
			),
			'received_by_dealer' => sprintf(
				/* translators: 1: store name, 2: transfer reference. */
				__( '%1$s: your dealer has received %2$s. Contact them to arrange pickup, and bring photo ID.', 'ffl-checkout-solutions' ),
				$store,
				(string) $transfer->transfer_ref
			),
			'nics_delayed'       => sprintf(
				/* translators: 1: store name, 2: transfer reference. */
				__( '%1$s: your background check for %2$s is delayed. Your dealer will be in touch.', 'ffl-checkout-solutions' ),
				$store,
				(string) $transfer->transfer_ref
			),
			'nics_approved'      => sprintf(
				/* translators: 1: store name, 2: transfer reference. */
				__( '%1$s: your background check for %2$s cleared. Contact your dealer to complete the transfer.', 'ffl-checkout-solutions' ),
				$store,
				(string) $transfer->transfer_ref
			),
			'transferred'        => sprintf(
				/* translators: 1: store name, 2: transfer reference. */
				__( '%1$s: transfer %2$s is complete. Thank you.', 'ffl-checkout-solutions' ),
				$store,
				(string) $transfer->transfer_ref
			),
		);

		$message = $copy[ $status ] ?? '';

		/**
		 * Filter an outgoing SMS message.
		 *
		 * @param string $message  Message text.
		 * @param string $status   Transfer status.
		 * @param object $transfer Transfer row.
		 */
		return (string) apply_filters( 'fflcs_sms_message', $message, $status, $transfer );
	}

	/**
	 * Deliver one message.
	 *
	 * @param string $to      Destination number.
	 * @param string $message Message body.
	 */
	public function send( string $to, string $message ): void {
		$provider = (string) get_option( 'fflcs_sms_provider', '' );

		if ( '' === $provider ) {
			return;
		}

		$from = (string) get_option( 'fflcs_sms_from_number', '' );
		$sid  = Crypto::get_option( 'fflcs_sms_account_sid', 'fflcs_settings' );
		$auth = Crypto::get_option( 'fflcs_sms_auth_token', 'fflcs_settings' );

		if ( '' === $sid || '' === $auth || '' === $from ) {
			fflcs_log( 'SMS not sent — provider credentials incomplete' );
			return;
		}

		if ( 'twilio' === $provider ) {
			$response = wp_remote_post(
				'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $sid ) . '/Messages.json',
				array(
					'timeout' => 20,
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $auth ),
					),
					'body'    => array(
						'To'   => $to,
						'From' => $from,
						'Body' => $message,
					),
				)
			);
		} else {
			/**
			 * Filter the request arguments for a non-Twilio SMS provider.
			 *
			 * The seam for Verifyistic and any other provider a store uses.
			 *
			 * @param array|null $request  ['url' => string, 'args' => array] or null.
			 * @param string     $to       Destination number.
			 * @param string     $message  Message body.
			 * @param string     $provider Provider slug.
			 */
			$request = apply_filters( 'fflcs_sms_request', null, $to, $message, $provider );

			if ( ! is_array( $request ) || empty( $request['url'] ) ) {
				fflcs_log( 'SMS not sent — no request handler for provider ' . $provider );
				return;
			}

			$response = wp_remote_post( (string) $request['url'], (array) ( $request['args'] ?? array() ) );
		}

		if ( is_wp_error( $response ) ) {
			fflcs_log( 'SMS send failed', $response->get_error_message() );
		}
	}
}
