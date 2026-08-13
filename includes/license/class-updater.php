<?php
/**
 * Self-hosted update mechanism (Section 7).
 *
 * This plugin is not on WordPress.org, so core's update checker will never see
 * it. Without this class every install goes stale the moment it ships — no
 * security patches, no fixes, ever, short of a manual re-upload. For commercial
 * software that is not acceptable.
 *
 * Hooks into the same transient system WP.org plugins use, pointed at
 * api.wpistic.com.
 *
 * Two behaviours are deliberate and worth stating plainly:
 *
 *   An unlicensed site is told it is up to date. Not "an update exists but you
 *   cannot have it" — dangling a patch a customer cannot pull is worse than
 *   silence, and the nag would be unactionable.
 *
 *   An unreachable update server is silent. It never blocks, never warns, never
 *   degrades the plugin. The site keeps running the version it has.
 *
 * @package FFLCS
 */

namespace FFLCS\License;

defined( 'ABSPATH' ) || exit;

/**
 * Update checks against the WPistic release endpoint.
 */
class Updater {

	const CHECK_TRANSIENT = 'fflcs_update_check';

	/**
	 * How long a check is cached. Twice a day is plenty for a plugin that
	 * ships every few weeks, and it keeps the endpoint's load predictable.
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'authorise_download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );
	}

	/**
	 * The release endpoint.
	 */
	public static function endpoint(): string {
		return License_Client::server_url() . '/api/v1/plugins/ffl-checkout-solutions/latest';
	}

	// ── Update injection ─────────────────────────────────────────────────────

	/**
	 * Add this plugin to WordPress's update payload when a newer version exists.
	 *
	 * @param mixed $transient The update_plugins transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// An unlicensed site gets no update offer at all — see the class docblock.
		if ( ! License_Client::is_valid() ) {
			return $transient;
		}

		$release = $this->get_release();

		if ( ! $release || empty( $release['version'] ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$item = (object) array(
			'id'            => 'fflcs/ffl-checkout-solutions',
			'slug'          => 'ffl-checkout-solutions',
			'plugin'        => FFLCS_PLUGIN_BASENAME,
			'new_version'   => (string) $release['version'],
			'url'           => 'https://fflistic.com',
			'package'       => (string) ( $release['download_url'] ?? '' ),
			'tested'        => (string) ( $release['tested'] ?? '' ),
			'requires'      => (string) ( $release['requires'] ?? '6.4' ),
			'requires_php'  => (string) ( $release['requires_php'] ?? '8.1' ),
			'icons'         => array(),
			'banners'       => array(),
		);

		if ( version_compare( (string) $release['version'], FFLCS_VERSION, '>' ) ) {
			$transient->response[ FFLCS_PLUGIN_BASENAME ] = $item;
			unset( $transient->no_update[ FFLCS_PLUGIN_BASENAME ] );
		} else {
			// Listing under no_update is what makes "Check again" and the
			// plugin row report a real state instead of an unknown one.
			unset( $transient->response[ FFLCS_PLUGIN_BASENAME ] );
			$transient->no_update[ FFLCS_PLUGIN_BASENAME ] = $item;
		}

		return $transient;
	}

	/**
	 * Serve the "View version details" modal from our own release data.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action plugins_api action.
	 * @param mixed  $args   Request args.
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || 'ffl-checkout-solutions' !== $args->slug ) {
			return $result;
		}

		$release = $this->get_release();

		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'FFL Checkout Solutions',
			'slug'          => 'ffl-checkout-solutions',
			'version'       => (string) ( $release['version'] ?? FFLCS_VERSION ),
			'author'        => '<a href="https://wordpressistic.com">WordPressistic</a>',
			'homepage'      => 'https://fflistic.com',
			'requires'      => (string) ( $release['requires'] ?? '6.4' ),
			'tested'        => (string) ( $release['tested'] ?? '' ),
			'requires_php'  => (string) ( $release['requires_php'] ?? '8.1' ),
			'last_updated'  => (string) ( $release['last_updated'] ?? '' ),
			'download_link' => (string) ( $release['download_url'] ?? '' ),
			'sections'      => array(
				'description' => (string) ( $release['description'] ?? __( 'Complete FFL dealer management, WooCommerce checkout and transfer lifecycle tracking.', 'ffl-checkout-solutions' ) ),
				'changelog'   => wp_kses_post( (string) ( $release['changelog'] ?? '' ) ),
			),
		);
	}

	/**
	 * Attach the licence key to the package download request.
	 *
	 * The download URL from the server should already be short-lived and
	 * signed; the key is attached as belt-and-braces so a leaked URL alone does
	 * not hand out the paid ZIP.
	 *
	 * @param mixed  $reply   Short-circuit value.
	 * @param string $package Package URL.
	 * @param mixed  $upgrader Upgrader instance.
	 * @return mixed
	 */
	public function authorise_download( $reply, $package, $upgrader ) {
		if ( ! is_string( $package ) || '' === $package ) {
			return $reply;
		}

		// Only touch our own downloads.
		if ( false === strpos( $package, wp_parse_url( License_Client::server_url(), PHP_URL_HOST ) ?: 'api.wpistic.com' ) ) {
			return $reply;
		}

		$key = License_Client::key();

		if ( '' === $key ) {
			return $reply;
		}

		add_filter(
			'http_request_args',
			static function ( array $args, string $url ) use ( $package, $key ): array {
				if ( $url === $package ) {
					$args['headers']['X-Fflcs-License'] = $key;
					$args['headers']['X-Fflcs-Site']    = home_url( '/' );
				}
				return $args;
			},
			10,
			2
		);

		return $reply;
	}

	/**
	 * Re-check the licence after a successful self-update.
	 *
	 * @param mixed $upgrader Upgrader instance.
	 * @param array $extra    Update context.
	 */
	public function after_update( $upgrader, $extra ): void {
		if ( ! is_array( $extra ) || 'update' !== ( $extra['action'] ?? '' ) || 'plugin' !== ( $extra['type'] ?? '' ) ) {
			return;
		}

		$plugins = (array) ( $extra['plugins'] ?? array() );

		if ( ! in_array( FFLCS_PLUGIN_BASENAME, $plugins, true ) ) {
			return;
		}

		delete_transient( self::CHECK_TRANSIENT );

		// A new version may add tables or options; make sure the next request
		// migrates rather than waiting for a page load that happens to notice.
		\FFLCS\Installer::install();

		License_Client::validate( true );
	}

	// ── Release lookup ───────────────────────────────────────────────────────

	/**
	 * The latest release payload, cached.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array<string,mixed>|null
	 */
	public function get_release( bool $force = false ): ?array {
		if ( ! $force ) {
			$cached = get_transient( self::CHECK_TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$url = add_query_arg(
			array(
				'license_key' => rawurlencode( License_Client::key() ),
				'site'        => rawurlencode( home_url( '/' ) ),
				'version'     => rawurlencode( FFLCS_VERSION ),
				'instance'    => rawurlencode( License_Client::instance() ),
			),
			self::endpoint()
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the miss briefly so an outage does not mean an outbound
			// request on every admin page load.
			set_transient( self::CHECK_TRANSIENT, array(), 30 * MINUTE_IN_SECONDS );

			fflcs_log( 'Update check failed', is_wp_error( $response ) ? $response->get_error_message() : 'non-200' );

			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			set_transient( self::CHECK_TRANSIENT, array(), 30 * MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( self::CHECK_TRANSIENT, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Force a fresh check, for the "Check again" button.
	 *
	 * Shares the licence client's manual rate limiter — the same admin clicking
	 * the same kind of button should not get two separate budgets.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function check_now() {
		if ( License_Client::is_rate_limited() ) {
			return new \WP_Error(
				'fflcs_rate_limited',
				__( 'Too many checks. Please wait a while before trying again.', 'ffl-checkout-solutions' )
			);
		}

		License_Client::record_attempt();

		delete_transient( self::CHECK_TRANSIENT );

		$release = $this->get_release( true );

		if ( null === $release ) {
			return new \WP_Error(
				'fflcs_update_check_failed',
				__( 'Could not reach the update server. Your site is unaffected and will keep running the installed version.', 'ffl-checkout-solutions' )
			);
		}

		return array(
			'current'        => FFLCS_VERSION,
			'latest'         => (string) $release['version'],
			'update_available' => version_compare( (string) $release['version'], FFLCS_VERSION, '>' ),
		);
	}

	/**
	 * Cron entry point.
	 */
	public static function cron_check(): void {
		if ( ! License_Client::is_valid() ) {
			return;
		}

		( new self() )->get_release( true );
	}
}
