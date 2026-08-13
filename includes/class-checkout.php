<?php
/**
 * WooCommerce checkout integration (Section 9.1).
 *
 * Responsibilities:
 *   - product data fields that mark a product as FFL-required and classify it
 *   - validating that a dealer was chosen before the order can be placed
 *   - persisting the selection to order meta, HPOS-safe
 *   - creating one transfer record per physical firearm unit once paid
 *
 * The transfer-creation path is hooked on two WooCommerce events that many
 * gateways fire close together for the same order. That race is handled at the
 * database level, not with a PHP pre-check — see create_unit_transfer().
 *
 * @package FFLCS
 */

namespace FFLCS;

use FFLCS\Services\Analytics;
use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Mailer;
use FFLCS\Services\Scheduler;
use FFLCS\Services\Token;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout hooks and transfer creation.
 */
class Checkout {

	const META_DEALER_ID   = '_fflcs_dealer_id';
	const META_DEALER_NAME = '_fflcs_dealer_name';
	const META_DEALER_ZIP  = '_fflcs_dealer_zip';
	const META_TRANSFER_ID = '_fflcs_transfer_id';

	const PRODUCT_FFL_REQUIRED  = '_fflcs_ffl_required';
	const PRODUCT_ITEM_TYPE     = '_fflcs_item_type';
	const PRODUCT_MANUFACTURER  = '_fflcs_manufacturer';
	const PRODUCT_MODEL         = '_fflcs_model';
	const PRODUCT_CALIBER       = '_fflcs_caliber';
	const PRODUCT_EXCISE        = '_fflcs_excise_taxable';
	const PRODUCT_EXCISE_RATE   = '_fflcs_excise_rate';
	const PRODUCT_AGE_RESTRICTED = '_fflcs_age_restricted';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		// Product data panel.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_field' ), 10, 2 );

		// Checkout validation.
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_selection' ) );

		// Order meta — both hooks, because WooCommerce 8 changed which one
		// carries the order object and stores run a wide range of versions.
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_order_meta_by_id' ) );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'save_order_meta_by_order' ) );

		// Transfer creation on payment.
		add_action( 'woocommerce_payment_complete', array( $this, 'create_transfers' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'create_transfers' ) );

		// Cancellation mirrors back onto the transfer records.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_transfers' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'cancel_transfers' ) );

		// Order display.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'render_admin_order_panel' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_order_panel' ) );

		// Async dealer notification.
		add_action( 'fflcs_async_issue_dealer_token', array( $this, 'issue_dealer_token' ), 10, 1 );
	}

	// ── Product fields ───────────────────────────────────────────────────────

	/**
	 * FFL fields on the product data panel.
	 */
	public function render_product_fields(): void {
		echo '<div class="options_group fflcs-product-options">';

		woocommerce_wp_checkbox(
			array(
				'id'          => self::PRODUCT_FFL_REQUIRED,
				'label'       => __( 'FFL transfer required', 'ffl-checkout-solutions' ),
				'description' => __( 'Buyers must select a licensed FFL dealer at checkout for this product.', 'ffl-checkout-solutions' ),
			)
		);

		woocommerce_wp_select(
			array(
				'id'          => self::PRODUCT_ITEM_TYPE,
				'label'       => __( 'Item type', 'ffl-checkout-solutions' ),
				'description' => __( 'Drives state compliance rules, the 4473 worksheet, the bound book, and federal multiple-sale detection. Leave unset only for non-firearms.', 'ffl-checkout-solutions' ),
				'desc_tip'    => true,
				'options'     => array_merge( array( '' => __( '— Not a firearm —', 'ffl-checkout-solutions' ) ), fflcs_item_types() ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::PRODUCT_MANUFACTURER,
				'label'       => __( 'Manufacturer / importer', 'ffl-checkout-solutions' ),
				'description' => __( 'Pre-fills the 4473 worksheet and the bound book entry.', 'ffl-checkout-solutions' ),
				'desc_tip'    => true,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'    => self::PRODUCT_MODEL,
				'label' => __( 'Model', 'ffl-checkout-solutions' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'    => self::PRODUCT_CALIBER,
				'label' => __( 'Caliber / gauge', 'ffl-checkout-solutions' ),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => self::PRODUCT_EXCISE,
				'label'       => __( 'Federal excise tax applies', 'ffl-checkout-solutions' ),
				'description' => __( 'Only enable this if you are the manufacturer or importer of record. Under 26 U.S.C. § 4181 the Pittman-Robertson excise tax is owed by manufacturers and importers, not by resellers. This is not tax advice — confirm with your accountant.', 'ffl-checkout-solutions' ),
				'desc_tip'    => true,
			)
		);

		woocommerce_wp_select(
			array(
				'id'      => self::PRODUCT_EXCISE_RATE,
				'label'   => __( 'Excise tax rate', 'ffl-checkout-solutions' ),
				'options' => array(
					'10' => __( '10% — pistols and revolvers', 'ffl-checkout-solutions' ),
					'11' => __( '11% — other firearms and ammunition', 'ffl-checkout-solutions' ),
				),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => self::PRODUCT_AGE_RESTRICTED,
				'label'       => __( 'Age-restricted item', 'ffl-checkout-solutions' ),
				'description' => __( 'For items that ship directly to the buyer but are still age-restricted — ammunition, magazines, certain accessories. Independent of the FFL transfer flag above.', 'ffl-checkout-solutions' ),
				'desc_tip'    => true,
			)
		);

		echo '</div>';
	}

	/**
	 * Persist product fields.
	 *
	 * WooCommerce verifies the product-save nonce before this hook fires, which
	 * is why the nonce sniff is suppressed rather than duplicated.
	 *
	 * @param int $product_id Product ID.
	 */
	public function save_product_fields( int $product_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product-save nonce.
		update_post_meta( $product_id, self::PRODUCT_FFL_REQUIRED, isset( $_POST[ self::PRODUCT_FFL_REQUIRED ] ) ? 'yes' : 'no' );

		$item_type = sanitize_key( wp_unslash( $_POST[ self::PRODUCT_ITEM_TYPE ] ?? '' ) );
		update_post_meta(
			$product_id,
			self::PRODUCT_ITEM_TYPE,
			array_key_exists( $item_type, fflcs_item_types() ) ? $item_type : ''
		);

		update_post_meta( $product_id, self::PRODUCT_MANUFACTURER, sanitize_text_field( wp_unslash( $_POST[ self::PRODUCT_MANUFACTURER ] ?? '' ) ) );
		update_post_meta( $product_id, self::PRODUCT_MODEL, sanitize_text_field( wp_unslash( $_POST[ self::PRODUCT_MODEL ] ?? '' ) ) );
		update_post_meta( $product_id, self::PRODUCT_CALIBER, sanitize_text_field( wp_unslash( $_POST[ self::PRODUCT_CALIBER ] ?? '' ) ) );
		update_post_meta( $product_id, self::PRODUCT_EXCISE, isset( $_POST[ self::PRODUCT_EXCISE ] ) ? 'yes' : 'no' );

		$rate = sanitize_text_field( wp_unslash( $_POST[ self::PRODUCT_EXCISE_RATE ] ?? '11' ) );
		update_post_meta( $product_id, self::PRODUCT_EXCISE_RATE, in_array( $rate, array( '10', '11' ), true ) ? $rate : '11' );

		update_post_meta( $product_id, self::PRODUCT_AGE_RESTRICTED, isset( $_POST[ self::PRODUCT_AGE_RESTRICTED ] ) ? 'yes' : 'no' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Per-variation FFL checkbox.
	 *
	 * Untyped parameters deliberately: WooCommerce has passed $loop as both int
	 * and string across versions.
	 *
	 * @param mixed $loop           Loop index.
	 * @param mixed $variation_data Variation data.
	 * @param mixed $variation      Variation post.
	 */
	public function render_variation_field( $loop, $variation_data, $variation ): void {
		if ( ! isset( $variation->ID ) ) {
			return;
		}

		woocommerce_wp_checkbox(
			array(
				'id'    => self::PRODUCT_FFL_REQUIRED . '_variation_' . $loop,
				'name'  => 'fflcs_ffl_required_variation[' . $loop . ']',
				'label' => __( 'FFL transfer required', 'ffl-checkout-solutions' ),
				'value' => get_post_meta( (int) $variation->ID, self::PRODUCT_FFL_REQUIRED, true ),
			)
		);
	}

	/**
	 * Persist the per-variation flag.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Loop index.
	 */
	public function save_variation_field( int $variation_id, int $loop ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the variation-save nonce.
		$checked = isset( $_POST['fflcs_ffl_required_variation'][ $loop ] );

		update_post_meta( $variation_id, self::PRODUCT_FFL_REQUIRED, $checked ? 'yes' : 'no' );
	}

	// ── Checkout validation ──────────────────────────────────────────────────

	/**
	 * Block order placement when no dealer was selected.
	 *
	 * Skipped entirely when strict mode is off, which lets a store take FFL
	 * orders and assign a dealer manually afterwards.
	 */
	public function validate_selection(): void {
		if ( ! self::cart_requires_ffl() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
		// Honeypot: a hidden field only an automated submitter fills.
		if ( ! empty( $_POST['fflcs_hp'] ) ) {
			wc_add_notice(
				__( 'Your submission was flagged by our spam filter. Please reload the page and try again.', 'ffl-checkout-solutions' ),
				'error'
			);
			return;
		}

		$dealer_id = (int) ( $_POST['fflcs_dealer_id'] ?? 0 );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '1' !== (string) get_option( 'fflcs_checkout_strict_mode', '1' ) ) {
			return;
		}

		if ( ! $dealer_id ) {
			wc_add_notice(
				__( 'Please select an FFL dealer before placing your order.', 'ffl-checkout-solutions' ),
				'error'
			);
			return;
		}

		if ( ! Dealer_Service::is_selectable( $dealer_id ) ) {
			wc_add_notice(
				__( 'The selected dealer is no longer available. Please search again and choose another.', 'ffl-checkout-solutions' ),
				'error'
			);
		}
	}

	// ── Order meta ───────────────────────────────────────────────────────────

	/**
	 * Legacy hook — WooCommerce passes an order ID.
	 *
	 * @param int $order_id Order ID.
	 */
	public function save_order_meta_by_id( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			$this->persist_dealer_meta( $order );
		}
	}

	/**
	 * Modern hook — WooCommerce passes the order object.
	 *
	 * @param mixed $order Order.
	 */
	public function save_order_meta_by_order( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->persist_dealer_meta( $order );
		}
	}

	/**
	 * Write the dealer selection to order meta.
	 *
	 * HPOS-safe: goes through the order object's meta API rather than
	 * update_post_meta(), which does not reach the orders table when HPOS is on.
	 *
	 * The dealer ID is re-validated against the database here. It arrives in a
	 * POST field, so trusting it would let anyone point an order at an
	 * arbitrary integer, or at an inactive dealer.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function persist_dealer_meta( \WC_Order $order ): void {
		if ( ! self::cart_requires_ffl() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
		$dealer_id = (int) ( $_POST['fflcs_dealer_id'] ?? 0 );
		$zip       = sanitize_text_field( wp_unslash( $_POST['fflcs_dealer_zip'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $dealer_id ) {
			return;
		}

		$dealer = Dealer_Service::get( $dealer_id );
		if ( ! $dealer || ! (int) $dealer->is_active ) {
			return;
		}

		// The name is taken from the database rather than the submitted field,
		// so a tampered POST cannot write an arbitrary business name onto an
		// order that will later appear in customer email and admin screens.
		$order->update_meta_data( self::META_DEALER_ID, $dealer_id );
		$order->update_meta_data( self::META_DEALER_NAME, $dealer->business_name );
		$order->update_meta_data( self::META_DEALER_ZIP, $zip ?: (string) $dealer->premise_zip );
		$order->save();

		Analytics::record( 'dealer_selected', null, $dealer_id );
	}

	// ── Transfer creation ────────────────────────────────────────────────────

	/**
	 * Create one transfer per physical FFL unit in a paid order.
	 *
	 * @param int $order_id Order ID.
	 */
	public function create_transfers( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$dealer_id = (int) $order->get_meta( self::META_DEALER_ID );
		if ( ! $dealer_id ) {
			return;
		}

		$units = self::ffl_units( $order );
		if ( ! $units ) {
			return;
		}

		// Advisory only, and once per order: the dealer/buyer pair does not
		// vary by line item.
		$dealer = Dealer_Service::get( $dealer_id );
		if ( $dealer ) {
			Services\Compliance::log_dealer_buyer_states( $dealer, $order );
		}

		// Whether the dealer gets notified at all is decided once per order,
		// not per unit — it depends on the order, not the line item.
		$notify = $order->is_paid()
			&& '1' === (string) get_option( 'fflcs_auto_notify_dealer', '1' );

		foreach ( $units as $unit ) {
			$this->create_unit_transfer( $order, $unit['item_id'], $unit['unit'], $unit['product'], $dealer_id, $notify );
		}
	}

	/**
	 * Every FFL-flagged unit in an order.
	 *
	 * A line with quantity 3 yields three entries: each physical firearm is its
	 * own transfer, its own bound-book row and its own 4473.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,array{item_id:int,unit:int,product:\WC_Product}>
	 */
	public static function ffl_units( \WC_Order $order ): array {
		$units = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( ! self::product_requires_ffl( $product->get_id() ) ) {
				continue;
			}

			$quantity = max( 1, (int) $item->get_quantity() );

			for ( $unit = 1; $unit <= $quantity; $unit++ ) {
				$units[] = array(
					'item_id' => (int) $item_id,
					'unit'    => $unit,
					'product' => $product,
				);
			}
		}

		return $units;
	}

	/**
	 * Create the transfer row for one unit, with side effects fired exactly once.
	 *
	 * Concurrency: both hooks that call into here can run for the same order
	 * within milliseconds of each other. The unique index on
	 * (order_id, order_item_id, order_item_unit) is what guarantees a single
	 * row survives; Transfer_Service::create() reports which call won. The
	 * loser still links order meta — so the order references every transfer —
	 * but fires no events, no email and no dealer notification, because the
	 * winner already did.
	 *
	 * @param \WC_Order   $order     Order.
	 * @param int         $item_id   Order item ID.
	 * @param int         $unit      1-based unit index within the line.
	 * @param \WC_Product $product   Product.
	 * @param int         $dealer_id Dealer ID.
	 * @param bool        $notify    Whether to notify the dealer.
	 */
	private function create_unit_transfer( \WC_Order $order, int $item_id, int $unit, \WC_Product $product, int $dealer_id, bool $notify ): void {
		$product_id = $product->get_id();

		$result = Transfer_Service::create(
			array(
				'order_id'        => $order->get_id(),
				'order_item_id'   => $item_id,
				'order_item_unit' => $unit,
				'dealer_id'       => $dealer_id,
				'customer_id'     => $order->get_customer_id() ?: null,
				'customer_name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'customer_email'  => $order->get_billing_email(),
				'customer_phone'  => $order->get_billing_phone(),
				'customer_ip'     => $order->get_customer_ip_address(),
				'product_id'      => $product_id,
				'product_name'    => $product->get_name(),
				'product_sku'     => $product->get_sku(),
				'product_type'    => (string) get_post_meta( $product_id, self::PRODUCT_ITEM_TYPE, true ),
				'manufacturer'    => (string) get_post_meta( $product_id, self::PRODUCT_MANUFACTURER, true ),
				'model'           => (string) get_post_meta( $product_id, self::PRODUCT_MODEL, true ),
				'caliber'         => (string) get_post_meta( $product_id, self::PRODUCT_CALIBER, true ),
				'status'          => 'payment_confirmed',
				'payment_status'  => $order->get_status(),
				'is_age_restricted' => 'yes' === get_post_meta( $product_id, self::PRODUCT_AGE_RESTRICTED, true ) ? 1 : 0,
				'is_ffl_required' => 1,
				'source'          => 'woocommerce',
			)
		);

		$transfer_id = $result['id'];
		if ( ! $transfer_id ) {
			return;
		}

		// Non-unique meta: an order owns one row per transfer. Read them all
		// back with $order->get_meta( META_TRANSFER_ID, false ).
		if ( ! in_array( (string) $transfer_id, array_map( 'strval', (array) $order->get_meta( self::META_TRANSFER_ID, false ) ), true ) ) {
			$order->add_meta_data( self::META_TRANSFER_ID, $transfer_id, false );
			$order->save();
		}

		if ( ! $result['is_new'] ) {
			return;
		}

		Transfer_Service::log_event(
			$transfer_id,
			'created',
			array(
				'new_status' => 'payment_confirmed',
				'dealer_id'  => $dealer_id,
				'actor'      => 'system',
				'notes'      => sprintf(
					/* translators: %d: WooCommerce order number. */
					__( 'Transfer created automatically from order #%d.', 'ffl-checkout-solutions' ),
					$order->get_id()
				),
			)
		);

		Mailer::send_status_update( $transfer_id, 'payment_confirmed' );

		/**
		 * Fires once per newly created transfer.
		 *
		 * @param int   $transfer_id Transfer ID.
		 * @param array $context     Order and dealer context.
		 */
		do_action(
			'fflcs_transfer_created',
			$transfer_id,
			array(
				'order_id'  => $order->get_id(),
				'dealer_id' => $dealer_id,
				'product_id' => $product_id,
			)
		);

		// Legacy alias for v1.x integrations (Section 21.2).
		do_action( 'wpistic_ffl_transfer_created', $transfer_id, $order->get_id() );

		/**
		 * Filter whether the dealer is notified for this order.
		 *
		 * @param bool      $notify      Current decision.
		 * @param \WC_Order $order       Order.
		 * @param int       $transfer_id Transfer ID.
		 */
		if ( (bool) apply_filters( 'fflcs_can_notify_dealer_on_order', $notify, $order, $transfer_id ) ) {
			// Queued, never inline: a slow SMTP host must not extend checkout.
			Scheduler::async( 'fflcs_async_issue_dealer_token', array( $transfer_id ) );
		}
	}

	/**
	 * Mint and email a dealer confirmation link.
	 *
	 * @param int $transfer_id Transfer ID.
	 */
	public function issue_dealer_token( int $transfer_id ): void {
		$transfer = Transfer_Service::get( $transfer_id );
		if ( ! $transfer ) {
			return;
		}

		$token = Token::generate( $transfer_id, (int) $transfer->dealer_id );
		if ( is_wp_error( $token ) ) {
			fflcs_log( 'Dealer token generation failed', $token->get_error_message() );
			return;
		}

		Mailer::send_dealer_invitation( $transfer_id, Token::build_url( $token ) );
	}

	/**
	 * Cancel transfers when their order is cancelled or refunded.
	 *
	 * Terminal transfers are left alone. A firearm that already changed hands
	 * cannot be un-transferred by a bookkeeping action in WooCommerce, and the
	 * bound-book record of it must stand.
	 *
	 * @param int $order_id Order ID.
	 */
	public function cancel_transfers( int $order_id ): void {
		foreach ( Transfer_Service::for_order( $order_id ) as $transfer ) {
			if ( in_array( (string) $transfer->status, Transfer_Service::TERMINAL_STATUSES, true ) ) {
				continue;
			}

			Token::revoke_all_for_transfer( (int) $transfer->id );

			Transfer_Service::set_status(
				(int) $transfer->id,
				'cancelled',
				'woocommerce',
				__( 'The WooCommerce order was cancelled or refunded.', 'ffl-checkout-solutions' )
			);
		}
	}

	// ── Order display ────────────────────────────────────────────────────────

	/**
	 * FFL panel on the admin order screen.
	 *
	 * @param mixed $order Order.
	 */
	public function render_admin_order_panel( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$dealer_name = (string) $order->get_meta( self::META_DEALER_NAME );
		if ( '' === $dealer_name ) {
			return;
		}

		// Queried from the database, not from order meta: an order can own
		// several transfers and the table is authoritative.
		$transfers = Transfer_Service::for_order( $order->get_id() );

		echo '<div class="fflcs-order-panel" style="margin-top:16px;">';
		echo '<h3>' . esc_html__( 'FFL Transfer', 'ffl-checkout-solutions' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Dealer:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( $dealer_name ) . '</p>';

		if ( $transfers ) {
			echo '<ul style="margin:0;padding-left:18px;">';
			foreach ( $transfers as $transfer ) {
				printf(
					'<li><a href="%1$s">%2$s</a> — %3$s</li>',
					esc_url( admin_url( 'admin.php?page=fflcs-transfers&transfer=' . (int) $transfer->id ) ),
					esc_html( (string) $transfer->transfer_ref ),
					esc_html( fflcs_status_label( (string) $transfer->status ) )
				);
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * FFL panel on the customer's order page and emails.
	 *
	 * @param mixed $order Order.
	 */
	public function render_customer_order_panel( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$dealer_name = (string) $order->get_meta( self::META_DEALER_NAME );
		if ( '' === $dealer_name ) {
			return;
		}

		$accent = Services\Theming::sanitize_color( Services\Theming::settings()['color_primary'] ) ?: '#2563EB';

		echo '<section class="fflcs-order-summary" style="margin-top:24px;padding:16px;border-left:4px solid ' . esc_attr( $accent ) . ';background:rgba(15,23,42,.03);">';
		echo '<h2 style="margin:0 0 8px;font-size:16px;">' . esc_html__( 'FFL dealer selected', 'ffl-checkout-solutions' ) . '</h2>';
		printf(
			'<p style="margin:0 0 4px;">%s <strong>%s</strong></p>',
			esc_html__( 'Your firearm will transfer through:', 'ffl-checkout-solutions' ),
			esc_html( $dealer_name )
		);
		echo '<p style="margin:0;font-size:13px;color:#64748B;">' . esc_html__( 'We will email you when your dealer confirms the firearm has arrived and is ready for pickup.', 'ffl-checkout-solutions' ) . '</p>';

		foreach ( Transfer_Service::for_order( $order->get_id() ) as $transfer ) {
			$url = Services\Tracking_Links::customer_url( (string) $transfer->transfer_ref );
			printf(
				'<p style="margin:8px 0 0;font-size:13px;"><a href="%1$s">%2$s %3$s</a></p>',
				esc_url( $url ),
				esc_html__( 'Track transfer', 'ffl-checkout-solutions' ),
				esc_html( (string) $transfer->transfer_ref )
			);
		}

		echo '</section>';
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Whether the current cart holds any FFL-required product.
	 */
	public static function cart_requires_ffl(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $line ) {
			$product_id = (int) ( $line['variation_id'] ?: $line['product_id'] );
			if ( self::product_requires_ffl( $product_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a product requires an FFL transfer.
	 *
	 * A variation inherits its parent's flag unless it sets its own, so a
	 * merchant does not have to tick every variation of a firearm.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	public static function product_requires_ffl( int $product_id ): bool {
		if ( 'yes' === get_post_meta( $product_id, self::PRODUCT_FFL_REQUIRED, true ) ) {
			return true;
		}

		$parent_id = (int) wp_get_post_parent_id( $product_id );

		return $parent_id > 0 && 'yes' === get_post_meta( $parent_id, self::PRODUCT_FFL_REQUIRED, true );
	}

	/**
	 * Whether a product is flagged age-restricted.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function product_is_age_restricted( int $product_id ): bool {
		return 'yes' === get_post_meta( $product_id, self::PRODUCT_AGE_RESTRICTED, true );
	}

	/**
	 * Whether the cart holds any age-restricted product.
	 */
	public static function cart_has_age_restricted(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $line ) {
			$product_id = (int) ( $line['variation_id'] ?: $line['product_id'] );
			if ( self::product_is_age_restricted( $product_id ) || self::product_requires_ffl( $product_id ) ) {
				return true;
			}
		}

		return false;
	}
}
