<?php
/**
 * Licence screen (Section 6.3) — activate, deactivate, check for updates.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

use FFLCS\License\Entitlement;
use FFLCS\License\License_Client;
use FFLCS\License\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Licence management.
 */
class Admin_License extends Admin_Page {

	/**
	 * Register hooks and menu.
	 */
	public function init(): void {
		parent::init();

		add_action( 'admin_post_fflcs_license_action', array( $this, 'handle_action' ) );
	}

	/**
	 * Menu priority — near the bottom of the menu.
	 */
	protected function menu_priority(): int {
		return 40;
	}

	/**
	 * Register the submenu.
	 */
	public function register_menu(): void {
		$this->ensure_parent_menu();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Licence', 'ffl-checkout-solutions' ),
			__( 'Licence', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			'fflcs-license',
			array( $this, 'render' )
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$this->require_capability();

		$summary = License_Client::summary();

		$this->open_page(
			__( 'Licence', 'ffl-checkout-solutions' ),
			__( 'Your licence unlocks premium modules and delivers updates and security patches to this site.', 'ffl-checkout-solutions' )
		);

		?>
		<div class="fflcs-grid">
			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'Status', 'ffl-checkout-solutions' ); ?></h2>

				<?php if ( $summary['is_valid'] ) : ?>
					<p class="fflcs-license__state is-valid">
						<?php
						printf(
							/* translators: %s: plan name. */
							esc_html__( '%s plan — active', 'ffl-checkout-solutions' ),
							esc_html( Entitlement::tier_label( (string) $summary['plan'] ) )
						);
						?>
					</p>
				<?php elseif ( $summary['has_key'] ) : ?>
					<p class="fflcs-license__state is-invalid">
						<?php
						switch ( (string) $summary['status'] ) {
							case 'expired':
								esc_html_e( 'This licence has expired. Renew it to restore premium modules.', 'ffl-checkout-solutions' );
								break;
							case 'invalid':
								esc_html_e( 'This licence key was not accepted. Check it, or contact support.', 'ffl-checkout-solutions' );
								break;
							default:
								esc_html_e( 'This licence is not currently active.', 'ffl-checkout-solutions' );
						}
						?>
					</p>
				<?php else : ?>
					<p class="fflcs-license__state is-free">
						<?php esc_html_e( 'Running on the free tier. Core FFL checkout, dealer management, the confirmation portal and state notices all work with no licence.', 'ffl-checkout-solutions' ); ?>
					</p>
				<?php endif; ?>

				<dl class="fflcs-facts">
					<?php if ( $summary['has_key'] ) : ?>
						<dt><?php esc_html_e( 'Key', 'ffl-checkout-solutions' ); ?></dt>
						<dd><code><?php echo esc_html( (string) $summary['masked_key'] ); ?></code></dd>
					<?php endif; ?>

					<?php if ( $summary['expires'] ) : ?>
						<dt><?php esc_html_e( 'Expires', 'ffl-checkout-solutions' ); ?></dt>
						<dd><?php echo esc_html( (string) $summary['expires'] ); ?></dd>
					<?php endif; ?>

					<?php if ( $summary['last_checked'] ) : ?>
						<dt><?php esc_html_e( 'Last verified', 'ffl-checkout-solutions' ); ?></dt>
						<dd><?php echo esc_html( (string) $summary['last_checked'] ); ?></dd>
					<?php endif; ?>

					<?php if ( (int) $summary['sites_max'] > 0 ) : ?>
						<dt><?php esc_html_e( 'Sites', 'ffl-checkout-solutions' ); ?></dt>
						<dd><?php echo esc_html( $summary['sites_used'] . ' / ' . $summary['sites_max'] ); ?></dd>
					<?php endif; ?>

					<dt><?php esc_html_e( 'Version', 'ffl-checkout-solutions' ); ?></dt>
					<dd><?php echo esc_html( FFLCS_VERSION ); ?></dd>
				</dl>

				<?php if ( $summary['constant'] ) : ?>
					<p class="description">
						<?php esc_html_e( 'This key comes from the FFLCS_LICENSE_KEY constant in wp-config.php, so it cannot be changed here.', 'ffl-checkout-solutions' ); ?>
					</p>
				<?php endif; ?>
			</section>

			<section class="fflcs-panel">
				<h2><?php esc_html_e( 'Manage', 'ffl-checkout-solutions' ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fflcs_license_action">
					<?php wp_nonce_field( 'fflcs_license_action' ); ?>

					<?php if ( ! $summary['constant'] ) : ?>
						<p>
							<label for="license_key"><?php esc_html_e( 'Licence key', 'ffl-checkout-solutions' ); ?></label><br>
							<input type="text" id="license_key" name="license_key" class="regular-text" autocomplete="off"
								placeholder="<?php echo esc_attr( $summary['has_key'] ? (string) $summary['masked_key'] : 'FFLCS-XXXX-XXXX-XXXX' ); ?>">
						</p>

						<p>
							<button type="submit" name="do" value="activate" class="button button-primary">
								<?php echo esc_html( $summary['has_key'] ? __( 'Re-activate', 'ffl-checkout-solutions' ) : __( 'Activate', 'ffl-checkout-solutions' ) ); ?>
							</button>

							<?php if ( $summary['has_key'] ) : ?>
								<button type="submit" name="do" value="deactivate" class="button">
									<?php esc_html_e( 'Deactivate this site', 'ffl-checkout-solutions' ); ?>
								</button>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<p>
						<button type="submit" name="do" value="recheck" class="button">
							<?php esc_html_e( 'Re-check licence', 'ffl-checkout-solutions' ); ?>
						</button>
						<button type="submit" name="do" value="check_update" class="button">
							<?php esc_html_e( 'Check for updates', 'ffl-checkout-solutions' ); ?>
						</button>
					</p>
				</form>

				<p class="description">
					<?php
					printf(
						/* translators: %s: licensing portal URL. */
						wp_kses_post( __( 'Manage your subscription and find your key at <a href="%s" target="_blank" rel="noopener">app.wpistic.com</a>.', 'ffl-checkout-solutions' ) ),
						esc_url( Entitlement::upgrade_url() )
					);
					?>
				</p>
			</section>
		</div>

		<section class="fflcs-panel">
			<h2><?php esc_html_e( 'What each plan includes', 'ffl-checkout-solutions' ); ?></h2>
			<?php $this->render_plan_table(); ?>
		</section>
		<?php

		$this->close_page();
	}

	/**
	 * Module availability by tier.
	 */
	private function render_plan_table(): void {
		$grouped = \FFLCS\Addon_Manager::grouped();

		echo '<table class="widefat striped fflcs-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Feature', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'Plan', 'ffl-checkout-solutions' ) . '</th>';
		echo '<th>' . esc_html__( 'Available here', 'ffl-checkout-solutions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $grouped as $category => $manifests ) {
			printf(
				'<tr><th colspan="3" class="fflcs-table__group">%s</th></tr>',
				esc_html( \FFLCS\Addon_Manager::category_label( (string) $category ) )
			);

			foreach ( $manifests as $id => $manifest ) {
				$entitled = Entitlement::can( (string) $id, (string) $manifest['tier'] );

				echo '<tr>';
				echo '<td>' . esc_html( (string) $manifest['name'] ) . '</td>';
				echo '<td>' . esc_html( Entitlement::tier_label( (string) $manifest['tier'] ) ) . '</td>';
				printf(
					'<td>%s</td>',
					$entitled
						? '<span class="fflcs-pill fflcs-pill--ok">' . esc_html__( 'Yes', 'ffl-checkout-solutions' ) . '</span>'
						: '<span class="fflcs-pill fflcs-pill--muted">' . esc_html__( 'Upgrade', 'ffl-checkout-solutions' ) . '</span>'
				);
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
	}

	/**
	 * Handle activate / deactivate / re-check / update check.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the licence.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_license_action' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$do  = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$key = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$args = array( 'page' => 'fflcs-license' );

		switch ( $do ) {
			case 'activate':
				$result = License_Client::activate( $key );

				$args += is_wp_error( $result )
					? array( 'fflcs_error' => rawurlencode( $result->get_error_message() ) )
					: array( 'fflcs_message' => rawurlencode( __( 'Licence activated.', 'ffl-checkout-solutions' ) ) );
				break;

			case 'deactivate':
				$result = License_Client::deactivate();

				$args += is_wp_error( $result )
					? array( 'fflcs_error' => rawurlencode( $result->get_error_message() ) )
					: array( 'fflcs_message' => rawurlencode( __( 'This site has been deactivated.', 'ffl-checkout-solutions' ) ) );
				break;

			case 'recheck':
				$checked = License_Client::validate( true );

				$args += array(
					'fflcs_message' => rawurlencode(
						$checked
							? __( 'Licence re-checked.', 'ffl-checkout-solutions' )
							: __( 'Could not reach the licence server. Your existing licence keeps working.', 'ffl-checkout-solutions' )
					),
				);
				break;

			case 'check_update':
				$result = ( new Updater() )->check_now();

				if ( is_wp_error( $result ) ) {
					$args += array( 'fflcs_error' => rawurlencode( $result->get_error_message() ) );
					break;
				}

				$args += array(
					'fflcs_message' => rawurlencode(
						$result['update_available']
							? sprintf(
								/* translators: %s: version number. */
								__( 'Version %s is available. Update from the Plugins screen.', 'ffl-checkout-solutions' ),
								$result['latest']
							)
							: __( 'You are running the latest version.', 'ffl-checkout-solutions' )
					),
				);
				break;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
