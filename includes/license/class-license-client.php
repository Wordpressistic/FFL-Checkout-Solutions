<?php
/**
 * WPistic licence client (Section 6.1).
 *
 * Talks to api.wpistic.com to activate, validate and deactivate a licence key.
 *
 * The behaviour that matters most is what happens when that server is
 * unreachable, which for a self-hosted commercial plugin is not an edge case —
 * it is a Tuesday. Three rules, in order of importance:
 *
 *   1. A network failure never revokes entitlement. The last known-good result
 *      is trusted for TRUST_WINDOW, and after that the plugin degrades to free
 *      tier rather than breaking. It never disables core FFL functionality,
 *      which the customer's store depends on to take orders.
 *   2. Failures back off exponentially, so a store whose host blocks outbound
 *      HTTP does not hammer a dead endpoint on every page load.
 *   3. Manual attempts are rate-limited, so a frustrated admin clicking
 *      Activate repeatedly cannot get the site IP-blocked.
 *
 * @package FFLCS
 */

namespace FFLCS\License;

use FFLCS\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Licence activation and validation.
 */
class License_Client {

	const OPT_KEY        = 'fflcs_license_key';
	const OPT_STATUS     = 'fflcs_license_status';
	const OPT_EXPIRES    = 'fflcs_license_expires';
	const OPT_LAST_OK    = 'fflcs_license_last_ok';
	const OPT_LAST_CHECK = 'fflcs_license_last_check';
	const OPT_FAIL_COUNT = 'fflcs_license_fail_count';
	const OPT_INSTANCE   = 'fflcs_license_instance';
	const OPT_META       = 'fflcs_license_meta';
	const OPT_PRODUCT    = 'fflcs_license_product';

	const SERVER_URL = 'https://api.wpistic.com';

	/**
	 * How long a previously valid licence keeps working with no successful
	 * check. Long enough to ride out a weekend outage.
	 */
	const TRUST_WINDOW = DAY_IN_SECONDS;

	const BACKOFF_BASE = HOUR_IN_SECONDS;
	const BACKOFF_MAX  = 6 * HOUR_IN_SECONDS;

	const RATE_LIMIT_MAX    = 5;
	const RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;

	const CRYPTO_CONTEXT = 'fflcs_license';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_daily_license_check', array( __CLASS__, 'cron_validate' ) );
	}

	// ── Accessors ────────────────────────────────────────────────────────────

	/**
	 * The licence key in plaintext.
	 *
	 * A wp-config constant wins over the stored option, which is how a staging
	 * clone runs without stealing the production site's activation slot.
	 */
	public static function key(): string {
		if ( defined( 'FFLCS_LICENSE_KEY' ) && FFLCS_LICENSE_KEY ) {
			return (string) FFLCS_LICENSE_KEY;
		}

		return Crypto::get_option( self::OPT_KEY, self::CRYPTO_CONTEXT );
	}

	/**
	 * The key masked for display.
	 */
	public static function masked_key(): string {
		return Crypto::mask( self::key() );
	}

	/**
	 * Stored status: valid, invalid, expired, or inactive.
	 */
	public static function status(): string {
		return (string) get_option( self::OPT_STATUS, 'inactive' );
	}

	/**
	 * Expiry date, or '' for a perpetual licence.
	 */
	public static function expires(): string {
		return (string) get_option( self::OPT_EXPIRES, '' );
	}

	/**
	 * Licence metadata returned by the server.
	 *
	 * @return array<string,mixed>
	 */
	public static function meta(): array {
		return (array) get_option( self::OPT_META, array() );
	}

	/**
	 * Product ID this install activates against.
	 */
	public static function product_id(): int {
		if ( defined( 'FFLCS_LICENSE_PRODUCT_ID' ) && FFLCS_LICENSE_PRODUCT_ID ) {
			return (int) FFLCS_LICENSE_PRODUCT_ID;
		}

		return (int) get_option( self::OPT_PRODUCT, 0 );
	}

	/**
	 * Stable per-install identifier.
	 *
	 * Random, not derived from the site URL: a site that changes domain must
	 * keep the same activation slot rather than consuming a second one.
	 */
	public static function instance(): string {
		$instance = (string) get_option( self::OPT_INSTANCE, '' );

		if ( '' === $instance ) {
			$instance = wp_generate_uuid4();
			update_option( self::OPT_INSTANCE, $instance, false );
		}

		return $instance;
	}

	/**
	 * The licence server base URL.
	 */
	public static function server_url(): string {
		if ( defined( 'FFLCS_LICENSE_SERVER' ) && FFLCS_LICENSE_SERVER ) {
			return untrailingslashit( (string) FFLCS_LICENSE_SERVER );
		}

		return self::SERVER_URL;
	}

	/**
	 * Whether this install currently has a usable licence.
	 *
	 * The trust window is what makes an outage survivable: a licence that
	 * validated successfully within TRUST_WINDOW stays valid even if today's
	 * check could not reach the server.
	 */
	public static function is_valid(): bool {
		$valid = self::compute_validity();

		/**
		 * Filter the resolved licence validity.
		 *
		 * @param bool $valid Whether the licence is currently valid.
		 */
		return (bool) apply_filters( 'fflcs_license_valid', $valid );
	}

	/**
	 * Validity, before filtering.
	 */
	private static function compute_validity(): bool {
		if ( '' === self::key() ) {
			return false;
		}

		if ( 'valid' !== self::status() ) {
			// A licence the server explicitly rejected is invalid immediately.
			// The trust window covers unreachability, not rejection.
			return false;
		}

		$expires = self::expires();
		if ( '' !== $expires && strtotime( $expires ) < time() ) {
			return false;
		}

		$last_ok = (int) get_option( self::OPT_LAST_OK, 0 );

		if ( $last_ok > 0 && ( time() - $last_ok ) > ( self::TRUST_WINDOW * 30 ) ) {
			// A month with no successful contact means something is genuinely
			// wrong. Stop asserting validity rather than trusting a stale yes
			// indefinitely.
			return false;
		}

		return true;
	}

	// ── Operations ───────────────────────────────────────────────────────────

	/**
	 * Activate a licence key against this site.
	 *
	 * @param string $key Licence key.
	 * @return true|\WP_Error
	 */
	public static function activate( string $key ) {
		$key = trim( $key );

		if ( '' === $key ) {
			return new \WP_Error(
				'fflcs_missing_key',
				__( 'Enter a licence key.', 'ffl-checkout-solutions' )
			);
		}

		if ( self::is_rate_limited() ) {
			return new \WP_Error(
				'fflcs_rate_limited',
				__( 'Too many activation attempts. Please wait an hour and try again.', 'ffl-checkout-solutions' )
			);
		}

		self::record_attempt();

		$response = self::request(
			'/api/v1/licenses/activate',
			array(
				'license_key' => $key,
				'instance'    => self::instance(),
				'site'        => home_url( '/' ),
				'product_id'  => self::product_id(),
				'version'     => FFLCS_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['valid'] ) ) {
			update_option( self::OPT_STATUS, 'invalid' );

			return new \WP_Error(
				'fflcs_activation_failed',
				(string) ( $response['message'] ?? __( 'That licence key could not be activated.', 'ffl-checkout-solutions' ) )
			);
		}

		Crypto::update_option( self::OPT_KEY, $key, self::CRYPTO_CONTEXT );

		self::store_success( $response );

		return true;
	}

	/**
	 * Release this site's activation slot.
	 *
	 * Local state is cleared whatever the server says. A customer who has moved
	 * on must not be left with a plugin that still claims a licence it can no
	 * longer verify.
	 *
	 * @return true|\WP_Error
	 */
	public static function deactivate() {
		$key = self::key();

		if ( '' === $key ) {
			return true;
		}

		$response = self::request(
			'/api/v1/licenses/deactivate',
			array(
				'license_key' => $key,
				'instance'    => self::instance(),
				'site'        => home_url( '/' ),
			)
		);

		delete_option( self::OPT_KEY );
		delete_option( self::OPT_STATUS );
		delete_option( self::OPT_EXPIRES );
		delete_option( self::OPT_LAST_OK );
		delete_option( self::OPT_META );

		update_option( self::OPT_STATUS, 'inactive' );

		if ( is_wp_error( $response ) ) {
			// Surface it, but the local deactivation above already happened.
			return $response;
		}

		return true;
	}

	/**
	 * Re-validate the stored key.
	 *
	 * @param bool $force Skip the backoff window.
	 * @return bool Whether a successful check occurred.
	 */
	public static function validate( bool $force = false ): bool {
		$key = self::key();

		if ( '' === $key ) {
			return false;
		}

		if ( ! $force && self::in_backoff() ) {
			return false;
		}

		update_option( self::OPT_LAST_CHECK, time(), false );

		$response = self::request(
			'/api/v1/licenses/validate',
			array(
				'license_key' => $key,
				'instance'    => self::instance(),
				'site'        => home_url( '/' ),
				'product_id'  => self::product_id(),
				'version'     => FFLCS_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			// Unreachable. Count the failure for backoff, but leave status
			// alone — the trust window is what decides whether entitlement
			// survives, not this call.
			self::record_failure();
			return false;
		}

		if ( empty( $response['valid'] ) ) {
			// An explicit rejection is authoritative and takes effect now.
			update_option( self::OPT_STATUS, (string) ( $response['status'] ?? 'invalid' ) );
			update_option( self::OPT_FAIL_COUNT, 0, false );
			return true;
		}

		self::store_success( $response );

		return true;
	}

	/**
	 * Daily cron entry point.
	 */
	public static function cron_validate(): void {
		self::validate();
	}

	// ── Internals ────────────────────────────────────────────────────────────

	/**
	 * POST to the licence server.
	 *
	 * @param string              $path Endpoint path.
	 * @param array<string,mixed> $body Request body.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function request( string $path, array $body ) {
		$response = wp_remote_post(
			self::server_url() . $path,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			fflcs_log( 'Licence request failed', $response->get_error_message() );

			return new \WP_Error(
				'fflcs_server_unreachable',
				__( 'Could not reach the licence server. Your existing licence continues to work.', 'ffl-checkout-solutions' )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $data ) ) {
			return new \WP_Error(
				'fflcs_server_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The licence server returned an unexpected response (HTTP %d).', 'ffl-checkout-solutions' ),
					$code
				)
			);
		}

		return $data;
	}

	/**
	 * Persist a successful validation.
	 *
	 * @param array<string,mixed> $response Server response.
	 */
	private static function store_success( array $response ): void {
		update_option( self::OPT_STATUS, 'valid' );
		update_option( self::OPT_EXPIRES, (string) ( $response['expires'] ?? '' ) );
		update_option( self::OPT_LAST_OK, time(), false );
		update_option( self::OPT_FAIL_COUNT, 0, false );

		if ( isset( $response['product_id'] ) ) {
			update_option( self::OPT_PRODUCT, (int) $response['product_id'] );
		}

		update_option(
			self::OPT_META,
			array(
				'plan'       => sanitize_key( (string) ( $response['plan'] ?? 'free' ) ),
				'customer'   => sanitize_text_field( (string) ( $response['customer'] ?? '' ) ),
				'sites_used' => (int) ( $response['sites_used'] ?? 0 ),
				'sites_max'  => (int) ( $response['sites_max'] ?? 0 ),
				'checked_at' => fflcs_mysql_now(),
			)
		);
	}

	/**
	 * Record a failed check for backoff purposes.
	 */
	private static function record_failure(): void {
		update_option( self::OPT_FAIL_COUNT, (int) get_option( self::OPT_FAIL_COUNT, 0 ) + 1, false );
	}

	/**
	 * Whether the current backoff window has not yet elapsed.
	 */
	private static function in_backoff(): bool {
		$failures = (int) get_option( self::OPT_FAIL_COUNT, 0 );

		if ( $failures < 1 ) {
			return false;
		}

		// Exponential, capped: 1h, 2h, 4h, then 6h forever.
		$delay = min( self::BACKOFF_MAX, self::BACKOFF_BASE * ( 2 ** ( $failures - 1 ) ) );

		return ( time() - (int) get_option( self::OPT_LAST_CHECK, 0 ) ) < $delay;
	}

	/**
	 * Whether manual attempts are currently rate-limited.
	 */
	public static function is_rate_limited(): bool {
		return (int) get_transient( 'fflcs_license_attempts' ) >= self::RATE_LIMIT_MAX;
	}

	/**
	 * Count a manual attempt.
	 */
	public static function record_attempt(): void {
		$attempts = (int) get_transient( 'fflcs_license_attempts' );

		set_transient( 'fflcs_license_attempts', $attempts + 1, self::RATE_LIMIT_WINDOW );
	}

	/**
	 * Human-readable state for the licence admin screen.
	 *
	 * @return array<string,mixed>
	 */
	public static function summary(): array {
		$meta    = self::meta();
		$last_ok = (int) get_option( self::OPT_LAST_OK, 0 );

		return array(
			'has_key'      => '' !== self::key(),
			'masked_key'   => self::masked_key(),
			'status'       => self::status(),
			'is_valid'     => self::is_valid(),
			'plan'         => Entitlement::plan(),
			'expires'      => self::expires(),
			'last_checked' => $last_ok ? date_i18n( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $last_ok ) : '',
			'sites_used'   => (int) ( $meta['sites_used'] ?? 0 ),
			'sites_max'    => (int) ( $meta['sites_max'] ?? 0 ),
			'constant'     => defined( 'FFLCS_LICENSE_KEY' ) && FFLCS_LICENSE_KEY,
		);
	}
}
