<?php
/**
 * Add-ons screen (Section 8.3).
 *
 * A card grid of every module. Each card states what the module does, what tier
 * it needs, and — per Section 14 — exactly what data it sends to a third party.
 * That last line appears here, at the moment someone decides to switch it on,
 * rather than only in a privacy document.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

use FFLCS\Addon_Manager;
use FFLCS\License\Entitlement;

defined( 'ABSPATH' ) || exit;

/**
 * Module marketplace.
 */
class Admin_Addons extends Admin_Page {

	/**
	 * Menu priority.
	 */
	protected function menu_priority(): int {
		return 35;
	}

	/**
	 * Register the submenu.
	 */
	public function register_menu(): void {
		$this->ensure_parent_menu();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Add-ons', 'ffl-checkout-solutions' ),
			__( 'Add-ons', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			'fflcs-addons',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the card grid.
	 */
	public function render(): void {
		$this->require_capability();

		$this->open_page(
			__( 'Add-ons', 'ffl-checkout-solutions' ),
			__( 'Switch features on and off. Anything switched off registers no hooks, no endpoints and no scheduled jobs — but keeps its settings and history.', 'ffl-checkout-solutions' )
		);

		$active = Addon_Manager::active_map();

		foreach ( Addon_Manager::grouped() as $category => $manifests ) {
			printf(
				'<h2 class="fflcs-addons__heading">%s</h2>',
				esc_html( Addon_Manager::category_label( (string) $category ) )
			);

			echo '<div class="fflcs-addons">';

			foreach ( $manifests as $id => $manifest ) {
				$this->render_card( (string) $id, $manifest, ! empty( $active[ $id ] ) );
			}

			echo '</div>';
		}

		$this->close_page();
	}

	/**
	 * One module card.
	 *
	 * @param string              $id        Module ID.
	 * @param array<string,mixed> $manifest  Manifest.
	 * @param bool                $is_on     Whether the toggle is on.
	 */
	private function render_card( string $id, array $manifest, bool $is_on ): void {
		$entitled = Entitlement::can( $id, (string) $manifest['tier'] );
		$required = ! empty( $manifest['required'] );
		$running  = Addon_Manager::is_active( $id );

		$classes = 'fflcs-addon';
		$classes .= $running ? ' is-active' : '';
		$classes .= $entitled ? '' : ' is-locked';

		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<div class="fflcs-addon__head">
				<span class="dashicons <?php echo esc_attr( (string) $manifest['icon'] ); ?>" aria-hidden="true"></span>
				<h3 class="fflcs-addon__name"><?php echo esc_html( (string) $manifest['name'] ); ?></h3>
				<?php if ( 'free' !== $manifest['tier'] ) : ?>
					<span class="fflcs-tier fflcs-tier--<?php echo esc_attr( (string) $manifest['tier'] ); ?>">
						<?php echo esc_html( Entitlement::tier_label( (string) $manifest['tier'] ) ); ?>
					</span>
				<?php endif; ?>
			</div>

			<p class="fflcs-addon__desc"><?php echo esc_html( (string) $manifest['desc'] ); ?></p>

			<?php if ( ! empty( $manifest['sends_data'] ) ) : ?>
				<p class="fflcs-addon__data">
					<strong><?php esc_html_e( 'Data sent:', 'ffl-checkout-solutions' ); ?></strong>
					<?php echo esc_html( (string) $manifest['sends_data'] ); ?>
				</p>
			<?php endif; ?>

			<div class="fflcs-addon__foot">
				<?php if ( $required ) : ?>
					<span class="fflcs-pill fflcs-pill--ok"><?php esc_html_e( 'Always on', 'ffl-checkout-solutions' ); ?></span>
				<?php elseif ( ! $entitled ) : ?>
					<a class="button button-small" href="<?php echo esc_url( Entitlement::upgrade_url() ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Unlock', 'ffl-checkout-solutions' ); ?>
					</a>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="fflcs_toggle_module">
						<input type="hidden" name="module" value="<?php echo esc_attr( $id ); ?>">
						<input type="hidden" name="active" value="<?php echo esc_attr( $is_on ? '0' : '1' ); ?>">
						<?php wp_nonce_field( 'fflcs_toggle_module' ); ?>
						<button class="button button-small <?php echo esc_attr( $is_on ? '' : 'button-primary' ); ?>">
							<?php echo $is_on ? esc_html__( 'Turn off', 'ffl-checkout-solutions' ) : esc_html__( 'Turn on', 'ffl-checkout-solutions' ); ?>
						</button>
					</form>
				<?php endif; ?>

				<?php if ( $running && ! empty( $manifest['configure'] ) ) : ?>
					<a class="fflcs-addon__configure" href="<?php echo esc_url( admin_url( (string) $manifest['configure'] ) ); ?>">
						<?php esc_html_e( 'Configure', 'ffl-checkout-solutions' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
