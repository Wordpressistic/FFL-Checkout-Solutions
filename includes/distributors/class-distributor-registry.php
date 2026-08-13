<?php
/**
 * Distributor registry (Section 11.1).
 *
 * Knows which drop-ship partners exist, what each one needs to authenticate,
 * and — importantly — what is actually confirmed about each wire protocol
 * versus what is inferred. That last part is carried as an honesty note on each
 * entry and surfaced in the admin, because a store owner deciding whether to
 * route real money through an integration deserves to know how well-verified it
 * is.
 *
 * @package FFLCS
 */

namespace FFLCS\Distributors;

use FFLCS\Addon_Manager;
use FFLCS\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Distributor definitions and credential storage.
 */
class Distributor_Registry {

	/**
	 * Crypto context for distributor credentials.
	 */
	const CRYPTO_CONTEXT = 'fflcs_distributor';

	/**
	 * Every known distributor.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function known(): array {
		$distributors = array(
			'lipseys'      => array(
				'name'         => "Lipsey's",
				'class'        => Distributor_Lipseys::class,
				'module'       => 'distributor_lipseys',
				'protocol'     => __( 'REST API over HTTPS (api.lipseys.com)', 'ffl-checkout-solutions' ),
				'credentials'  => array(
					'email'    => __( 'Dealer account email', 'ffl-checkout-solutions' ),
					'password' => __( 'Dealer account password', 'ffl-checkout-solutions' ),
				),
				'honesty_note' => __( 'Targets the documented Lipsey\'s dealer API. Authentication and catalog endpoints follow their published integration guide; the exact order-submission response shape is handled defensively because it was not verifiable without a live dealer account.', 'ffl-checkout-solutions' ),
			),
			'sports-south' => array(
				'name'         => 'Sports South',
				'class'        => Distributor_Sports_South::class,
				'module'       => 'distributor_sports_south',
				'protocol'     => __( 'SOAP / ASMX web service', 'ffl-checkout-solutions' ),
				'credentials'  => array(
					'username'    => __( 'Username', 'ffl-checkout-solutions' ),
					'password'    => __( 'Password', 'ffl-checkout-solutions' ),
					'customer_no' => __( 'Customer number', 'ffl-checkout-solutions' ),
					'source'      => __( 'Source ID', 'ffl-checkout-solutions' ),
				),
				'honesty_note' => __( 'Uses the DailyItemUpdate catalog call and the AddHeader → AddDetail → Submit order sequence described in the Sports South integration documentation. Field-level ordering of the order calls is inferred from that documentation and should be verified against a test account before first live use.', 'ffl-checkout-solutions' ),
			),
			'rsr'          => array(
				'name'         => 'RSR Group',
				'class'        => Distributor_Rsr::class,
				'module'       => 'distributor_rsr',
				'protocol'     => __( 'FTPS file exchange', 'ffl-checkout-solutions' ),
				'credentials'  => array(
					'host'     => __( 'FTPS host', 'ffl-checkout-solutions' ),
					'username' => __( 'Username', 'ffl-checkout-solutions' ),
					'password' => __( 'Password', 'ffl-checkout-solutions' ),
					'account'  => __( 'Account number', 'ffl-checkout-solutions' ),
				),
				'honesty_note' => __( 'RSR exchanges fixed-width inventory and order files over FTPS, with asynchronous ECONF/EERR acknowledgement files. The field positions used here follow RSR\'s published layout; because the layout is versioned by RSR and not discoverable at runtime, confirm it against your current dealer packet before enabling live ordering.', 'ffl-checkout-solutions' ),
			),
			'bill-hicks'   => array(
				'name'         => 'Bill Hicks & Co.',
				'class'        => Distributor_Bill_Hicks::class,
				'module'       => 'distributor_bill_hicks',
				'protocol'     => __( 'FTPS file exchange', 'ffl-checkout-solutions' ),
				'credentials'  => array(
					'host'     => __( 'FTPS host', 'ffl-checkout-solutions' ),
					'username' => __( 'Username', 'ffl-checkout-solutions' ),
					'password' => __( 'Password', 'ffl-checkout-solutions' ),
					'account'  => __( 'Account number', 'ffl-checkout-solutions' ),
				),
				'honesty_note' => __( 'Catalog download, order upload and acknowledgement reading over the dealer FTPS account. Directory names and file naming follow the Bill Hicks dealer packet and vary per account, so they are configurable rather than hardcoded.', 'ffl-checkout-solutions' ),
			),
			'chattanooga'  => array(
				'name'         => 'Chattanooga Shooting Supplies',
				'class'        => Distributor_Chattanooga::class,
				'module'       => 'distributor_chattanooga',
				'protocol'     => __( 'REST API over HTTPS (api.chattanoogashooting.com)', 'ffl-checkout-solutions' ),
				'credentials'  => array(
					'sid'   => __( 'SID', 'ffl-checkout-solutions' ),
					'token' => __( 'API token', 'ffl-checkout-solutions' ),
				),
				'honesty_note' => __( 'Targets the documented Chattanooga dealer API, including its FFL-on-file precondition: an order for a firearm is refused unless the receiving dealer\'s licence is already on file with Chattanooga, which this plugin cannot verify on their behalf.', 'ffl-checkout-solutions' ),
			),
		);

		/**
		 * Filter the distributor registry.
		 *
		 * @param array $distributors Distributor definitions.
		 */
		return (array) apply_filters( 'fflcs_distributor_providers', $distributors );
	}

	/**
	 * An instantiated client, when the distributor's module is running.
	 *
	 * @param string $slug Distributor slug.
	 */
	public static function client( string $slug ): ?Distributor_Support {
		$meta = self::known()[ $slug ] ?? null;

		if ( ! $meta ) {
			return null;
		}

		// Entitlement and activation are both checked here, not just in the
		// module loader, because a REST route could reach a client directly.
		if ( ! Addon_Manager::is_active( (string) $meta['module'] ) ) {
			return null;
		}

		$class = (string) $meta['class'];

		if ( ! class_exists( $class ) ) {
			return null;
		}

		return new $class( $slug );
	}

	// ── Credentials ──────────────────────────────────────────────────────────

	/**
	 * Option name for one credential field.
	 *
	 * @param string $slug  Distributor slug.
	 * @param string $field Field key.
	 */
	private static function option_name( string $slug, string $field ): string {
		return 'fflcs_dist_' . str_replace( '-', '_', $slug ) . '_' . $field;
	}

	/**
	 * Store one credential, encrypted.
	 *
	 * @param string $slug  Distributor slug.
	 * @param string $field Field key.
	 * @param string $value Plaintext value.
	 */
	public static function save_credential( string $slug, string $field, string $value ): void {
		Crypto::update_option( self::option_name( $slug, $field ), $value, self::CRYPTO_CONTEXT );
	}

	/**
	 * Read one credential.
	 *
	 * @param string $slug  Distributor slug.
	 * @param string $field Field key.
	 */
	public static function credential( string $slug, string $field ): string {
		return Crypto::get_option( self::option_name( $slug, $field ), self::CRYPTO_CONTEXT );
	}

	/**
	 * A credential masked for display.
	 *
	 * @param string $slug  Distributor slug.
	 * @param string $field Field key.
	 */
	public static function masked_credential( string $slug, string $field ): string {
		$value = self::credential( $slug, $field );

		return '' === $value ? '' : Crypto::mask( $value );
	}

	/**
	 * Whether every credential field for a distributor has a value.
	 *
	 * @param string $slug Distributor slug.
	 */
	public static function is_configured( string $slug ): bool {
		$meta = self::known()[ $slug ] ?? null;

		if ( ! $meta ) {
			return false;
		}

		foreach ( array_keys( (array) $meta['credentials'] ) as $field ) {
			if ( '' === self::credential( $slug, (string) $field ) ) {
				return false;
			}
		}

		return true;
	}
}
