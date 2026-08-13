<?php
/**
 * Signed public URL builders.
 *
 * Kept separate from Token because these links are not tokens: they are not
 * stored, not single-use and not revocable. They are HMAC-signed so the
 * reference cannot be guessed or enumerated, which is the right level of
 * protection for a read-only page the customer is expected to bookmark and
 * revisit.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and validates signed public URLs.
 */
class Tracking_Links {

	/**
	 * Rewrite slug for the customer tracking page.
	 */
	const TRACK_SLUG = 'track-transfer';

	/**
	 * Rewrite slug for the 4473 worksheet draft.
	 */
	const FORM_SLUG = 'ffl-4473-draft';

	/**
	 * Customer tracking URL for a transfer reference.
	 *
	 * @param string $reference Transfer reference.
	 */
	public static function customer_url( string $reference ): string {
		if ( '' === $reference ) {
			return '';
		}

		return trailingslashit( home_url( '/' . self::TRACK_SLUG . '/' ) )
			. rawurlencode( $reference ) . '/'
			. Token::tracking_signature( $reference ) . '/';
	}

	/**
	 * Validate the reference/signature pair from a tracking request.
	 *
	 * @param string $reference Transfer reference.
	 * @param string $signature Signature.
	 */
	public static function verify( string $reference, string $signature ): bool {
		return Token::verify_tracking_signature( $reference, $signature );
	}

	/**
	 * Admin-only URL for the 4473 worksheet draft.
	 *
	 * Signed as well as capability-gated. The signature stops a staff member
	 * from walking transfer IDs by hand, and the capability check on the
	 * receiving end is what actually authorises the request.
	 *
	 * @param int $transfer_id Transfer ID.
	 */
	public static function form_4473_url( int $transfer_id ): string {
		$signature = substr(
			hash_hmac( 'sha256', '4473|' . $transfer_id, Token::secret() ),
			0,
			32
		);

		return trailingslashit( home_url( '/' . self::FORM_SLUG . '/' ) )
			. $transfer_id . '/' . $signature . '/';
	}

	/**
	 * Validate a 4473 worksheet signature.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $signature   Signature.
	 */
	public static function verify_form_4473( int $transfer_id, string $signature ): bool {
		$expected = substr(
			hash_hmac( 'sha256', '4473|' . $transfer_id, Token::secret() ),
			0,
			32
		);

		return hash_equals( $expected, $signature );
	}

	/**
	 * Register the plugin's public rewrite rules.
	 *
	 * Called on init and again from the activator, because rules added on init
	 * alone are not persisted until something flushes them.
	 */
	public static function register_rewrites(): void {
		add_rewrite_rule(
			'^' . self::TRACK_SLUG . '/([^/]+)/([^/]+)/?$',
			'index.php?fflcs_track_ref=$matches[1]&fflcs_track_sig=$matches[2]',
			'top'
		);

		add_rewrite_rule(
			'^' . self::FORM_SLUG . '/([0-9]+)/([^/]+)/?$',
			'index.php?fflcs_form_id=$matches[1]&fflcs_form_sig=$matches[2]',
			'top'
		);

		$portal_slug = trim( (string) get_option( 'fflcs_portal_slug', FFLCS_PORTAL_SLUG ), '/' );
		if ( '' === $portal_slug ) {
			$portal_slug = FFLCS_PORTAL_SLUG;
		}

		add_rewrite_rule(
			'^' . preg_quote( $portal_slug, '#' ) . '/([^/]+)/?$',
			'index.php?fflcs_portal_token=$matches[1]',
			'top'
		);
	}

	/**
	 * Register the query vars the rewrites above populate.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public static function register_query_vars( array $vars ): array {
		return array_merge(
			$vars,
			array(
				'fflcs_track_ref',
				'fflcs_track_sig',
				'fflcs_form_id',
				'fflcs_form_sig',
				'fflcs_portal_token',
			)
		);
	}
}
