<?php
/**
 * Distributors screen — one tab per drop-ship partner.
 *
 * Ordering is always an explicit click on this screen. Nothing here, and
 * nothing scheduled by this plugin, ever places a wholesale order on its own.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

use FFLCS\Addon_Manager;
use FFLCS\Distributors\Distributor_Registry;
use FFLCS\License\Entitlement;

defined( 'ABSPATH' ) || exit;

/**
 * Distributor management.
 */
class Admin_Distributors extends Admin_Page {

	/**
	 * Register hooks and menu.
	 */
	public function init(): void {
		parent::init();

		add_action( 'admin_post_fflcs_save_distributor', array( $this, 'handle_save' ) );
		add_action( 'admin_post_fflcs_sync_distributor', array( $this, 'handle_sync' ) );
	}

	/**
	 * Menu priority.
	 */
	protected function menu_priority(): int {
		return 11;
	}

	/**
	 * Register the submenu.
	 */
	public function register_menu(): void {
		$this->ensure_parent_menu();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Distributors', 'ffl-checkout-solutions' ),
			__( 'Distributors', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			'fflcs-distributors',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the active distributor tab.
	 */
	public function render(): void {
		$this->require_capability();

		$this->open_page(
			__( 'Distributors', 'ffl-checkout-solutions' ),
			__( 'Wholesale catalog sync and drop-ship ordering. Catalog sync is automatic; placing an order is always a deliberate click.', 'ffl-checkout-solutions' )
		);

		if ( ! Entitlement::plan_at_least( 'business' ) ) {
			$this->render_locked( __( 'Distributor integrations', 'ffl-checkout-solutions' ), 'business' );
			$this->close_page();
			return;
		}

		$tabs = array();

		foreach ( Distributor_Registry::known() as $slug => $meta ) {
			$tabs[ $slug ] = (string) $meta['name'];
		}

		if ( ! $tabs ) {
			printf( '<p class="fflcs-empty">%s</p>', esc_html__( 'No distributor modules are available.', 'ffl-checkout-solutions' ) );
			$this->close_page();
			return;
		}

		$tab  = $this->render_tabs( $tabs, 'fflcs-distributors', (string) array_key_first( $tabs ) );
		$meta = Distributor_Registry::known()[ $tab ] ?? null;

		if ( ! $meta ) {
			$this->close_page();
			return;
		}

		$module_id = 'distributor_' . str_replace( '-', '_', $tab );

		if ( ! Addon_Manager::is_active( $module_id ) ) {
			$this->render_locked( (string) $meta['name'], 'business' );
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=fflcs-addons' ) ),
				esc_html__( 'Turn this distributor on', 'ffl-checkout-solutions' )
			);
			$this->close_page();
			return;
		}

		$this->render_distributor( $tab, $meta );

		$this->close_page();
	}

	/**
	 * One distributor's credentials, status and catalog.
	 *
	 * @param string              $slug Distributor slug.
	 * @param array<string,mixed> $meta Registry entry.
	 */
	private function render_distributor( string $slug, array $meta ): void {
		$client = Distributor_Registry::client( $slug );
		$status = $client ? $client->status() : array();

		?>
		<div class="fflcs-grid">
			<section class="fflcs-panel">
				<h2><?php echo esc_html( (string) $meta['name'] ); ?></h2>
				<p class="description"><?php echo esc_html( (string) $meta['protocol'] ); ?></p>

				<?php if ( ! empty( $meta['honesty_note'] ) ) : ?>
					<div class="fflcs-note">
						<strong><?php esc_html_e( 'Integration status', 'ffl-checkout-solutions' ); ?></strong>
						<p><?php echo esc_html( (string) $meta['honesty_note'] ); ?></p>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fflcs_save_distributor">
					<input type="hidden" name="distributor" value="<?php echo esc_attr( $slug ); ?>">
					<?php wp_nonce_field( 'fflcs_save_distributor' ); ?>

					<table class="form-table" role="presentation">
						<?php foreach ( (array) $meta['credentials'] as $field => $label ) : ?>
							<tr>
								<th scope="row">
									<label for="<?php echo esc_attr( $slug . '_' . $field ); ?>"><?php echo esc_html( (string) $label ); ?></label>
								</th>
								<td>
									<input type="password" autocomplete="new-password" class="regular-text"
										id="<?php echo esc_attr( $slug . '_' . $field ); ?>"
										name="<?php echo esc_attr( $field ); ?>"
										placeholder="<?php echo esc_attr( Distributor_Registry::masked_credential( $slug, (string) $field ) ); ?>">
								</td>
							</tr>
						<?php endforeach; ?>
					</table>

					<p class="description"><?php esc_html_e( 'Credentials are encrypted at rest. Leave a field blank to keep its current value.', 'ffl-checkout-solutions' ); ?></p>

					<?php submit_button( __( 'Save credentials', 'ffl-checkout-solutions' ) ); ?>
				</form>
			</section>

			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'Catalog', 'ffl-checkout-solutions' ); ?></h2>

				<dl class="fflcs-facts">
					<dt><?php esc_html_e( 'Products cached', 'ffl-checkout-solutions' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( (int) ( $status['product_count'] ?? 0 ) ) ); ?></dd>
					<dt><?php esc_html_e( 'Last sync', 'ffl-checkout-solutions' ); ?></dt>
					<dd><?php echo esc_html( ! empty( $status['last_sync'] ) ? $this->format_datetime( (string) $status['last_sync'] ) : __( 'never', 'ffl-checkout-solutions' ) ); ?></dd>
					<dt><?php esc_html_e( 'Credentials', 'ffl-checkout-solutions' ); ?></dt>
					<dd>
						<?php if ( ! empty( $status['configured'] ) ) : ?>
							<span class="fflcs-pill fflcs-pill--ok"><?php esc_html_e( 'Configured', 'ffl-checkout-solutions' ); ?></span>
						<?php else : ?>
							<span class="fflcs-pill fflcs-pill--muted"><?php esc_html_e( 'Not set', 'ffl-checkout-solutions' ); ?></span>
						<?php endif; ?>
					</dd>
					<dt><?php esc_html_e( 'Orders submitted', 'ffl-checkout-solutions' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( (int) ( $status['order_count'] ?? 0 ) ) ); ?></dd>
				</dl>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fflcs_sync_distributor">
					<input type="hidden" name="distributor" value="<?php echo esc_attr( $slug ); ?>">
					<?php wp_nonce_field( 'fflcs_sync_distributor' ); ?>
					<button class="button" <?php disabled( empty( $status['configured'] ) ); ?>>
						<?php esc_html_e( 'Sync catalog now', 'ffl-checkout-solutions' ); ?>
					</button>
				</form>

				<p class="description">
					<?php esc_html_e( 'Syncing the catalog only reads data. It never places an order.', 'ffl-checkout-solutions' ); ?>
				</p>
			</section>
		</div>
		<?php
	}

	/**
	 * Save distributor credentials.
	 */
	public function handle_save(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to configure distributors.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_save_distributor' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$slug = sanitize_key( wp_unslash( $_POST['distributor'] ?? '' ) );
		$meta = Distributor_Registry::known()[ $slug ] ?? null;

		if ( $meta ) {
			foreach ( array_keys( (array) $meta['credentials'] ) as $field ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );

				if ( '' !== $value ) {
					Distributor_Registry::save_credential( $slug, (string) $field, $value );
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-distributors',
					'tab'           => $slug,
					'fflcs_message' => rawurlencode( __( 'Credentials saved.', 'ffl-checkout-solutions' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Queue a catalog sync.
	 */
	public function handle_sync(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_sync_distributor' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$slug   = sanitize_key( wp_unslash( $_POST['distributor'] ?? '' ) );
		$client = Distributor_Registry::client( $slug );

		$message = __( 'That distributor is not available.', 'ffl-checkout-solutions' );

		if ( $client ) {
			$result = $client->sync_catalog();

			$message = is_wp_error( $result )
				? $result->get_error_message()
				: sprintf(
					/* translators: %d: number of products. */
					__( 'Catalog synced — %d products cached.', 'ffl-checkout-solutions' ),
					(int) $result
				);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-distributors',
					'tab'           => $slug,
					'fflcs_message' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
