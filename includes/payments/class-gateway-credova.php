<?php
/**
 * Credova financing gateway (Section 11.3).
 *
 * Redirect-based: the customer applies with Credova, and the order stays
 * on-hold until Credova confirms approval. The order is never marked paid at
 * checkout time, because at checkout time nobody has been approved for
 * anything — treating a submitted application as payment would ship a firearm
 * against a loan that may be declined.
 *
 * Resolution comes from three directions — a webhook, a return-URL check, and a
 * daily sweep — because a financing decision that never lands leaves a customer
 * waiting and a firearm in limbo.
 *
 * @package FFLCS
 */

namespace FFLCS\Payments;

use FFLCS\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce gateway for Credova.
 */
class Gateway_Credova extends \WC_Payment_Gateway {

	/**
	 * Daily reconciliation hook.
	 */
	const CRON_HOOK = 'fflcs_credova_status_sweep';

	/**
	 * API base.
	 */
	const API_BASE = 'https://lending-api.credova.com/v2';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'fflcs_credova';
		$this->method_title       = __( 'Credova financing (FFL Checkout)', 'ffl-checkout-solutions' );
		$this->method_description = __( 'Consumer financing for firearms purchases. Orders are held until the lender approves.', 'ffl-checkout-solutions' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Financing', 'ffl-checkout-solutions' ) );
		$this->description = $this->get_option( 'description', __( 'Apply for financing. Your order is confirmed once the lender approves.', 'ffl-checkout-solutions' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Gateway settings.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable', 'ffl-checkout-solutions' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer Credova financing', 'ffl-checkout-solutions' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'   => __( 'Title', 'ffl-checkout-solutions' ),
				'type'    => 'text',
				'default' => __( 'Financing', 'ffl-checkout-solutions' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'ffl-checkout-solutions' ),
				'type'    => 'textarea',
				'default' => __( 'Apply for financing. Your order is confirmed once the lender approves.', 'ffl-checkout-solutions' ),
			),
		);
	}

	/**
	 * Only offer it when configured.
	 */
	public function is_available(): bool {
		return 'yes' === $this->enabled
			&& '' !== Crypto::get_option( 'fflcs_credova_public_key', 'fflcs_settings' )
			&& '' !== Crypto::get_option( 'fflcs_credova_secret_key', 'fflcs_settings' );
	}

	/**
	 * Start an application and hold the order.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,string>
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return array( 'result' => 'failure' );
		}

		$token = $this->access_token();

		if ( is_wp_error( $token ) ) {
			wc_add_notice( __( 'Financing is unavailable right now. Please choose another payment method.', 'ffl-checkout-solutions' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$response = wp_remote_post(
			self::API_BASE . '/applications',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'orderNumber' => (string) $order->get_id(),
						'amount'      => (float) $order->get_total(),
						'firstName'   => $order->get_billing_first_name(),
						'lastName'    => $order->get_billing_last_name(),
						'email'       => $order->get_billing_email(),
						'phone'       => $order->get_billing_phone(),
						'redirectUrl' => $this->get_return_url( $order ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wc_add_notice( __( 'We could not reach the financing provider. Please try again.', 'ffl-checkout-solutions' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		$redirect    = (string) ( $data['redirectUrl'] ?? $data['url'] ?? '' );
		$application = (string) ( $data['publicId'] ?? $data['id'] ?? '' );

		if ( '' === $redirect ) {
			wc_add_notice( __( 'The financing provider did not return an application link. Please choose another payment method.', 'ffl-checkout-solutions' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_fflcs_credova_application', $application );
		$order->update_status(
			'on-hold',
			__( 'Awaiting a financing decision from Credova. Do not ship until it is approved.', 'ffl-checkout-solutions' )
		);
		$order->save();

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * Register the webhook route.
	 */
	public function register_routes(): void {
		register_rest_route(
			FFLCS_REST_NS,
			'/webhooks/credova',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Apply a decision pushed by Credova.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_webhook( \WP_REST_Request $request ) {
		$secret = Crypto::get_option( 'fflcs_credova_secret_key', 'fflcs_settings' );

		if ( '' === $secret ) {
			return new \WP_Error(
				'fflcs_not_configured',
				__( 'Financing is not configured on this site.', 'ffl-checkout-solutions' ),
				array( 'status' => 503 )
			);
		}

		$signature = (string) $request->get_header( 'x_credova_signature' );
		$expected  = hash_hmac( 'sha256', $request->get_body(), $secret );

		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return new \WP_Error(
				'fflcs_invalid_signature',
				__( 'Invalid webhook signature.', 'ffl-checkout-solutions' ),
				array( 'status' => 403 )
			);
		}

		$payload = json_decode( $request->get_body(), true );

		if ( ! is_array( $payload ) ) {
			return new \WP_Error(
				'fflcs_invalid_payload',
				__( 'The webhook body was not valid JSON.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$order_id = (int) ( $payload['orderNumber'] ?? 0 );
		$status   = strtolower( (string) ( $payload['status'] ?? '' ) );

		$this->apply_decision( $order_id, $status );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Move an order according to a financing decision.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $status   Credova status.
	 */
	private function apply_decision( int $order_id, string $status ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Only these two words mean funded. Anything else leaves the order
		// on-hold for a human to look at.
		if ( in_array( $status, array( 'approved', 'funded' ), true ) ) {
			if ( ! $order->is_paid() ) {
				$order->payment_complete();
				$order->add_order_note( __( 'Credova approved the financing application.', 'ffl-checkout-solutions' ) );
			}

			return;
		}

		if ( in_array( $status, array( 'declined', 'cancelled', 'expired' ), true ) ) {
			$order->update_status(
				'cancelled',
				sprintf(
					/* translators: %s: lender status. */
					__( 'Credova reported the application as %s.', 'ffl-checkout-solutions' ),
					$status
				)
			);
		}
	}

	/**
	 * Daily reconciliation for orders still on hold.
	 */
	public static function cron_check_pending(): void {
		$orders = wc_get_orders(
			array(
				'status'         => array( 'on-hold' ),
				'payment_method' => 'fflcs_credova',
				'limit'          => 50,
			)
		);

		$gateway = new self();
		$token   = $gateway->access_token();

		if ( is_wp_error( $token ) ) {
			return;
		}

		foreach ( $orders as $order ) {
			$application = (string) $order->get_meta( '_fflcs_credova_application' );

			if ( '' === $application ) {
				continue;
			}

			$response = wp_remote_get(
				self::API_BASE . '/applications/' . rawurlencode( $application ),
				array(
					'timeout' => 20,
					'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				)
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			$gateway->apply_decision(
				$order->get_id(),
				strtolower( (string) ( $data['status'] ?? '' ) )
			);
		}
	}

	/**
	 * Fetch an OAuth access token.
	 *
	 * @return string|\WP_Error
	 */
	private function access_token() {
		$cached = get_transient( 'fflcs_credova_token' );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::API_BASE . '/auth/token',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'username' => Crypto::get_option( 'fflcs_credova_public_key', 'fflcs_settings' ),
						'password' => Crypto::get_option( 'fflcs_credova_secret_key', 'fflcs_settings' ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = (string) ( $data['accessToken'] ?? $data['access_token'] ?? '' );

		if ( '' === $token ) {
			return new \WP_Error(
				'fflcs_credova_auth_failed',
				__( 'Credova did not return an access token.', 'ffl-checkout-solutions' )
			);
		}

		set_transient( 'fflcs_credova_token', $token, 30 * MINUTE_IN_SECONDS );

		return $token;
	}
}
