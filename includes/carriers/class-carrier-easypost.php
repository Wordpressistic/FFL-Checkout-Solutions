<?php
/**
 * EasyPost carrier client (Section 10.1).
 *
 * Covers the two operations a firearms retailer actually needs: look up a
 * tracker's current state, and buy a label for a shipment to a dealer.
 *
 * Label purchase is never automatic. It spends money and creates a real
 * shipping liability, so it happens on an explicit admin click and nowhere else.
 *
 * @package FFLCS
 */

namespace FFLCS\Carriers;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal EasyPost REST client.
 */
class Carrier_Easypost {

	/**
	 * API base.
	 */
	const API_BASE = 'https://api.easypost.com/v2';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key EasyPost API key.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Current state of a tracking number.
	 *
	 * @param string $tracking_number Tracking number.
	 * @param string $carrier         Carrier code hint.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function track( string $tracking_number, string $carrier = '' ) {
		$body = array( 'tracking_code' => $tracking_number );

		if ( '' !== $carrier ) {
			$body['carrier'] = strtoupper( $carrier );
		}

		$response = $this->request( 'POST', '/trackers', array( 'tracker' => $body ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'state'       => Carrier_Registry::normalise_state( (string) ( $response['status'] ?? '' ) ),
			'raw'         => (string) ( $response['status'] ?? '' ),
			'description' => (string) ( $response['status_detail'] ?? '' ),
			'carrier'     => (string) ( $response['carrier'] ?? $carrier ),
			'est_delivery' => (string) ( $response['est_delivery_date'] ?? '' ),
		);
	}

	/**
	 * Quote rates for a shipment to a dealer.
	 *
	 * @param array<string,mixed> $to_address Destination address.
	 * @param array<string,mixed> $parcel     Parcel dimensions and weight.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function rates( array $to_address, array $parcel ) {
		$from = array(
			'name'    => (string) get_option( 'fflcs_store_name', get_option( 'blogname' ) ),
			'street1' => (string) get_option( 'fflcs_store_address', '' ),
			'phone'   => (string) get_option( 'fflcs_store_phone', '' ),
		);

		$response = $this->request(
			'POST',
			'/shipments',
			array(
				'shipment' => array(
					'to_address'   => $to_address,
					'from_address' => $from,
					'parcel'       => $parcel,
					'options'      => $this->shipment_options(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'shipment_id' => (string) ( $response['id'] ?? '' ),
			'rates'       => (array) ( $response['rates'] ?? array() ),
		);
	}

	/**
	 * Buy a label for a previously quoted shipment.
	 *
	 * @param string $shipment_id EasyPost shipment ID.
	 * @param string $rate_id     Chosen rate ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function buy_label( string $shipment_id, string $rate_id ) {
		$response = $this->request(
			'POST',
			'/shipments/' . rawurlencode( $shipment_id ) . '/buy',
			array( 'rate' => array( 'id' => $rate_id ) )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'tracking_code' => (string) ( $response['tracking_code'] ?? '' ),
			'label_url'     => (string) ( $response['postage_label']['label_url'] ?? '' ),
			'carrier'       => strtolower( (string) ( $response['selected_rate']['carrier'] ?? '' ) ),
		);
	}

	/**
	 * Shipment options.
	 *
	 * Adult signature is offered because a firearm arriving at an unattended
	 * loading dock is a real operational risk, not because any rule here
	 * requires it. It is a store setting.
	 *
	 * @return array<string,mixed>
	 */
	private function shipment_options(): array {
		$options = array();

		if ( '1' === (string) get_option( 'fflcs_carrier_adult_signature', '0' ) ) {
			$options['delivery_confirmation'] = 'ADULT_SIGNATURE';
		}

		return $options;
	}

	/**
	 * Signed request to EasyPost.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   Path.
	 * @param array<string,mixed> $body   Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( string $method, string $path, array $body = array() ) {
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				// EasyPost authenticates with the API key as HTTP Basic user,
				// empty password.
				'Authorization' => 'Basic ' . base64_encode( $this->api_key . ':' ),
				'Content-Type'  => 'application/json',
			),
		);

		if ( $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'fflcs_carrier_unreachable',
				__( 'Could not reach the carrier API.', 'ffl-checkout-solutions' ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new \WP_Error(
				'fflcs_carrier_error',
				(string) ( $data['error']['message'] ?? __( 'The carrier API returned an error.', 'ffl-checkout-solutions' ) ),
				array( 'status' => 502 )
			);
		}

		return $data;
	}
}
