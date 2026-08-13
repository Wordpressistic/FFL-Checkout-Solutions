<?php
/**
 * Shared base for admin screens.
 *
 * Owns the top-level menu registration (once, from whichever screen loads
 * first), capability checks, tab rendering and the notice helpers, so no
 * individual screen has to re-implement them and drift.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Base admin screen.
 */
abstract class Admin_Page {

	/**
	 * Top-level menu slug.
	 */
	const MENU_SLUG = 'fflcs-dashboard';

	/**
	 * Capability required for every plugin screen.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Whether the parent menu has been registered this request.
	 *
	 * @var bool
	 */
	private static bool $menu_registered = false;

	/**
	 * Register hooks. Screens override register_menu(), not this.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), $this->menu_priority() );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Menu registration priority — controls submenu order.
	 */
	protected function menu_priority(): int {
		return 10;
	}

	/**
	 * Register this screen's menu entry.
	 */
	abstract public function register_menu(): void;

	/**
	 * Render this screen.
	 */
	abstract public function render(): void;

	/**
	 * Ensure the top-level menu exists.
	 *
	 * Called by every screen; only the first call does anything.
	 */
	protected function ensure_parent_menu(): void {
		if ( self::$menu_registered ) {
			return;
		}

		self::$menu_registered = true;

		add_menu_page(
			__( 'FFL Checkout', 'ffl-checkout-solutions' ),
			__( 'FFL Checkout', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_parent' ),
			'dashicons-shield-alt',
			56 // Just below WooCommerce.
		);
	}

	/**
	 * The parent menu's own callback.
	 *
	 * Only reached when the dashboard screen is not the one that registered the
	 * parent, which should not happen — but rendering a blank page would be a
	 * confusing failure, so redirect instead.
	 */
	public function render_parent(): void {
		$dashboard = new Admin_Dashboard();
		$dashboard->render();
	}

	/**
	 * Enqueue admin CSS and JS on plugin screens only.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'fflcs' ) ) {
			return;
		}

		wp_enqueue_style(
			'fflcs-admin',
			FFLCS_PLUGIN_URL . 'assets/css/admin.css',
			array( 'fflcs-tokens' ),
			FFLCS_VERSION
		);

		wp_enqueue_script(
			'fflcs-admin',
			FFLCS_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			FFLCS_VERSION,
			true
		);

		wp_localize_script(
			'fflcs-admin',
			'fflcsAdmin',
			array(
				'restUrl' => rest_url( FFLCS_REST_NS . '/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'confirm'  => __( 'Are you sure?', 'ffl-checkout-solutions' ),
					'saving'   => __( 'Saving…', 'ffl-checkout-solutions' ),
					'saved'    => __( 'Saved.', 'ffl-checkout-solutions' ),
					'error'    => __( 'Something went wrong. Please try again.', 'ffl-checkout-solutions' ),
					'checking' => __( 'Checking…', 'ffl-checkout-solutions' ),
				),
			)
		);
	}

	// ── Rendering helpers ────────────────────────────────────────────────────

	/**
	 * Block rendering when the current user lacks the capability.
	 */
	protected function require_capability(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'ffl-checkout-solutions' ),
				403
			);
		}
	}

	/**
	 * Open the page wrapper.
	 *
	 * @param string $title    Page title.
	 * @param string $subtitle Optional supporting line.
	 */
	protected function open_page( string $title, string $subtitle = '' ): void {
		echo '<div class="wrap fflcs-admin">';
		echo '<h1 class="fflcs-admin__title">' . esc_html( $title ) . '</h1>';

		if ( '' !== $subtitle ) {
			echo '<p class="fflcs-admin__subtitle">' . esc_html( $subtitle ) . '</p>';
		}

		$this->render_notices();
	}

	/**
	 * Close the page wrapper.
	 */
	protected function close_page(): void {
		echo '</div>';
	}

	/**
	 * Render tab navigation and return the active tab.
	 *
	 * @param array<string,string> $tabs    Slug => label.
	 * @param string               $page    Page slug.
	 * @param string               $default Default tab slug.
	 */
	protected function render_tabs( array $tabs, string $page, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation state only.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( ! isset( $tabs[ $current ] ) ) {
			$current = '' !== $default ? $default : (string) array_key_first( $tabs );
		}

		echo '<nav class="nav-tab-wrapper fflcs-admin__tabs">';

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
				esc_url( admin_url( 'admin.php?page=' . $page . '&tab=' . $slug ) ),
				esc_attr( $slug === $current ? 'nav-tab-active' : '' ),
				esc_html( $label )
			);
		}

		echo '</nav>';

		return $current;
	}

	/**
	 * Print queued notices from redirects.
	 */
	protected function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only state from our own redirects.
		if ( isset( $_GET['fflcs_error'] ) ) {
			$this->notice( sanitize_text_field( wp_unslash( $_GET['fflcs_error'] ) ), 'error' );
		}

		if ( isset( $_GET['fflcs_message'] ) ) {
			$this->notice( sanitize_text_field( wp_unslash( $_GET['fflcs_message'] ) ), 'success' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$transient = get_transient( 'fflcs_wizard_notice' );

		if ( $transient ) {
			delete_transient( 'fflcs_wizard_notice' );
			$this->notice( (string) $transient, 'error' );
		}
	}

	/**
	 * Print one notice.
	 *
	 * @param string $message Message.
	 * @param string $type    success|error|warning|info.
	 */
	protected function notice( string $message, string $type = 'info' ): void {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Render a KPI card.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $hint  Supporting text.
	 */
	protected function stat_card( string $label, string $value, string $hint = '' ): void {
		echo '<div class="fflcs-stat">';
		echo '<span class="fflcs-stat__label">' . esc_html( $label ) . '</span>';
		echo '<span class="fflcs-stat__value">' . esc_html( $value ) . '</span>';

		if ( '' !== $hint ) {
			echo '<span class="fflcs-stat__hint">' . esc_html( $hint ) . '</span>';
		}

		echo '</div>';
	}

	/**
	 * Render a status badge.
	 *
	 * @param string $status Status slug.
	 */
	protected function status_badge( string $status ): void {
		printf(
			'<span class="fflcs-badge-status" style="--badge-color:%1$s">%2$s</span>',
			esc_attr( \FFLCS\Services\Theming::status_color( $status ) ),
			esc_html( fflcs_status_label( $status ) )
		);
	}

	/**
	 * A locked-feature panel shown in place of a pro screen's content.
	 *
	 * @param string $feature Feature name.
	 * @param string $tier    Required tier.
	 */
	protected function render_locked( string $feature, string $tier ): void {
		?>
		<div class="fflcs-locked">
			<h2><?php echo esc_html( $feature ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: plan name. */
					esc_html__( 'This is part of the %s plan.', 'ffl-checkout-solutions' ),
					esc_html( \FFLCS\License\Entitlement::tier_label( $tier ) )
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( \FFLCS\License\Entitlement::upgrade_url() ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'View plans', 'ffl-checkout-solutions' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-license' ) ); ?>">
					<?php esc_html_e( 'Enter a licence key', 'ffl-checkout-solutions' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Pagination links.
	 *
	 * @param int $total    Total rows.
	 * @param int $per_page Rows per page.
	 * @param int $paged    Current page.
	 */
	protected function render_pagination( int $total, int $per_page, int $paged ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );

		if ( $pages < 2 ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';

		echo '<span class="displaying-num">' . esc_html(
			sprintf(
				/* translators: %s: item count. */
				_n( '%s item', '%s items', $total, 'ffl-checkout-solutions' ),
				number_format_i18n( $total )
			)
		) . '</span>';

		echo wp_kses_post(
			(string) paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'total'     => $pages,
					'current'   => $paged,
					'type'      => 'plain',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			)
		);

		echo '</div></div>';
	}

	/**
	 * Format a stored UTC datetime for display in the site's timezone.
	 *
	 * @param string $datetime MySQL datetime.
	 */
	protected function format_datetime( string $datetime ): string {
		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '—';
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		if ( ! $timestamp ) {
			return '—';
		}

		return wp_date(
			(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
			$timestamp
		);
	}
}
