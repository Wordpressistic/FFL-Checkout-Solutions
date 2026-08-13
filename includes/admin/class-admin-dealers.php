<?php
/**
 * Dealers screen — browse, edit fees, recommend, run the ATF sync.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

use FFLCS\Services\ATF_Sync;
use FFLCS\Services\Dealer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Dealer directory management.
 */
class Admin_Dealers extends Admin_Page {

	/**
	 * Register hooks and menu.
	 */
	public function init(): void {
		parent::init();

		add_action( 'admin_post_fflcs_save_dealer', array( $this, 'handle_save' ) );
		add_action( 'admin_post_fflcs_run_atf_sync', array( $this, 'handle_sync' ) );
	}

	/**
	 * Menu priority.
	 */
	protected function menu_priority(): int {
		return 6;
	}

	/**
	 * Register the submenu.
	 */
	public function register_menu(): void {
		$this->ensure_parent_menu();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dealers', 'ffl-checkout-solutions' ),
			__( 'Dealers', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			'fflcs-dealers',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the list or the single-dealer editor.
	 */
	public function render(): void {
		$this->require_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation state only.
		$dealer_id = isset( $_GET['dealer'] ) ? absint( wp_unslash( $_GET['dealer'] ) ) : 0;

		if ( $dealer_id ) {
			$this->render_single( $dealer_id );
			return;
		}

		$this->render_list();
	}

	/**
	 * Searchable dealer list.
	 */
	private function render_list(): void {
		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$state     = isset( $_GET['state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['state'] ) ) ) : '';
		$preferred = ! empty( $_GET['preferred'] );
		$paged     = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 25;
		$table    = fflcs_table( 'dealers' );
		$where    = array( '1=1' );
		$params   = array();

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( business_name LIKE %s OR license_number LIKE %s OR premise_city LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== $state ) {
			$where[]  = 'premise_state = %s';
			$params[] = $state;
		}

		if ( $preferred ) {
			$where[] = 'is_preferred = 1';
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		$total = (int) ( $params
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			: $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY is_preferred DESC, business_name ASC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, ...array_merge( $params, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) );

		$sync = ATF_Sync::status();

		$this->open_page(
			__( 'Dealers', 'ffl-checkout-solutions' ),
			__( 'The FFL directory your customers search at checkout. Recommend dealers you trust and record the fees they charge.', 'ffl-checkout-solutions' )
		);

		?>
		<div class="fflcs-toolbar">
			<form method="get" class="fflcs-toolbar__search">
				<input type="hidden" name="page" value="fflcs-dealers">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name, licence number or city', 'ffl-checkout-solutions' ); ?>">
				<select name="state">
					<option value=""><?php esc_html_e( 'All states', 'ffl-checkout-solutions' ); ?></option>
					<?php foreach ( fflcs_us_states() as $code ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $state, $code ); ?>><?php echo esc_html( $code ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="fflcs-toolbar__check">
					<input type="checkbox" name="preferred" value="1" <?php checked( $preferred ); ?>>
					<?php esc_html_e( 'Recommended only', 'ffl-checkout-solutions' ); ?>
				</label>
				<button class="button"><?php esc_html_e( 'Filter', 'ffl-checkout-solutions' ); ?></button>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="fflcs_run_atf_sync">
				<?php wp_nonce_field( 'fflcs_run_atf_sync' ); ?>
				<button class="button button-secondary" <?php disabled( 'running' === $sync['status'] ); ?>>
					<?php
					echo 'running' === $sync['status']
						? esc_html__( 'Import in progress…', 'ffl-checkout-solutions' )
						: esc_html__( 'Sync ATF dealer list', 'ffl-checkout-solutions' );
					?>
				</button>
			</form>
		</div>

		<?php if ( 'running' === $sync['status'] ) : ?>
			<div class="fflcs-progress"><span style="width:<?php echo esc_attr( (string) (int) $sync['percentage'] ); ?>%"></span></div>
			<p class="description">
				<?php
				printf(
					/* translators: %s: number of dealers imported. */
					esc_html__( 'Importing in the background — %s dealers so far. You can leave this page.', 'ffl-checkout-solutions' ),
					esc_html( number_format_i18n( (int) $sync['total_done'] ) )
				);
				?>
			</p>
		<?php endif; ?>

		<table class="widefat striped fflcs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Business', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Licence', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Location', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Fee', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ffl-checkout-solutions' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5" class="fflcs-empty">
						<?php esc_html_e( 'No dealers matched. If the directory is empty, run the ATF sync above.', 'ffl-checkout-solutions' ); ?>
					</td></tr>
				<?php endif; ?>

				<?php foreach ( $rows as $dealer ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-dealers&dealer=' . (int) $dealer->id ) ); ?>">
								<strong><?php echo esc_html( (string) $dealer->business_name ); ?></strong>
							</a>
							<?php if ( (int) $dealer->is_preferred ) : ?>
								<span class="fflcs-pill fflcs-pill--success"><?php esc_html_e( 'Recommended', 'ffl-checkout-solutions' ); ?></span>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( (string) $dealer->license_number ); ?></code></td>
						<td><?php echo esc_html( trim( $dealer->premise_city . ', ' . $dealer->premise_state . ' ' . $dealer->premise_zip, ', ' ) ); ?></td>
						<td><?php echo esc_html( (float) $dealer->transfer_fee > 0 ? wc_price( (float) $dealer->transfer_fee ) : '—' ); ?></td>
						<td>
							<?php if ( (int) $dealer->is_active ) : ?>
								<span class="fflcs-pill fflcs-pill--ok"><?php esc_html_e( 'Active', 'ffl-checkout-solutions' ); ?></span>
							<?php else : ?>
								<span class="fflcs-pill fflcs-pill--muted"><?php esc_html_e( 'Inactive', 'ffl-checkout-solutions' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$this->render_pagination( $total, $per_page, $paged );
		$this->close_page();
	}

	/**
	 * Single dealer editor.
	 *
	 * @param int $dealer_id Dealer ID.
	 */
	private function render_single( int $dealer_id ): void {
		$dealer = Dealer_Service::get( $dealer_id );

		if ( ! $dealer ) {
			$this->open_page( __( 'Dealer', 'ffl-checkout-solutions' ) );
			$this->notice( __( 'That dealer no longer exists.', 'ffl-checkout-solutions' ), 'error' );
			$this->close_page();
			return;
		}

		$this->open_page( (string) $dealer->business_name, (string) $dealer->license_number );

		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-dealers' ) ); ?>">&larr; <?php esc_html_e( 'All dealers', 'ffl-checkout-solutions' ); ?></a></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fflcs_save_dealer">
			<input type="hidden" name="dealer_id" value="<?php echo esc_attr( (string) $dealer_id ); ?>">
			<?php wp_nonce_field( 'fflcs_save_dealer' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Premises', 'ffl-checkout-solutions' ); ?></th>
					<td>
						<p class="description"><?php echo esc_html( \FFLCS\Services\Mailer::dealer_address( $dealer ) ); ?></p>
						<p class="description"><?php esc_html_e( 'Address and licence details come from the ATF file and refresh on each sync, so they are not editable here.', 'ffl-checkout-solutions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="transfer_fee"><?php esc_html_e( 'Transfer fee', 'ffl-checkout-solutions' ); ?></label></th>
					<td>
						<input type="number" step="0.01" min="0" id="transfer_fee" name="transfer_fee" value="<?php echo esc_attr( (string) $dealer->transfer_fee ); ?>">
						<p class="description"><?php esc_html_e( 'Shown to customers at checkout. Leave at zero to display "contact dealer for fee".', 'ffl-checkout-solutions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="email"><?php esc_html_e( 'Contact email', 'ffl-checkout-solutions' ); ?></label></th>
					<td>
						<input type="email" id="email" name="email" class="regular-text" value="<?php echo esc_attr( (string) $dealer->email ); ?>">
						<p class="description"><?php esc_html_e( 'Where confirmation links are sent. The ATF file does not include email addresses, so this is usually blank until you add it.', 'ffl-checkout-solutions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="phone"><?php esc_html_e( 'Phone', 'ffl-checkout-solutions' ); ?></label></th>
					<td><input type="text" id="phone" name="phone" class="regular-text" value="<?php echo esc_attr( (string) $dealer->phone ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Visibility', 'ffl-checkout-solutions' ); ?></th>
					<td>
						<label><input type="checkbox" name="is_preferred" value="1" <?php checked( (int) $dealer->is_preferred, 1 ); ?>> <?php esc_html_e( 'Recommend this dealer — shown first at checkout', 'ffl-checkout-solutions' ); ?></label><br>
						<label><input type="checkbox" name="is_active" value="1" <?php checked( (int) $dealer->is_active, 1 ); ?>> <?php esc_html_e( 'Selectable at checkout', 'ffl-checkout-solutions' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="notes"><?php esc_html_e( 'Internal notes', 'ffl-checkout-solutions' ); ?></label></th>
					<td>
						<textarea id="notes" name="notes" rows="4" class="large-text"><?php echo esc_textarea( (string) $dealer->notes ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Staff-only. Never shown to customers or exposed through the API.', 'ffl-checkout-solutions' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save dealer', 'ffl-checkout-solutions' ) ); ?>
		</form>
		<?php

		$this->close_page();
	}

	// ── Handlers ─────────────────────────────────────────────────────────────

	/**
	 * Save dealer edits.
	 */
	public function handle_save(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to edit dealers.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_save_dealer' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$dealer_id = absint( wp_unslash( $_POST['dealer_id'] ?? 0 ) );

		Dealer_Service::update(
			$dealer_id,
			array(
				'transfer_fee' => (float) ( $_POST['transfer_fee'] ?? 0 ),
				'email'        => wp_unslash( $_POST['email'] ?? '' ),
				'phone'        => wp_unslash( $_POST['phone'] ?? '' ),
				'is_preferred' => ! empty( $_POST['is_preferred'] ),
				'is_active'    => ! empty( $_POST['is_active'] ),
				'notes'        => wp_unslash( $_POST['notes'] ?? '' ),
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-dealers',
					'dealer'        => $dealer_id,
					'fflcs_message' => rawurlencode( __( 'Dealer saved.', 'ffl-checkout-solutions' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Kick off an ATF sync.
	 */
	public function handle_sync(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to run the sync.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_run_atf_sync' );

		// Queued, not run inline: downloading and unpacking ~80,000 rows would
		// time out the request on most hosts.
		\FFLCS\Services\Scheduler::async( 'fflcs_atf_sync' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-dealers',
					'fflcs_message' => rawurlencode( __( 'ATF import queued. Progress appears here shortly.', 'ffl-checkout-solutions' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
