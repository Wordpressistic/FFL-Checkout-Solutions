<?php
/**
 * Sports South drop-ship client.
 *
 * HONESTY NOTE: Sports South exposes a SOAP/ASMX service. This client speaks it
 * over plain HTTP POST with a hand-built envelope rather than requiring the PHP
 * SOAP extension, which is absent on a good share of shared hosting. The
 * DailyItemUpdate catalog call and the AddHeader → AddDetail → Submit order
 * sequence follow their integration documentation; the exact element ordering
 * within the order calls is inferred from that documentation and should be
 * verified against a test account before first live use.
 *
 * @package FFLCS
 */

namespace FFLCS\Distributors;

defined( 'ABSPATH' ) || exit;

/**
 * Sports South SOAP client.
 */
class Distributor_Sports_South extends Distributor_Support {

	/**
	 * Service endpoint.
	 */
	const ENDPOINT = 'http://webservices.theshootingwarehouse.com/smart/inventory.asmx';

	/**
	 * SOAP namespace.
	 */
	const NAMESPACE_URI = 'http://webservices.theshootingwarehouse.com/smart/inventory.asmx';

	/**
	 * Pull the catalog via DailyItemUpdate.
	 *
	 * @return int|\WP_Error
	 */
	public function sync_catalog() {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}

		// Their catalog call is incremental by design; a far-past date pulls
		// everything on a first run.
		$since = (string) get_option( 'fflcs_sports_south_last_sync', '1990-01-01' );

		$xml = $this->call(
			'DailyItemUpdate',
			array(
				'UserName'   => $this->credential( 'username' ),
				'Password'   => $this->credential( 'password' ),
				'CustomerNumber' => $this->credential( 'customer_no' ),
				'Source'     => $this->credential( 'source' ),
				'LastUpdate' => $since,
				'LastItem'   => '-1',
			)
		);

		if ( is_wp_error( $xml ) ) {
			return $xml;
		}

		$products = $this->parse_catalog( $xml );

		$written = $this->cache_products( $products );

		update_option( 'fflcs_sports_south_last_sync', current_time( 'Y-m-d' ) );

		return $written;
	}

	/**
	 * Submit an order.
	 *
	 * @param array<string,mixed> $order Order details.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function submit_order( array $order ) {
		if ( ! $this->is_configured() ) {
			return $this->not_configured();
		}

		$ffl = trim( (string) ( $order['ship_to_ffl'] ?? '' ) );

		if ( '' === $ffl ) {
			return new \WP_Error(
				'fflcs_missing_ffl',
				__( 'A receiving dealer FFL number is required before an order can be placed.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$po = $this->purchase_order_number( (int) ( $order['transfer_id'] ?? 0 ) );

		$credentials = array(
			'UserName'       => $this->credential( 'username' ),
			'Password'       => $this->credential( 'password' ),
			'CustomerNumber' => $this->credential( 'customer_no' ),
			'Source'         => $this->credential( 'source' ),
		);

		// Three calls in sequence. If a later one fails the earlier ones are
		// already recorded on their side, so the failure is surfaced with the
		// stage it reached rather than swallowed.
		$header = $this->call( 'OrderHeaderAdd', $credentials + array( 'PONumber' => $po, 'FFLNumber' => $ffl ) );

		if ( is_wp_error( $header ) ) {
			return $header;
		}

		$order_number = $this->extract( $header, 'OrderNumber' );

		if ( '' === $order_number ) {
			return new \WP_Error(
				'fflcs_sports_south_no_order',
				__( 'Sports South did not return an order number for the header call.', 'ffl-checkout-solutions' ),
				array( 'status' => 502 )
			);
		}

		$detail = $this->call(
			'OrderDetailAdd',
			$credentials + array(
				'OrderNumber' => $order_number,
				'ItemNumber'  => (string) ( $order['item_no'] ?? '' ),
				'Quantity'    => (string) max( 1, (int) ( $order['quantity'] ?? 1 ) ),
			)
		);

		if ( is_wp_error( $detail ) ) {
			return $detail;
		}

		$submit = $this->call( 'OrderSubmit', $credentials + array( 'OrderNumber' => $order_number ) );

		if ( is_wp_error( $submit ) ) {
			return $submit;
		}

		$result = array(
			'status'    => 'submitted',
			'order_ref' => $order_number,
			'po_number' => $po,
			'raw'       => array( 'order_number' => $order_number ),
		);

		$order['po_number'] = $po;

		$this->record_order( $order, $result );

		return $result;
	}

	/**
	 * Perform one SOAP call.
	 *
	 * @param string                $method     Method name.
	 * @param array<string,string>  $parameters Parameters, in document order.
	 * @return string|\WP_Error Raw XML response body.
	 */
	private function call( string $method, array $parameters ) {
		$body = '';

		foreach ( $parameters as $name => $value ) {
			$body .= '<' . $name . '>' . htmlspecialchars( (string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</' . $name . '>';
		}

		$envelope = '<?xml version="1.0" encoding="utf-8"?>'
			. '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap:Body><' . $method . ' xmlns="' . self::NAMESPACE_URI . '">'
			. $body
			. '</' . $method . '></soap:Body></soap:Envelope>';

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type' => 'text/xml; charset=utf-8',
					'SOAPAction'   => self::NAMESPACE_URI . '/' . $method,
					'User-Agent'   => 'FFLCheckoutSolutions/' . FFLCS_VERSION,
				),
				'body'    => $envelope,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'fflcs_distributor_unreachable',
				__( 'Could not reach Sports South.', 'ffl-checkout-solutions' ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$xml  = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new \WP_Error(
				'fflcs_distributor_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Sports South returned HTTP %d.', 'ffl-checkout-solutions' ),
					$code
				),
				array( 'status' => 502 )
			);
		}

		return $xml;
	}

	/**
	 * Pull the first value of an element out of a response.
	 *
	 * @param string $xml     Response XML.
	 * @param string $element Element name.
	 */
	private function extract( string $xml, string $element ): string {
		if ( preg_match( '#<' . preg_quote( $element, '#' ) . '>(.*?)</' . preg_quote( $element, '#' ) . '>#s', $xml, $match ) ) {
			return trim( html_entity_decode( $match[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
		}

		return '';
	}

	/**
	 * Parse the DailyItemUpdate dataset into normalised products.
	 *
	 * Uses SimpleXML with entity loading left at PHP 8's safe default, and
	 * suppresses libxml errors so a malformed row cannot emit a warning into
	 * an admin page.
	 *
	 * @param string $xml Response XML.
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_catalog( string $xml ): array {
		$previous = libxml_use_internal_errors( true );

		$document = simplexml_load_string( $xml );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false === $document ) {
			return array();
		}

		$products = array();

		// The dataset arrives as repeated <Table> rows regardless of nesting
		// depth, so select them by name wherever they sit.
		foreach ( $document->xpath( '//*[local-name()="Table"]' ) ?: array() as $row ) {
			$item_no = trim( (string) ( $row->ITEMNO ?? '' ) );

			if ( '' === $item_no ) {
				continue;
			}

			$products[] = array(
				'item_no'      => $item_no,
				'description'  => trim( (string) ( $row->IDESC ?? '' ) ),
				'manufacturer' => trim( (string) ( $row->IMFGNO ?? '' ) ),
				'model'        => trim( (string) ( $row->IMODEL ?? '' ) ),
				'upc'          => trim( (string) ( $row->ITUPC ?? '' ) ),
				'price'        => (float) ( $row->CPRC ?? 0 ),
				'msrp'         => (float) ( $row->MFPRC ?? 0 ),
				'quantity'     => (int) ( $row->QTYOH ?? 0 ),
				// ITYPE 1 is their long-gun/handgun serialised class.
				'is_firearm'   => '1' === trim( (string) ( $row->ITYPE ?? '' ) ),
			);
		}

		return $products;
	}
}
