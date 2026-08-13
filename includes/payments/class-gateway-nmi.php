<?php
/**
 * NMI payment gateway (Section 10.15).
 *
 * Uses Collect.js tokenisation: the browser posts card details straight to NMI
 * and this server only ever sees a one-time payment token. That keeps the store
 * in SAQ A-EP scope rather than handling PAN data — the whole reason to prefer
 * this over a direct post integration.
 *
 * Fails closed. If the gateway response is anything other than an explicit
 * approval, the order is not marked paid (Section 29.8).
 *
 * @package FFLCS
 */

namespace FFLCS\Payments;

use FFLCS\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce gateway for NMI.
 */
class Gateway_Nmi extends \WC_Payment_Gateway {

	/**
	 * NMI transaction endpoint.
	 */
	const API_URL = 'https://secure.networkmerchants.com/api/transact.php';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'fflcs_nmi';
		$this->method_title       = __( 'NMI (FFL Checkout)', 'ffl-checkout-solutions' );
		$this->method_description = __( 'Card payments through NMI, using Collect.js so card data never reaches your server.', 'ffl-checkout-solutions' );
		$this->has_fields         = true;
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Credit card', 'ffl-checkout-solutions' ) );
		$this->description = $this->get_option( 'description', '' );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_collect_js' ) );
	}

	/**
	 * Gateway settings.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'ffl-checkout-solutions' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept card payments through NMI', 'ffl-checkout-solutions' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'   => __( 'Title', 'ffl-checkout-solutions' ),
				'type'    => 'text',
				'default' => __( 'Credit card', 'ffl-checkout-solutions' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'ffl-checkout-solutions' ),
				'type'    => 'textarea',
				'default' => '',
			),
			'credentials' => array(
				'title'       => __( 'Credentials', 'ffl-checkout-solutions' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: settings page URL. */
					__( 'Keys are stored encrypted under <a href="%s">FFL Checkout → Settings → Payments</a>.', 'ffl-checkout-solutions' ),
					esc_url( admin_url( 'admin.php?page=fflcs-settings&tab=payments' ) )
				),
			),
		);
	}

	/**
	 * Only offer the gateway once it can actually take a payment.
	 */
	public function is_available(): bool {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		return '' !== $this->security_key() && '' !== $this->tokenization_key();
	}

	/**
	 * Load Collect.js on checkout.
	 */
	public function enqueue_collect_js(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! $this->is_available() ) {
			return;
		}

		wp_enqueue_script(
			'fflcs-nmi-collect',
			'https://secure.networkmerchants.com/token/Collect.js',
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Vendor script; a version query string breaks their data-attribute bootstrap.
			true
		);

		wp_script_add_data( 'fflcs-nmi-collect', 'data-tokenization-key', $this->tokenization_key() );
	}

	/**
	 * Payment fields.
	 *
	 * Collect.js replaces these placeholders with hosted iframes, so no card
	 * input in this markup is ever a real input.
	 */
	public function payment_fields(): void {
		if ( $this->description ) {
			echo '<p>' . esc_html( $this->description ) . '</p>';
		}

		echo '<div id="fflcs-nmi-fields">';
		echo '<div data-collect-js="ccnumber"></div>';
		echo '<div data-collect-js="ccexp"></div>';
		echo '<div data-collect-js="cvv"></div>';
		echo '<input type="hidden" name="fflcs_nmi_token" id="fflcs-nmi-token">';
		echo '</div>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Card details go directly to our payment processor and are never stored on this site.', 'ffl-checkout-solutions' )
		);
	}

	/**
	 * Charge the payment token.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,string>
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return array( 'result' => 'failure' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		$token = isset( $_POST['fflcs_nmi_token'] ) ? sanitize_text_field( wp_unslash( $_POST['fflcs_nmi_token'] ) ) : '';

		if ( '' === $token ) {
			wc_add_notice( __( 'Your card details could not be read. Please re-enter them.', 'ffl-checkout-solutions' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'security_key'  => $this->security_key(),
					'type'          => 'sale',
					'payment_token' => $token,
					'amount'        => number_format( (float) $order->get_total(), 2, '.', '' ),
					'orderid'       => (string) $order->get_id(),
					'first_name'    => $order->get_billing_first_name(),
					'last_name'     => $order->get_billing_last_name(),
					'address1'      => $order->get_billing_address_1(),
					'city'          => $order->get_billing_city(),
					'state'         => $order->get_billing_state(),
					'zip'           => $order->get_billing_postcode(),
					'country'       => $order->get_billing_country(),
					'email'         => $order->get_billing_email(),
				),
			)
		);

		// An unreachable gateway is a failure, never a silent success.
		if ( is_wp_error( $response ) ) {
			$order->add_order_note( __( 'NMI was unreachable. The payment was not taken.', 'ffl-checkout-solutions' ) );
			wc_add_notice( __( 'We could not reach the payment processor. Please try again.', 'ffl-checkout-solutions' ), 'error' );

			return array( 'result' => 'failure' );
		}

		parse_str( (string) wp_remote_retrieve_body( $response ), $result );

		// NMI returns response=1 for approved. Anything else — declined, error,
		// or a shape we do not recognise — leaves the order unpaid.
		if ( '1' !== (string) ( $result['response'] ?? '' ) ) {
			$message = (string) ( $result['responsetext'] ?? __( 'The payment was declined.', 'ffl-checkout-solutions' ) );

			$order->add_order_note(
				sprintf(
					/* translators: %s: gateway message. */
					__( 'NMI declined the payment: %s', 'ffl-checkout-solutions' ),
					$message
				)
			);

			wc_add_notice( $message, 'error' );

			return array( 'result' => 'failure' );
		}

		$order->payment_complete( (string) ( $result['transactionid'] ?? '' ) );

		$order->add_order_note(
			sprintf(
				/* translators: %s: transaction ID. */
				__( 'NMI approved the payment. Transaction %s.', 'ffl-checkout-solutions' ),
				(string) ( $result['transactionid'] ?? '' )
			)
		);

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * The security key.
	 */
	private function security_key(): string {
		return Crypto::get_option( 'fflcs_nmi_security_key', 'fflcs_settings' );
	}

	/**
	 * The Collect.js tokenisation key.
	 */
	private function tokenization_key(): string {
		return Crypto::get_option( 'fflcs_nmi_tokenization_key', 'fflcs_settings' );
	}
}
