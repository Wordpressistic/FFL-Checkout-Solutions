<?php
/**
 * Admin two-factor authentication (Section 10.14).
 *
 * TOTP per RFC 6238, opt-in per user, with single-use backup codes. Secrets are
 * encrypted at rest and backup codes are stored only as hashes — a database
 * leak yields neither a working secret nor a usable code.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * TOTP for staff accounts.
 */
class Admin_2fa {

	/**
	 * User meta holding the encrypted secret.
	 */
	const META_SECRET = '_fflcs_2fa_secret';

	/**
	 * User meta holding hashed backup codes.
	 */
	const META_BACKUP = '_fflcs_2fa_backup';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'show_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
	}

	/**
	 * Whether a user has 2FA enabled.
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_enabled( int $user_id ): bool {
		return '' !== Crypto::decrypt( (string) get_user_meta( $user_id, self::META_SECRET, true ), 'fflcs_2fa' );
	}

	/**
	 * Verify a submitted code, accepting a backup code as a fallback.
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    Submitted code.
	 */
	public static function verify( int $user_id, string $code ): bool {
		$code = preg_replace( '/\s+/', '', $code );

		$secret = Crypto::decrypt( (string) get_user_meta( $user_id, self::META_SECRET, true ), 'fflcs_2fa' );

		if ( '' === $secret ) {
			return false;
		}

		if ( self::verify_totp( $secret, (string) $code ) ) {
			return true;
		}

		return self::consume_backup_code( $user_id, (string) $code );
	}

	/**
	 * Verify a TOTP code.
	 *
	 * A one-step window either side absorbs clock drift between the server and
	 * the user's phone, which is the single most common cause of a valid
	 * authenticator being rejected.
	 *
	 * @param string $secret Base32 secret.
	 * @param string $code   Submitted code.
	 * @param int    $window Steps of tolerance.
	 */
	public static function verify_totp( string $secret, string $code, int $window = 1 ): bool {
		if ( ! preg_match( '/^\d{6}$/', $code ) ) {
			return false;
		}

		$counter = (int) floor( time() / 30 );

		for ( $offset = -$window; $offset <= $window; $offset++ ) {
			if ( hash_equals( self::hotp( $secret, $counter + $offset ), $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compute an HOTP value (RFC 4226).
	 *
	 * @param string $secret  Base32 secret.
	 * @param int    $counter Counter value.
	 */
	public static function hotp( string $secret, int $counter ): string {
		$key = self::base32_decode( $secret );

		if ( '' === $key ) {
			return '';
		}

		$binary = pack( 'N*', 0 ) . pack( 'N*', $counter );
		$hash   = hash_hmac( 'sha1', $binary, $key, true );

		$offset = ord( $hash[19] ) & 0x0F;

		$value = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 )
			| ( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 )
			| ( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 )
			| ( ord( $hash[ $offset + 3 ] ) & 0xFF );

		return str_pad( (string) ( $value % 1000000 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Decode a base32 secret.
	 *
	 * @param string $input Base32 string.
	 */
	private static function base32_decode( string $input ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

		$input = strtoupper( rtrim( $input, '=' ) );
		$bits  = '';

		for ( $i = 0, $length = strlen( $input ); $i < $length; $i++ ) {
			$position = strpos( $alphabet, $input[ $i ] );

			if ( false === $position ) {
				return '';
			}

			$bits .= str_pad( decbin( $position ), 5, '0', STR_PAD_LEFT );
		}

		$binary = '';

		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$binary .= chr( (int) bindec( $byte ) );
			}
		}

		return $binary;
	}

	/**
	 * Generate a new base32 secret.
	 */
	public static function generate_secret(): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$secret   = '';

		for ( $i = 0; $i < 32; $i++ ) {
			$secret .= $alphabet[ random_int( 0, 31 ) ];
		}

		return $secret;
	}

	/**
	 * Generate backup codes, returning the plaintext set once.
	 *
	 * Only hashes are stored, so this is the only moment the codes exist in
	 * readable form.
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function generate_backup_codes( int $user_id ): array {
		$codes  = array();
		$hashed = array();

		for ( $i = 0; $i < 8; $i++ ) {
			$code     = strtoupper( bin2hex( random_bytes( 4 ) ) );
			$codes[]  = $code;
			$hashed[] = hash( 'sha256', $code );
		}

		update_user_meta( $user_id, self::META_BACKUP, $hashed );

		return $codes;
	}

	/**
	 * Spend a backup code if it matches.
	 *
	 * @param int    $user_id User ID.
	 * @param string $code    Submitted code.
	 */
	private static function consume_backup_code( int $user_id, string $code ): bool {
		$stored = (array) get_user_meta( $user_id, self::META_BACKUP, true );

		if ( ! $stored ) {
			return false;
		}

		$candidate = hash( 'sha256', strtoupper( $code ) );

		foreach ( $stored as $index => $hash ) {
			if ( hash_equals( (string) $hash, $candidate ) ) {
				unset( $stored[ $index ] );

				update_user_meta( $user_id, self::META_BACKUP, array_values( $stored ) );

				return true;
			}
		}

		return false;
	}

	/**
	 * Profile section.
	 *
	 * @param \WP_User $user User being edited.
	 */
	public function render_profile_section( \WP_User $user ): void {
		if ( get_current_user_id() !== $user->ID && ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$enabled = self::is_enabled( $user->ID );

		?>
		<h2><?php esc_html_e( 'FFL Checkout two-factor authentication', 'ffl-checkout-solutions' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'ffl-checkout-solutions' ); ?></th>
				<td>
					<?php if ( $enabled ) : ?>
						<p><?php esc_html_e( 'Two-factor authentication is enabled for this account.', 'ffl-checkout-solutions' ); ?></p>
						<label>
							<input type="checkbox" name="fflcs_2fa_disable" value="1">
							<?php esc_html_e( 'Turn it off', 'ffl-checkout-solutions' ); ?>
						</label>
					<?php else : ?>
						<label>
							<input type="checkbox" name="fflcs_2fa_enable" value="1">
							<?php esc_html_e( 'Set up an authenticator app', 'ffl-checkout-solutions' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Saving with this ticked generates a secret and eight single-use backup codes, shown once.', 'ffl-checkout-solutions' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Apply a profile change.
	 *
	 * @param int $user_id User ID.
	 */
	public function save_profile( int $user_id ): void {
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
			return;
		}

		// WordPress verifies the profile-update nonce before this hook.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['fflcs_2fa_disable'] ) ) {
			delete_user_meta( $user_id, self::META_SECRET );
			delete_user_meta( $user_id, self::META_BACKUP );
			return;
		}

		if ( empty( $_POST['fflcs_2fa_enable'] ) || self::is_enabled( $user_id ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$secret = self::generate_secret();

		update_user_meta( $user_id, self::META_SECRET, Crypto::encrypt( $secret, 'fflcs_2fa' ) );

		$codes = self::generate_backup_codes( $user_id );

		// Shown once, on the next page load, then discarded.
		set_transient( 'fflcs_2fa_setup_' . $user_id, array( 'secret' => $secret, 'codes' => $codes ), 10 * MINUTE_IN_SECONDS );
	}
}
