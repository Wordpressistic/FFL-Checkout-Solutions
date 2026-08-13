<?php
/**
 * Saved dealers / quick pick (Section 9.9).
 *
 * Remembers the dealers a customer has actually used so a repeat purchase is
 * one tap instead of a search. Capped at five, most recent first.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Per-customer dealer memory.
 */
class Saved_Dealers {

	/**
	 * User meta key.
	 */
	const META_KEY = '_fflcs_saved_dealers';

	/**
	 * How many to remember.
	 */
	const MAX_SAVED = 5;

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_transfer_created', array( $this, 'remember' ), 30, 2 );
		add_filter( 'fflcs_checkout_localize', array( $this, 'add_to_checkout' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'woocommerce_account_ffl-transfers_endpoint', array( $this, 'render_account_tab' ) );
		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_account_menu_item' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
	}

	/**
	 * Register the My Account endpoint.
	 */
	public function register_endpoint(): void {
		add_rewrite_endpoint( 'ffl-transfers', EP_ROOT | EP_PAGES );
	}

	/**
	 * Register the endpoint's query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = 'ffl-transfers';

		return $vars;
	}

	/**
	 * Add the account menu item.
	 *
	 * @param array<string,string> $items Menu items.
	 * @return array<string,string>
	 */
	public function add_account_menu_item( array $items ): array {
		// Inserted before Logout rather than appended, so it does not land
		// underneath it.
		$logout = isset( $items['customer-logout'] ) ? array( 'customer-logout' => $items['customer-logout'] ) : array();

		unset( $items['customer-logout'] );

		$items['ffl-transfers'] = __( 'My FFL Transfers', 'ffl-checkout-solutions' );

		return $items + $logout;
	}

	/**
	 * Remember the dealer a customer just used.
	 *
	 * @param int   $transfer_id Transfer ID.
	 * @param array $context     Creation context.
	 */
	public function remember( int $transfer_id, array $context ): void {
		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer || ! $transfer->customer_id ) {
			return;
		}

		$user_id   = (int) $transfer->customer_id;
		$dealer_id = (int) $transfer->dealer_id;

		$saved = array_map( 'intval', (array) get_user_meta( $user_id, self::META_KEY, true ) );

		// Move to front rather than appending, so "most recently used" stays
		// true across repeat purchases from the same dealer.
		$saved = array_values( array_diff( $saved, array( $dealer_id ) ) );

		array_unshift( $saved, $dealer_id );

		update_user_meta( $user_id, self::META_KEY, array_slice( $saved, 0, self::MAX_SAVED ) );
	}

	/**
	 * A customer's saved dealers, as public payloads.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_user( int $user_id ): array {
		if ( ! $user_id ) {
			return array();
		}

		$dealers = array();

		foreach ( array_map( 'intval', (array) get_user_meta( $user_id, self::META_KEY, true ) ) as $dealer_id ) {
			$dealer = Dealer_Service::get( $dealer_id );

			// Skip dealers that have since gone inactive — offering a one-tap
			// pick that then fails validation would be worse than not offering it.
			if ( $dealer && (int) $dealer->is_active ) {
				$dealers[] = Dealer_Service::to_public_array( $dealer );
			}
		}

		return $dealers;
	}

	/**
	 * Add saved dealers to the checkout payload.
	 *
	 * @param array<string,mixed> $data Localised data.
	 * @return array<string,mixed>
	 */
	public function add_to_checkout( array $data ): array {
		$data['savedDealers'] = self::for_user( get_current_user_id() );

		return $data;
	}

	/**
	 * Hand the saved list to the checkout widget.
	 */
	public function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( ! wp_script_is( 'fflcs-checkout-widget', 'enqueued' ) ) {
			return;
		}

		wp_add_inline_script(
			'fflcs-checkout-widget',
			'document.addEventListener("DOMContentLoaded",function(){'
			. 'if(window.fflcsRenderSavedDealers&&window.fflcsCheckout&&window.fflcsCheckout.savedDealers){'
			. 'window.fflcsRenderSavedDealers(window.fflcsCheckout.savedDealers);}});'
		);
	}

	/**
	 * The My Account tab.
	 */
	public function render_account_tab(): void {
		global $wpdb;

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$table = fflcs_table( 'transfers' );

		$transfers = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE customer_id = %d ORDER BY created_at DESC LIMIT 50",
				$user_id
			)
		);

		if ( ! $transfers ) {
			echo '<p>' . esc_html__( 'You have no FFL transfers yet.', 'ffl-checkout-solutions' ) . '</p>';
			return;
		}

		echo '<table class="woocommerce-orders-table shop_table"><thead><tr>';
		echo '<th>' . esc_html__( 'Reference', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'Item', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'Dealer', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $transfers as $transfer ) {
			$dealer = Dealer_Service::get( (int) $transfer->dealer_id );

			echo '<tr>';
			echo '<td>' . esc_html( (string) $transfer->transfer_ref ) . '</td>';
			echo '<td>' . esc_html( (string) $transfer->product_name ) . '</td>';
			echo '<td>' . esc_html( $dealer ? (string) $dealer->business_name : '—' ) . '</td>';
			echo '<td>' . esc_html( fflcs_status_label( (string) $transfer->status ) ) . '</td>';
			printf(
				'<td><a class="button" href="%1$s">%2$s</a></td>',
				esc_url( \FFLCS\Services\Tracking_Links::customer_url( (string) $transfer->transfer_ref ) ),
				esc_html__( 'Track', 'ffl-checkout-solutions' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
