<?php
/**
 * Multiple sale watcher (Section 10.5).
 *
 * Detects two or more handgun transfers to the same buyer inside a rolling
 * five-business-day window — the trigger for ATF Form 3310.4 — and puts them in
 * a review queue with a same-day alert.
 *
 * It never files anything. Form 3310.4 is filed by the licensee, and this
 * module's entire job is to make sure a dealer notices the pattern in time to
 * do it. Detection is not disclosure.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Services\Mailer;
use FFLCS\Services\Nics_Tracker;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Form 3310.4 detection.
 */
class Multi_Sale_Watcher {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_transfer_status_changed', array( $this, 'on_status_changed' ), 30, 3 );
		add_action( 'admin_post_fflcs_mark_multi_sale_filed', array( $this, 'handle_mark_filed' ) );
	}

	/**
	 * Check for a reportable pattern when a firearm changes hands.
	 *
	 * Keyed on the disposition, not the order: the reporting trigger is the
	 * transfer of two handguns to the same person, whenever they were bought.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $new_status  New status.
	 * @param string $old_status  Previous status.
	 */
	public function on_status_changed( int $transfer_id, string $new_status, string $old_status ): void {
		if ( 'transferred' !== $new_status ) {
			return;
		}

		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer || 'handgun' !== (string) $transfer->product_type ) {
			return;
		}

		$this->evaluate( (string) $transfer->customer_email, (int) $transfer->customer_id );
	}

	/**
	 * Look for two or more handgun dispositions to one buyer in the window.
	 *
	 * @param string $email       Buyer email.
	 * @param int    $customer_id WooCommerce customer ID.
	 */
	private function evaluate( string $email, int $customer_id ): void {
		global $wpdb;

		if ( '' === $email ) {
			return;
		}

		$window_start = $this->window_start();
		$transfers    = fflcs_table( 'transfers' );

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, transfer_ref, transferred_date, manufacturer, model, serial_number
				 FROM {$transfers}
				 WHERE customer_email = %s
				   AND product_type = %s
				   AND status = %s
				   AND transferred_date >= %s
				 ORDER BY transferred_date ASC",
				$email,
				'handgun',
				'transferred',
				$window_start
			)
		);

		if ( count( $rows ) < 2 ) {
			return;
		}

		$ids = wp_list_pluck( $rows, 'id' );
		$key = implode( ',', array_map( 'intval', $ids ) );

		$flags = fflcs_table( 'multi_sale_flags' );

		// Already flagged for exactly this set — do not re-alert.
		$exists = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$flags} WHERE customer_email = %s AND transfer_ids = %s LIMIT 1",
				$email,
				$key
			)
		);

		if ( $exists ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$flags,
			array(
				'customer_email' => $email,
				'customer_id'    => $customer_id ?: null,
				'transfer_ids'   => $key,
				'window_start'   => $window_start,
				'window_end'     => current_time( 'Y-m-d' ),
				'status'         => 'pending_review',
				'created_at'     => fflcs_mysql_now(),
				'updated_at'     => fflcs_mysql_now(),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$this->alert( $email, $rows );
	}

	/**
	 * The start of the rolling five-business-day window.
	 */
	private function window_start(): string {
		$timestamp = current_time( 'timestamp' );
		$counted   = 0;

		while ( $counted < 5 ) {
			$timestamp = strtotime( '-1 day', $timestamp );
			$weekday   = (int) gmdate( 'N', $timestamp );

			if ( $weekday < 6 && ! Nics_Tracker::is_federal_holiday( gmdate( 'Y-m-d', $timestamp ) ) ) {
				++$counted;
			}
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Alert staff immediately.
	 *
	 * Immediately, because 27 CFR 478.126a requires the report by the close of
	 * business on the day of the second transfer. A digest tomorrow is too late.
	 *
	 * @param string   $email Buyer email.
	 * @param object[] $rows  Matching transfers.
	 */
	private function alert( string $email, array $rows ): void {
		$list = '';

		foreach ( $rows as $row ) {
			$list .= '<li>' . esc_html(
				sprintf(
					'%s — %s %s (%s), %s',
					(string) $row->transfer_ref,
					(string) $row->manufacturer,
					(string) $row->model,
					(string) ( $row->serial_number ?: __( 'no serial recorded', 'ffl-checkout-solutions' ) ),
					(string) $row->transferred_date
				)
			) . '</li>';
		}

		Mailer::send_admin_alert(
			__( 'Action today: multiple handgun transfers may require Form 3310.4', 'ffl-checkout-solutions' ),
			'<p>' . sprintf(
				/* translators: %d: number of transfers. */
				esc_html__( '%d handgun transfers to the same buyer completed within five business days.', 'ffl-checkout-solutions' ),
				count( $rows )
			) . '</p>'
			. '<p><strong>' . esc_html__( 'Buyer:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( $email ) . '</p>'
			. '<ul>' . $list . '</ul>'
			. '<p>' . esc_html__( 'Under 27 CFR 478.126a a report is due by the close of business on the day of the second transfer. Filing is your responsibility — this plugin has not contacted ATF and will not.', 'ffl-checkout-solutions' ) . '</p>'
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=fflcs-compliance&tab=multi-sale' ) ) . '">'
			. esc_html__( 'Open the review queue', 'ffl-checkout-solutions' ) . '</a></p>'
		);
	}

	/**
	 * Review queue.
	 */
	public function render_admin(): void {
		global $wpdb;

		$table = fflcs_table( 'multi_sale_flags' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY status ASC, created_at DESC LIMIT 200" );

		?>
		<p class="description">
			<?php esc_html_e( 'Detected patterns that may require ATF Form 3310.4. This plugin never files a report — mark an entry filed once you have submitted it yourself.', 'ffl-checkout-solutions' ); ?>
		</p>

		<table class="widefat striped fflcs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Detected', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Buyer', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Transfers', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Window', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ffl-checkout-solutions' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6" class="fflcs-empty"><?php esc_html_e( 'Nothing detected.', 'ffl-checkout-solutions' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $rows as $flag ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $flag->created_at ); ?></td>
						<td><?php echo esc_html( (string) $flag->customer_email ); ?></td>
						<td><?php echo esc_html( (string) $flag->transfer_ids ); ?></td>
						<td><?php echo esc_html( $flag->window_start . ' → ' . $flag->window_end ); ?></td>
						<td>
							<?php if ( 'filed' === (string) $flag->status ) : ?>
								<span class="fflcs-pill fflcs-pill--ok"><?php esc_html_e( 'Filed', 'ffl-checkout-solutions' ); ?></span>
							<?php else : ?>
								<span class="fflcs-pill fflcs-pill--warn"><?php esc_html_e( 'Needs review', 'ffl-checkout-solutions' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( 'filed' !== (string) $flag->status ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="fflcs_mark_multi_sale_filed">
									<input type="hidden" name="flag_id" value="<?php echo esc_attr( (string) $flag->id ); ?>">
									<?php wp_nonce_field( 'fflcs_mark_multi_sale_filed' ); ?>
									<button class="button button-small"><?php esc_html_e( 'Mark filed', 'ffl-checkout-solutions' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Record that a staff member filed the report.
	 */
	public function handle_mark_filed(): void {
		global $wpdb;

		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_mark_multi_sale_filed' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$flag_id = absint( wp_unslash( $_POST['flag_id'] ?? 0 ) );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'multi_sale_flags' ),
			array(
				'status'     => 'filed',
				'filed_at'   => fflcs_mysql_now(),
				'filed_by'   => get_current_user_id(),
				'updated_at' => fflcs_mysql_now(),
			),
			array( 'id' => $flag_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		// Recorded as an audit event: who said it was filed, and when.
		Transfer_Service::log_event(
			0,
			'multi_sale_marked_filed',
			array(
				'actor' => wp_get_current_user()->user_login ?: 'admin',
				'notes' => sprintf(
					/* translators: %d: flag row ID. */
					__( 'Staff recorded that Form 3310.4 was filed for detection #%d.', 'ffl-checkout-solutions' ),
					$flag_id
				),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-compliance',
					'tab'           => 'multi-sale',
					'fflcs_message' => rawurlencode( __( 'Marked as filed.', 'ffl-checkout-solutions' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
