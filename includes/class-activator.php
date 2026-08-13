<?php
/**
 * Activation routine — schema, defaults, roles, cron, v1.x migration, wizard.
 *
 * @package FFLCS
 */

namespace FFLCS;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on register_activation_hook.
 */
class Activator {

	/**
	 * Full activation sequence.
	 */
	public static function activate(): void {
		// Section 21 — adopt v1.x data before the installer runs, so dbDelta
		// upgrades the renamed tables in place rather than creating empty ones
		// beside them.
		$migrated = self::migrate_legacy_data();

		Installer::install();

		self::seed_default_options();
		self::ensure_token_secret();
		self::register_roles();
		self::schedule_cron_events();

		if ( $migrated ) {
			update_option( 'fflcs_migrated_from_v1', fflcs_mysql_now() );
		}

		// Section 17.1 — one-time setup wizard redirect. A transient, not an
		// option, so a bulk activation of several plugins does not strand the
		// user in a wizard, and the redirect can never repeat.
		if ( '1' !== (string) get_option( 'fflcs_setup_wizard_complete', '0' ) ) {
			set_transient( 'fflcs_activation_redirect', 1, 30 );
		}

		update_option( 'fflcs_version', FFLCS_VERSION );

		flush_rewrite_rules();
	}

	// ── Defaults ─────────────────────────────────────────────────────────────

	/**
	 * Section 20 default options.
	 *
	 * add_option (not update_option) throughout: an upgrade must never reset a
	 * choice the store owner already made.
	 */
	public static function seed_default_options(): void {
		foreach ( self::defaults() as $key => $value ) {
			add_option( $key, $value );
		}
	}

	/**
	 * The Section 20 default option map.
	 *
	 * @return array<string,string>
	 */
	public static function defaults(): array {
		return array(
			'fflcs_enabled'                  => '1',
			'fflcs_store_name'               => (string) get_option( 'blogname' ),
			'fflcs_store_ffl_license'        => '',
			'fflcs_store_email'              => (string) get_option( 'admin_email' ),
			'fflcs_store_phone'              => '',
			'fflcs_store_address'            => '',
			'fflcs_business_hours'           => '',
			'fflcs_transfer_fee_default'     => '25.00',
			'fflcs_checkout_widget_enabled'  => '1',
			'fflcs_checkout_strict_mode'     => '1',
			'fflcs_checkout_default_radius'  => '50',
			'fflcs_auto_notify_dealer'       => '1',
			'fflcs_portal_token_expiry_days' => '30',
			'fflcs_portal_two_factor'        => 'none',
			'fflcs_portal_slug'              => FFLCS_PORTAL_SLUG,
			'fflcs_portal_single_use'        => '1',
			'fflcs_reminder_schedule'        => '3,7,14',
			'fflcs_email_customer_enabled'   => '1',
			'fflcs_email_dealer_enabled'     => '1',
			'fflcs_email_admin_enabled'      => '1',
			'fflcs_carrier_auto_advance'     => '1',
			'fflcs_nics_strict_mode'         => '0',
			'fflcs_compliance_strict'        => '1',
			'fflcs_data_retention_days'      => '0',
			'fflcs_delete_data_on_uninstall' => '0',
			'fflcs_debug_mode'               => '0',
			'fflcs_setup_wizard_complete'    => '0',
			// Theming — professional blue/gray (Section 15.1 / Section 29.2).
			'fflcs_theme_primary'            => '#2563EB',
			'fflcs_theme_success'            => '#059669',
			'fflcs_theme_warning'            => '#D97706',
			'fflcs_theme_danger'             => '#DC2626',
			'fflcs_theme_surface'            => '#1E293B',
			'fflcs_theme_text'               => '#F8FAFC',
			'fflcs_theme_logo'               => '',
		);
	}

	/**
	 * Generate the HMAC signing secret once.
	 *
	 * autoload=false: it is read only on portal and tracking requests, not on
	 * every page load, and keeping it out of the autoloaded option blob narrows
	 * where it can leak from.
	 */
	private static function ensure_token_secret(): void {
		if ( get_option( 'fflcs_token_secret' ) ) {
			return;
		}
		update_option( 'fflcs_token_secret', wp_generate_password( 64, true, true ), false );
	}

	// ── Roles ────────────────────────────────────────────────────────────────

	/**
	 * Register the dealer role and the FFL staff capability.
	 *
	 * ffl_dealer deliberately has no wp-admin access beyond read — a dealer is
	 * an external party confirming shipments, not site staff.
	 */
	public static function register_roles(): void {
		if ( ! get_role( 'ffl_dealer' ) ) {
			add_role(
				'ffl_dealer',
				__( 'FFL Dealer', 'ffl-checkout-solutions' ),
				array(
					'read'                 => true,
					'fflcs_dealer_portal'  => true,
				)
			);
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'manage_fflcs_transfers' );
			$admin->add_cap( 'view_fflcs_documents' );
		}

		$shop_manager = get_role( 'shop_manager' );
		if ( $shop_manager ) {
			$shop_manager->add_cap( 'manage_fflcs_transfers' );
			$shop_manager->add_cap( 'view_fflcs_documents' );
		}
	}

	// ── Cron ─────────────────────────────────────────────────────────────────

	/**
	 * Schedule the Section 18.2 recurring events.
	 *
	 * Every schedule is guarded by wp_next_scheduled so re-activation never
	 * stacks duplicates.
	 */
	public static function schedule_cron_events(): void {
		$events = array(
			'fflcs_atf_sync'             => array( 'fflcs_monthly', strtotime( 'first day of next month 03:00:00' ) ),
			'fflcs_carrier_poll'         => array( 'daily', strtotime( 'tomorrow 04:00' ) ),
			'fflcs_nics_delay_alert'     => array( 'daily', strtotime( 'tomorrow 08:00' ) ),
			'fflcs_compliance_reminder'  => array( 'daily', strtotime( 'tomorrow 08:15' ) ),
			'fflcs_dealer_health_check'  => array( 'daily', strtotime( 'tomorrow 05:00' ) ),
			'fflcs_regulatory_watch'     => array( 'daily', strtotime( 'tomorrow 02:00' ) ),
			'fflcs_daily_runner'         => array( 'daily', strtotime( 'tomorrow 09:00' ) ),
			'fflcs_webhook_retry'        => array( 'fflcs_every_five_minutes', time() + 300 ),
			'fflcs_license_validate'     => array( 'daily', time() + HOUR_IN_SECONDS ),
			'fflcs_daily_license_check'  => array( 'daily', time() + HOUR_IN_SECONDS ),
			'fflcs_update_check'         => array( 'twicedaily', time() + HOUR_IN_SECONDS ),
		);

		foreach ( $events as $hook => $config ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( $config[1], $config[0], $hook );
			}
		}
	}

	// ── Section 21: v1.x migration ───────────────────────────────────────────

	/**
	 * Adopt data from a v1.x install.
	 *
	 * Renames wp_wpistic_ffl_* tables to wp_fflcs_* and copies wpistic_ffl_*
	 * options to their fflcs_* equivalents. Both steps are skipped when the
	 * destination already exists, so this is safe to run on every activation
	 * and never overwrites v2 data with stale v1 data.
	 *
	 * @return bool Whether anything was migrated.
	 */
	public static function migrate_legacy_data(): bool {
		global $wpdb;

		$migrated = false;

		$legacy_prefix = $wpdb->prefix . 'wpistic_ffl_';
		$new_prefix    = $wpdb->prefix . FFLCS_DB_PREFIX;

		// Table names differ in a few places between v1 and v2 — map those, and
		// carry the rest across unchanged.
		$renames = array(
			'zip_coords'                 => 'zip_cache',
			'ffl_verification_documents' => 'ffl_verification_documents',
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$legacy_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $legacy_prefix ) . '%' ) );

		foreach ( (array) $legacy_tables as $legacy_table ) {
			$bare = substr( $legacy_table, strlen( $legacy_prefix ) );
			$bare = $renames[ $bare ] ?? $bare;
			$dest = $new_prefix . $bare;

			// Never clobber an existing v2 table.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $dest ) ) === $dest ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "RENAME TABLE `{$legacy_table}` TO `{$dest}`" );
			$migrated = true;
		}

		// Options: wpistic_ffl_foo -> fflcs_foo, only where the target is unset.
		$legacy_options = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wpistic\\_ffl\\_%'"
		);

		foreach ( (array) $legacy_options as $option ) {
			$new_name = 'fflcs_' . substr( $option->option_name, strlen( 'wpistic_ffl_' ) );
			if ( false !== get_option( $new_name, false ) ) {
				continue;
			}
			add_option( $new_name, maybe_unserialize( $option->option_value ) );
			$migrated = true;
		}

		if ( $migrated ) {
			// Surfaced once, then dismissed — see Admin\Admin_Dashboard.
			update_option( 'fflcs_show_migration_notice', '1' );
		}

		return $migrated;
	}
}
