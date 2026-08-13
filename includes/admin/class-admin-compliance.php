<?php
/**
 * Compliance hub — state rules, bound book, multiple-sale review, verification.
 *
 * Tabs whose module is off, or whose tier is not licensed, render the locked
 * panel instead of their content. The module itself is not running either way,
 * so this is presentation, not the gate.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

use FFLCS\Addon_Manager;
use FFLCS\Services\Compliance;

defined( 'ABSPATH' ) || exit;

/**
 * Compliance screens.
 */
class Admin_Compliance extends Admin_Page {

	/**
	 * Register hooks and menu.
	 */
	public function init(): void {
		parent::init();

		add_action( 'admin_post_fflcs_save_state_rule', array( $this, 'handle_save_rule' ) );
	}

	/**
	 * Menu priority.
	 */
	protected function menu_priority(): int {
		return 15;
	}

	/**
	 * Register the submenu.
	 */
	public function register_menu(): void {
		$this->ensure_parent_menu();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Compliance', 'ffl-checkout-solutions' ),
			__( 'Compliance', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			'fflcs-compliance',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the active tab.
	 */
	public function render(): void {
		$this->require_capability();

		$this->open_page(
			__( 'Compliance', 'ffl-checkout-solutions' ),
			__( 'Record-keeping and review queues. Nothing on these screens files anything with ATF or makes a compliance decision for you.', 'ffl-checkout-solutions' )
		);

		$tabs = array(
			'state-rules' => __( 'State rules', 'ffl-checkout-solutions' ),
			'bound-book'  => __( 'Bound book', 'ffl-checkout-solutions' ),
			'multi-sale'  => __( 'Multiple sales', 'ffl-checkout-solutions' ),
			'verification' => __( 'Verification hub', 'ffl-checkout-solutions' ),
			'audit'       => __( 'Audit', 'ffl-checkout-solutions' ),
		);

		$tab = $this->render_tabs( $tabs, 'fflcs-compliance', 'state-rules' );

		switch ( $tab ) {
			case 'bound-book':
				$this->render_module_tab( 'bound_book', __( 'Bound Book', 'ffl-checkout-solutions' ) );
				break;
			case 'multi-sale':
				$this->render_module_tab( 'multi_sale_watcher', __( 'Multiple Sale Watcher', 'ffl-checkout-solutions' ) );
				break;
			case 'verification':
				$this->render_module_tab( 'verification_hub', __( 'FFL Verification Hub', 'ffl-checkout-solutions' ) );
				break;
			case 'audit':
				$this->render_audit();
				break;
			default:
				$this->render_state_rules();
		}

		$this->close_page();
	}

	/**
	 * Delegate a tab to its module, or show the locked panel.
	 *
	 * @param string $module_id Module ID.
	 * @param string $label     Human name.
	 */
	private function render_module_tab( string $module_id, string $label ): void {
		$manifests = Addon_Manager::manifests();
		$tier      = (string) ( $manifests[ $module_id ]['tier'] ?? 'pro' );

		if ( ! Addon_Manager::is_active( $module_id ) ) {
			$this->render_locked( $label, $tier );
			return;
		}

		$module = \FFLCS\Plugin::instance()->modules()?->get( $module_id );

		if ( $module && method_exists( $module, 'render_admin' ) ) {
			$module->render_admin();
			return;
		}

		printf( '<p class="fflcs-empty">%s</p>', esc_html__( 'This module is on but has nothing to show yet.', 'ffl-checkout-solutions' ) );
	}

	/**
	 * State rule editor.
	 */
	private function render_state_rules(): void {
		global $wpdb;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$state = isset( $_GET['state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['state'] ) ) ) : '';

		$table = fflcs_table( 'state_rules' );

		if ( '' !== $state ) {
			$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE state = %s ORDER BY rule_key ASC",
					$state
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY state ASC, rule_key ASC LIMIT 300" );
		}

		?>
		<p class="description">
			<?php esc_html_e( 'These notices appear at checkout for buyers in the matching state. They ship as conservative defaults for you to review with your own counsel — firearms law changes often, and keeping these current is your call, not the plugin\'s.', 'ffl-checkout-solutions' ); ?>
		</p>

		<form method="get" class="fflcs-toolbar__search">
			<input type="hidden" name="page" value="fflcs-compliance">
			<input type="hidden" name="tab" value="state-rules">
			<select name="state">
				<option value=""><?php esc_html_e( 'All states', 'ffl-checkout-solutions' ); ?></option>
				<?php foreach ( fflcs_us_states() as $code ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $state, $code ); ?>><?php echo esc_html( $code ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php esc_html_e( 'Filter', 'ffl-checkout-solutions' ); ?></button>
		</form>

		<table class="widefat striped fflcs-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'State', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Rule', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Notice', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Applies to', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Blocking', 'ffl-checkout-solutions' ); ?></th>
					<th><?php esc_html_e( 'Active', 'ffl-checkout-solutions' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $rule ) : ?>
					<tr>
						<td><strong><?php echo esc_html( (string) $rule->state ); ?></strong></td>
						<td><code><?php echo esc_html( (string) $rule->rule_key ); ?></code></td>
						<td><?php echo esc_html( (string) $rule->rule_value ); ?></td>
						<td><?php echo esc_html( (string) $rule->item_types ?: __( 'all', 'ffl-checkout-solutions' ) ); ?></td>
						<td>
							<?php if ( (int) $rule->is_blocking ) : ?>
								<span class="fflcs-pill fflcs-pill--warn"><?php esc_html_e( 'Blocks', 'ffl-checkout-solutions' ); ?></span>
							<?php else : ?>
								<span class="fflcs-pill fflcs-pill--muted"><?php esc_html_e( 'Notice', 'ffl-checkout-solutions' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo (int) $rule->is_active ? esc_html__( 'Yes', 'ffl-checkout-solutions' ) : esc_html__( 'No', 'ffl-checkout-solutions' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Add or update a rule', 'ffl-checkout-solutions' ); ?></h3>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fflcs_save_state_rule">
			<?php wp_nonce_field( 'fflcs_save_state_rule' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="rule_state"><?php esc_html_e( 'State', 'ffl-checkout-solutions' ); ?></label></th>
					<td>
						<select id="rule_state" name="state" required>
							<?php foreach ( fflcs_us_states() as $code ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $code ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rule_key"><?php esc_html_e( 'Rule key', 'ffl-checkout-solutions' ); ?></label></th>
					<td>
						<input type="text" id="rule_key" name="rule_key" class="regular-text" required>
						<p class="description"><?php esc_html_e( 'A short identifier, for example waiting_period. Saving an existing state and key updates that rule.', 'ffl-checkout-solutions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="rule_value"><?php esc_html_e( 'Notice text', 'ffl-checkout-solutions' ); ?></label></th>
					<td><textarea id="rule_value" name="rule_value" rows="3" class="large-text" required></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="item_types"><?php esc_html_e( 'Applies to', 'ffl-checkout-solutions' ); ?></label></th>
					<td>
						<input type="text" id="item_types" name="item_types" class="regular-text" placeholder="handgun,rifle,shotgun,other">
						<p class="description"><?php esc_html_e( 'Comma-separated item types. Leave blank to apply to everything.', 'ffl-checkout-solutions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Behaviour', 'ffl-checkout-solutions' ); ?></th>
					<td>
						<label><input type="checkbox" name="is_blocking" value="1"> <?php esc_html_e( 'Prevent the order from being placed online', 'ffl-checkout-solutions' ); ?></label>
						<p class="description"><?php esc_html_e( 'A store policy control, not a legal determination. Blocking rules only take effect while strict compliance mode is on.', 'ffl-checkout-solutions' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save rule', 'ffl-checkout-solutions' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Configuration and completeness snapshot.
	 */
	private function render_audit(): void {
		$audit = Compliance::audit();

		echo '<table class="widefat striped fflcs-table"><tbody>';

		foreach ( $audit as $key => $value ) {
			if ( 'note' === $key ) {
				continue;
			}

			printf(
				'<tr><td style="width:35%%">%1$s</td><td>%2$s</td></tr>',
				esc_html( ucwords( str_replace( '_', ' ', (string) $key ) ) ),
				esc_html( is_bool( $value ) ? ( $value ? __( 'Yes', 'ffl-checkout-solutions' ) : __( 'No', 'ffl-checkout-solutions' ) ) : (string) $value )
			);
		}

		echo '</tbody></table>';

		printf( '<p class="description">%s</p>', esc_html( (string) $audit['note'] ) );
	}

	/**
	 * Save a state rule.
	 */
	public function handle_save_rule(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to edit compliance rules.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_save_state_rule' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$result = Compliance::save_rule(
			array(
				'state'       => strtoupper( sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ) ),
				'rule_key'    => sanitize_key( wp_unslash( $_POST['rule_key'] ?? '' ) ),
				'rule_value'  => sanitize_textarea_field( wp_unslash( $_POST['rule_value'] ?? '' ) ),
				'item_types'  => sanitize_text_field( wp_unslash( $_POST['item_types'] ?? '' ) ),
				'is_blocking' => ! empty( $_POST['is_blocking'] ) ? 1 : 0,
				'is_active'   => 1,
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$args = array(
			'page' => 'fflcs-compliance',
			'tab'  => 'state-rules',
		);

		$args += is_wp_error( $result )
			? array( 'fflcs_error' => rawurlencode( $result->get_error_message() ) )
			: array( 'fflcs_message' => rawurlencode( __( 'Rule saved.', 'ffl-checkout-solutions' ) ) );

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
