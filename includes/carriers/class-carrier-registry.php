<?php
/**
 * Carrier registry — provider selection, polling and webhook receipt.
 *
 * @package FFLCS
 */

namespace FFLCS\Carriers;

use FFLCS\Crypto;
use FFLCS\Services\Carrier_Links;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates carrier providers.
 */
class Carrier_Registry {

	/**
	 * The configured provider, or null when none is set up.
	 */
	public static function provider(): ?Carrier_Easypost {
		$key = Crypto::get_option( 'fflcs_easypost_api_key', 'fflcs_settings' );

		return '' !== $key ? new Carrier_Easypost( $key ) : null;
	}

	/**
	 * Transfers shipped but not yet confirmed received, with a tracking number.
	 *
	 * @return object[]
	 */
	public static function in_flight_transfers(): array {
		global $wpdb;

		$table = fflcs_table( 'transfers' );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table}
				 WHERE status = %s AND tracking_number <> '' AND tracking_number IS NOT NULL
				 ORDER BY shipped_date ASC
				 LIMIT 100",
				'shipped_to_dealer'
			)
		);
	}

	/**
	 * Poll one transfer's shipment.
	 *
	 * @param int $transfer_id Transfer ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function poll_transfer( int $transfer_id ) {
		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer ) {
			return new \WP_Error(
				'fflcs_not_found',
				__( 'Transfer not found.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		if ( '' === (string) $transfer->tracking_number ) {
			return new \WP_Error(
				'fflcs_no_tracking',
				__( 'That transfer has no tracking number recorded.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$provider = self::provider();

		if ( ! $provider ) {
			return new \WP_Error(
				'fflcs_no_provider',
				__( 'No carrier API key is configured, so live status is unavailable. The tracking link on the customer page still works.', 'ffl-checkout-solutions' ),
				array( 'status' => 503 )
			);
		}

		$status = $provider->track(
			(string) $transfer->tracking_number,
			(string) $transfer->carrier
		);

		if ( is_wp_error( $status ) ) {
			return $status;
		}

		self::apply_status( $transfer_id, $status );

		return $status;
	}

	/**
	 * Poll everything in flight. Runs daily.
	 */
	public static function poll_all(): void {
		foreach ( self::in_flight_transfers() as $transfer ) {
			self::poll_transfer( (int) $transfer->id );
		}
	}

	/**
	 * Apply a normalised carrier status to a transfer.
	 *
	 * Advancing to "received" on a delivery scan is opt-in. A carrier scan says
	 * the parcel reached the address; only the dealer can attest they have the
	 * firearm and have logged it, which is the fact the compliance record needs.
	 *
	 * @param int                 $transfer_id Transfer ID.
	 * @param array<string,mixed> $status      Normalised status.
	 */
	public static function apply_status( int $transfer_id, array $status ): void {
		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer ) {
			return;
		}

		Transfer_Service::log_event(
			$transfer_id,
			'carrier_status',
			array(
				'dealer_id' => (int) $transfer->dealer_id,
				'actor'     => 'carrier',
				'notes'     => (string) ( $status['description'] ?? '' ),
				'data'      => $status,
			)
		);

		/**
		 * Fires when a carrier status is received.
		 *
		 * @param int   $transfer_id Transfer ID.
		 * @param array $status      Normalised carrier status.
		 */
		do_action( 'fflcs_carrier_status_received', $transfer_id, $status );

		if ( 'delivered' !== ( $status['state'] ?? '' ) ) {
			return;
		}

		if ( '1' !== (string) get_option( 'fflcs_carrier_auto_advance', '1' ) ) {
			return;
		}

		if ( 'shipped_to_dealer' !== (string) $transfer->status ) {
			return;
		}

		Transfer_Service::set_status(
			$transfer_id,
			'received_by_dealer',
			'carrier',
			__( 'The carrier reported delivery. The dealer should still confirm receipt and record the serial number.', 'ffl-checkout-solutions' )
		);
	}

	// ── Webhook ──────────────────────────────────────────────────────────────

	/**
	 * Verify an inbound carrier webhook signature.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public static function verify_webhook( \WP_REST_Request $request ) {
		$secret = Crypto::get_option( 'fflcs_carrier_webhook_secret', 'fflcs_settings' );

		if ( '' === $secret ) {
			return new \WP_Error(
				'fflcs_webhook_not_configured',
				__( 'No carrier webhook secret is configured on this site.', 'ffl-checkout-solutions' ),
				array( 'status' => 503 )
			);
		}

		$signature = (string) $request->get_header( 'x_fflcs_signature' );

		if ( '' === $signature ) {
			$signature = (string) $request->get_header( 'x_hmac_signature' );
		}

		$expected = hash_hmac( 'sha256', $request->get_body(), $secret );

		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return new \WP_Error(
				'fflcs_invalid_signature',
				__( 'Invalid webhook signature.', 'ffl-checkout-solutions' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Apply an inbound carrier push.
	 *
	 * HONESTY NOTE: each carrier aggregator (EasyPost, Shippo, AfterShip,
	 * ShipStation) uses its own payload shape. The mapping below covers the
	 * common EasyPost tracker shape and a generic flat shape; anything else is
	 * mapped through the fflcs_carrier_webhook_payload filter rather than
	 * guessed at. See docs/STATUS.md.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error Transfer reference handled.
	 */
	public static function handle_webhook( \WP_REST_Request $request ) {
		$payload = json_decode( $request->get_body(), true );

		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'fflcs_invalid_payload',
				__( 'The webhook body was not valid JSON.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Filter a decoded carrier webhook payload before it is applied.
		 *
		 * @param array            $payload Decoded body.
		 * @param \WP_REST_Request $request Request.
		 */
		$payload = (array) apply_filters( 'fflcs_carrier_webhook_payload', $payload, $request );

		$tracking = sanitize_text_field(
			(string) ( $payload['tracking_code']
				?? $payload['tracking_number']
				?? $payload['result']['tracking_code']
				?? '' )
		);

		if ( '' === $tracking ) {
			return new \WP_Error(
				'fflcs_missing_tracking',
				__( 'The payload did not include a tracking number.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		global $wpdb;

		$table = fflcs_table( 'transfers' );

		$transfer = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE tracking_number = %s ORDER BY id DESC LIMIT 1",
				$tracking
			)
		);

		if ( ! $transfer ) {
			// Not an error worth a 4xx — the store may track shipments this
			// plugin does not own, and a carrier retrying forever helps nobody.
			return 'ignored';
		}

		$raw_status = strtolower(
			(string) ( $payload['status'] ?? $payload['result']['status'] ?? '' )
		);

		self::apply_status(
			(int) $transfer->id,
			array(
				'state'       => self::normalise_state( $raw_status ),
				'raw'         => $raw_status,
				'description' => sanitize_text_field( (string) ( $payload['status_detail'] ?? $payload['message'] ?? '' ) ),
				'carrier'     => Carrier_Links::label( (string) $transfer->carrier ),
			)
		);

		return (string) $transfer->transfer_ref;
	}

	/**
	 * Reduce carrier-specific status vocabulary to our own.
	 *
	 * @param string $status Raw carrier status.
	 */
	public static function normalise_state( string $status ): string {
		$status = strtolower( trim( $status ) );

		$map = array(
			'delivered'        => 'delivered',
			'available_for_pickup' => 'delivered',
			'in_transit'       => 'in_transit',
			'out_for_delivery' => 'in_transit',
			'pre_transit'      => 'pending',
			'unknown'          => 'pending',
			'return_to_sender' => 'exception',
			'failure'          => 'exception',
			'cancelled'        => 'exception',
			'error'            => 'exception',
		);

		return $map[ $status ] ?? 'pending';
	}
}
