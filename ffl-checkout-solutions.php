<?php
/**
 * Plugin Name:       FFL Checkout Solutions
 * Plugin URI:        https://fflistic.com
 * Description:       Complete FFL dealer management, WooCommerce checkout integration and transfer lifecycle tracking for federally licensed firearms retailers. Dealer search, ATF dealer sync, transfer records, dealer confirmation portal, state compliance notices and modular premium add-ons.
 * Version:           2.0.0
 * Requires at least: 6.4
 * Tested up to:      6.7
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            WordPressistic
 * Author URI:        https://wordpressistic.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ffl-checkout-solutions
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.x
 *
 * @package FFLCS
 */

defined( 'ABSPATH' ) || exit;

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * PHP version guard.
 *
 * Runs before anything else is defined. The plugin header's "Requires PHP"
 * field only stops WordPress 5.1+ from *activating* on an old PHP; a site that
 * downgrades PHP after activation would still load this file, so the guard has
 * to exist in code as well as in the header.
 * ─────────────────────────────────────────────────────────────────────────────
 */
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p><strong>FFL Checkout Solutions:</strong> PHP 8.1 or higher is required. This site is running PHP ' . esc_html( PHP_VERSION ) . '.</p></div>';
		}
	);
	return;
}

// ── Constants (Section 3) ────────────────────────────────────────────────────
define( 'FFLCS_VERSION', '2.0.0' );
define( 'FFLCS_DB_VERSION', '2.0.0' );
define( 'FFLCS_PLUGIN_FILE', __FILE__ );
define( 'FFLCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFLCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FFLCS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'FFLCS_MIN_WC_VERSION', '8.0' );
define( 'FFLCS_MIN_PHP_VERSION', '8.1' );

// Derived identifiers. Kept as constants so every subsystem references one source.
define( 'FFLCS_DB_PREFIX', 'fflcs_' );
define( 'FFLCS_REST_NS', 'fflcs/v1' );
define( 'FFLCS_TEXT_DOMAIN', 'ffl-checkout-solutions' );

if ( ! defined( 'FFLCS_PORTAL_SLUG' ) ) {
	define( 'FFLCS_PORTAL_SLUG', 'ffl-confirm' );
}

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * Autoloader.
 *
 * Maps FFLCS\Sub\Namespace\Class_Name to includes/sub/namespace/class-class-name.php.
 * Deliberately hand-rolled rather than Composer-generated: the shipped ZIP must
 * run with no vendor/ directory and no build step on the customer's server.
 * ─────────────────────────────────────────────────────────────────────────────
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'FFLCS\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );

		// Sub-namespaces become directories: Admin\Dealers -> admin/class-dealers.php
		$dir = '';
		foreach ( $parts as $part ) {
			$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}

		$slug = strtolower( str_replace( '_', '-', $name ) );
		$file = 'class-' . $slug . '.php';

		$candidates = array( FFLCS_PLUGIN_DIR . 'includes/' . $dir . $file );

		// Feature modules live in a directory of their own — includes/modules/
		// carrier-tracking/class-carrier-tracking.php — so each one can carry
		// its own views and assets beside its class.
		if ( 'modules/' === $dir ) {
			$candidates[] = FFLCS_PLUGIN_DIR . 'includes/modules/' . $slug . '/' . $file;
		}

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

// Procedural helpers are always available — they are used by the guard below,
// which runs before the container boots.
require_once FFLCS_PLUGIN_DIR . 'includes/helpers/functions.php';

// ── Lifecycle hooks ──────────────────────────────────────────────────────────
register_activation_hook( __FILE__, array( '\FFLCS\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\FFLCS\Deactivator', 'deactivate' ) );

/*
 * HPOS + Cart/Checkout Blocks compatibility declaration (Section 9.0.1).
 *
 * Must be registered on before_woocommerce_init, which fires earlier than
 * plugins_loaded — so it lives here at file scope rather than inside the
 * plugin container, and it must run even when the dependency guard later
 * decides not to boot (declaring compatibility is inert without WooCommerce).
 */
add_action( 'before_woocommerce_init', array( '\FFLCS\HPOS_Compat', 'declare_compatibility' ) );

// ── Boot ─────────────────────────────────────────────────────────────────────
add_action(
	'plugins_loaded',
	static function (): void {
		\FFLCS\Plugin::instance()->boot();
	},
	20
);
