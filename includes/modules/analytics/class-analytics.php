<?php
/**
 * Analytics module (Section 10.10).
 *
 * Charts render on a vendored canvas renderer — no CDN, no external script, no
 * data leaving the site. For a plugin whose event stream describes firearm
 * transfers, shipping the chart library is the only defensible option.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Services\Analytics as Analytics_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Reporting screen.
 */
class Analytics {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Register the Analytics screen.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'fflcs-dashboard',
			__( 'Analytics', 'ffl-checkout-solutions' ),
			__( 'Analytics', 'ffl-checkout-solutions' ),
			'manage_woocommerce',
			'fflcs-analytics',
			array( $this, 'render' )
		);
	}

	/**
	 * Load the chart renderer on plugin screens.
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'fflcs' ) ) {
			return;
		}

		wp_enqueue_script(
			'fflcs-charts',
			FFLCS_PLUGIN_URL . 'assets/js/charts.js',
			array(),
			FFLCS_VERSION,
			true
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view analytics.', 'ffl-checkout-solutions' ), 403 );
		}

		$kpis     = Analytics_Service::kpis();
		$funnel   = Analytics_Service::portal_funnel( 30 );
		$statuses = Analytics_Service::transfers_by_status();

		$series = array(
			'created'   => Analytics_Service::daily_series( 'status_payment_confirmed', 30 ),
			'shipped'   => Analytics_Service::daily_series( 'status_shipped_to_dealer', 30 ),
			'completed' => Analytics_Service::daily_series( 'status_transferred', 30 ),
		);

		?>
		<div class="wrap fflcs-admin">
			<h1 class="fflcs-admin__title"><?php esc_html_e( 'Analytics', 'ffl-checkout-solutions' ); ?></h1>
			<p class="fflcs-admin__subtitle"><?php esc_html_e( 'Computed from this site\'s own data. Nothing is sent anywhere.', 'ffl-checkout-solutions' ); ?></p>

			<div class="fflcs-stats">
				<div class="fflcs-stat">
					<span class="fflcs-stat__label"><?php esc_html_e( 'Transfers, 30 days', 'ffl-checkout-solutions' ); ?></span>
					<span class="fflcs-stat__value"><?php echo esc_html( number_format_i18n( (int) $kpis['transfers_30d'] ) ); ?></span>
				</div>
				<div class="fflcs-stat">
					<span class="fflcs-stat__label"><?php esc_html_e( 'Open', 'ffl-checkout-solutions' ); ?></span>
					<span class="fflcs-stat__value"><?php echo esc_html( number_format_i18n( (int) $kpis['open_transfers'] ) ); ?></span>
				</div>
				<div class="fflcs-stat">
					<span class="fflcs-stat__label"><?php esc_html_e( 'Completed', 'ffl-checkout-solutions' ); ?></span>
					<span class="fflcs-stat__value"><?php echo esc_html( number_format_i18n( (int) $kpis['completed_transfers'] ) ); ?></span>
				</div>
				<div class="fflcs-stat">
					<span class="fflcs-stat__label"><?php esc_html_e( 'Median days to pickup', 'ffl-checkout-solutions' ); ?></span>
					<span class="fflcs-stat__value"><?php echo esc_html( (string) $kpis['median_days'] ); ?></span>
				</div>
			</div>

			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'Transfer volume, last 30 days', 'ffl-checkout-solutions' ); ?></h2>
				<canvas id="fflcs-volume-chart" height="240"
					data-chart="line"
					data-series="<?php echo esc_attr( (string) wp_json_encode( $series ) ); ?>"></canvas>
			</section>

			<div class="fflcs-grid">
				<section class="fflcs-panel">
					<h2><?php esc_html_e( 'By status', 'ffl-checkout-solutions' ); ?></h2>
					<canvas id="fflcs-status-chart" height="240"
						data-chart="bar"
						data-series="<?php echo esc_attr( (string) wp_json_encode( array_filter( $statuses ) ) ); ?>"></canvas>
				</section>

				<section class="fflcs-panel">
					<h2><?php esc_html_e( 'Dealer confirmation funnel, 30 days', 'ffl-checkout-solutions' ); ?></h2>
					<table class="widefat striped fflcs-table">
						<tbody>
							<?php
							$labels = array(
								'token_issued'   => __( 'Confirmation links issued', 'ffl-checkout-solutions' ),
								'email_sent'     => __( 'Emails sent', 'ffl-checkout-solutions' ),
								'portal_viewed'  => __( 'Portal opened', 'ffl-checkout-solutions' ),
								'confirmed'      => __( 'Receipt confirmed', 'ffl-checkout-solutions' ),
								'issue_reported' => __( 'Problems reported', 'ffl-checkout-solutions' ),
							);

							foreach ( $labels as $key => $label ) :
								?>
								<tr>
									<td><?php echo esc_html( $label ); ?></td>
									<td><strong><?php echo esc_html( number_format_i18n( (int) ( $funnel[ $key ] ?? 0 ) ) ); ?></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</section>
			</div>
		</div>
		<?php
	}
}
