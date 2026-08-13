<?php
/**
 * Operations toolset (Section 10.12).
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk actions and health alerts.
 */
class Ops_Tools {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_dealer_health_check', array( $this, 'health_check' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 22 );
		add_action( 'admin_post_fflcs_bulk_dealer_fee', array( $this, 'handle_bulk_fee' ) );
	}

	/**
	 * Register the Operations screen.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'fflcs-dashboard',
			__( 'Operations', 'ffl-checkout-solutions' ),
			__( 'Operations', 'ffl-checkout-solutions' ),
			'manage_woocommerce',
			'fflcs-ops',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ffl-checkout-solutions' ), 403 );
		}

		$alerts = Dealer_Service::health_alerts();

		?>
		<div class="wrap fflcs-admin">
			<h1 class="fflcs-admin__title"><?php esc_html_e( 'Operations', 'ffl-checkout-solutions' ); ?></h1>

			<div class="fflcs-grid">
				<section class="fflcs-panel">
					<h2><?php esc_html_e( 'Bulk transfer fee by state', 'ffl-checkout-solutions' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Sets the same fee on every dealer in a state. Useful after negotiating a regional rate.', 'ffl-checkout-solutions' ); ?></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="fflcs_bulk_dealer_fee">
						<?php wp_nonce_field( 'fflcs_bulk_dealer_fee' ); ?>

						<p>
							<label for="bulk_state"><?php esc_html_e( 'State', 'ffl-checkout-solutions' ); ?></label>
							<select id="bulk_state" name="state" required>
								<?php foreach ( fflcs_us_states() as $code ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $code ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>

						<p>
							<label for="bulk_fee"><?php esc_html_e( 'Fee', 'ffl-checkout-solutions' ); ?></label>
							<input type="number" step="0.01" min="0" id="bulk_fee" name="fee" required>
						</p>

						<button class="button button-primary"><?php esc_html_e( 'Apply', 'ffl-checkout-solutions' ); ?></button>
					</form>
				</section>

				<section class="fflcs-panel">
					<h2><?php esc_html_e( 'Dealer health', 'ffl-checkout-solutions' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Dealers with an unusually high rate of reported problems, across at least five transfers.', 'ffl-checkout-solutions' ); ?></p>

					<?php if ( ! $alerts ) : ?>
						<p class="fflcs-empty"><?php esc_html_e( 'No dealers are flagged.', 'ffl-checkout-solutions' ); ?></p>
					<?php else : ?>
						<table class="widefat striped fflcs-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Dealer', 'ffl-checkout-solutions' ); ?></th>
									<th><?php esc_html_e( 'Transfers', 'ffl-checkout-solutions' ); ?></th>
									<th><?php esc_html_e( 'Issues', 'ffl-checkout-solutions' ); ?></th>
									<th><?php esc_html_e( 'Rate', 'ffl-checkout-solutions' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $alerts as $alert ) : ?>
									<tr>
										<td>
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-dealers&dealer=' . (int) $alert->id ) ); ?>">
												<?php echo esc_html( (string) $alert->business_name ); ?>
											</a>
										</td>
										<td><?php echo esc_html( (string) $alert->total_transfers ); ?></td>
										<td><?php echo esc_html( (string) $alert->issue_count ); ?></td>
										<td><?php echo esc_html( round( (float) $alert->issue_rate * 100 ) . '%' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Nightly health sweep.
	 */
	public function health_check(): void {
		$alerts = Dealer_Service::health_alerts();

		if ( ! $alerts ) {
			return;
		}

		$list = '';

		foreach ( $alerts as $alert ) {
			$list .= '<li>' . esc_html(
				sprintf(
					/* translators: 1: dealer name, 2: issue count, 3: transfer count. */
					__( '%1$s — %2$d problems across %3$d transfers', 'ffl-checkout-solutions' ),
					(string) $alert->business_name,
					(int) $alert->issue_count,
					(int) $alert->total_transfers
				)
			) . '</li>';
		}

		Mailer::send_admin_alert(
			__( 'Dealers with a high rate of reported problems', 'ffl-checkout-solutions' ),
			'<p>' . esc_html__( 'These dealers have a higher-than-usual rate of reported problems. Worth a phone call.', 'ffl-checkout-solutions' ) . '</p><ul>' . $list . '</ul>'
		);
	}

	/**
	 * Apply a bulk fee.
	 */
	public function handle_bulk_fee(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_bulk_dealer_fee' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$updated = Dealer_Service::bulk_set_fee_by_state(
			sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ),
			(float) ( $_POST['fee'] ?? 0 )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-ops',
					'fflcs_message' => rawurlencode(
						sprintf(
							/* translators: %d: number of dealers updated. */
							_n( 'Updated %d dealer.', 'Updated %d dealers.', $updated, 'ffl-checkout-solutions' ),
							$updated
						)
					),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
