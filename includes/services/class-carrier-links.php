<?php
/**
 * Carrier tracking URL builder.
 *
 * Pure string formatting with no network calls and no API key, so it works on
 * the free tier — a customer seeing "UPS 1Z…" as a clickable link should not
 * require a paid carrier integration. The paid `carrier_tracking` module adds
 * live status polling and label purchase on top of this.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Maps carrier codes to public tracking URLs.
 */
class Carrier_Links {

	/**
	 * Supported carriers: code => [ label, URL template with %s ].
	 *
	 * @return array<string,array{label:string,url:string}>
	 */
	public static function carriers(): array {
		$carriers = array(
			'ups'    => array(
				'label' => 'UPS',
				'url'   => 'https://www.ups.com/track?tracknum=%s',
			),
			'usps'   => array(
				'label' => 'USPS',
				'url'   => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
			),
			'fedex'  => array(
				'label' => 'FedEx',
				'url'   => 'https://www.fedex.com/fedextrack/?trknbr=%s',
			),
			'dhl'    => array(
				'label' => 'DHL',
				'url'   => 'https://www.dhl.com/en/express/tracking.html?AWB=%s',
			),
			'ontrac' => array(
				'label' => 'OnTrac',
				'url'   => 'https://www.ontrac.com/tracking/?number=%s',
			),
		);

		/**
		 * Filter the carrier registry.
		 *
		 * @param array $carriers Carrier definitions.
		 */
		return (array) apply_filters( 'fflcs_carrier_providers', $carriers );
	}

	/**
	 * Public tracking URL for a shipment, or '' when it cannot be built.
	 *
	 * @param string $carrier Carrier code.
	 * @param string $number  Tracking number.
	 */
	public static function tracking_url( string $carrier, string $number ): string {
		$carrier = strtolower( trim( $carrier ) );
		$number  = trim( $number );

		if ( '' === $carrier || '' === $number ) {
			return '';
		}

		$carriers = self::carriers();
		if ( ! isset( $carriers[ $carrier ]['url'] ) ) {
			return '';
		}

		return sprintf( $carriers[ $carrier ]['url'], rawurlencode( $number ) );
	}

	/**
	 * Display label for a carrier code.
	 *
	 * @param string $carrier Carrier code.
	 */
	public static function label( string $carrier ): string {
		$carrier  = strtolower( trim( $carrier ) );
		$carriers = self::carriers();

		return $carriers[ $carrier ]['label'] ?? strtoupper( $carrier );
	}

	/**
	 * Best-effort carrier inference from a tracking number's shape.
	 *
	 * Used only to pre-select the dropdown when staff paste a bare number; the
	 * value is always editable, and nothing downstream trusts the guess.
	 *
	 * @param string $number Tracking number.
	 * @return string Carrier code, or '' when the shape is ambiguous.
	 */
	public static function guess_carrier( string $number ): string {
		$number = strtoupper( preg_replace( '/\s+/', '', $number ) );

		if ( preg_match( '/^1Z[0-9A-Z]{16}$/', $number ) ) {
			return 'ups';
		}
		if ( preg_match( '/^(94|93|92|95|82)\d{18,20}$/', $number ) ) {
			return 'usps';
		}
		if ( preg_match( '/^[A-Z]{2}\d{9}US$/', $number ) ) {
			return 'usps';
		}
		if ( preg_match( '/^\d{12}$/', $number ) || preg_match( '/^\d{15}$/', $number ) ) {
			return 'fedex';
		}
		if ( preg_match( '/^\d{10}$/', $number ) ) {
			return 'dhl';
		}
		if ( preg_match( '/^[CD]\d{14}$/', $number ) ) {
			return 'ontrac';
		}

		return '';
	}
}
