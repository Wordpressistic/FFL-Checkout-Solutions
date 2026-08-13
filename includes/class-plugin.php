<?php
/**
 * Main plugin container — singleton, dependency guard, boot sequence.
 *
 * Boot order is deliberate and enforced:
 *   1. check_dependencies()  — Section 9.0. Nothing else runs if this fails.
 *   2. i18n
 *   3. schema upgrade check  — a ZIP upgrade never re-fires the activation hook,
 *                              so the version comparison here is the only path
 *                              that migrates an already-activated site.
 *   4. core services
 *   5. modules               — via Module_Loader, each entitlement-gated.
 *   6. admin / frontend      — context-dependent.
 *
 * @package FFLCS
 */

namespace FFLCS;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin container.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Booted flag — boot() is idempotent.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Instantiated service objects, keyed by short name.
	 *
	 * @var array<string,object>
	 */
	private array $services = array();

	/**
	 * The module loader, once booted.
	 *
	 * @var Module_Loader|null
	 */
	private ?Module_Loader $modules = null;

	/**
	 * Private — use instance().
	 */
	private function __construct() {}

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Full boot sequence.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->load_textdomain();

		// Section 9.0 — hard dependency guard. Everything below this line assumes
		// WooCommerce exists at the required version.
		if ( ! $this->check_dependencies() ) {
			return;
		}

		$this->booted = true;

		$this->maybe_upgrade_schema();
		$this->register_cron_schedules();
		$this->boot_core_services();
		$this->boot_modules();
		$this->boot_admin();
		$this->boot_frontend();
		$this->register_cron_handlers();

		/**
		 * Fires once the plugin has fully booted.
		 *
		 * @param Plugin $plugin The plugin container.
		 */
		do_action( 'fflcs_after_init', $this );
	}

	// ── Section 9.0: Dependency guard ────────────────────────────────────────

	/**
	 * Verify WooCommerce is active and meets FFLCS_MIN_WC_VERSION.
	 *
	 * On failure the plugin deactivates itself and shows an actionable notice
	 * rather than letting the rest of the bootstrap fatal on an undefined WC_*
	 * class. The `Requires Plugins: woocommerce` header covers the common case
	 * on WP 6.5+, but it does not cover WooCommerce being deactivated or
	 * downgraded *after* this plugin is already active — that is what this is
	 * for.
	 *
	 * @return bool True when it is safe to boot.
	 */
	public function check_dependencies(): bool {
		$failure = $this->dependency_failure();

		if ( null === $failure ) {
			return true;
		}

		// Self-deactivate so the site is never left in a half-booted state.
		add_action(
			'admin_init',
			static function (): void {
				if ( ! function_exists( 'deactivate_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				deactivate_plugins( FFLCS_PLUGIN_BASENAME );

				// Suppress the "Plugin activated" notice from the redirect that
				// triggered this, so the user only sees our explanation.
				unset( $_GET['activate'] ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		);

		add_action(
			'admin_notices',
			static function () use ( $failure ): void {
				printf(
					'<div class="notice notice-error is-dismissible"><p><strong>%s</strong> %s</p><p>%s</p></div>',
					esc_html__( 'FFL Checkout Solutions could not start.', 'ffl-checkout-solutions' ),
					esc_html( $failure['message'] ),
					wp_kses_post( $failure['action'] )
				);
			}
		);

		return false;
	}

	/**
	 * Describe the dependency failure, or null when dependencies are satisfied.
	 *
	 * @return array{message:string,action:string}|null
	 */
	private function dependency_failure(): ?array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'message' => __( 'WooCommerce is required and is not active.', 'ffl-checkout-solutions' ),
				'action'  => sprintf(
					/* translators: %s: URL to the plugin install screen. */
					wp_kses_post( __( 'Install and activate <a href="%s">WooCommerce</a>, then activate FFL Checkout Solutions again.', 'ffl-checkout-solutions' ) ),
					esc_url( self_admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) )
				),
			);
		}

		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : '0';

		if ( version_compare( $wc_version, FFLCS_MIN_WC_VERSION, '<' ) ) {
			return array(
				'message' => sprintf(
					/* translators: 1: required WooCommerce version, 2: installed WooCommerce version. */
					__( 'WooCommerce %1$s or higher is required. This site is running WooCommerce %2$s.', 'ffl-checkout-solutions' ),
					FFLCS_MIN_WC_VERSION,
					$wc_version
				),
				'action'  => sprintf(
					/* translators: %s: URL to the plugin update screen. */
					wp_kses_post( __( 'Update WooCommerce from the <a href="%s">Plugins screen</a>, then activate FFL Checkout Solutions again.', 'ffl-checkout-solutions' ) ),
					esc_url( self_admin_url( 'plugins.php' ) )
				),
			);
		}

		return null;
	}

	// ── Boot steps ───────────────────────────────────────────────────────────

	/**
	 * Load translations.
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain(
			'ffl-checkout-solutions',
			false,
			dirname( FFLCS_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Run pending schema migrations.
	 *
	 * A plugin ZIP upgrade does not re-fire register_activation_hook, so this
	 * comparison on every load is the only thing that creates tables added in a
	 * later version on an already-activated site.
	 */
	private function maybe_upgrade_schema(): void {
		if ( get_option( 'fflcs_db_version' ) === Installer::SCHEMA_VERSION ) {
			return;
		}
		Installer::install();
	}

	/**
	 * Register the custom cron intervals the scheduler relies on.
	 */
	private function register_cron_schedules(): void {
		add_filter(
			'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
			static function ( array $schedules ): array {
				if ( ! isset( $schedules['fflcs_every_minute'] ) ) {
					$schedules['fflcs_every_minute'] = array(
						'interval' => MINUTE_IN_SECONDS,
						'display'  => __( 'Every minute (FFL Checkout)', 'ffl-checkout-solutions' ),
					);
				}
				if ( ! isset( $schedules['fflcs_every_five_minutes'] ) ) {
					$schedules['fflcs_every_five_minutes'] = array(
						'interval' => 5 * MINUTE_IN_SECONDS,
						'display'  => __( 'Every 5 minutes (FFL Checkout)', 'ffl-checkout-solutions' ),
					);
				}
				if ( ! isset( $schedules['fflcs_monthly'] ) ) {
					$schedules['fflcs_monthly'] = array(
						'interval' => 30 * DAY_IN_SECONDS,
						'display'  => __( 'Monthly (FFL Checkout)', 'ffl-checkout-solutions' ),
					);
				}
				return $schedules;
			}
		);
	}

	/**
	 * Instantiate always-on services.
	 *
	 * These are the free-tier spine: checkout, REST, portal, theming, licensing.
	 * Feature-level behaviour lives in modules, not here.
	 */
	private function boot_core_services(): void {
		$this->services['license']   = new License\License_Client();
		$this->services['updater']   = new License\Updater();
		$this->services['theming']   = new Services\Theming();
		$this->services['rest']      = new REST_API();
		$this->services['checkout']  = new Checkout();
		$this->services['scheduler'] = new Services\Scheduler();
		$this->services['mailer']    = new Services\Mailer();
		$this->services['bridge']    = new Services\Status_Bridge();
		$this->services['addons']    = new Addon_Manager();

		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'init' ) ) {
				$service->init();
			}
		}
	}

	/**
	 * Discover, entitlement-check and boot feature modules.
	 */
	private function boot_modules(): void {
		$this->modules = new Module_Loader();
		$this->modules->boot();
	}

	/**
	 * Admin screens.
	 */
	private function boot_admin(): void {
		if ( ! is_admin() ) {
			return;
		}

		$screens = array(
			new Admin\Admin_Dashboard(),
			new Admin\Admin_Dealers(),
			new Admin\Admin_Transfers(),
			new Admin\Admin_Settings(),
			new Admin\Admin_Distributors(),
			new Admin\Admin_Carriers(),
			new Admin\Admin_Compliance(),
			new Admin\Admin_Activity_Log(),
			new Admin\Admin_Diagnostics(),
			new Admin\Admin_License(),
			new Admin\Admin_Addons(),
			new Admin\Admin_Setup_Wizard(),
		);

		foreach ( $screens as $screen ) {
			$screen->init();
			$this->services[ 'admin.' . get_class( $screen ) ] = $screen;
		}
	}

	/**
	 * Public-facing controllers.
	 */
	private function boot_frontend(): void {
		$frontend = array(
			new Frontend\Checkout_Widget(),
			new Frontend\Dealer_Portal(),
			new Frontend\Tracking_Page(),
			new Frontend\Dealer_Onboard(),
		);

		foreach ( $frontend as $controller ) {
			$controller->init();
			$this->services[ 'frontend.' . get_class( $controller ) ] = $controller;
		}
	}

	/**
	 * Wire cron hooks to their handlers (Section 18.2).
	 */
	private function register_cron_handlers(): void {
		add_action( 'fflcs_atf_sync', array( '\FFLCS\Services\ATF_Sync', 'start_full_sync' ) );
		add_action( 'fflcs_atf_sync_chunk', array( '\FFLCS\Services\ATF_Sync', 'process_chunk' ) );
		add_action( 'fflcs_daily_runner', array( '\FFLCS\Services\Transfer_Service', 'daily_runner' ) );
		add_action( 'fflcs_license_validate', array( '\FFLCS\License\License_Client', 'cron_validate' ) );
		add_action( 'fflcs_daily_license_check', array( '\FFLCS\License\License_Client', 'cron_validate' ) );
		add_action( 'fflcs_update_check', array( '\FFLCS\License\Updater', 'cron_check' ) );
	}

	// ── Accessors ────────────────────────────────────────────────────────────

	/**
	 * Fetch a booted service by key.
	 *
	 * @param string $key Service key.
	 */
	public function service( string $key ): ?object {
		return $this->services[ $key ] ?? null;
	}

	/**
	 * The module loader, or null when the plugin did not boot.
	 */
	public function modules(): ?Module_Loader {
		return $this->modules;
	}

	/**
	 * Whether boot() completed.
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Cloning and unserializing a singleton is always a bug.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup(): void {
		throw new \RuntimeException( 'FFLCS\Plugin cannot be unserialized.' );
	}
}
