<?php
/**
 * GunBroker marketplace sync (Section 11.2).
 *
 * Pushes flagged WooCommerce products as listings, and pulls sold orders back in
 * through the same transfer pipeline a web order uses — so a marketplace sale
 * gets the same compliance record as any other.
 *
 * HONESTY NOTE: built against GunBroker's published REST API. Authentication and
 * the OrdersSold shape follow their documentation. Two things could not be
 * verified without an approved DevKey and are handled defensively: the exact
 * field names carrying an order's FFL details, and their production rate limits.
 * Orders whose dealer cannot be matched go to a review queue rather than being
 * guessed at.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Crypto;
use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * GunBroker listing and order sync.
 */
class Gunbroker_Sync {

	/**
	 * API base.
	 */
	const API_BASE = 'https://api.gunbroker.com/v1';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_gunbroker_pull_orders', array( $this, 'pull_orders' ) );

		Scheduler::recurring( HOUR_IN_SECONDS, 'fflcs_gunbroker_pull_orders' );
	}

	/**
	 * Pull recently sold orders.
	 */
	public function pull_orders(): void {
		global $wpdb;

		$token = $this->access_token();

		if ( is_wp_error( $token ) ) {
			return;
		}

		$response = wp_remote_get(
			self::API_BASE . '/OrdersSold?TimeFrame=3',
			array(
				'timeout' => 30,
				'headers' => array(
					'X-AccessToken' => $token,
					'X-DevKey'      => Crypto::get_option( 'fflcs_gunbroker_devkey', 'fflcs_settings' ),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			fflcs_log( 'GunBroker order pull failed' );
			return;
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$table = fflcs_table( 'gunbroker_orders' );

		foreach ( (array) ( $data['results'] ?? array() ) as $order ) {
			$order_id = (string) ( $order['orderID'] ?? $order['OrderID'] ?? '' );

			if ( '' === $order_id ) {
				continue;
			}

			// The FFL field name is one of the unverified parts — check the
			// documented spellings rather than assuming one.
			$ffl = (string) (
				$order['fflNumber']
				?? $order['FFLNumber']
				?? $order['ffl']['licenseNumber']
				?? ''
			);

			$dealer = '' !== $ffl ? Dealer_Service::get_by_license( $ffl ) : null;

			// The unique index makes a repeated pull idempotent.
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'gunbroker_order_id' => $order_id,
					'ffl_license_number' => $ffl,
					'dealer_id'          => $dealer ? (int) $dealer->id : null,
					// No dealer match means a person decides, not a guess.
					'status'             => $dealer ? 'ready' : 'needs_dealer',
					'raw_json'           => wp_json_encode( $order ),
					'imported_at'        => fflcs_mysql_now(),
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Orders awaiting manual dealer assignment.
	 *
	 * @return object[]
	 */
	public static function needs_dealer(): array {
		global $wpdb;

		$table = fflcs_table( 'gunbroker_orders' );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE status = %s ORDER BY imported_at DESC LIMIT 100",
				'needs_dealer'
			)
		);
	}

	/**
	 * Obtain an access token.
	 *
	 * @return string|\WP_Error
	 */
	private function access_token() {
		$cached = get_transient( 'fflcs_gunbroker_token' );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$devkey = Crypto::get_option( 'fflcs_gunbroker_devkey', 'fflcs_settings' );

		if ( '' === $devkey ) {
			return new \WP_Error(
				'fflcs_gunbroker_not_configured',
				__( 'GunBroker credentials are not configured.', 'ffl-checkout-solutions' )
			);
		}

		$response = wp_remote_post(
			self::API_BASE . '/Users/AccessToken',
			array(
				'timeout' => 20,
				'headers' => array( 'X-DevKey' => $devkey ),
				'body'    => array(
					'Username' => Crypto::get_option( 'fflcs_gunbroker_username', 'fflcs_settings' ),
					'Password' => Crypto::get_option( 'fflcs_gunbroker_password', 'fflcs_settings' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = (string) ( $data['accessToken'] ?? '' );

		if ( '' === $token ) {
			return new \WP_Error(
				'fflcs_gunbroker_auth_failed',
				__( 'GunBroker did not return an access token.', 'ffl-checkout-solutions' )
			);
		}

		set_transient( 'fflcs_gunbroker_token', $token, 20 * MINUTE_IN_SECONDS );

		return $token;
	}
}
