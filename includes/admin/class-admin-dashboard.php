<?php
/**
 * Dashboard screen (Section 16.1).
 *
 * The first screen a dealer sees each morning. It answers one question — what
 * needs my attention today — before it shows any charts.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

use FFLCS\License\Entitlement;
use FFLCS\Services\Analytics;
use FFLCS\Services\ATF_Sync;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Overview screen.
 */
class Admin_Dashboard extends Admin_Page {

	/**
	 * Earliest priority — this screen registers the parent menu.
	 */
	protected function menu_priority(): int {
		return 5;
	}

	/**
	 * Register menu entries.
	 */
	public function register_menu(): void {
		$this->ensure_parent_menu();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'ffl-checkout-solutions' ),
			__( 'Dashboard', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$this->require_capability();

		$kpis     = Analytics::kpis();
		$statuses = Analytics::transfers_by_status();
		$sync     = ATF_Sync::status();

		$this->open_page(
			__( 'FFL Checkout', 'ffl-checkout-solutions' ),
			__( 'Transfer activity, dealer coverage and anything waiting on you.', 'ffl-checkout-solutions' )
		);

		$this->render_migration_notice();
		$this->render_setup_prompt();

		?>
		<div class="fflcs-stats">
			<?php
			$this->stat_card(
				__( 'Open transfers', 'ffl-checkout-solutions' ),
				number_format_i18n( (int) $kpis['open_transfers'] ),
				__( 'In progress right now', 'ffl-checkout-solutions' )
			);
			$this->stat_card(
				__( 'Last 30 days', 'ffl-checkout-solutions' ),
				number_format_i18n( (int) $kpis['transfers_30d'] ),
				__( 'New transfers created', 'ffl-checkout-solutions' )
			);
			$this->stat_card(
				__( 'Completed', 'ffl-checkout-solutions' ),
				number_format_i18n( (int) $kpis['completed_transfers'] ),
				__( 'All time', 'ffl-checkout-solutions' )
			);
			$this->stat_card(
				__( 'Median days', 'ffl-checkout-solutions' ),
				(string) $kpis['median_days'],
				__( 'Order to pickup', 'ffl-checkout-solutions' )
			);
			$this->stat_card(
				__( 'Active dealers', 'ffl-checkout-solutions' ),
				number_format_i18n( (int) $kpis['active_dealers'] ),
				__( 'Searchable at checkout', 'ffl-checkout-solutions' )
			);
			?>
		</div>

		<div class="fflcs-grid">
			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'Needs attention', 'ffl-checkout-solutions' ); ?></h2>
				<?php $this->render_attention_list(); ?>
			</section>

			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'By status', 'ffl-checkout-solutions' ); ?></h2>
				<?php $this->render_status_breakdown( $statuses ); ?>
			</section>
		</div>

		<div class="fflcs-grid">
			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'Recent transfers', 'ffl-checkout-solutions' ); ?></h2>
				<?php $this->render_recent_transfers(); ?>
			</section>

			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'Dealer database', 'ffl-checkout-solutions' ); ?></h2>
				<?php $this->render_sync_status( $sync ); ?>
			</section>
		</div>

		<?php if ( Entitlement::can( 'analytics', 'pro' ) ) : ?>
			<section class="fflcs-panel">
				<h2><?php esc_html_e( '30-day trend', 'ffl-checkout-solutions' ); ?></h2>
				<canvas id="fflcs-trend-chart" height="220"
					data-series="<?php echo esc_attr( (string) wp_json_encode( Analytics::daily_series( 'status_payment_confirmed', 30 ) ) ); ?>"></canvas>
			</section>
		<?php endif; ?>

		<?php
		$this->close_page();
	}

	/**
	 * Show the v1 migration notice once.
	 */
	private function render_migration_notice(): void {
		if ( '1' !== (string) get_option( 'fflcs_show_migration_notice', '0' ) ) {
			return;
		}

		?>
		<div class="notice notice-success">
			<p>
				<strong><?php esc_html_e( 'Your data was carried over.', 'ffl-checkout-solutions' ); ?></strong>
				<?php esc_html_e( 'FFL Checkout Solutions has been upgraded to version 2.0. Your dealers, transfers, events and settings were all preserved.', 'ffl-checkout-solutions' ); ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=fflcs-dashboard&fflcs_dismiss=migration' ), 'fflcs_dismiss' ) ); ?>">
					<?php esc_html_e( 'Dismiss', 'ffl-checkout-solutions' ); ?>
				</a>
			</p>
		</div>
		<?php

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below before acting.
		if ( isset( $_GET['fflcs_dismiss'] ) && 'migration' === $_GET['fflcs_dismiss'] && check_admin_referer( 'fflcs_dismiss' ) ) {
			delete_option( 'fflcs_show_migration_notice' );
		}
	}

	/**
	 * Nudge toward the wizard when setup was skipped.
	 */
	private function render_setup_prompt(): void {
		if ( '1' === (string) get_option( 'fflcs_setup_wizard_complete', '0' ) ) {
			return;
		}

		?>
		<div class="notice notice-info">
			<p>
				<?php esc_html_e( 'Setup is not finished yet. The guided steps take about two minutes and configure your store details, product flags and features.', 'ffl-checkout-solutions' ); ?>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-setup' ) ); ?>">
					<?php esc_html_e( 'Run setup', 'ffl-checkout-solutions' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * The work queue.
	 *
	 * Each row is something a person has to do, with a link straight to it.
	 * Nothing here resolves itself.
	 */
	private function render_attention_list(): void {
		global $wpdb;

		$transfers = fflcs_table( 'transfers' );
		$items     = array();

		$awaiting = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$transfers} WHERE status = %s",
				'shipped_to_dealer'
			)
		);

		if ( $awaiting > 0 ) {
			$items[] = array(
				'label' => sprintf(
					/* translators: %d: count. */
					_n( '%d shipment awaiting dealer confirmation', '%d shipments awaiting dealer confirmation', $awaiting, 'ffl-checkout-solutions' ),
					$awaiting
				),
				'url'   => admin_url( 'admin.php?page=fflcs-transfers&status=shipped_to_dealer' ),
				'tone'  => 'info',
			);
		}

		$issues = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$transfers} WHERE status = %s",
				'issue_reported'
			)
		);

		if ( $issues > 0 ) {
			$items[] = array(
				'label' => sprintf(
					/* translators: %d: count. */
					_n( '%d transfer with a reported problem', '%d transfers with reported problems', $issues, 'ffl-checkout-solutions' ),
					$issues
				),
				'url'   => admin_url( 'admin.php?page=fflcs-transfers&status=issue_reported' ),
				'tone'  => 'danger',
			);
		}

		$delayed = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$transfers}
				 WHERE status = %s AND nics_delay_expires IS NOT NULL AND nics_delay_expires <= %s",
				'nics_delayed',
				current_time( 'Y-m-d' )
			)
		);

		if ( $delayed > 0 ) {
			$items[] = array(
				'label' => sprintf(
					/* translators: %d: count. */
					_n( '%d delayed check has passed its three-business-day window', '%d delayed checks have passed their three-business-day window', $delayed, 'ffl-checkout-solutions' ),
					$delayed
				),
				'url'   => admin_url( 'admin.php?page=fflcs-transfers&status=nics_delayed' ),
				'tone'  => 'warning',
			);
		}

		$missing_serial = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$transfers}
				 WHERE ( serial_number IS NULL OR serial_number = '' ) AND status IN (%s, %s)",
				'received_by_dealer',
				'transferred'
			)
		);

		if ( $missing_serial > 0 ) {
			$items[] = array(
				'label' => sprintf(
					/* translators: %d: count. */
					_n( '%d received transfer has no serial number recorded', '%d received transfers have no serial number recorded', $missing_serial, 'ffl-checkout-solutions' ),
					$missing_serial
				),
				'url'   => admin_url( 'admin.php?page=fflcs-transfers&status=received_by_dealer' ),
				'tone'  => 'warning',
			);
		}

		if ( ! $items ) {
			echo '<p class="fflcs-empty">' . esc_html__( 'Nothing needs your attention right now.', 'ffl-checkout-solutions' ) . '</p>';
			return;
		}

		echo '<ul class="fflcs-attention">';

		foreach ( $items as $item ) {
			printf(
				'<li class="fflcs-attention__item is-%1$s"><a href="%2$s">%3$s</a></li>',
				esc_attr( $item['tone'] ),
				esc_url( $item['url'] ),
				esc_html( $item['label'] )
			);
		}

		echo '</ul>';
	}

	/**
	 * Horizontal status breakdown.
	 *
	 * @param array<string,int> $statuses Counts by status.
	 */
	private function render_status_breakdown( array $statuses ): void {
		$total = array_sum( $statuses );

		if ( 0 === $total ) {
			echo '<p class="fflcs-empty">' . esc_html__( 'No transfers yet. They appear here once a customer orders an FFL-flagged product.', 'ffl-checkout-solutions' ) . '</p>';
			return;
		}

		echo '<ul class="fflcs-breakdown">';

		foreach ( $statuses as $status => $count ) {
			if ( 0 === $count ) {
				continue;
			}

			$percent = round( ( $count / $total ) * 100 );

			printf(
				'<li class="fflcs-breakdown__row">
					<span class="fflcs-breakdown__label">%1$s</span>
					<span class="fflcs-breakdown__bar"><span style="width:%2$d%%;background:%3$s"></span></span>
					<span class="fflcs-breakdown__count">%4$s</span>
				</li>',
				esc_html( fflcs_status_label( (string) $status ) ),
				(int) $percent,
				esc_attr( \FFLCS\Services\Theming::status_color( (string) $status ) ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * The ten most recent transfers.
	 */
	private function render_recent_transfers(): void {
		$results = Transfer_Service::query(
			array(
				'per_page' => 10,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);

		if ( ! $results['items'] ) {
			echo '<p class="fflcs-empty">' . esc_html__( 'No transfers yet.', 'ffl-checkout-solutions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped fflcs-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Reference', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'Created', 'ffl-checkout-solutions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $results['items'] as $transfer ) {
			echo '<tr>';
			printf(
				'<td><a href="%1$s">%2$s</a></td>',
				esc_url( admin_url( 'admin.php?page=fflcs-transfers&transfer=' . (int) $transfer->id ) ),
				esc_html( (string) $transfer->transfer_ref )
			);
			echo '<td>' . esc_html( (string) $transfer->customer_name ) . '</td>';
			echo '<td>';
			$this->status_badge( (string) $transfer->status );
			echo '</td>';
			echo '<td>' . esc_html( $this->format_datetime( (string) $transfer->created_at ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * ATF sync progress.
	 *
	 * @param array<string,mixed> $sync Sync status.
	 */
	private function render_sync_status( array $sync ): void {
		$status = (string) $sync['status'];

		echo '<p class="fflcs-sync__count">';
		printf(
			/* translators: 1: active dealer count, 2: total dealer count. */
			esc_html__( '%1$s active dealers of %2$s on file.', 'ffl-checkout-solutions' ),
			esc_html( number_format_i18n( (int) $sync['active_count'] ) ),
			esc_html( number_format_i18n( (int) $sync['dealer_count'] ) )
		);
		echo '</p>';

		if ( 'running' === $status ) {
			printf(
				'<div class="fflcs-progress"><span style="width:%1$d%%"></span></div><p class="description">%2$s</p>',
				(int) $sync['percentage'],
				esc_html(
					sprintf(
						/* translators: 1: files done, 2: total files. */
						__( 'Importing — file %1$d of %2$d.', 'ffl-checkout-solutions' ),
						(int) $sync['file_index'],
						(int) $sync['total_files']
					)
				)
			);
		} elseif ( 0 === (int) $sync['dealer_count'] ) {
			echo '<p class="fflcs-empty">' . esc_html__( 'No dealers imported yet. Run the ATF sync to populate the directory that checkout searches.', 'ffl-checkout-solutions' ) . '</p>';
		} elseif ( 0 === strpos( $status, 'error' ) ) {
			printf(
				'<p class="fflcs-error">%s</p>',
				esc_html( (string) $sync['message'] ?: __( 'The last import did not finish.', 'ffl-checkout-solutions' ) )
			);
		} elseif ( '' !== (string) $sync['completed_at'] ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: date and time. */
						__( 'Last imported %s.', 'ffl-checkout-solutions' ),
						$this->format_datetime( (string) $sync['completed_at'] )
					)
				)
			);
		}

		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=fflcs-dealers' ) ),
			esc_html__( 'Manage dealers', 'ffl-checkout-solutions' )
		);
	}
}
