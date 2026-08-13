<?php
/**
 * FFL compliance verification hub (Section 10.6).
 *
 * Tracks certified copies of dealer licences, their expiry dates, and a manual
 * eZ Check log.
 *
 * Manual, and that is not a limitation to work around: ATF publishes no eZ Check
 * API. Any plugin claiming to verify a licence with ATF automatically is either
 * scraping a service that forbids it or making it up. This module records what a
 * staff member checked and when, which is what an audit actually asks for.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Installer;
use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Dealer licence document tracking.
 */
class Verification_Hub {

	/**
	 * Days before expiry at which reminders fire.
	 *
	 * @var int[]
	 */
	const REMINDER_DAYS = array( 60, 30, 7, 0 );

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_compliance_reminder', array( $this, 'send_expiry_reminders' ) );
		add_action( 'admin_post_fflcs_log_ez_check', array( $this, 'handle_ez_check_log' ) );
	}

	/**
	 * Email staff about licences approaching expiry.
	 *
	 * Each threshold is marked once it has been sent, so a document sitting
	 * unrenewed does not generate an identical email every night.
	 */
	public function send_expiry_reminders(): void {
		global $wpdb;

		$table = fflcs_table( 'ffl_verification_documents' );

		foreach ( self::REMINDER_DAYS as $days ) {
			$column = 'reminder_' . $days . '_sent_at';
			$target = gmdate( 'Y-m-d', strtotime( "+{$days} days" ) );

			$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table}
					 WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s AND {$column} IS NULL
					 LIMIT 25",
					'active',
					$target
				)
			);

			foreach ( $rows as $document ) {
				$dealer = Dealer_Service::get( (int) $document->dealer_id );

				Mailer::send_admin_alert(
					0 === $days
						? __( 'A dealer licence copy on file has expired', 'ffl-checkout-solutions' )
						: sprintf(
							/* translators: %d: number of days. */
							__( 'A dealer licence copy expires in %d days', 'ffl-checkout-solutions' ),
							$days
						),
					'<p>' . esc_html(
						sprintf(
							/* translators: 1: dealer name, 2: expiry date. */
							__( 'The certified copy on file for %1$s expires on %2$s.', 'ffl-checkout-solutions' ),
							$dealer ? (string) $dealer->business_name : __( 'a dealer', 'ffl-checkout-solutions' ),
							(string) $document->expires_at
						)
					) . '</p>'
					. '<p>' . esc_html__( 'Request an updated copy before shipping to them again.', 'ffl-checkout-solutions' ) . '</p>'
				);

				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array( $column => fflcs_mysql_now() ),
					array( 'id' => (int) $document->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		// Separately, mark anything already past its date so the review queue
		// is accurate even if the reminder mail failed.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET status = %s WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s",
				'expired',
				'active',
				current_time( 'Y-m-d' )
			)
		);
	}

	/**
	 * Record a manual eZ Check result.
	 */
	public function handle_ez_check_log(): void {
		global $wpdb;

		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to log verifications.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_log_ez_check' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$dealer_id = absint( wp_unslash( $_POST['dealer_id'] ?? 0 ) );
		$result    = sanitize_key( wp_unslash( $_POST['result'] ?? '' ) );
		$notes     = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$dealer = Dealer_Service::get( $dealer_id );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'ffl_verifications' ),
			array(
				'dealer_id'          => $dealer_id,
				'method'             => 'ez_check_manual',
				'result_status'      => in_array( $result, array( 'valid', 'invalid', 'not_found' ), true ) ? $result : 'manual_review_required',
				'checked_ffl_number' => $dealer ? (string) $dealer->license_number : '',
				'notes'              => $notes,
				'verified_by'        => get_current_user_id(),
				'created_at'         => fflcs_mysql_now(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-compliance',
					'tab'           => 'verification',
					'fflcs_message' => rawurlencode( __( 'Verification logged.', 'ffl-checkout-solutions' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Compare a dealer's stored licence against the synced ATF data.
	 *
	 * This is the only automated check available, and it is honest about its
	 * limits: it says whether our own ATF snapshot still lists the dealer with
	 * an unexpired licence. It is not a live ATF query.
	 *
	 * @param int $dealer_id Dealer ID.
	 * @return array<string,mixed>
	 */
	public static function check_against_atf_data( int $dealer_id ): array {
		$dealer = Dealer_Service::get( $dealer_id );

		if ( ! $dealer ) {
			return array(
				'result' => 'not_found',
				'note'   => __( 'No dealer record.', 'ffl-checkout-solutions' ),
			);
		}

		$expired = $dealer->license_expires && strtotime( (string) $dealer->license_expires ) < time();

		return array(
			'result'       => $expired ? 'expired' : ( (int) $dealer->is_active ? 'listed' : 'not_listed' ),
			'license'      => (string) $dealer->license_number,
			'expires'      => (string) $dealer->license_expires,
			'last_synced'  => (string) $dealer->last_synced,
			'note'         => __( 'Checked against this site\'s most recent ATF import, not against ATF directly. ATF publishes no verification API — confirm through eZ Check and log the result.', 'ffl-checkout-solutions' ),
		);
	}

	/**
	 * Admin panel.
	 */
	public function render_admin(): void {
		global $wpdb;

		$documents = fflcs_table( 'ffl_verification_documents' );
		$checks    = fflcs_table( 'ffl_verifications' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$expiring = (array) $wpdb->get_results( "SELECT * FROM {$documents} WHERE status IN ('active','expired') ORDER BY expires_at ASC LIMIT 50" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$recent = (array) $wpdb->get_results( "SELECT * FROM {$checks} ORDER BY created_at DESC LIMIT 25" );

		?>
		<p class="description">
			<?php esc_html_e( 'Certified licence copies and their expiry dates, plus your manual eZ Check log. ATF publishes no verification API, so eZ Check results are recorded by hand — that record is what an audit asks for.', 'ffl-checkout-solutions' ); ?>
		</p>

		<div class="fflcs-grid">
			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'Documents on file', 'ffl-checkout-solutions' ); ?></h2>

				<?php if ( ! $expiring ) : ?>
					<p class="fflcs-empty"><?php esc_html_e( 'No certified copies on file yet.', 'ffl-checkout-solutions' ); ?></p>
				<?php else : ?>
					<table class="widefat striped fflcs-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Dealer', 'ffl-checkout-solutions' ); ?></th>
								<th><?php esc_html_e( 'Expires', 'ffl-checkout-solutions' ); ?></th>
								<th><?php esc_html_e( 'Status', 'ffl-checkout-solutions' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $expiring as $document ) :
								$dealer = Dealer_Service::get( (int) $document->dealer_id );
								?>
								<tr>
									<td><?php echo esc_html( $dealer ? (string) $dealer->business_name : '—' ); ?></td>
									<td><?php echo esc_html( (string) ( $document->expires_at ?: '—' ) ); ?></td>
									<td>
										<?php if ( 'expired' === (string) $document->status ) : ?>
											<span class="fflcs-pill fflcs-pill--warn"><?php esc_html_e( 'Expired', 'ffl-checkout-solutions' ); ?></span>
										<?php else : ?>
											<span class="fflcs-pill fflcs-pill--ok"><?php esc_html_e( 'Active', 'ffl-checkout-solutions' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<p class="description">
					<?php
					printf(
						/* translators: %s: private directory path. */
						esc_html__( 'Uploaded copies are stored outside the media library, in %s, and served only through a capability check.', 'ffl-checkout-solutions' ),
						'<code>' . esc_html( str_replace( ABSPATH, '', Installer::private_dir() ) ) . '</code>'
					);
					?>
				</p>
			</section>

			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'eZ Check log', 'ffl-checkout-solutions' ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fflcs_log_ez_check">
					<?php wp_nonce_field( 'fflcs_log_ez_check' ); ?>

					<p>
						<label for="ez_dealer_id"><?php esc_html_e( 'Dealer ID', 'ffl-checkout-solutions' ); ?></label>
						<input type="number" id="ez_dealer_id" name="dealer_id" required>
					</p>
					<p>
						<label for="ez_result"><?php esc_html_e( 'Result', 'ffl-checkout-solutions' ); ?></label>
						<select id="ez_result" name="result">
							<option value="valid"><?php esc_html_e( 'Valid', 'ffl-checkout-solutions' ); ?></option>
							<option value="invalid"><?php esc_html_e( 'Invalid', 'ffl-checkout-solutions' ); ?></option>
							<option value="not_found"><?php esc_html_e( 'Not found', 'ffl-checkout-solutions' ); ?></option>
						</select>
					</p>
					<p>
						<label for="ez_notes"><?php esc_html_e( 'Notes', 'ffl-checkout-solutions' ); ?></label>
						<textarea id="ez_notes" name="notes" rows="2" class="large-text"></textarea>
					</p>

					<button class="button button-primary"><?php esc_html_e( 'Log this check', 'ffl-checkout-solutions' ); ?></button>
				</form>

				<?php if ( $recent ) : ?>
					<table class="widefat striped fflcs-table" style="margin-top:16px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'When', 'ffl-checkout-solutions' ); ?></th>
								<th><?php esc_html_e( 'Licence', 'ffl-checkout-solutions' ); ?></th>
								<th><?php esc_html_e( 'Result', 'ffl-checkout-solutions' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent as $check ) : ?>
								<tr>
									<td><?php echo esc_html( (string) $check->created_at ); ?></td>
									<td><code><?php echo esc_html( (string) $check->checked_ffl_number ); ?></code></td>
									<td><?php echo esc_html( (string) $check->result_status ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}
}
