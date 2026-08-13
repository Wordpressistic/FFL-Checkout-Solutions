<?php
/**
 * NICS tracking module (Section 10.2).
 *
 * Wires the tracker's cron sweep and the manual-entry admin action. All the
 * date arithmetic and outcome recording lives in Services\Nics_Tracker.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Services\Nics_Tracker;

defined( 'ABSPATH' ) || exit;

/**
 * Background-check tracking.
 */
class Nics_Tracking {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_nics_delay_alert', array( Nics_Tracker::class, 'alert_elapsed_delays' ) );
		add_action( 'admin_post_fflcs_record_nics', array( $this, 'handle_record' ) );
		add_action( 'fflcs_transfer_detail_panels', array( $this, 'render_panel' ) );
	}

	/**
	 * Manual entry panel on the transfer detail screen.
	 *
	 * Manual entry is the primary path, not a fallback: most retailers get a
	 * NICS result verbally or on screen and type it in.
	 *
	 * @param object $transfer Transfer row.
	 */
	public function render_panel( object $transfer ): void {
		?>
		<section class="fflcs-panel">
			<h2><?php esc_html_e( 'Background check', 'ffl-checkout-solutions' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fflcs_record_nics">
				<input type="hidden" name="transfer_id" value="<?php echo esc_attr( (string) $transfer->id ); ?>">
				<?php wp_nonce_field( 'fflcs_record_nics' ); ?>

				<p>
					<label for="nics_outcome"><?php esc_html_e( 'Outcome', 'ffl-checkout-solutions' ); ?></label><br>
					<select id="nics_outcome" name="outcome">
						<?php foreach ( Nics_Tracker::outcomes() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( (string) $transfer->nics_status, $slug ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p>
					<label for="nics_ntn_entry"><?php esc_html_e( 'Transaction number (NTN)', 'ffl-checkout-solutions' ); ?></label><br>
					<input type="text" id="nics_ntn_entry" name="ntn" class="regular-text" value="<?php echo esc_attr( (string) $transfer->nics_ntn ); ?>">
				</p>

				<p>
					<label for="nics_response_entry"><?php esc_html_e( 'Notes', 'ffl-checkout-solutions' ); ?></label><br>
					<textarea id="nics_response_entry" name="response" rows="2" class="large-text"></textarea>
				</p>

				<?php if ( $transfer->nics_delay_expires ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: date. */
							esc_html__( 'The three-business-day window for this delay ends %s. Proceeding after that date is the dealer\'s decision alone.', 'ffl-checkout-solutions' ),
							esc_html( (string) $transfer->nics_delay_expires )
						);
						?>
					</p>
				<?php endif; ?>

				<p><button class="button button-primary"><?php esc_html_e( 'Record outcome', 'ffl-checkout-solutions' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	/**
	 * Persist a manually entered outcome.
	 */
	public function handle_record(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to record background-check results.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_record_nics' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$transfer_id = absint( wp_unslash( $_POST['transfer_id'] ?? 0 ) );

		$result = Nics_Tracker::record(
			$transfer_id,
			sanitize_key( wp_unslash( $_POST['outcome'] ?? '' ) ),
			array(
				'ntn'      => sanitize_text_field( wp_unslash( $_POST['ntn'] ?? '' ) ),
				'response' => sanitize_textarea_field( wp_unslash( $_POST['response'] ?? '' ) ),
				'source'   => 'manual',
				'actor'    => wp_get_current_user()->user_login ?: 'staff',
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$args = array(
			'page'     => 'fflcs-transfers',
			'transfer' => $transfer_id,
		);

		$args += is_wp_error( $result )
			? array( 'fflcs_error' => rawurlencode( $result->get_error_message() ) )
			: array( 'fflcs_message' => rawurlencode( __( 'Background-check outcome recorded.', 'ffl-checkout-solutions' ) ) );

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
