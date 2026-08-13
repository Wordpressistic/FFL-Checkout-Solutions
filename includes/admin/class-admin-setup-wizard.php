<?php
/**
 * First-run setup wizard (Section 17).
 *
 * A dealer who buys this plugin, activates it, and lands on an empty settings
 * page files a support ticket. Four guided steps instead is the difference
 * between a product that scales and one that does not.
 *
 * The redirect fires once, from a short-lived transient set at activation, so
 * it can never repeat and never hijacks a bulk activation of several plugins.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

use FFLCS\Addon_Manager;
use FFLCS\License\Entitlement;
use FFLCS\License\License_Client;

defined( 'ABSPATH' ) || exit;

/**
 * The onboarding wizard.
 */
class Admin_Setup_Wizard {

	/**
	 * Step order.
	 *
	 * @var string[]
	 */
	const STEPS = array( 'store', 'products', 'license', 'modules' );

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_post_fflcs_wizard_step', array( $this, 'handle_step' ) );
	}

	/**
	 * Register the wizard as a hidden page.
	 *
	 * Not a menu item — it is a one-time flow, and a permanent menu entry for
	 * "Setup" reads as unfinished business forever.
	 */
	public function register_page(): void {
		add_submenu_page(
			'', // Hidden from the menu.
			__( 'Set up FFL Checkout', 'ffl-checkout-solutions' ),
			__( 'Setup', 'ffl-checkout-solutions' ),
			'manage_woocommerce',
			'fflcs-setup',
			array( $this, 'render' )
		);
	}

	/**
	 * Redirect to the wizard once, after activation.
	 */
	public function maybe_redirect(): void {
		if ( ! get_transient( 'fflcs_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'fflcs_activation_redirect' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Never hijack a bulk activation — the user is mid-task with other
		// plugins and would lose their place.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WordPress's own activation context.
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		if ( '1' === (string) get_option( 'fflcs_setup_wizard_complete', '0' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=fflcs-setup' ) );
		exit;
	}

	/**
	 * Render the current step.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to run setup.', 'ffl-checkout-solutions' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation state only.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'store';

		if ( ! in_array( $step, self::STEPS, true ) && 'done' !== $step ) {
			$step = 'store';
		}

		$index = array_search( $step, self::STEPS, true );
		$index = false === $index ? count( self::STEPS ) : (int) $index;

		?>
		<div class="wrap fflcs-wizard">
			<div class="fflcs-wizard__head">
				<h1><?php esc_html_e( 'Set up FFL Checkout Solutions', 'ffl-checkout-solutions' ); ?></h1>
				<a class="fflcs-wizard__skip" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fflcs_wizard_step&step=skip' ), 'fflcs_wizard' ) ); ?>">
					<?php esc_html_e( 'Skip setup', 'ffl-checkout-solutions' ); ?>
				</a>
			</div>

			<ol class="fflcs-wizard__steps">
				<?php foreach ( self::STEPS as $position => $slug ) : ?>
					<li class="<?php echo esc_attr( $position < $index ? 'is-done' : ( $position === $index ? 'is-current' : '' ) ); ?>">
						<span class="fflcs-wizard__num"><?php echo esc_html( (string) ( $position + 1 ) ); ?></span>
						<span><?php echo esc_html( $this->step_label( $slug ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>

			<div class="fflcs-wizard__panel">
				<?php
				switch ( $step ) {
					case 'products':
						$this->render_products_step();
						break;
					case 'license':
						$this->render_license_step();
						break;
					case 'modules':
						$this->render_modules_step();
						break;
					case 'done':
						$this->render_done_step();
						break;
					default:
						$this->render_store_step();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Translated step label.
	 *
	 * @param string $slug Step slug.
	 */
	private function step_label( string $slug ): string {
		$labels = array(
			'store'    => __( 'Store & FFL details', 'ffl-checkout-solutions' ),
			'products' => __( 'Flag your products', 'ffl-checkout-solutions' ),
			'license'  => __( 'Licence', 'ffl-checkout-solutions' ),
			'modules'  => __( 'Choose features', 'ffl-checkout-solutions' ),
		);

		return $labels[ $slug ] ?? $slug;
	}

	// ── Steps ────────────────────────────────────────────────────────────────

	/**
	 * Step 1 — store identity, and the disclaimer acknowledgement.
	 */
	private function render_store_step(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fflcs_wizard_step">
			<input type="hidden" name="step" value="store">
			<?php wp_nonce_field( 'fflcs_wizard' ); ?>

			<h2><?php esc_html_e( 'Tell us about your store', 'ffl-checkout-solutions' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These details appear in customer emails, on the dealer confirmation portal, and on the tracking page.', 'ffl-checkout-solutions' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fflcs_store_name"><?php esc_html_e( 'Business name', 'ffl-checkout-solutions' ); ?></label></th>
					<td><input name="fflcs_store_name" id="fflcs_store_name" type="text" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'fflcs_store_name', get_option( 'blogname' ) ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="fflcs_store_ffl_license"><?php esc_html_e( 'Your FFL licence number', 'ffl-checkout-solutions' ); ?></label></th>
					<td>
						<input name="fflcs_store_ffl_license" id="fflcs_store_ffl_license" type="text" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'fflcs_store_ffl_license', '' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Optional. Used on paperwork drafts and in dealer correspondence.', 'ffl-checkout-solutions' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="fflcs_store_email"><?php esc_html_e( 'Notification email', 'ffl-checkout-solutions' ); ?></label></th>
					<td><input name="fflcs_store_email" id="fflcs_store_email" type="email" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'fflcs_store_email', get_option( 'admin_email' ) ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="fflcs_store_phone"><?php esc_html_e( 'Phone', 'ffl-checkout-solutions' ); ?></label></th>
					<td><input name="fflcs_store_phone" id="fflcs_store_phone" type="text" class="regular-text" value="<?php echo esc_attr( (string) get_option( 'fflcs_store_phone', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="fflcs_transfer_fee_default"><?php esc_html_e( 'Default transfer fee', 'ffl-checkout-solutions' ); ?></label></th>
					<td>
						<input name="fflcs_transfer_fee_default" id="fflcs_transfer_fee_default" type="number" step="0.01" min="0" value="<?php echo esc_attr( (string) get_option( 'fflcs_transfer_fee_default', '25.00' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Shown for dealers who have not told you their own fee.', 'ffl-checkout-solutions' ); ?></p>
					</td>
				</tr>
			</table>

			<div class="fflcs-wizard__disclaimer">
				<h3><?php esc_html_e( 'Before you continue', 'ffl-checkout-solutions' ); ?></h3>
				<blockquote>
					<?php echo esc_html( self::disclaimer_text() ); ?>
				</blockquote>
				<label>
					<input type="checkbox" name="accept_disclaimer" value="1" required
						<?php checked( '' !== (string) get_option( 'fflcs_legal_disclaimer_accepted_at', '' ) ); ?>>
					<?php esc_html_e( 'I have read and understood this.', 'ffl-checkout-solutions' ); ?>
				</label>
			</div>

			<?php submit_button( __( 'Continue', 'ffl-checkout-solutions' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Step 2 — how to flag a product.
	 */
	private function render_products_step(): void {
		$flagged = $this->count_flagged_products();
		?>
		<h2><?php esc_html_e( 'Mark which products need a transfer', 'ffl-checkout-solutions' ); ?></h2>
		<p><?php esc_html_e( 'The dealer selector only appears when the cart holds a product you have flagged as requiring an FFL transfer. Nothing changes for your other products.', 'ffl-checkout-solutions' ); ?></p>

		<ol class="fflcs-wizard__howto">
			<li><?php esc_html_e( 'Open any firearm product for editing.', 'ffl-checkout-solutions' ); ?></li>
			<li><?php esc_html_e( 'In the Product data panel, on the General tab, tick "FFL transfer required".', 'ffl-checkout-solutions' ); ?></li>
			<li><?php esc_html_e( 'Set the item type — handgun, rifle, shotgun or other. This drives your state notices, the bound book, the 4473 worksheet and multiple-sale detection, so it is worth setting properly.', 'ffl-checkout-solutions' ); ?></li>
			<li><?php esc_html_e( 'Optionally add the manufacturer, model and caliber to pre-fill paperwork later.', 'ffl-checkout-solutions' ); ?></li>
		</ol>

		<div class="fflcs-wizard__status">
			<?php if ( $flagged > 0 ) : ?>
				<p class="fflcs-wizard__ok">
					<?php
					printf(
						/* translators: %d: number of products. */
						esc_html( _n( '%d product is already flagged as requiring an FFL transfer.', '%d products are already flagged as requiring an FFL transfer.', $flagged, 'ffl-checkout-solutions' ) ),
						(int) $flagged
					);
					?>
				</p>
			<?php else : ?>
				<p class="fflcs-wizard__warn">
					<?php esc_html_e( 'No products are flagged yet. Until at least one is, the dealer selector will not appear at checkout.', 'ffl-checkout-solutions' ); ?>
				</p>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Open products in a new tab', 'ffl-checkout-solutions' ); ?>
			</a>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fflcs_wizard_step">
			<input type="hidden" name="step" value="products">
			<?php wp_nonce_field( 'fflcs_wizard' ); ?>
			<?php submit_button( __( 'Continue', 'ffl-checkout-solutions' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Step 3 — licence activation, or continue free.
	 */
	private function render_license_step(): void {
		$summary = License_Client::summary();
		?>
		<h2><?php esc_html_e( 'Activate your licence', 'ffl-checkout-solutions' ); ?></h2>
		<p><?php esc_html_e( 'Everything you have set up so far works on the free tier and always will. A licence unlocks compliance tooling, carrier integrations, distributors and analytics — and it is what delivers updates and security patches to this site.', 'ffl-checkout-solutions' ); ?></p>

		<?php if ( $summary['is_valid'] ) : ?>
			<p class="fflcs-wizard__ok">
				<?php
				printf(
					/* translators: %s: plan name. */
					esc_html__( 'A %s licence is already active on this site.', 'ffl-checkout-solutions' ),
					esc_html( Entitlement::tier_label( (string) $summary['plan'] ) )
				);
				?>
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fflcs_wizard_step">
			<input type="hidden" name="step" value="license">
			<?php wp_nonce_field( 'fflcs_wizard' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="fflcs_license_key"><?php esc_html_e( 'Licence key', 'ffl-checkout-solutions' ); ?></label></th>
					<td>
						<input name="license_key" id="fflcs_license_key" type="text" class="regular-text" autocomplete="off"
							placeholder="<?php echo esc_attr( $summary['has_key'] ? (string) $summary['masked_key'] : 'FFLCS-XXXX-XXXX-XXXX' ); ?>">
						<p class="description">
							<?php
							printf(
								/* translators: %s: URL of the licensing portal. */
								wp_kses_post( __( 'Find your key at <a href="%s" target="_blank" rel="noopener">app.wpistic.com</a>.', 'ffl-checkout-solutions' ) ),
								esc_url( Entitlement::upgrade_url() )
							);
							?>
						</p>
					</td>
				</tr>
			</table>

			<p class="fflcs-wizard__actions">
				<button type="submit" name="activate" value="1" class="button button-primary"><?php esc_html_e( 'Activate', 'ffl-checkout-solutions' ); ?></button>
				<button type="submit" name="skip_license" value="1" class="button"><?php esc_html_e( 'Continue on the free tier', 'ffl-checkout-solutions' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Step 4 — recommended module set for the resolved plan.
	 */
	private function render_modules_step(): void {
		$plan      = Entitlement::plan();
		$manifests = Addon_Manager::manifests();
		$active    = Addon_Manager::active_map();

		$recommended = array();

		foreach ( $manifests as $id => $manifest ) {
			if ( ! empty( $manifest['required'] ) ) {
				continue;
			}
			if ( ! Entitlement::can( (string) $id, (string) $manifest['tier'] ) ) {
				continue;
			}
			$recommended[ $id ] = $manifest;
		}

		?>
		<h2><?php esc_html_e( 'Choose your features', 'ffl-checkout-solutions' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: plan name. */
				esc_html__( 'These are available on your %s plan. You can change any of this later under Add-ons.', 'ffl-checkout-solutions' ),
				esc_html( Entitlement::tier_label( $plan ) )
			);
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="fflcs_wizard_step">
			<input type="hidden" name="step" value="modules">
			<?php wp_nonce_field( 'fflcs_wizard' ); ?>

			<div class="fflcs-wizard__modules">
				<?php foreach ( $recommended as $id => $manifest ) : ?>
					<label class="fflcs-wizard__module">
						<input type="checkbox" name="modules[]" value="<?php echo esc_attr( (string) $id ); ?>"
							<?php checked( ! empty( $active[ $id ] ) || ! empty( $manifest['default_active'] ) ); ?>>
						<span class="fflcs-wizard__module-name"><?php echo esc_html( (string) $manifest['name'] ); ?></span>
						<span class="fflcs-wizard__module-desc"><?php echo esc_html( (string) $manifest['desc'] ); ?></span>
						<?php if ( ! empty( $manifest['sends_data'] ) ) : ?>
							<span class="fflcs-wizard__module-data"><?php echo esc_html( (string) $manifest['sends_data'] ); ?></span>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			</div>

			<?php submit_button( __( 'Finish setup', 'ffl-checkout-solutions' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Completion state.
	 */
	private function render_done_step(): void {
		?>
		<div class="fflcs-wizard__done">
			<h2><?php esc_html_e( 'You are set up', 'ffl-checkout-solutions' ); ?></h2>
			<p><?php esc_html_e( 'FFL Checkout Solutions is live. Flag a product as requiring a transfer, then place a test order to see the full flow end to end.', 'ffl-checkout-solutions' ); ?></p>

			<p class="fflcs-wizard__actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-dashboard' ) ); ?>"><?php esc_html_e( 'Go to the dashboard', 'ffl-checkout-solutions' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=fflcs-addons' ) ); ?>"><?php esc_html_e( 'Review add-ons', 'ffl-checkout-solutions' ); ?></a>
			</p>

			<p class="description">
				<?php esc_html_e( 'The user guide and compliance notes ship in the plugin\'s docs folder. Support is available through your WPistic account.', 'ffl-checkout-solutions' ); ?>
			</p>
		</div>
		<?php
	}

	// ── Submission ───────────────────────────────────────────────────────────

	/**
	 * Process a wizard step.
	 */
	public function handle_step(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to run setup.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_wizard' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_admin_referer above.
		$step = isset( $_REQUEST['step'] ) ? sanitize_key( wp_unslash( $_REQUEST['step'] ) ) : 'store';

		if ( 'skip' === $step ) {
			update_option( 'fflcs_setup_wizard_complete', '1' );
			wp_safe_redirect( admin_url( 'admin.php?page=fflcs-dashboard' ) );
			exit;
		}

		$next = $this->process_step( $step );

		wp_safe_redirect( admin_url( 'admin.php?page=fflcs-setup&step=' . $next ) );
		exit;
	}

	/**
	 * Apply one step's input and return the next step slug.
	 *
	 * @param string $step Step slug.
	 */
	private function process_step( string $step ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by the caller.
		switch ( $step ) {
			case 'store':
				update_option( 'fflcs_store_name', sanitize_text_field( wp_unslash( $_POST['fflcs_store_name'] ?? '' ) ) );
				update_option( 'fflcs_store_ffl_license', sanitize_text_field( wp_unslash( $_POST['fflcs_store_ffl_license'] ?? '' ) ) );
				update_option( 'fflcs_store_email', sanitize_email( wp_unslash( $_POST['fflcs_store_email'] ?? '' ) ) );
				update_option( 'fflcs_store_phone', sanitize_text_field( wp_unslash( $_POST['fflcs_store_phone'] ?? '' ) ) );
				update_option( 'fflcs_transfer_fee_default', number_format( max( 0, (float) ( $_POST['fflcs_transfer_fee_default'] ?? 25 ) ), 2, '.', '' ) );

				// Section 22.2 — the acknowledgement is the evidence trail, so
				// it records who accepted and when, not just that someone did.
				if ( ! empty( $_POST['accept_disclaimer'] ) && '' === (string) get_option( 'fflcs_legal_disclaimer_accepted_at', '' ) ) {
					update_option( 'fflcs_legal_disclaimer_accepted_at', fflcs_mysql_now() );
					update_option( 'fflcs_legal_disclaimer_accepted_by', get_current_user_id() );
				}

				return 'products';

			case 'products':
				return 'license';

			case 'license':
				if ( ! empty( $_POST['skip_license'] ) ) {
					return 'modules';
				}

				$key = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );

				if ( '' !== $key ) {
					$result = License_Client::activate( $key );

					if ( is_wp_error( $result ) ) {
						set_transient(
							'fflcs_wizard_notice',
							$result->get_error_message(),
							60
						);
						return 'license';
					}
				}

				return 'modules';

			case 'modules':
				$chosen = array_map( 'sanitize_key', (array) ( $_POST['modules'] ?? array() ) );

				foreach ( Addon_Manager::manifests() as $id => $manifest ) {
					if ( ! empty( $manifest['required'] ) ) {
						continue;
					}
					// set_active re-checks entitlement, so a tampered form
					// cannot switch on a module this plan does not include.
					Addon_Manager::set_active( (string) $id, in_array( (string) $id, $chosen, true ) );
				}

				update_option( 'fflcs_setup_wizard_complete', '1' );

				return 'done';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return 'store';
	}

	// ── Shared ───────────────────────────────────────────────────────────────

	/**
	 * The Section 22.1 disclaimer text.
	 *
	 * Defined once here and reused by docs generation and the compliance
	 * settings tab, so the wording cannot drift between them.
	 */
	public static function disclaimer_text(): string {
		return __( 'FFL Checkout Solutions is a workflow and record-keeping tool. It does not provide legal advice, does not guarantee compliance with ATF regulations or state and local firearms laws, and does not replace your obligation to independently verify every transfer, NICS result and compliance requirement. All compliance decisions — approving or denying a transfer, filing Form 3310.4, verifying FFL validity — remain the sole responsibility of the licensed dealer using this software. WordPressistic and FFListic are not liable for compliance failures, missed filings, or regulatory penalties arising from use of this plugin. Consult qualified legal counsel for compliance questions specific to your business.', 'ffl-checkout-solutions' );
	}

	/**
	 * How many products are flagged as requiring a transfer.
	 */
	private function count_flagged_products(): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				\FFLCS\Checkout::PRODUCT_FFL_REQUIRED,
				'yes'
			)
		);
	}
}
