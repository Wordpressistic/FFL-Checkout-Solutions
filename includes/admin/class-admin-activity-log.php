<?php
/**
 * Activity log screen — the unified, append-only event feed.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Audit event browser.
 */
class Admin_Activity_Log extends Admin_Page {

	/**
	 * Menu priority.
	 */
	protected function menu_priority(): int {
		return 20;
	}

	/**
	 * Register the submenu.
	 */
	public function register_menu(): void {
		$this->ensure_parent_menu();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Activity Log', 'ffl-checkout-solutions' ),
			__( 'Activity Log', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			'fflcs-activity',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the feed.
	 */
	public function render(): void {
		global $wpdb;

		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$type  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$paged = max( 1, isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1 );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 50;
		$table    = fflcs_table( 'events' );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $type ) {
			$where[]  = 'event_type = %s';
			$params[] = $type;
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		$total = (int) ( $params
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			: $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, ...array_merge( $params, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$types = (array) $wpdb->get_col( "SELECT DISTINCT event_type FROM {$table} ORDER BY event_type ASC" );

		$this->open_page(
			__( 'Activity Log', 'ffl-checkout-solutions' ),
			__( 'Every recorded action, in order. Entries are only ever appended — nothing in this plugin edits or deletes a row here.', 'ffl-checkout-solutions' )
		);

		?>
		<form method="get" class="fflcs-toolbar__search">
			<input type="hidden" name="page" value="fflcs-activity">
			<select name="type">
				<option value=""><?php esc_html_e( 'All event types', 'ffl-checkout-solutions' ); ?></option>
				<?php foreach ( $types as $event_type ) : ?>
					<option value="<?php echo esc_attr( (string) $event_type ); ?>" <?php selected( $type, (string) $event_type ); ?>>
						<?php echo esc_html( (string) $event_type ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'ffl-checkout-solutions' ); ?></button>
		</form>

		<table class="widefat striped fflcs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Event', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Transfer', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Change', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'By', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'IP', 'ffl-checkout-solutions' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6" class="fflcs-empty"><?php esc_html_e( 'No events recorded yet.', 'ffl-checkout-solutions' ); ?></td></tr>
				<?php endif; ?>

				<?php foreach ( $rows as $event ) : ?>
					<tr>
						<td><?php echo esc_html( $this->format_datetime( (string) $event->created_at ) ); ?></td>
						<td><code><?php echo esc_html( (string) $event->event_type ); ?></code></td>
						<td>
							<?php if ( $event->transfer_id ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-transfers&transfer=' . (int) $event->transfer_id ) ); ?>">
									#<?php echo esc_html( (string) $event->transfer_id ); ?>
								</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td>
							<?php
							echo esc_html(
								$event->old_status || $event->new_status
									? fflcs_status_label( (string) $event->old_status ) . ' → ' . fflcs_status_label( (string) $event->new_status )
									: '—'
							);
							?>
						</td>
						<td><?php echo esc_html( (string) $event->actor ); ?></td>
						<td><code><?php echo esc_html( (string) $event->ip_address ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$this->render_pagination( $total, $per_page, $paged );
		$this->close_page();
	}
}
