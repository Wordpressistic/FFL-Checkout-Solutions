<?php
/**
 * Bound book / A&D ledger module (Section 10.3).
 *
 * Auto-populates the acquisition side when a dealer confirms receipt, and the
 * disposition side when a transfer completes. Entries are retained for 20 years
 * and are excluded from GDPR erasure — that exclusion is enforced in the GDPR
 * module, and this class is the reason it exists.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Acquisition and disposition ledger.
 */
class Bound_Book {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_transfer_status_changed', array( $this, 'on_status_changed' ), 10, 3 );
		add_action( 'admin_post_fflcs_export_bound_book', array( $this, 'handle_export' ) );
	}

	/**
	 * Record acquisition and disposition as the transfer progresses.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $new_status  New status.
	 * @param string $old_status  Previous status.
	 */
	public function on_status_changed( int $transfer_id, string $new_status, string $old_status ): void {
		if ( 'received_by_dealer' === $new_status ) {
			$this->record_acquisition( $transfer_id );
			return;
		}

		if ( 'transferred' === $new_status ) {
			$this->record_disposition( $transfer_id );
		}
	}

	/**
	 * Create or update the acquisition side.
	 *
	 * @param int $transfer_id Transfer ID.
	 */
	private function record_acquisition( int $transfer_id ): void {
		global $wpdb;

		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer ) {
			return;
		}

		$table = fflcs_table( 'ad_ledger' );

		$exists = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE transfer_id = %d LIMIT 1",
				$transfer_id
			)
		);

		if ( $exists ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'transfer_id'      => $transfer_id,
				'dealer_id'        => (int) $transfer->dealer_id,
				'manufacturer'     => (string) $transfer->manufacturer,
				'model'            => (string) $transfer->model,
				'serial_number'    => (string) $transfer->serial_number,
				'item_type'        => (string) $transfer->product_type,
				'caliber_gauge'    => (string) $transfer->caliber,
				'acquisition_date' => (string) ( $transfer->received_date ?: current_time( 'Y-m-d' ) ),
				'acquired_from'    => (string) get_option( 'fflcs_store_name', get_option( 'blogname' ) ),
				'acquisition_type' => 'transfer_in',
				'status'           => 'in_inventory',
				'created_at'       => fflcs_mysql_now(),
				'updated_at'       => fflcs_mysql_now(),
			)
		);

		Transfer_Service::log_event(
			$transfer_id,
			'bound_book_acquisition',
			array(
				'dealer_id' => (int) $transfer->dealer_id,
				'actor'     => 'system',
				'notes'     => '' === (string) $transfer->serial_number
					? __( 'Acquisition recorded with no serial number — add it before this entry is complete.', 'ffl-checkout-solutions' )
					: __( 'Acquisition recorded in the bound book.', 'ffl-checkout-solutions' ),
			)
		);
	}

	/**
	 * Fill in the disposition side.
	 *
	 * @param int $transfer_id Transfer ID.
	 */
	private function record_disposition( int $transfer_id ): void {
		global $wpdb;

		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer ) {
			return;
		}

		// A transfer that never had an acquisition row — a walk-in recorded
		// straight to complete — still needs one, or the book has a
		// disposition with no matching acquisition.
		$this->record_acquisition( $transfer_id );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'ad_ledger' ),
			array(
				'disposition_date' => (string) ( $transfer->transferred_date ?: current_time( 'Y-m-d' ) ),
				'disposed_to_name' => (string) $transfer->customer_name,
				'disposed_to_type' => 'individual_over_counter',
				'serial_number'    => (string) $transfer->serial_number,
				'form_4473_ref'    => (string) $transfer->nics_ntn,
				'status'           => 'disposed',
				'updated_at'       => fflcs_mysql_now(),
			),
			array( 'transfer_id' => $transfer_id ),
			null,
			array( '%d' )
		);

		Transfer_Service::log_event(
			$transfer_id,
			'bound_book_disposition',
			array(
				'dealer_id' => (int) $transfer->dealer_id,
				'actor'     => 'system',
				'notes'     => __( 'Disposition recorded in the bound book.', 'ffl-checkout-solutions' ),
			)
		);
	}

	/**
	 * Entries needing a serial number before they are complete.
	 *
	 * @return object[]
	 */
	public static function needs_serial(): array {
		global $wpdb;

		$table = fflcs_table( 'ad_ledger' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE serial_number = '' OR serial_number IS NULL ORDER BY acquisition_date ASC LIMIT 100" );
	}

	/**
	 * Admin panel for the compliance screen.
	 */
	public function render_admin(): void {
		global $wpdb;

		$table = fflcs_table( 'ad_ledger' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY acquisition_date DESC, id DESC LIMIT 100" );

		$incomplete = self::needs_serial();

		?>
		<p class="description">
			<?php esc_html_e( 'Acquisition and disposition entries, created automatically as transfers progress. Entries are kept for 20 years and are never removed by the GDPR erasure tool.', 'ffl-checkout-solutions' ); ?>
		</p>

		<?php if ( $incomplete ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %d: number of entries. */
						esc_html( _n( '%d entry has no serial number recorded.', '%d entries have no serial number recorded.', count( $incomplete ), 'ffl-checkout-solutions' ) ),
						count( $incomplete )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fflcs_export_bound_book">
			<?php wp_nonce_field( 'fflcs_export' ); ?>
			<p><button class="button"><?php esc_html_e( 'Export bound book CSV', 'ffl-checkout-solutions' ); ?></button></p>
		</form>

		<table class="widefat striped fflcs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Acquired', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Manufacturer', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Model', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Type', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Serial', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Disposed', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'To', 'ffl-checkout-solutions' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7" class="fflcs-empty"><?php esc_html_e( 'No entries yet.', 'ffl-checkout-solutions' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $rows as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $entry->acquisition_date ?: '—' ) ); ?></td>
						<td><?php echo esc_html( (string) $entry->manufacturer ); ?></td>
						<td><?php echo esc_html( (string) $entry->model ); ?></td>
						<td><?php echo esc_html( (string) $entry->item_type ); ?></td>
						<td>
							<?php if ( '' === (string) $entry->serial_number ) : ?>
								<span class="fflcs-pill fflcs-pill--warn"><?php esc_html_e( 'missing', 'ffl-checkout-solutions' ); ?></span>
							<?php else : ?>
								<code><?php echo esc_html( (string) $entry->serial_number ); ?></code>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) ( $entry->disposition_date ?: '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry->disposed_to_name ?: '—' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Stream the bound book as CSV.
	 */
	public function handle_export(): void {
		global $wpdb;

		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to export the bound book.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_export' );

		$table = fflcs_table( 'ad_ledger' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY acquisition_date ASC, id ASC" );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="fflcs-bound-book-' . gmdate( 'Y-m-d' ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				'Manufacturer',
				'Importer',
				'Model',
				'Serial Number',
				'Type',
				'Caliber or Gauge',
				'Acquisition Date',
				'Acquired From',
				'Disposition Date',
				'Disposed To',
				'Form 4473 Reference',
			)
		);

		foreach ( $rows as $entry ) {
			$dealer = Dealer_Service::get( (int) $entry->dealer_id );

			fputcsv(
				$out,
				array_map(
					'fflcs_csv_cell',
					array(
						$entry->manufacturer,
						$entry->importer,
						$entry->model,
						$entry->serial_number,
						$entry->item_type,
						$entry->caliber_gauge,
						$entry->acquisition_date,
						$entry->acquired_from,
						$entry->disposition_date,
						$entry->disposed_to_name,
						$entry->form_4473_ref,
					)
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}
}
