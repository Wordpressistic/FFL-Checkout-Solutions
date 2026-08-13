<?php
/**
 * REST API bootstrap (Section 12).
 *
 * Registers every controller under the fflcs/v1 namespace, plus the legacy
 * wpistic-ffl/v1 alias kept for two major versions (Section 21.2).
 *
 * Also owns CORS. Section 13.4: headers are emitted only for routes inside this
 * plugin's namespace, never globally. WordPress core's own CORS handler is
 * removed and re-added around ours so a plugin route cannot end up with two
 * conflicting Access-Control-Allow-Origin headers.
 *
 * @package FFLCS
 */

namespace FFLCS;

defined( 'ABSPATH' ) || exit;

/**
 * Route registration and cross-cutting REST concerns.
 */
class REST_API {

	/**
	 * Controller classes to register.
	 *
	 * @var string[]
	 */
	const CONTROLLERS = array(
		'\FFLCS\REST\Dealers_Controller',
		'\FFLCS\REST\Transfers_Controller',
		'\FFLCS\REST\Analytics_Controller',
		'\FFLCS\REST\Compliance_Controller',
		'\FFLCS\REST\Portal_Controller',
		'\FFLCS\REST\Carrier_Controller',
		'\FFLCS\REST\Webhook_Controller',
		'\FFLCS\REST\Distributor_Controller',
	);

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this, 'register_cors' ), 15 );
	}

	/**
	 * Instantiate and register every controller.
	 */
	public function register_routes(): void {
		foreach ( self::CONTROLLERS as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$controller = new $class();

			if ( method_exists( $controller, 'register_routes' ) ) {
				$controller->register_routes();
			}
		}

		$this->register_utility_routes();
	}

	/**
	 * Small cross-cutting routes that belong to no single controller.
	 */
	private function register_utility_routes(): void {
		register_rest_route(
			FFLCS_REST_NS,
			'/nonce',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_nonce' ),
				// Public by design: a nonce is only useful to the session it is
				// issued for, and the checkout widget needs one before the
				// shopper has authenticated.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Issue a fresh wp_rest nonce.
	 *
	 * Explicitly uncached — a page-cached nonce is an expired nonce, which is
	 * exactly the bug this endpoint exists to work around.
	 */
	public function get_nonce(): \WP_REST_Response {
		$response = rest_ensure_response(
			array(
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'expires' => time() + ( 12 * HOUR_IN_SECONDS ),
			)
		);

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		return $response;
	}

	/**
	 * Namespace-scoped CORS.
	 */
	public function register_cors(): void {
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

		add_filter(
			'rest_pre_serve_request',
			static function ( $served, $result, $request ) {
				$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';

				// Anything outside our namespace gets core's behaviour back,
				// unchanged.
				if ( 0 !== strpos( $route, '/' . FFLCS_REST_NS . '/' ) ) {
					return rest_send_cors_headers( $served );
				}

				/**
				 * Filter the origins allowed to call this plugin's REST routes.
				 *
				 * Empty by default: a store that does not run an external
				 * dashboard needs no cross-origin access at all.
				 *
				 * @param string[] $origins Allowed origins.
				 */
				$allowed = (array) apply_filters( 'fflcs_cors_allowed_origins', array() );

				$origin = isset( $_SERVER['HTTP_ORIGIN'] )
					? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) )
					: '';

				if ( '' !== $origin && in_array( $origin, $allowed, true ) ) {
					header( 'Access-Control-Allow-Origin: ' . $origin );
					header( 'Access-Control-Allow-Credentials: true' );
					header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
					header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Requested-With' );
					header( 'Access-Control-Max-Age: 600' );
					// Vary: Origin or a shared cache will serve one origin's
					// CORS headers to a different origin.
					header( 'Vary: Origin', false );
				}

				return $served;
			},
			10,
			3
		);
	}

	// ── Shared permission callbacks ──────────────────────────────────────────

	/**
	 * Staff-level permission check for admin routes.
	 *
	 * Section 29.6: mutating routes are never left as __return_true.
	 */
	public static function check_admin_permission(): bool {
		return fflcs_can_manage();
	}

	/**
	 * Permission check for routes that read compliance documents.
	 */
	public static function check_documents_permission(): bool {
		return current_user_can( 'view_fflcs_documents' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Public-route guard with rate limiting.
	 *
	 * Returns a WP_Error carrying a 429 so REST renders the right status and
	 * Retry-After header, rather than a bare false, which would render 401 and
	 * tell the caller the wrong thing.
	 *
	 * @param string $bucket Rate-limit bucket.
	 * @param int    $limit  Requests per window.
	 * @param int    $window Window seconds.
	 * @return true|\WP_Error
	 */
	public static function check_public_rate_limit( string $bucket, int $limit = 30, int $window = MINUTE_IN_SECONDS ) {
		/**
		 * Filter a public endpoint's rate limit.
		 *
		 * @param array  $config array{limit:int,window:int}
		 * @param string $bucket Bucket name.
		 */
		$config = (array) apply_filters(
			'fflcs_search_rate_limit',
			array(
				'limit'  => $limit,
				'window' => $window,
			),
			$bucket
		);

		$limit  = max( 1, (int) ( $config['limit'] ?? $limit ) );
		$window = max( 1, (int) ( $config['window'] ?? $window ) );

		if ( Services\Token::is_rate_limited( $bucket, $limit, $window ) ) {
			return Services\Token::rate_limit_error( $window );
		}

		return true;
	}
}
