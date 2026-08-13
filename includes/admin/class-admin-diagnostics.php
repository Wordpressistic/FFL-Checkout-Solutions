<?php
/**
 * Diagnostics screen — health check, mail log, module status.
 *
 * Exists so a support conversation starts with facts instead of guesses.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

use FFLCS\Crypto;
use FFLCS\HPOS_Compat;
use FFLCS\Installer;
use FFLCS\Plugin;
use FFLCS\Services\Mailer;
use FFLCS\Services\Scheduler;
use FFLCS\Services\Zip_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Health and troubleshooting.
 */
class Admin_Diagnostics extends Admin_Page {

	/**
	 * Register hooks and menu.
	 */
	public function init(): void {
		parent::init();

		add_action( 'admin_post_fflcs_clear_mail_log', array( $this, 'handle_clear_mail_log' ) );
		add_action( 'admin_post_fflcs_repair_schema', array( $this, 'handle_repair_schema' ) );
	}

	/**
	 * Menu priority.
	 */
	protected function menu_priority(): int {
		return 25;
	}

	/**
	 * Register the submenu.
	 */
	public function register_menu(): void {
		$this->ensure_parent_menu();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Diagnostics', 'ffl-checkout-solutions' ),
			__( 'Diagnostics', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			'fflcs-diagnostics',
			array( $this, 'render' )
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$this->require_capability();

		$this->open_page(
			__( 'Diagnostics', 'ffl-checkout-solutions' ),
			__( 'What this installation actually looks like right now. Useful to have open when contacting support.', 'ffl-checkout-solutions' )
		);

		$this->render_health();
		$this->render_modules();
		$this->render_cron();
		$this->render_mail_log();

		$this->close_page();
	}

	/**
	 * Environment checks.
	 */
	private function render_health(): void {
		$tables = Installer::table_status();

		$checks = array(
			array(
				'label' => __( 'PHP version', 'ffl-checkout-solutions' ),
				'value' => PHP_VERSION,
				'ok'    => version_compare( PHP_VERSION, FFLCS_MIN_PHP_VERSION, '>=' ),
			),
			array(
				'label' => __( 'WooCommerce', 'ffl-checkout-solutions' ),
				'value' => defined( 'WC_VERSION' ) ? WC_VERSION : __( 'not detected', 'ffl-checkout-solutions' ),
				'ok'    => defined( 'WC_VERSION' ) && version_compare( WC_VERSION, FFLCS_MIN_WC_VERSION, '>=' ),
			),
			array(
				'label' => __( 'HPOS (order storage)', 'ffl-checkout-solutions' ),
				'value' => HPOS_Compat::hpos_enabled()
					? __( 'enabled — compatibility declared', 'ffl-checkout-solutions' )
					: __( 'not enabled — compatibility declared', 'ffl-checkout-solutions' ),
				'ok'    => true,
			),
			array(
				'label' => __( 'Database tables', 'ffl-checkout-solutions' ),
				'value' => sprintf(
					/* translators: 1: present count, 2: expected count. */
					__( '%1$d of %2$d present', 'ffl-checkout-solutions' ),
					count( $tables['present'] ),
					count( Installer::TABLES )
				),
				'ok'    => empty( $tables['missing'] ),
			),
			array(
				'label' => __( 'Schema version', 'ffl-checkout-solutions' ),
				'value' => (string) get_option( 'fflcs_db_version', __( 'unknown', 'ffl-checkout-solutions' ) ),
				'ok'    => get_option( 'fflcs_db_version' ) === Installer::SCHEMA_VERSION,
			),
			array(
				'label' => __( 'Secret encryption', 'ffl-checkout-solutions' ),
				'value' => Crypto::available()
					? __( 'AES-256-GCM available', 'ffl-checkout-solutions' )
					: __( 'OpenSSL unavailable — secrets stored unencrypted', 'ffl-checkout-solutions' ),
				'ok'    => Crypto::available(),
			),
			array(
				'label' => __( 'Token secret', 'ffl-checkout-solutions' ),
				'value' => defined( 'FFLCS_TOKEN_SECRET' )
					? __( 'from wp-config.php constant', 'ffl-checkout-solutions' )
					: __( 'stored in the database', 'ffl-checkout-solutions' ),
				'ok'    => true,
			),
			array(
				'label' => __( 'Action Scheduler', 'ffl-checkout-solutions' ),
				'value' => Scheduler::has_action_scheduler()
					? __( 'available', 'ffl-checkout-solutions' )
					: __( 'not available — falling back to WP-Cron', 'ffl-checkout-solutions' ),
				'ok'    => Scheduler::has_action_scheduler(),
			),
			array(
				'label' => __( 'ZIP centroids cached', 'ffl-checkout-solutions' ),
				'value' => number_format_i18n( Zip_Engine::cache_size() ),
				'ok'    => true,
			),
			array(
				'label' => __( 'Private upload directory', 'ffl-checkout-solutions' ),
				'value' => is_dir( Installer::private_dir() )
					? __( 'present and protected', 'ffl-checkout-solutions' )
					: __( 'missing', 'ffl-checkout-solutions' ),
				'ok'    => is_dir( Installer::private_dir() ),
			),
		);

		echo '<section class="fflcs-panel"><h2>' . esc_html__( 'Health', 'ffl-checkout-solutions' ) . '</h2>';
		echo '<table class="widefat striped fflcs-table"><tbody>';

		foreach ( $checks as $check ) {
			printf(
				'<tr><td style="width:35%%">%1$s</td><td>%2$s</td><td style="width:80px">%3$s</td></tr>',
				esc_html( (string) $check['label'] ),
				esc_html( (string) $check['value'] ),
				$check['ok']
					? '<span class="fflcs-pill fflcs-pill--ok">' . esc_html__( 'OK', 'ffl-checkout-solutions' ) . '</span>'
					: '<span class="fflcs-pill fflcs-pill--warn">' . esc_html__( 'Check', 'ffl-checkout-solutions' ) . '</span>'
			);
		}

		echo '</tbody></table>';

		if ( ! empty( $tables['missing'] ) ) {
			printf(
				'<form method="post" action="%1$s"><input type="hidden" name="action" value="fflcs_repair_schema">%2$s
				<p><button class="button button-primary">%3$s</button>
				<span class="description">%4$s</span></p></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( 'fflcs_repair_schema', '_wpnonce', true, false ),
				esc_html__( 'Rebuild missing tables', 'ffl-checkout-solutions' ),
				esc_html( implode( ', ', $tables['missing'] ) )
			);
		}

		echo '</section>';
	}

	/**
	 * Which modules are running, and why the others are not.
	 */
	private function render_modules(): void {
		$loader = Plugin::instance()->modules();

		echo '<section class="fflcs-panel"><h2>' . esc_html__( 'Modules', 'ffl-checkout-solutions' ) . '</h2>';

		if ( ! $loader ) {
			echo '<p class="fflcs-empty">' . esc_html__( 'The module loader did not run.', 'ffl-checkout-solutions' ) . '</p></section>';
			return;
		}

		echo '<table class="widefat striped fflcs-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Module', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'ffl-checkout-solutions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $loader->booted_ids() as $id ) {
			printf(
				'<tr><td>%1$s</td><td><span class="fflcs-pill fflcs-pill--ok">%2$s</span></td></tr>',
				esc_html( $id ),
				esc_html__( 'Running', 'ffl-checkout-solutions' )
			);
		}

		foreach ( $loader->skipped() as $id => $reason ) {
			printf(
				'<tr><td>%1$s</td><td><span class="fflcs-pill fflcs-pill--muted">%2$s</span></td></tr>',
				esc_html( (string) $id ),
				esc_html( (string) $reason )
			);
		}

		echo '</tbody></table></section>';
	}

	/**
	 * Scheduled work.
	 */
	private function render_cron(): void {
		$hooks = array(
			'fflcs_atf_sync'            => __( 'ATF dealer sync', 'ffl-checkout-solutions' ),
			'fflcs_daily_runner'        => __( 'Nightly maintenance', 'ffl-checkout-solutions' ),
			'fflcs_carrier_poll'        => __( 'Carrier status poll', 'ffl-checkout-solutions' ),
			'fflcs_nics_delay_alert'    => __( 'NICS delay alerts', 'ffl-checkout-solutions' ),
			'fflcs_compliance_reminder' => __( 'Compliance reminders', 'ffl-checkout-solutions' ),
			'fflcs_dealer_health_check' => __( 'Dealer health alerts', 'ffl-checkout-solutions' ),
			'fflcs_regulatory_watch'    => __( 'Regulatory watch', 'ffl-checkout-solutions' ),
			'fflcs_webhook_retry'       => __( 'Webhook retries', 'ffl-checkout-solutions' ),
			'fflcs_license_validate'    => __( 'Licence validation', 'ffl-checkout-solutions' ),
			'fflcs_update_check'        => __( 'Update check', 'ffl-checkout-solutions' ),
		);

		echo '<section class="fflcs-panel"><h2>' . esc_html__( 'Scheduled jobs', 'ffl-checkout-solutions' ) . '</h2>';
		echo '<table class="widefat striped fflcs-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Job', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'Next run', 'ffl-checkout-solutions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $hooks as $hook => $label ) {
			$next = wp_next_scheduled( $hook );

			printf(
				'<tr><td>%1$s<br><code>%2$s</code></td><td>%3$s</td></tr>',
				esc_html( $label ),
				esc_html( $hook ),
				$next
					? esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $next ) )
					: esc_html__( 'not scheduled', 'ffl-checkout-solutions' )
			);
		}

		echo '</tbody></table></section>';
	}

	/**
	 * The last 100 mail attempts.
	 */
	private function render_mail_log(): void {
		$log = Mailer::get_log();

		echo '<section class="fflcs-panel"><h2>' . esc_html__( 'Mail log', 'ffl-checkout-solutions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The last 100 send attempts. Recipient addresses are partly masked.', 'ffl-checkout-solutions' ) . '</p>';

		if ( ! $log ) {
			echo '<p class="fflcs-empty">' . esc_html__( 'No mail sent yet.', 'ffl-checkout-solutions' ) . '</p></section>';
			return;
		}

		echo '<table class="widefat striped fflcs-table"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'To', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'Subject', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'ffl-checkout-solutions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $log as $entry ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( $this->format_datetime( (string) ( $entry['time'] ?? '' ) ) ),
				esc_html( (string) ( $entry['to'] ?? '' ) ),
				esc_html( (string) ( $entry['subject'] ?? '' ) ),
				! empty( $entry['success'] )
					? '<span class="fflcs-pill fflcs-pill--ok">' . esc_html__( 'Sent', 'ffl-checkout-solutions' ) . '</span>'
					: '<span class="fflcs-pill fflcs-pill--warn">' . esc_html( (string) ( $entry['reason'] ?? __( 'Failed', 'ffl-checkout-solutions' ) ) ) . '</span>'
			);
		}

		echo '</tbody></table>';

		printf(
			'<form method="post" action="%1$s"><input type="hidden" name="action" value="fflcs_clear_mail_log">%2$s
			<p><button class="button">%3$s</button></p></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'fflcs_clear_mail_log', '_wpnonce', true, false ),
			esc_html__( 'Clear the mail log', 'ffl-checkout-solutions' )
		);

		echo '</section>';
	}

	/**
	 * Empty the mail log.
	 */
	public function handle_clear_mail_log(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_clear_mail_log' );

		Mailer::clear_log();

		wp_safe_redirect( admin_url( 'admin.php?page=fflcs-diagnostics' ) );
		exit;
	}

	/**
	 * Re-run the installer to create missing tables.
	 */
	public function handle_repair_schema(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_repair_schema' );

		Installer::install();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-diagnostics',
					'fflcs_message' => rawurlencode( __( 'Schema rebuilt.', 'ffl-checkout-solutions' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
