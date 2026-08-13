<?php
/**
 * Carriers screen — EasyPost configuration and manual status checks.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

use FFLCS\Addon_Manager;
use FFLCS\Carriers\Carrier_Registry;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Carrier operations.
 */
class Admin_Carriers extends Admin_Page {

	/**
	 * Register hooks and menu.
	 */
	public function init(): void {
		parent::init();

		add_action( 'admin_post_fflcs_poll_carriers', array( $this, 'handle_poll' ) );
	}

	/**
	 * Menu priority.
	 */
	protected function menu_priority(): int {
		return 12;
	}

	/**
	 * Register the submenu.
	 */
	public function register_menu(): void {
		$this->ensure_parent_menu();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Carriers', 'ffl-checkout-solutions' ),
			__( 'Carriers', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			'fflcs-carriers',
			array( $this, 'render' )
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$this->require_capability();

		$this->open_page(
			__( 'Carriers', 'ffl-checkout-solutions' ),
			__( 'Shipment tracking for firearms in transit to your dealers.', 'ffl-checkout-solutions' )
		);

		if ( ! Addon_Manager::is_active( 'carrier_tracking' ) ) {
			$this->render_locked( __( 'Carrier Tracking & Labels', 'ffl-checkout-solutions' ), 'pro' );

			echo '<p class="description">';
			esc_html_e( 'Tracking numbers can still be recorded by hand on any transfer, and the customer tracking page will link to the carrier. This module adds automatic status updates and label purchase.', 'ffl-checkout-solutions' );
			echo '</p>';

			$this->close_page();
			return;
		}

		$in_flight = Carrier_Registry::in_flight_transfers();

		?>
		<div class="fflcs-grid">
			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'Configuration', 'ffl-checkout-solutions' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'API keys and the webhook secret live under Settings → Carriers, encrypted at rest.', 'ffl-checkout-solutions' ); ?>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-settings&tab=carriers' ) ); ?>">
						<?php esc_html_e( 'Open carrier settings', 'ffl-checkout-solutions' ); ?>
					</a>
				</p>

				<h3><?php esc_html_e( 'Webhook endpoint', 'ffl-checkout-solutions' ); ?></h3>
				<p><code><?php echo esc_html( rest_url( FFLCS_REST_NS . '/webhooks/carrier' ) ); ?></code></p>
				<p class="description">
					<?php esc_html_e( 'Point your carrier account at this URL to receive status pushes. Every request is HMAC-verified against your webhook secret.', 'ffl-checkout-solutions' ); ?>
				</p>
			</section>

			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'In transit', 'ffl-checkout-solutions' ); ?></h2>

				<?php if ( ! $in_flight ) : ?>
					<p class="fflcs-empty"><?php esc_html_e( 'Nothing is currently in transit with a tracking number recorded.', 'ffl-checkout-solutions' ); ?></p>
				<?php else : ?>
					<table class="widefat striped fflcs-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Transfer', 'ffl-checkout-solutions' ); ?></th>
								<th><?php esc_html_e( 'Carrier', 'ffl-checkout-solutions' ); ?></th>
								<th><?php esc_html_e( 'Tracking', 'ffl-checkout-solutions' ); ?></th>
								<th><?php esc_html_e( 'Shipped', 'ffl-checkout-solutions' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $in_flight as $transfer ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-transfers&transfer=' . (int) $transfer->id ) ); ?>">
											<?php echo esc_html( (string) $transfer->transfer_ref ); ?>
										</a>
									</td>
									<td><?php echo esc_html( \FFLCS\Services\Carrier_Links::label( (string) $transfer->carrier ) ); ?></td>
									<td><code><?php echo esc_html( (string) $transfer->tracking_number ); ?></code></td>
									<td><?php echo esc_html( (string) ( $transfer->shipped_date ?: '—' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="fflcs_poll_carriers">
						<?php wp_nonce_field( 'fflcs_poll_carriers' ); ?>
						<p><button class="button"><?php esc_html_e( 'Check all now', 'ffl-checkout-solutions' ); ?></button></p>
					</form>
				<?php endif; ?>
			</section>
		</div>
		<?php

		$this->close_page();
	}

	/**
	 * Poll every in-flight shipment on demand.
	 */
	public function handle_poll(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_poll_carriers' );

		$checked = 0;

		foreach ( Carrier_Registry::in_flight_transfers() as $transfer ) {
			$result = Carrier_Registry::poll_transfer( (int) $transfer->id );

			if ( ! is_wp_error( $result ) ) {
				++$checked;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-carriers',
					'fflcs_message' => rawurlencode(
						sprintf(
							/* translators: %d: number of shipments checked. */
							_n( 'Checked %d shipment.', 'Checked %d shipments.', $checked, 'ffl-checkout-solutions' ),
							$checked
						)
					),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
