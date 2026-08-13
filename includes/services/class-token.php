<?php
/**
 * HMAC-signed, single-use dealer portal tokens (Section 13.1).
 *
 * Design rules, all of which are load-bearing:
 *   - The raw token is never persisted. Only its SHA-256 hash goes in the DB,
 *     so a database leak does not hand an attacker working magic links.
 *   - Signature comparison uses hash_equals(). A byte-by-byte early-exit
 *     comparison leaks the correct prefix through timing.
 *   - Expiry and single-use are enforced in the database as well as in the
 *     signed payload. The payload alone would be enough only if we trusted the
 *     clock and never needed revocation; we need both.
 *   - Issuing a new token for a transfer revokes the previous one, so a
 *     forwarded old email cannot be used after a re-send.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Portal token minting and verification.
 */
class Token {

	const DEFAULT_ACTION      = 'mark_received';
	const DEFAULT_EXPIRY_DAYS = 30;
	const TOKEN_VERSION       = 'v1';

	/**
	 * Expiry windows offered in the admin UI (Section 9.5).
	 *
	 * @var int[]
	 */
	const EXPIRY_CHOICES = array( 7, 14, 30, 60, 90, 180 );

	/**
	 * The HMAC signing secret.
	 *
	 * Prefers a wp-config.php constant so the secret can live outside the
	 * database entirely; falls back to a generated option.
	 */
	public static function secret(): string {
		if ( defined( 'FFLCS_TOKEN_SECRET' ) && FFLCS_TOKEN_SECRET ) {
			return (string) FFLCS_TOKEN_SECRET;
		}

		$secret = get_option( 'fflcs_token_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'fflcs_token_secret', $secret, false );
		}

		return (string) $secret;
	}

	/**
	 * Mint a token and persist its hash.
	 *
	 * @param int    $transfer_id Transfer the token authorises.
	 * @param int    $dealer_id   Dealer expected to use it.
	 * @param string $action      Action authorised.
	 * @param int    $expiry_days Override the configured expiry.
	 * @return string|\WP_Error Raw token (email it, never store it) or error.
	 */
	public static function generate( int $transfer_id, int $dealer_id, string $action = self::DEFAULT_ACTION, int $expiry_days = 0 ) {
		global $wpdb;

		if ( $transfer_id <= 0 || $dealer_id <= 0 ) {
			return new \WP_Error( 'fflcs_invalid_args', __( 'A transfer and dealer are required to issue a token.', 'ffl-checkout-solutions' ) );
		}

		$expiry_days = $expiry_days ?: (int) get_option( 'fflcs_portal_token_expiry_days', self::DEFAULT_EXPIRY_DAYS );
		if ( $expiry_days < 1 ) {
			$expiry_days = self::DEFAULT_EXPIRY_DAYS;
		}

		$expires = time() + ( $expiry_days * DAY_IN_SECONDS );
		$nonce   = bin2hex( random_bytes( 8 ) );

		// Payload: version.transfer.dealer.action.expiry.nonce
		$payload   = implode( '.', array( self::TOKEN_VERSION, $transfer_id, $dealer_id, $action, $expires, $nonce ) );
		$signature = self::sign( $payload );
		$raw       = self::base64url_encode( $payload . '.' . $signature );

		$table = fflcs_table( 'dealer_tokens' );

		// One active token per transfer+action. A resend supersedes the old
		// link rather than leaving two valid ones in two inboxes.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET status = %s WHERE transfer_id = %d AND action = %s AND status = %s",
				'revoked',
				$transfer_id,
				$action,
				'active'
			)
		);

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'transfer_id'       => $transfer_id,
				'dealer_id'         => $dealer_id,
				'token_hash'        => hash( 'sha256', $raw ),
				'action'            => $action,
				'token_expires'     => gmdate( 'Y-m-d H:i:s', $expires ),
				'two_factor_method' => (string) get_option( 'fflcs_portal_two_factor', 'none' ),
				'status'            => 'active',
				'created_ip'        => self::client_ip(),
				'created_at'        => fflcs_mysql_now(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'fflcs_db_error', __( 'Could not store the confirmation token.', 'ffl-checkout-solutions' ) );
		}

		Analytics::record( 'token_issued', $transfer_id, $dealer_id );

		return $raw;
	}

	/**
	 * Verify a raw token without consuming it.
	 *
	 * Verification order matters: signature first (cheap, constant-time, and
	 * rejects forged input before it can touch the database), then expiry, then
	 * the stored row's state.
	 *
	 * @param string $raw Raw token from the URL.
	 * @return array{row:object,payload:array}|\WP_Error
	 */
	public static function verify( string $raw ) {
		global $wpdb;

		$raw = trim( $raw );
		if ( '' === $raw ) {
			return new \WP_Error( 'fflcs_missing_token', __( 'Missing confirmation token.', 'ffl-checkout-solutions' ), array( 'status' => 400 ) );
		}

		$decoded = self::base64url_decode( $raw );
		if ( false === $decoded ) {
			return new \WP_Error( 'fflcs_invalid_token', __( 'Invalid confirmation token.', 'ffl-checkout-solutions' ), array( 'status' => 400 ) );
		}

		$parts = explode( '.', $decoded );
		if ( 7 !== count( $parts ) ) {
			return new \WP_Error( 'fflcs_invalid_token', __( 'Malformed confirmation token.', 'ffl-checkout-solutions' ), array( 'status' => 400 ) );
		}

		list( $version, $transfer_id, $dealer_id, $action, $expires, $nonce, $signature ) = $parts;

		if ( self::TOKEN_VERSION !== $version ) {
			return new \WP_Error( 'fflcs_invalid_token', __( 'Unsupported token version.', 'ffl-checkout-solutions' ), array( 'status' => 400 ) );
		}

		$payload  = implode( '.', array( $version, $transfer_id, $dealer_id, $action, $expires, $nonce ) );
		$expected = self::sign( $payload );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new \WP_Error( 'fflcs_invalid_signature', __( 'Invalid token signature.', 'ffl-checkout-solutions' ), array( 'status' => 403 ) );
		}

		if ( (int) $expires < time() ) {
			return new \WP_Error( 'fflcs_token_expired', __( 'This confirmation link has expired. Please ask the store for a new one.', 'ffl-checkout-solutions' ), array( 'status' => 410 ) );
		}

		$table = fflcs_table( 'dealer_tokens' );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1",
				hash( 'sha256', $raw )
			)
		);

		if ( ! $row ) {
			return new \WP_Error( 'fflcs_not_found', __( 'This confirmation link is not recognised.', 'ffl-checkout-solutions' ), array( 'status' => 404 ) );
		}

		if ( 'revoked' === $row->status ) {
			return new \WP_Error( 'fflcs_token_revoked', __( 'This confirmation link has been replaced by a newer one.', 'ffl-checkout-solutions' ), array( 'status' => 410 ) );
		}

		if ( 'used' === $row->status || ! empty( $row->used_at ) ) {
			return new \WP_Error(
				'fflcs_token_used',
				__( 'This link has already been used.', 'ffl-checkout-solutions' ),
				array(
					'status'      => 409,
					'already_used' => true,
					'used_at'     => $row->used_at,
					'used_action' => $row->used_action,
					'transfer_id' => (int) $row->transfer_id,
				)
			);
		}

		return array(
			'row'     => $row,
			'payload' => array(
				'version'     => $version,
				'transfer_id' => (int) $transfer_id,
				'dealer_id'   => (int) $dealer_id,
				'action'      => $action,
				'expires_at'  => (int) $expires,
				'nonce'       => $nonce,
			),
		);
	}

	/**
	 * Mark a token used and record who used it.
	 *
	 * @param int    $token_id    Token row ID.
	 * @param string $used_action Action actually taken.
	 */
	public static function consume( int $token_id, string $used_action ): bool {
		global $wpdb;

		$single_use = '1' === (string) get_option( 'fflcs_portal_single_use', '1' );

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'dealer_tokens' ),
			array(
				'used_at'     => fflcs_mysql_now(),
				'used_action' => $used_action,
				'used_ip'     => self::client_ip(),
				'used_ua'     => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'status'      => $single_use ? 'used' : 'active',
			),
			array( 'id' => $token_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Revoke every active token for a transfer.
	 *
	 * Called when the dealer changes or the transfer is cancelled — the old
	 * dealer must lose the ability to confirm receipt.
	 *
	 * @param int $transfer_id Transfer ID.
	 * @return int Rows affected.
	 */
	public static function revoke_all_for_transfer( int $transfer_id ): int {
		global $wpdb;

		$table = fflcs_table( 'dealer_tokens' );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET status = %s WHERE transfer_id = %d AND status = %s",
				'revoked',
				$transfer_id,
				'active'
			)
		);
	}

	/**
	 * The current active token row for a transfer, if any.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $action      Action.
	 */
	public static function active_for_transfer( int $transfer_id, string $action = self::DEFAULT_ACTION ): ?object {
		global $wpdb;

		$table = fflcs_table( 'dealer_tokens' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE transfer_id = %d AND action = %s AND status = %s ORDER BY created_at DESC LIMIT 1",
				$transfer_id,
				$action,
				'active'
			)
		);

		return $row ?: null;
	}

	/**
	 * Public portal URL for a raw token.
	 *
	 * @param string $raw Raw token.
	 */
	public static function build_url( string $raw ): string {
		$slug = trim( (string) get_option( 'fflcs_portal_slug', FFLCS_PORTAL_SLUG ), '/' );
		if ( '' === $slug ) {
			$slug = FFLCS_PORTAL_SLUG;
		}

		return trailingslashit( home_url( '/' . $slug . '/' ) ) . rawurlencode( $raw ) . '/';
	}

	// ── Signed non-token links (tracking page) ───────────────────────────────

	/**
	 * Signature for a public tracking URL (Section 9.8).
	 *
	 * Not a stored token: the tracking page is read-only and shareable by the
	 * customer, so it needs unguessability rather than revocation. Truncated to
	 * 32 hex characters — 128 bits, far past brute force, and short enough that
	 * the URL stays usable in an SMS.
	 *
	 * @param string $reference Transfer reference.
	 */
	public static function tracking_signature( string $reference ): string {
		return substr( hash_hmac( 'sha256', 'track|' . $reference, self::secret() ), 0, 32 );
	}

	/**
	 * Constant-time check of a tracking signature.
	 *
	 * @param string $reference Transfer reference.
	 * @param string $signature Signature from the URL.
	 */
	public static function verify_tracking_signature( string $reference, string $signature ): bool {
		return hash_equals( self::tracking_signature( $reference ), $signature );
	}

	// ── Rate limiting (Section 13.2) ─────────────────────────────────────────

	/**
	 * Fixed-window per-IP rate limiter backed by transients.
	 *
	 * Returns true when the caller is over budget. Windowed rather than a
	 * token bucket because transients are the only storage guaranteed present
	 * on every host, and a fixed window is the honest thing to implement on
	 * top of them.
	 *
	 * @param string $bucket Action name.
	 * @param int    $limit  Requests permitted per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function is_rate_limited( string $bucket, int $limit, int $window = MINUTE_IN_SECONDS ): bool {
		$key  = 'fflcs_rl_' . $bucket . '_' . wp_hash( self::client_ip() );
		$hits = (int) get_transient( $key );

		if ( $hits >= $limit ) {
			return true;
		}

		set_transient( $key, $hits + 1, $window );

		return false;
	}

	/**
	 * Build the 429 response for a rate-limited request.
	 *
	 * @param int $retry_after Seconds until the window resets.
	 */
	public static function rate_limit_error( int $retry_after = 60 ): \WP_Error {
		return new \WP_Error(
			'fflcs_rate_limited',
			__( 'Too many requests. Please wait a moment and try again.', 'ffl-checkout-solutions' ),
			array(
				'status'  => 429,
				'headers' => array( 'Retry-After' => (string) $retry_after ),
			)
		);
	}

	// ── IP resolution (Section 13.3) ─────────────────────────────────────────

	/**
	 * Client IP, honouring proxy headers only from trusted proxies.
	 *
	 * Defaults to trusting nothing. A site behind Cloudflare or a load balancer
	 * opts in via the fflcs_trusted_proxies filter with CIDR ranges, or '*' to
	 * trust every upstream. Without this, any visitor could forge
	 * X-Forwarded-For to evade rate limits and forge audit-log entries.
	 */
	public static function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		/**
		 * Filter the trusted proxy list.
		 *
		 * @param array $proxies CIDR strings, bare IPs, or '*' to trust all.
		 */
		$trusted = (array) apply_filters( 'fflcs_trusted_proxies', array() );

		if ( in_array( '*', $trusted, true ) || self::ip_in_cidr_list( $remote, $trusted ) ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) {
					continue;
				}
				$candidate = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				if ( false !== strpos( $candidate, ',' ) ) {
					$candidate = trim( explode( ',', $candidate )[0] );
				}
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
	}

	/**
	 * Whether an IP falls inside any of the given CIDR ranges (v4 or v6).
	 *
	 * @param string   $ip    Candidate IP.
	 * @param string[] $cidrs Ranges.
	 */
	private static function ip_in_cidr_list( string $ip, array $cidrs ): bool {
		if ( '' === $ip || empty( $cidrs ) ) {
			return false;
		}

		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $packed ) {
			return false;
		}

		foreach ( $cidrs as $cidr ) {
			if ( '*' === $cidr ) {
				return true;
			}

			if ( false === strpos( (string) $cidr, '/' ) ) {
				if ( $packed === @inet_pton( (string) $cidr ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return true;
				}
				continue;
			}

			list( $subnet, $bits ) = explode( '/', (string) $cidr, 2 );

			$subnet_packed = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $subnet_packed || strlen( $subnet_packed ) !== strlen( $packed ) ) {
				continue;
			}

			$bits       = (int) $bits;
			$whole      = intdiv( $bits, 8 );
			$remainder  = $bits % 8;

			if ( substr( $packed, 0, $whole ) !== substr( $subnet_packed, 0, $whole ) ) {
				continue;
			}

			if ( 0 === $remainder ) {
				return true;
			}

			$mask = chr( ( 0xFF << ( 8 - $remainder ) ) & 0xFF );
			if ( ( $packed[ $whole ] & $mask ) === ( $subnet_packed[ $whole ] & $mask ) ) {
				return true;
			}
		}

		return false;
	}

	// ── Internals ────────────────────────────────────────────────────────────

	/**
	 * HMAC-SHA256 over the payload.
	 *
	 * @param string $payload Payload string.
	 */
	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, self::secret() );
	}

	/**
	 * URL-safe base64 encode.
	 *
	 * @param string $data Raw data.
	 */
	public static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * URL-safe base64 decode.
	 *
	 * @param string $data Encoded data.
	 * @return string|false
	 */
	public static function base64url_decode( string $data ) {
		$data = strtr( $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $data, true );
	}
}
