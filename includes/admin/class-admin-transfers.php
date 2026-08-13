<?php
/**
 * Transfers screen — list, filter, detail view, CSV export.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

use FFLCS\Services\Carrier_Links;
use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Token;
use FFLCS\Services\Tracking_Links;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Transfer management.
 */
class Admin_Transfers extends Admin_Page {

	/**
	 * Register hooks and menu.
	 */
	public function init(): void {
		parent::init();

		add_action( 'admin_post_fflcs_save_transfer', array( $this, 'handle_save' ) );
		add_action( 'admin_post_fflcs_export_transfers', array( $this, 'handle_export' ) );
		add_action( 'admin_post_fflcs_resend_dealer_link', array( $this, 'handle_resend' ) );
	}

	/**
	 * Menu priority.
	 */
	protected function menu_priority(): int {
		return 7;
	}

	/**
	 * Register the submenu.
	 */
	public function register_menu(): void {
		$this->ensure_parent_menu();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Transfers', 'ffl-checkout-solutions' ),
			__( 'Transfers', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			'fflcs-transfers',
			array( $this, 'render' )
		);
	}

	/**
	 * Render list or detail.
	 */
	public function render(): void {
		$this->require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation state only.
		$transfer_id = isset( $_GET['transfer'] ) ? absint( wp_unslash( $_GET['transfer'] ) ) : 0;

		if ( $transfer_id ) {
			$this->render_detail( $transfer_id );
			return;
		}

		$this->render_list();
	}

	/**
	 * Filterable transfer list.
	 */
	private function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 25;

		$results = Transfer_Service::query(
			array(
				'status'   => $status,
				'search'   => $search,
				'page'     => $paged,
				'per_page' => $per_page,
			)
		);

		$this->open_page(
			__( 'Transfers', 'ffl-checkout-solutions' ),
			__( 'Every firearm transfer this store has handled, and where each one has got to.', 'ffl-checkout-solutions' )
		);

		?>
		<div class="fflcs-toolbar">
			<form method="get" class="fflcs-toolbar__search">
				<input type="hidden" name="page" value="fflcs-transfers">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Reference, customer, serial or product', 'ffl-checkout-solutions' ); ?>">
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'ffl-checkout-solutions' ); ?></option>
					<?php foreach ( fflcs_transfer_statuses() as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status, $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button"><?php esc_html_e( 'Filter', 'ffl-checkout-solutions' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fflcs_export_transfers">
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
				<?php wp_nonce_field( 'fflcs_export' ); ?>
				<button class="button"><?php esc_html_e( 'Export CSV', 'ffl-checkout-solutions' ); ?></button>
			</form>
		</div>

		<table class="widefat striped fflcs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Reference', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Item', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Dealer', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Created', 'ffl-checkout-solutions' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $results['items'] ) : ?>
					<tr><td colspan="6" class="fflcs-empty"><?php esc_html_e( 'No transfers matched.', 'ffl-checkout-solutions' ); ?></td></tr>
				<?php endif; ?>

				<?php
				foreach ( $results['items'] as $transfer ) :
					$dealer = Dealer_Service::get( (int) $transfer->dealer_id );
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-transfers&transfer=' . (int) $transfer->id ) ); ?>">
								<strong><?php echo esc_html( (string) $transfer->transfer_ref ); ?></strong>
							</a>
						</td>
						<td>
							<?php echo esc_html( (string) $transfer->customer_name ); ?><br>
							<span class="description"><?php echo esc_html( (string) $transfer->customer_email ); ?></span>
						</td>
						<td><?php echo esc_html( (string) $transfer->product_name ); ?></td>
						<td><?php echo esc_html( $dealer ? (string) $dealer->business_name : '—' ); ?></td>
						<td><?php $this->status_badge( (string) $transfer->status ); ?></td>
						<td><?php echo esc_html( $this->format_datetime( (string) $transfer->created_at ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$this->render_pagination( $results['total'], $per_page, $paged );
		$this->close_page();
	}

	/**
	 * Single transfer detail and editor.
	 *
	 * @param int $transfer_id Transfer ID.
	 */
	private function render_detail( int $transfer_id ): void {
		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer ) {
			$this->open_page( __( 'Transfer', 'ffl-checkout-solutions' ) );
			$this->notice( __( 'That transfer no longer exists.', 'ffl-checkout-solutions' ), 'error' );
			$this->close_page();
			return;
		}

		$dealer = Dealer_Service::get( (int) $transfer->dealer_id );
		$events = Transfer_Service::events( $transfer_id );
		$token  = Token::active_for_transfer( $transfer_id );

		$this->open_page(
			(string) $transfer->transfer_ref,
			(string) $transfer->product_name
		);

		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-transfers' ) ); ?>">&larr; <?php esc_html_e( 'All transfers', 'ffl-checkout-solutions' ); ?></a></p>

		<div class="fflcs-grid">
			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'Record', 'ffl-checkout-solutions' ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fflcs_save_transfer">
					<input type="hidden" name="transfer_id" value="<?php echo esc_attr( (string) $transfer_id ); ?>">
					<?php wp_nonce_field( 'fflcs_save_transfer' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="status"><?php esc_html_e( 'Status', 'ffl-checkout-solutions' ); ?></label></th>
							<td>
								<select id="status" name="status">
									<?php foreach ( fflcs_transfer_statuses() as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( (string) $transfer->status, $slug ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Every change is written to the audit log with your username and the time.', 'ffl-checkout-solutions' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="serial_number"><?php esc_html_e( 'Serial number', 'ffl-checkout-solutions' ); ?></label></th>
							<td>
								<input type="text" id="serial_number" name="serial_number" class="regular-text" value="<?php echo esc_attr( (string) $transfer->serial_number ); ?>">
								<p class="description"><?php esc_html_e( 'Required for the bound book. Record it when the firearm physically arrives.', 'ffl-checkout-solutions' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="manufacturer"><?php esc_html_e( 'Manufacturer', 'ffl-checkout-solutions' ); ?></label></th>
							<td><input type="text" id="manufacturer" name="manufacturer" class="regular-text" value="<?php echo esc_attr( (string) $transfer->manufacturer ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="model"><?php esc_html_e( 'Model', 'ffl-checkout-solutions' ); ?></label></th>
							<td><input type="text" id="model" name="model" class="regular-text" value="<?php echo esc_attr( (string) $transfer->model ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="caliber"><?php esc_html_e( 'Caliber / gauge', 'ffl-checkout-solutions' ); ?></label></th>
							<td><input type="text" id="caliber" name="caliber" class="regular-text" value="<?php echo esc_attr( (string) $transfer->caliber ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="carrier"><?php esc_html_e( 'Carrier', 'ffl-checkout-solutions' ); ?></label></th>
							<td>
								<select id="carrier" name="carrier">
									<option value=""><?php esc_html_e( '— none —', 'ffl-checkout-solutions' ); ?></option>
									<?php foreach ( Carrier_Links::carriers() as $code => $meta ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( (string) $transfer->carrier, $code ); ?>><?php echo esc_html( (string) $meta['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="tracking_number"><?php esc_html_e( 'Tracking number', 'ffl-checkout-solutions' ); ?></label></th>
							<td><input type="text" id="tracking_number" name="tracking_number" class="regular-text" value="<?php echo esc_attr( (string) $transfer->tracking_number ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="nics_ntn"><?php esc_html_e( 'NICS transaction number', 'ffl-checkout-solutions' ); ?></label></th>
							<td><input type="text" id="nics_ntn" name="nics_ntn" class="regular-text" value="<?php echo esc_attr( (string) $transfer->nics_ntn ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="staff_notes"><?php esc_html_e( 'Staff notes', 'ffl-checkout-solutions' ); ?></label></th>
							<td><textarea id="staff_notes" name="staff_notes" rows="4" class="large-text"><?php echo esc_textarea( (string) $transfer->staff_notes ); ?></textarea></td>
						</tr>
					</table>

					<?php submit_button( __( 'Save transfer', 'ffl-checkout-solutions' ) ); ?>
				</form>
			</section>

			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'Customer & dealer', 'ffl-checkout-solutions' ); ?></h2>

				<dl class="fflcs-facts">
					<dt><?php esc_html_e( 'Customer', 'ffl-checkout-solutions' ); ?></dt>
					<dd><?php echo esc_html( (string) $transfer->customer_name ); ?></dd>
					<dt><?php esc_html_e( 'Email', 'ffl-checkout-solutions' ); ?></dt>
					<dd><?php echo esc_html( (string) $transfer->customer_email ); ?></dd>
					<dt><?php esc_html_e( 'Phone', 'ffl-checkout-solutions' ); ?></dt>
					<dd><?php echo esc_html( (string) $transfer->customer_phone ?: '—' ); ?></dd>
					<dt><?php esc_html_e( 'Dealer', 'ffl-checkout-solutions' ); ?></dt>
					<dd>
						<?php if ( $dealer ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-dealers&dealer=' . (int) $dealer->id ) ); ?>">
								<?php echo esc_html( (string) $dealer->business_name ); ?>
							</a>
						<?php else : ?>
							—
						<?php endif; ?>
					</dd>
					<?php if ( $transfer->order_id ) : ?>
						<dt><?php esc_html_e( 'Order', 'ffl-checkout-solutions' ); ?></dt>
						<dd>
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $transfer->order_id . '&action=edit' ) ); ?>">
								#<?php echo esc_html( (string) $transfer->order_id ); ?>
							</a>
						</dd>
					<?php endif; ?>
					<dt><?php esc_html_e( 'Tracking page', 'ffl-checkout-solutions' ); ?></dt>
					<dd>
						<a href="<?php echo esc_url( Tracking_Links::customer_url( (string) $transfer->transfer_ref ) ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'Open the customer view', 'ffl-checkout-solutions' ); ?>
						</a>
					</dd>
				</dl>

				<h3><?php esc_html_e( 'Dealer confirmation link', 'ffl-checkout-solutions' ); ?></h3>
				<?php if ( $token ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: expiry date and time. */
							esc_html__( 'An unused link is outstanding, expiring %s.', 'ffl-checkout-solutions' ),
							esc_html( $this->format_datetime( (string) $token->token_expires ) )
						);
						?>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No active confirmation link.', 'ffl-checkout-solutions' ); ?></p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fflcs_resend_dealer_link">
					<input type="hidden" name="transfer_id" value="<?php echo esc_attr( (string) $transfer_id ); ?>">
					<?php wp_nonce_field( 'fflcs_resend_dealer_link' ); ?>
					<button class="button"><?php esc_html_e( 'Issue a new link and email it', 'ffl-checkout-solutions' ); ?></button>
					<p class="description"><?php esc_html_e( 'Any previous link stops working immediately.', 'ffl-checkout-solutions' ); ?></p>
				</form>
			</section>
		</div>

		<section class="fflcs-panel">
			<h2><?php esc_html_e( 'Audit trail', 'ffl-checkout-solutions' ); ?></h2>
			<?php if ( ! $events ) : ?>
				<p class="fflcs-empty"><?php esc_html_e( 'No events recorded yet.', 'ffl-checkout-solutions' ); ?></p>
			<?php else : ?>
				<table class="widefat striped fflcs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'ffl-checkout-solutions' ); ?></th>
							<th><?php esc_html_e( 'Event', 'ffl-checkout-solutions' ); ?></th>
							<th><?php esc_html_e( 'Change', 'ffl-checkout-solutions' ); ?></th>
							<th><?php esc_html_e( 'By', 'ffl-checkout-solutions' ); ?></th>
							<th><?php esc_html_e( 'Notes', 'ffl-checkout-solutions' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $events as $event ) : ?>
							<tr>
								<td><?php echo esc_html( $this->format_datetime( (string) $event->created_at ) ); ?></td>
								<td><code><?php echo esc_html( (string) $event->event_type ); ?></code></td>
								<td>
									<?php if ( $event->old_status || $event->new_status ) : ?>
										<?php echo esc_html( fflcs_status_label( (string) $event->old_status ) . ' → ' . fflcs_status_label( (string) $event->new_status ) ); ?>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) $event->actor ); ?></td>
								<td><?php echo esc_html( (string) ( $event->event_data ? wp_strip_all_tags( (string) $event->event_data ) : '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php

		$this->close_page();
	}

	// ── Handlers ─────────────────────────────────────────────────────────────

	/**
	 * Save transfer edits.
	 */
	public function handle_save(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to edit transfers.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_save_transfer' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$transfer_id = absint( wp_unslash( $_POST['transfer_id'] ?? 0 ) );

		Transfer_Service::update(
			$transfer_id,
			array(
				'status'          => sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ),
				'serial_number'   => sanitize_text_field( wp_unslash( $_POST['serial_number'] ?? '' ) ),
				'manufacturer'    => sanitize_text_field( wp_unslash( $_POST['manufacturer'] ?? '' ) ),
				'model'           => sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) ),
				'caliber'         => sanitize_text_field( wp_unslash( $_POST['caliber'] ?? '' ) ),
				'carrier'         => sanitize_key( wp_unslash( $_POST['carrier'] ?? '' ) ),
				'tracking_number' => sanitize_text_field( wp_unslash( $_POST['tracking_number'] ?? '' ) ),
				'nics_ntn'        => sanitize_text_field( wp_unslash( $_POST['nics_ntn'] ?? '' ) ),
				'staff_notes'     => sanitize_textarea_field( wp_unslash( $_POST['staff_notes'] ?? '' ) ),
			),
			wp_get_current_user()->user_login ?: 'admin'
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-transfers',
					'transfer'      => $transfer_id,
					'fflcs_message' => rawurlencode( __( 'Transfer saved.', 'ffl-checkout-solutions' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Issue and email a fresh dealer link.
	 */
	public function handle_resend(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_resend_dealer_link' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$transfer_id = absint( wp_unslash( $_POST['transfer_id'] ?? 0 ) );
		$transfer    = Transfer_Service::get( $transfer_id );

		$message = __( 'Could not issue a link for that transfer.', 'ffl-checkout-solutions' );

		if ( $transfer ) {
			$token = Token::generate( $transfer_id, (int) $transfer->dealer_id );

			if ( ! is_wp_error( $token ) ) {
				$sent = \FFLCS\Services\Mailer::send_dealer_invitation( $transfer_id, Token::build_url( $token ) );

				$message = $sent
					? __( 'A new confirmation link was emailed to the dealer.', 'ffl-checkout-solutions' )
					: __( 'A new link was issued, but no dealer email address is on file — add one on the dealer record.', 'ffl-checkout-solutions' );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-transfers',
					'transfer'      => $transfer_id,
					'fflcs_message' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Stream a CSV export.
	 *
	 * Every cell goes through fflcs_csv_cell() — dealer names and staff notes
	 * are attacker-influenced text landing in a spreadsheet the dealer opens
	 * locally, which is exactly the CSV-injection scenario.
	 */
	public function handle_export(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_export' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );

		$results = Transfer_Service::query(
			array(
				'status'   => $status,
				'per_page' => 200,
				'page'     => 1,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="fflcs-transfers-' . gmdate( 'Y-m-d' ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				'Reference',
				'Status',
				'Customer',
				'Email',
				'Product',
				'Type',
				'Manufacturer',
				'Model',
				'Caliber',
				'Serial',
				'Dealer',
				'Dealer FFL',
				'Created',
				'Shipped',
				'Received',
				'Transferred',
			)
		);

		foreach ( $results['items'] as $transfer ) {
			$dealer = Dealer_Service::get( (int) $transfer->dealer_id );

			fputcsv(
				$out,
				array_map(
					'fflcs_csv_cell',
					array(
						$transfer->transfer_ref,
						fflcs_status_label( (string) $transfer->status ),
						$transfer->customer_name,
						$transfer->customer_email,
						$transfer->product_name,
						$transfer->product_type,
						$transfer->manufacturer,
						$transfer->model,
						$transfer->caliber,
						$transfer->serial_number,
						$dealer ? $dealer->business_name : '',
						$dealer ? $dealer->license_number : '',
						$transfer->created_at,
						$transfer->shipped_date,
						$transfer->received_date,
						$transfer->transferred_date,
					)
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}
}
