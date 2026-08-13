<?php
/**
 * State compliance engine (Section 9.7).
 *
 * Surfaces the store's own configured notices to buyers and staff at the right
 * moments. It does not interpret law, does not decide eligibility, and does not
 * approve or deny anything — a blocking rule stops an order from being placed
 * online, which is a store policy control, not a legal determination.
 *
 * See docs/LEGAL-DISCLAIMER.md. The shipped rules are conservative defaults for
 * a dealer to review and maintain, not legal advice.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * State rule storage, matching and presentation.
 */
class Compliance {

	/**
	 * Active rules for a state, optionally narrowed to one item type.
	 *
	 * @param string $state     Two-letter state code.
	 * @param string $item_type Item type, or '' for all.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rules_for_state( string $state, string $item_type = '' ): array {
		global $wpdb;

		$state = strtoupper( substr( trim( $state ), 0, 2 ) );

		if ( ! in_array( $state, fflcs_us_states(), true ) ) {
			return array();
		}

		$table = fflcs_table( 'state_rules' );

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE state = %s AND is_active = 1 ORDER BY is_blocking DESC, rule_key ASC",
				$state
			)
		);

		$rules = array();

		foreach ( $rows as $row ) {
			// An empty item_types means "applies to everything". Matching is
			// done in PHP rather than SQL because the column is a CSV list and
			// a LIKE against it would match 'rifle' inside 'rifle_magazine'.
			if ( '' !== $item_type && '' !== (string) $row->item_types ) {
				$applies = array_map( 'trim', explode( ',', (string) $row->item_types ) );
				if ( ! in_array( $item_type, $applies, true ) ) {
					continue;
				}
			}

			$rules[] = array(
				'id'          => (int) $row->id,
				'state'       => (string) $row->state,
				'rule_key'    => (string) $row->rule_key,
				'rule_value'  => (string) $row->rule_value,
				'rule_type'   => (string) $row->rule_type,
				'is_blocking' => (bool) $row->is_blocking,
				'item_types'  => (string) $row->item_types,
				'source'      => (string) $row->source,
			);
		}

		return $rules;
	}

	/**
	 * Compliance notice HTML for the current cart, or '' when there is nothing
	 * to say.
	 *
	 * Resolves the state from the customer's billing address, which is what the
	 * shopper has told us so far at the point the widget renders.
	 */
	public static function checkout_notice_html(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}

		$state = strtoupper( (string) WC()->customer->get_billing_state() );

		if ( '' === $state ) {
			return '';
		}

		$item_types = self::cart_item_types();
		$collected  = array();

		foreach ( $item_types ?: array( '' ) as $item_type ) {
			foreach ( self::rules_for_state( $state, (string) $item_type ) as $rule ) {
				// Key by rule id so a rule matching two item types in one cart
				// is shown once, not twice.
				$collected[ $rule['id'] ] = $rule;
			}
		}

		if ( ! $collected ) {
			return '';
		}

		$html = '';

		foreach ( $collected as $rule ) {
			$html .= '<p>' . esc_html( $rule['rule_value'] ) . '</p>';
		}

		return $html;
	}

	/**
	 * Distinct FFL item types currently in the cart.
	 *
	 * @return string[]
	 */
	public static function cart_item_types(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$types = array();

		foreach ( WC()->cart->get_cart() as $line ) {
			$product_id = (int) ( $line['variation_id'] ?: $line['product_id'] );

			if ( ! \FFLCS\Checkout::product_requires_ffl( $product_id ) ) {
				continue;
			}

			$type = (string) get_post_meta( $product_id, \FFLCS\Checkout::PRODUCT_ITEM_TYPE, true );

			if ( '' === $type ) {
				$parent_id = (int) wp_get_post_parent_id( $product_id );
				$type      = $parent_id ? (string) get_post_meta( $parent_id, \FFLCS\Checkout::PRODUCT_ITEM_TYPE, true ) : '';
			}

			if ( '' !== $type ) {
				$types[ $type ] = $type;
			}
		}

		return array_values( $types );
	}

	/**
	 * Whether any blocking rule applies to the current cart and state.
	 *
	 * Blocking is opt-in per rule *and* gated on the strict-mode setting, so a
	 * store cannot accidentally block every order by enabling one rule.
	 */
	public static function cart_is_blocked(): bool {
		if ( '1' !== (string) get_option( 'fflcs_compliance_strict', '1' ) ) {
			return false;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return false;
		}

		$state = strtoupper( (string) WC()->customer->get_billing_state() );

		if ( '' === $state ) {
			return false;
		}

		foreach ( self::cart_item_types() ?: array( '' ) as $item_type ) {
			foreach ( self::rules_for_state( $state, (string) $item_type ) as $rule ) {
				if ( $rule['is_blocking'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Advisory check that a dealer is a sensible destination for a buyer.
	 *
	 * Logged, never enforced. Interstate transfers to a buyer's home-state FFL
	 * are the normal case for online firearm sales — a state mismatch is
	 * expected, not suspicious. It is recorded because the pattern is useful
	 * during a later audit, and for nothing else.
	 *
	 * @param object    $dealer Dealer row.
	 * @param \WC_Order $order  Order.
	 */
	public static function log_dealer_buyer_states( object $dealer, \WC_Order $order ): void {
		$dealer_state = strtoupper( (string) $dealer->premise_state );
		$buyer_state  = strtoupper( (string) $order->get_billing_state() );

		if ( '' === $dealer_state || '' === $buyer_state || $dealer_state === $buyer_state ) {
			return;
		}

		Transfer_Service::log_event(
			0,
			'dealer_state_mismatch',
			array(
				'dealer_id' => (int) $dealer->id,
				'actor'     => 'system',
				'notes'     => sprintf(
					/* translators: 1: dealer state, 2: buyer state. */
					__( 'Advisory: the selected dealer is in %1$s while the buyer bills to %2$s. This is normal for interstate sales and is recorded for audit purposes only.', 'ffl-checkout-solutions' ),
					$dealer_state,
					$buyer_state
				),
				'data'      => array(
					'order_id'     => $order->get_id(),
					'dealer_state' => $dealer_state,
					'buyer_state'  => $buyer_state,
				),
			)
		);
	}

	// ── Rule storage ─────────────────────────────────────────────────────────

	/**
	 * Create or update one rule.
	 *
	 * A hand-edited rule has its source set to 'manual', which is what stops a
	 * later re-seed from treating it as a built-in row it may replace.
	 *
	 * @param array<string,mixed> $rule Rule fields.
	 * @return int|\WP_Error Rule ID.
	 */
	public static function save_rule( array $rule ) {
		global $wpdb;

		$state    = strtoupper( substr( (string) ( $rule['state'] ?? '' ), 0, 2 ) );
		$rule_key = sanitize_key( (string) ( $rule['rule_key'] ?? '' ) );

		if ( ! in_array( $state, fflcs_us_states(), true ) ) {
			return new \WP_Error(
				'fflcs_invalid_state',
				__( 'A valid two-letter state code is required.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === $rule_key ) {
			return new \WP_Error(
				'fflcs_invalid_rule_key',
				__( 'A rule key is required.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$table = fflcs_table( 'state_rules' );
		$id    = (int) ( $rule['id'] ?? 0 );

		$data = array(
			'state'       => $state,
			'rule_key'    => $rule_key,
			'rule_value'  => (string) ( $rule['rule_value'] ?? '' ),
			'rule_type'   => (string) ( $rule['rule_type'] ?? 'notice' ),
			'item_types'  => (string) ( $rule['item_types'] ?? '' ),
			'is_blocking' => (int) ( $rule['is_blocking'] ?? 0 ),
			'is_active'   => (int) ( $rule['is_active'] ?? 1 ),
			'source'      => 'manual',
			'updated_at'  => fflcs_mysql_now(),
		);

		if ( $id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $id ), null, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $id;
		}

		$existing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE state = %s AND rule_key = %s LIMIT 1",
				$state,
				$rule_key
			)
		);

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'id' => $existing ), null, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $existing;
		}

		$data['created_at']   = fflcs_mysql_now();
		$data['rule_version'] = (string) get_option( 'fflcs_state_rules_version', '1.0' );

		$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $id Rule ID.
	 */
	public static function delete_rule( int $id ): bool {
		global $wpdb;

		return (bool) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'state_rules' ),
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	// ── Audit ────────────────────────────────────────────────────────────────

	/**
	 * Compliance posture snapshot for the audit screen and REST.
	 *
	 * Deliberately reports facts about configuration and record completeness,
	 * never a pass/fail compliance verdict — this plugin is not in a position
	 * to issue one.
	 *
	 * @return array<string,mixed>
	 */
	public static function audit(): array {
		global $wpdb;

		$transfers = fflcs_table( 'transfers' );
		$rules     = fflcs_table( 'state_rules' );
		$events    = fflcs_table( 'events' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total_transfers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$transfers}" );

		$missing_serials = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$transfers}
				 WHERE ( serial_number IS NULL OR serial_number = '' )
				   AND status IN (%s, %s)",
				'received_by_dealer',
				'transferred'
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$states_covered = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT state) FROM {$rules} WHERE is_active = 1" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$event_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" );

		return array(
			'generated_at'        => fflcs_mysql_now(),
			'plugin_version'      => FFLCS_VERSION,
			'store_ffl_license'   => (string) get_option( 'fflcs_store_ffl_license', '' ) ? 'configured' : 'missing',
			'total_transfers'     => $total_transfers,
			'audit_events'        => $event_count,
			'transfers_missing_serial' => $missing_serials,
			'states_with_rules'   => $states_covered,
			'strict_compliance'   => '1' === (string) get_option( 'fflcs_compliance_strict', '1' ),
			'disclaimer_accepted' => (string) get_option( 'fflcs_legal_disclaimer_accepted_at', '' ),
			'token_secret_source' => defined( 'FFLCS_TOKEN_SECRET' ) ? 'wp-config constant' : 'database option',
			'encryption_available' => \FFLCS\Crypto::available(),
			'note'                => __( 'This report describes how this installation is configured and how complete its records are. It is not an assessment of legal compliance.', 'ffl-checkout-solutions' ),
		);
	}
}
