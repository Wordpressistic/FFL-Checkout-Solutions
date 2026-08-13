<?php
/**
 * Symmetric encryption for secrets at rest (Section 13.6).
 *
 * AES-256-GCM: authenticated, so a tampered ciphertext fails to decrypt rather
 * than silently yielding garbage that then gets sent to a distributor API as a
 * password.
 *
 * Key derivation is per-context (HKDF-ish HMAC over the site's NONCE_SALT plus
 * a caller-supplied context string), so a leaked distributor ciphertext cannot
 * be replayed as a licence key ciphertext.
 *
 * Threat model, stated plainly: this protects secrets against database-only
 * exposure — a leaked SQL dump, a backup on shared storage, a read-only SQLi.
 * It cannot protect against an attacker who already has the filesystem, because
 * the key material derives from wp-config.php salts. That is the same boundary
 * every WordPress plugin operates under; it is worth having, and it is worth
 * not overclaiming.
 *
 * @package FFLCS
 */

namespace FFLCS;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypt/decrypt helper.
 */
class Crypto {

	/**
	 * Cipher. GCM gives us authentication for free.
	 */
	const CIPHER = 'aes-256-gcm';

	/**
	 * Prefix marking a value as produced by this class, so decrypt() can pass
	 * through plaintext written before encryption was introduced instead of
	 * corrupting it.
	 */
	const PREFIX = 'fflcs1:';

	/**
	 * Encrypt a value.
	 *
	 * @param string $plaintext Value to protect.
	 * @param string $context   Key-separation context, e.g. 'fflcs_license'.
	 * @return string Prefixed base64 blob, or the input unchanged when OpenSSL
	 *                is unavailable (never silently drop the caller's data).
	 */
	public static function encrypt( string $plaintext, string $context = 'default' ): string {
		if ( '' === $plaintext || ! self::available() ) {
			return $plaintext;
		}

		$key = self::derive_key( $context );
		$iv  = random_bytes( 12 ); // 96-bit nonce, the GCM standard.
		$tag = '';

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $ciphertext ) {
			return $plaintext;
		}

		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $value   Stored value.
	 * @param string $context Same context used to encrypt.
	 * @return string Plaintext, or '' when authentication fails.
	 */
	public static function decrypt( string $value, string $context = 'default' ): string {
		if ( '' === $value ) {
			return '';
		}

		// Values written before this class existed, or on a host without
		// OpenSSL, are stored as-is.
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return $value;
		}

		if ( ! self::available() ) {
			return '';
		}

		$blob = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		if ( false === $blob || strlen( $blob ) < 29 ) {
			return '';
		}

		$iv         = substr( $blob, 0, 12 );
		$tag        = substr( $blob, 12, 16 );
		$ciphertext = substr( $blob, 28 );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, self::derive_key( $context ), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Read an encrypted option.
	 *
	 * @param string $option  Option name.
	 * @param string $context Key context.
	 */
	public static function get_option( string $option, string $context = 'default' ): string {
		return self::decrypt( (string) get_option( $option, '' ), $context );
	}

	/**
	 * Write an option encrypted.
	 *
	 * @param string $option  Option name.
	 * @param string $value   Plaintext.
	 * @param string $context Key context.
	 */
	public static function update_option( string $option, string $value, string $context = 'default' ): bool {
		return update_option( $option, self::encrypt( $value, $context ), false );
	}

	/**
	 * Mask a secret for display: first 4 and last 4 characters only.
	 *
	 * @param string $secret Secret.
	 */
	public static function mask( string $secret ): string {
		$length = strlen( $secret );
		if ( $length <= 8 ) {
			return str_repeat( '•', max( 0, $length ) );
		}
		return substr( $secret, 0, 4 ) . str_repeat( '•', min( 20, $length - 8 ) ) . substr( $secret, -4 );
	}

	/**
	 * Whether the OpenSSL extension and this cipher are available.
	 */
	public static function available(): bool {
		static $available = null;

		if ( null === $available ) {
			$available = function_exists( 'openssl_encrypt' )
				&& in_array( self::CIPHER, (array) openssl_get_cipher_methods(), true );
		}

		return $available;
	}

	/**
	 * Derive a 256-bit key for a context.
	 *
	 * @param string $context Context label.
	 */
	private static function derive_key( string $context ): string {
		$base = defined( 'NONCE_SALT' ) ? NONCE_SALT : '';
		$base .= defined( 'AUTH_SALT' ) ? AUTH_SALT : '';

		if ( '' === $base ) {
			// A site with no salts defined is misconfigured, but must not be a
			// fatal — fall back to a stored random key so encryption still
			// happens rather than silently degrading to plaintext.
			$fallback = get_option( 'fflcs_crypto_fallback_key' );
			if ( ! $fallback ) {
				$fallback = wp_generate_password( 64, true, true );
				update_option( 'fflcs_crypto_fallback_key', $fallback, false );
			}
			$base = (string) $fallback;
		}

		return hash_hmac( 'sha256', 'fflcs|' . $context, $base, true );
	}
}
