<?php
/**
 * Settings screen (Section 16.1).
 *
 * Every tab writes through one allow-listed save handler, so a new field cannot
 * accidentally become an arbitrary-option write primitive.
 *
 * @package FFLCS
 */

namespace FFLCS\Admin;

use FFLCS\Crypto;
use FFLCS\Installer;
use FFLCS\Services\Theming;
use FFLCS\Services\Token;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
class Admin_Settings extends Admin_Page {

	/**
	 * Register hooks and menu.
	 */
	public function init(): void {
		parent::init();

		add_action( 'admin_post_fflcs_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_fflcs_reset_data', array( $this, 'handle_reset' ) );
	}

	/**
	 * Menu priority — last.
	 */
	protected function menu_priority(): int {
		return 50;
	}

	/**
	 * Register the submenu.
	 */
	public function register_menu(): void {
		$this->ensure_parent_menu();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'ffl-checkout-solutions' ),
			__( 'Settings', 'ffl-checkout-solutions' ),
			self::CAPABILITY,
			'fflcs-settings',
			array( $this, 'render' )
		);
	}

	/**
	 * Tab definitions.
	 *
	 * @return array<string,string>
	 */
	private function tabs(): array {
		return array(
			'general'      => __( 'General', 'ffl-checkout-solutions' ),
			'checkout'     => __( 'Checkout', 'ffl-checkout-solutions' ),
			'email'        => __( 'Email', 'ffl-checkout-solutions' ),
			'portal'       => __( 'Portal', 'ffl-checkout-solutions' ),
			'carriers'     => __( 'Carriers', 'ffl-checkout-solutions' ),
			'payments'     => __( 'Payments', 'ffl-checkout-solutions' ),
			'integrations' => __( 'Integrations', 'ffl-checkout-solutions' ),
			'theme'        => __( 'Theme', 'ffl-checkout-solutions' ),
			'compliance'   => __( 'Compliance', 'ffl-checkout-solutions' ),
			'advanced'     => __( 'Advanced', 'ffl-checkout-solutions' ),
		);
	}

	/**
	 * Which options each tab may write, and how each is sanitised.
	 *
	 * Anything not in this map is ignored on save. That is the whole point:
	 * a POST cannot reach an option this screen does not own.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function schema(): array {
		return array(
			'general'      => array(
				'fflcs_enabled'              => 'bool',
				'fflcs_store_name'           => 'text',
				'fflcs_store_ffl_license'    => 'text',
				'fflcs_store_email'          => 'email',
				'fflcs_store_phone'          => 'text',
				'fflcs_store_address'        => 'textarea',
				'fflcs_business_hours'       => 'textarea',
				'fflcs_transfer_fee_default' => 'decimal',
			),
			'checkout'     => array(
				'fflcs_checkout_widget_enabled'   => 'bool',
				'fflcs_checkout_strict_mode'      => 'bool',
				'fflcs_checkout_default_radius'   => 'int',
				'fflcs_auto_notify_dealer'        => 'bool',
				'fflcs_complete_order_on_transfer' => 'bool',
			),
			'email'        => array(
				'fflcs_email_customer_enabled' => 'bool',
				'fflcs_email_dealer_enabled'   => 'bool',
				'fflcs_email_admin_enabled'    => 'bool',
				'fflcs_email_from_name'        => 'text',
				'fflcs_email_from_address'     => 'email',
				'fflcs_email_reply_to'         => 'email',
			),
			'portal'       => array(
				'fflcs_portal_token_expiry_days' => 'int',
				'fflcs_portal_two_factor'        => 'key',
				'fflcs_portal_single_use'        => 'bool',
				'fflcs_portal_slug'              => 'slug',
				'fflcs_reminder_schedule'        => 'text',
			),
			'carriers'     => array(
				'fflcs_carrier_auto_advance' => 'bool',
				'fflcs_easypost_api_key'     => 'secret',
				'fflcs_carrier_webhook_secret' => 'secret',
				'fflcs_carrier_adult_signature' => 'bool',
			),
			'payments'     => array(
				'fflcs_nmi_security_key'    => 'secret',
				'fflcs_nmi_tokenization_key' => 'secret',
				'fflcs_nmi_test_mode'       => 'bool',
				'fflcs_credova_public_key'  => 'secret',
				'fflcs_credova_secret_key'  => 'secret',
				'fflcs_credova_test_mode'   => 'bool',
			),
			'integrations' => array(
				'fflcs_nics_webhook_secret' => 'secret',
				'fflcs_sms_provider'        => 'key',
				'fflcs_sms_account_sid'     => 'secret',
				'fflcs_sms_auth_token'      => 'secret',
				'fflcs_sms_from_number'     => 'text',
			),
			'theme'        => array(
				'fflcs_theme_primary'    => 'color',
				'fflcs_theme_success'    => 'color',
				'fflcs_theme_warning'    => 'color',
				'fflcs_theme_danger'     => 'color',
				'fflcs_theme_surface'    => 'color',
				'fflcs_theme_text'       => 'color',
				'fflcs_theme_logo'       => 'url',
				'fflcs_theme_custom_css' => 'css',
			),
			'compliance'   => array(
				'fflcs_compliance_strict'   => 'bool',
				'fflcs_nics_strict_mode'    => 'bool',
				'fflcs_data_retention_days' => 'int',
				'fflcs_analytics_retention_days' => 'int',
			),
			'advanced'     => array(
				'fflcs_debug_mode'               => 'bool',
				'fflcs_delete_data_on_uninstall' => 'bool',
				'fflcs_atf_url_override'         => 'url',
			),
		);
	}

	/**
	 * Render the active tab.
	 */
	public function render(): void {
		$this->require_capability();

		$this->open_page( __( 'Settings', 'ffl-checkout-solutions' ) );

		$tab = $this->render_tabs( $this->tabs(), 'fflcs-settings', 'general' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="fflcs_save_settings">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		wp_nonce_field( 'fflcs_save_settings' );

		echo '<table class="form-table" role="presentation">';

		switch ( $tab ) {
			case 'checkout':
				$this->fields_checkout();
				break;
			case 'email':
				$this->fields_email();
				break;
			case 'portal':
				$this->fields_portal();
				break;
			case 'carriers':
				$this->fields_carriers();
				break;
			case 'payments':
				$this->fields_payments();
				break;
			case 'integrations':
				$this->fields_integrations();
				break;
			case 'theme':
				$this->fields_theme();
				break;
			case 'compliance':
				$this->fields_compliance();
				break;
			case 'advanced':
				$this->fields_advanced();
				break;
			default:
				$this->fields_general();
		}

		echo '</table>';

		submit_button();

		echo '</form>';

		if ( 'advanced' === $tab ) {
			$this->render_danger_zone();
		}

		$this->close_page();
	}

	// ── Field groups ─────────────────────────────────────────────────────────

	/**
	 * General tab.
	 */
	private function fields_general(): void {
		$this->checkbox( 'fflcs_enabled', __( 'Enable FFL Checkout', 'ffl-checkout-solutions' ), __( 'Turn the whole plugin off without deactivating it. Records are preserved.', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_store_name', __( 'Business name', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_store_ffl_license', __( 'Your FFL licence number', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_store_email', __( 'Notification email', 'ffl-checkout-solutions' ), '', 'email' );
		$this->text( 'fflcs_store_phone', __( 'Phone', 'ffl-checkout-solutions' ) );
		$this->textarea( 'fflcs_store_address', __( 'Address', 'ffl-checkout-solutions' ) );
		$this->textarea( 'fflcs_business_hours', __( 'Business hours', 'ffl-checkout-solutions' ), __( 'Shown to dealers and customers on the portal and tracking pages.', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_transfer_fee_default', __( 'Default transfer fee', 'ffl-checkout-solutions' ), __( 'Used when a dealer has not told you their own fee.', 'ffl-checkout-solutions' ), 'number' );
	}

	/**
	 * Checkout tab.
	 */
	private function fields_checkout(): void {
		$this->checkbox( 'fflcs_checkout_widget_enabled', __( 'Show the dealer selector at checkout', 'ffl-checkout-solutions' ) );
		$this->checkbox( 'fflcs_checkout_strict_mode', __( 'Require a dealer before the order can be placed', 'ffl-checkout-solutions' ), __( 'Off means orders can be placed without a dealer and assigned manually later.', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_checkout_default_radius', __( 'Default search radius (miles)', 'ffl-checkout-solutions' ), '', 'number' );
		$this->checkbox( 'fflcs_auto_notify_dealer', __( 'Email the dealer automatically once an order is paid', 'ffl-checkout-solutions' ) );
		$this->checkbox( 'fflcs_complete_order_on_transfer', __( 'Mark the WooCommerce order complete when every transfer on it is complete', 'ffl-checkout-solutions' ) );
	}

	/**
	 * Email tab.
	 */
	private function fields_email(): void {
		$this->checkbox( 'fflcs_email_customer_enabled', __( 'Email customers on status changes', 'ffl-checkout-solutions' ) );
		$this->checkbox( 'fflcs_email_dealer_enabled', __( 'Email dealers confirmation links and reminders', 'ffl-checkout-solutions' ) );
		$this->checkbox( 'fflcs_email_admin_enabled', __( 'Email staff operational alerts', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_email_from_name', __( 'From name', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_email_from_address', __( 'From address', 'ffl-checkout-solutions' ), '', 'email' );
		$this->text( 'fflcs_email_reply_to', __( 'Reply-to address', 'ffl-checkout-solutions' ), '', 'email' );
	}

	/**
	 * Portal tab.
	 */
	private function fields_portal(): void {
		$this->select(
			'fflcs_portal_token_expiry_days',
			__( 'Confirmation link lifetime', 'ffl-checkout-solutions' ),
			array_combine(
				array_map( 'strval', Token::EXPIRY_CHOICES ),
				array_map(
					static fn( $days ) => sprintf(
						/* translators: %d: number of days. */
						_n( '%d day', '%d days', $days, 'ffl-checkout-solutions' ),
						$days
					),
					Token::EXPIRY_CHOICES
				)
			)
		);

		$this->select(
			'fflcs_portal_two_factor',
			__( 'Second factor', 'ffl-checkout-solutions' ),
			array(
				'none'      => __( 'None — the link alone is enough', 'ffl-checkout-solutions' ),
				'last4_ffl' => __( 'Last 4 of the FFL licence number', 'ffl-checkout-solutions' ),
				'email_otp' => __( 'Emailed one-time code', 'ffl-checkout-solutions' ),
			),
			__( 'The last-4 option guards against a misdirected email, not against a determined attacker. Use the emailed code where that matters.', 'ffl-checkout-solutions' )
		);

		$this->checkbox( 'fflcs_portal_single_use', __( 'Each link works only once', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_portal_slug', __( 'Portal URL slug', 'ffl-checkout-solutions' ), __( 'Changing this needs a permalink flush — visit Settings → Permalinks afterwards.', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_reminder_schedule', __( 'Reminder schedule (days)', 'ffl-checkout-solutions' ), __( 'Comma-separated days after issue to remind a dealer who has not confirmed. For example: 3,7,14', 'ffl-checkout-solutions' ) );
	}

	/**
	 * Carriers tab.
	 */
	private function fields_carriers(): void {
		$this->checkbox( 'fflcs_carrier_auto_advance', __( 'Advance a transfer to "shipped" when a tracking number is added', 'ffl-checkout-solutions' ) );
		$this->checkbox( 'fflcs_carrier_adult_signature', __( 'Request adult signature on delivery when buying labels', 'ffl-checkout-solutions' ) );
		$this->secret( 'fflcs_easypost_api_key', __( 'EasyPost API key', 'ffl-checkout-solutions' ), __( 'Encrypted at rest. Needed for live tracking and label purchase.', 'ffl-checkout-solutions' ) );
		$this->secret( 'fflcs_carrier_webhook_secret', __( 'Carrier webhook secret', 'ffl-checkout-solutions' ), __( 'Shared secret used to verify inbound carrier status pushes.', 'ffl-checkout-solutions' ) );
	}

	/**
	 * Payments tab.
	 */
	private function fields_payments(): void {
		echo '<tr><th colspan="2"><h3>' . esc_html__( 'NMI', 'ffl-checkout-solutions' ) . '</h3></th></tr>';
		$this->secret( 'fflcs_nmi_security_key', __( 'Security key', 'ffl-checkout-solutions' ) );
		$this->secret( 'fflcs_nmi_tokenization_key', __( 'Collect.js tokenisation key', 'ffl-checkout-solutions' ), __( 'Public by design — it is used in the browser so card data never reaches your server.', 'ffl-checkout-solutions' ) );
		$this->checkbox( 'fflcs_nmi_test_mode', __( 'Test mode', 'ffl-checkout-solutions' ) );

		echo '<tr><th colspan="2"><h3>' . esc_html__( 'Credova', 'ffl-checkout-solutions' ) . '</h3></th></tr>';
		$this->secret( 'fflcs_credova_public_key', __( 'Public key', 'ffl-checkout-solutions' ) );
		$this->secret( 'fflcs_credova_secret_key', __( 'Secret key', 'ffl-checkout-solutions' ) );
		$this->checkbox( 'fflcs_credova_test_mode', __( 'Test mode', 'ffl-checkout-solutions' ) );
	}

	/**
	 * Integrations tab.
	 */
	private function fields_integrations(): void {
		$this->secret( 'fflcs_nics_webhook_secret', __( 'NICS webhook secret', 'ffl-checkout-solutions' ), __( 'Used to verify signed background-check results posted to this site.', 'ffl-checkout-solutions' ) );

		echo '<tr><td colspan="2"><p class="description">';
		printf(
			/* translators: %s: webhook endpoint URL. */
			esc_html__( 'Your NICS webhook endpoint is %s', 'ffl-checkout-solutions' ),
			'<code>' . esc_html( rest_url( FFLCS_REST_NS . '/webhooks/nics' ) ) . '</code>'
		);
		echo '</p></td></tr>';

		$this->select(
			'fflcs_sms_provider',
			__( 'SMS provider', 'ffl-checkout-solutions' ),
			array(
				''            => __( '— none —', 'ffl-checkout-solutions' ),
				'twilio'      => __( 'Twilio', 'ffl-checkout-solutions' ),
				'verifyistic' => __( 'Verifyistic', 'ffl-checkout-solutions' ),
			)
		);

		$this->secret( 'fflcs_sms_account_sid', __( 'Account SID', 'ffl-checkout-solutions' ) );
		$this->secret( 'fflcs_sms_auth_token', __( 'Auth token', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_sms_from_number', __( 'From number', 'ffl-checkout-solutions' ) );
	}

	/**
	 * Theme tab.
	 */
	private function fields_theme(): void {
		foreach ( array(
			'fflcs_theme_primary' => __( 'Primary', 'ffl-checkout-solutions' ),
			'fflcs_theme_success' => __( 'Success', 'ffl-checkout-solutions' ),
			'fflcs_theme_warning' => __( 'Warning', 'ffl-checkout-solutions' ),
			'fflcs_theme_danger'  => __( 'Danger', 'ffl-checkout-solutions' ),
			'fflcs_theme_surface' => __( 'Surface', 'ffl-checkout-solutions' ),
			'fflcs_theme_text'    => __( 'Inverse text', 'ffl-checkout-solutions' ),
		) as $option => $label ) {
			$defaults = Theming::defaults();
			$key      = str_replace( 'fflcs_theme_', 'color_', $option );

			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="color" id="%1$s" name="%1$s" value="%3$s"></td></tr>',
				esc_attr( $option ),
				esc_html( $label ),
				esc_attr( (string) get_option( $option, $defaults[ $key ] ?? '#2563EB' ) )
			);
		}

		$this->text( 'fflcs_theme_logo', __( 'Logo URL', 'ffl-checkout-solutions' ), __( 'Used in emails, the dealer portal and the tracking page.', 'ffl-checkout-solutions' ), 'url' );
		$this->textarea( 'fflcs_theme_custom_css', __( 'Custom CSS', 'ffl-checkout-solutions' ), __( 'Applied to the checkout widget, portal and tracking page.', 'ffl-checkout-solutions' ) );
	}

	/**
	 * Compliance tab.
	 */
	private function fields_compliance(): void {
		$this->checkbox( 'fflcs_compliance_strict', __( 'Let blocking state rules stop an order at checkout', 'ffl-checkout-solutions' ), __( 'Off means every state rule is informational only.', 'ffl-checkout-solutions' ) );
		$this->checkbox( 'fflcs_nics_strict_mode', __( 'Require a recorded NICS outcome before a transfer can be completed', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_data_retention_days', __( 'Transfer retention (days)', 'ffl-checkout-solutions' ), __( 'Zero keeps everything. Bound-book entries are never pruned by this setting — ATF requires 20 years.', 'ffl-checkout-solutions' ), 'number' );
		$this->text( 'fflcs_analytics_retention_days', __( 'Analytics retention (days)', 'ffl-checkout-solutions' ), '', 'number' );

		$accepted = (string) get_option( 'fflcs_legal_disclaimer_accepted_at', '' );

		echo '<tr><th scope="row">' . esc_html__( 'Legal disclaimer', 'ffl-checkout-solutions' ) . '</th><td>';

		if ( '' !== $accepted ) {
			$user = get_userdata( (int) get_option( 'fflcs_legal_disclaimer_accepted_by', 0 ) );

			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: date and time, 2: user login. */
						__( 'Acknowledged %1$s by %2$s.', 'ffl-checkout-solutions' ),
						$this->format_datetime( $accepted ),
						$user ? $user->user_login : __( 'an administrator', 'ffl-checkout-solutions' )
					)
				)
			);
		} else {
			printf(
				'<p class="fflcs-warn">%s</p>',
				esc_html__( 'Not yet acknowledged. Run the setup wizard to record it.', 'ffl-checkout-solutions' )
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html( Admin_Setup_Wizard::disclaimer_text() )
		);

		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Documentation', 'ffl-checkout-solutions' ) . '</th><td><p class="description">';
		esc_html_e( 'The legal disclaimer and the external-services disclosure ship with the plugin, in docs/LEGAL-DISCLAIMER.md and docs/PRIVACY.md.', 'ffl-checkout-solutions' );
		echo '</p></td></tr>';
	}

	/**
	 * Advanced tab.
	 */
	private function fields_advanced(): void {
		$this->checkbox( 'fflcs_debug_mode', __( 'Debug logging', 'ffl-checkout-solutions' ), __( 'Writes plugin diagnostics to the WordPress debug log. Leave off in production.', 'ffl-checkout-solutions' ) );
		$this->checkbox( 'fflcs_delete_data_on_uninstall', __( 'Delete all plugin data when the plugin is deleted', 'ffl-checkout-solutions' ), __( 'Off by default. With this on, deleting the plugin permanently removes every transfer, dealer edit, bound-book entry and audit record.', 'ffl-checkout-solutions' ) );
		$this->text( 'fflcs_atf_url_override', __( 'ATF download URL override', 'ffl-checkout-solutions' ), __( 'Only needed if ATF changes their file naming and automatic discovery stops finding it.', 'ffl-checkout-solutions' ), 'url' );

		printf(
			'<tr><th scope="row">%1$s</th><td><a class="button" href="%2$s">%3$s</a></td></tr>',
			esc_html__( 'Setup wizard', 'ffl-checkout-solutions' ),
			esc_url( admin_url( 'admin.php?page=fflcs-setup' ) ),
			esc_html__( 'Run it again', 'ffl-checkout-solutions' )
		);
	}

	/**
	 * Destructive actions, kept visually separate and double-confirmed.
	 */
	private function render_danger_zone(): void {
		?>
		<section class="fflcs-danger">
			<h2><?php esc_html_e( 'Reset data', 'ffl-checkout-solutions' ); ?></h2>
			<p><?php esc_html_e( 'Permanently deletes every transfer, event, token and compliance record this plugin has stored. Your WooCommerce orders are untouched. This cannot be undone.', 'ffl-checkout-solutions' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('<?php echo esc_js( __( 'This permanently deletes all FFL transfer and compliance data. Continue?', 'ffl-checkout-solutions' ) ); ?>');">
				<input type="hidden" name="action" value="fflcs_reset_data">
				<?php wp_nonce_field( 'fflcs_reset_data' ); ?>
				<p>
					<label for="fflcs_reset_confirm"><?php esc_html_e( 'Type DELETE to confirm', 'ffl-checkout-solutions' ); ?></label>
					<input type="text" id="fflcs_reset_confirm" name="confirm" autocomplete="off">
				</p>
				<button class="button button-link-delete"><?php esc_html_e( 'Delete all plugin data', 'ffl-checkout-solutions' ); ?></button>
			</form>
		</section>
		<?php
	}

	// ── Field helpers ────────────────────────────────────────────────────────

	/**
	 * Text input row.
	 *
	 * @param string $option Option name.
	 * @param string $label  Label.
	 * @param string $help   Help text.
	 * @param string $type   Input type.
	 */
	private function text( string $option, string $label, string $help = '', string $type = 'text' ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%1$s" class="regular-text" value="%4$s">%5$s</td></tr>',
			esc_attr( $option ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( (string) get_option( $option, '' ) ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	/**
	 * Secret input row — never renders the stored value.
	 *
	 * @param string $option Option name.
	 * @param string $label  Label.
	 * @param string $help   Help text.
	 */
	private function secret( string $option, string $label, string $help = '' ): void {
		$stored = Crypto::get_option( $option, 'fflcs_settings' );

		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td>
				<input type="password" id="%1$s" name="%1$s" class="regular-text" autocomplete="new-password" placeholder="%3$s">
				%4$s
				<p class="description">%5$s</p>
			</td></tr>',
			esc_attr( $option ),
			esc_html( $label ),
			esc_attr( '' !== $stored ? Crypto::mask( $stored ) : '' ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : '',
			esc_html__( 'Leave blank to keep the current value.', 'ffl-checkout-solutions' )
		);
	}

	/**
	 * Textarea row.
	 *
	 * @param string $option Option name.
	 * @param string $label  Label.
	 * @param string $help   Help text.
	 */
	private function textarea( string $option, string $label, string $help = '' ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea id="%1$s" name="%1$s" rows="4" class="large-text">%3$s</textarea>%4$s</td></tr>',
			esc_attr( $option ),
			esc_html( $label ),
			esc_textarea( (string) get_option( $option, '' ) ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $option Option name.
	 * @param string $label  Label.
	 * @param string $help   Help text.
	 */
	private function checkbox( string $option, string $label, string $help = '' ): void {
		printf(
			'<tr><th scope="row">%2$s</th><td>
				<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %3$s> %4$s</label>%5$s
			</td></tr>',
			esc_attr( $option ),
			esc_html( $label ),
			checked( '1', (string) get_option( $option, '0' ), false ),
			esc_html__( 'Enabled', 'ffl-checkout-solutions' ),
			$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
		);
	}

	/**
	 * Select row.
	 *
	 * @param string                $option  Option name.
	 * @param string                $label   Label.
	 * @param array<string,string>  $choices Choices.
	 * @param string                $help    Help text.
	 */
	private function select( string $option, string $label, array $choices, string $help = '' ): void {
		$current = (string) get_option( $option, '' );

		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%1$s">', esc_attr( $option ), esc_html( $label ) );

		foreach ( $choices as $value => $text ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ),
				esc_html( (string) $text )
			);
		}

		echo '</select>';

		if ( $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}

		echo '</td></tr>';
	}

	// ── Save ─────────────────────────────────────────────────────────────────

	/**
	 * Persist the submitted tab.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change settings.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_save_settings' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$tab    = sanitize_key( wp_unslash( $_POST['tab'] ?? 'general' ) );
		$schema = $this->schema();

		if ( isset( $schema[ $tab ] ) ) {
			foreach ( $schema[ $tab ] as $option => $type ) {
				$this->save_option( $option, $type );
			}
		}

		// A changed portal slug means the rewrite rules no longer match.
		if ( 'portal' === $tab ) {
			flush_rewrite_rules();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-settings',
					'tab'           => $tab,
					'fflcs_message' => rawurlencode( __( 'Settings saved.', 'ffl-checkout-solutions' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Sanitise and store one option.
	 *
	 * @param string $option Option name.
	 * @param string $type   Value type.
	 */
	private function save_option( string $option, string $type ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by the caller.
		if ( 'bool' === $type ) {
			update_option( $option, isset( $_POST[ $option ] ) ? '1' : '0' );
			return;
		}

		if ( ! isset( $_POST[ $option ] ) ) {
			return;
		}

		$raw = wp_unslash( $_POST[ $option ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		switch ( $type ) {
			case 'secret':
				// Blank means "leave it alone" — the field never renders the
				// stored value, so an empty submit must not wipe a live key.
				if ( '' === trim( (string) $raw ) ) {
					return;
				}
				Crypto::update_option( $option, sanitize_text_field( (string) $raw ), 'fflcs_settings' );
				return;

			case 'email':
				update_option( $option, sanitize_email( (string) $raw ) );
				return;

			case 'int':
				update_option( $option, (string) max( 0, (int) $raw ) );
				return;

			case 'decimal':
				update_option( $option, number_format( max( 0, (float) $raw ), 2, '.', '' ) );
				return;

			case 'key':
				update_option( $option, sanitize_key( (string) $raw ) );
				return;

			case 'slug':
				update_option( $option, sanitize_title( (string) $raw ) );
				return;

			case 'color':
				$color = Theming::sanitize_color( (string) $raw );
				if ( '' !== $color ) {
					update_option( $option, $color );
				}
				return;

			case 'url':
				$url = esc_url_raw( (string) $raw );
				update_option( $option, $url );
				return;

			case 'css':
				update_option( $option, wp_strip_all_tags( (string) $raw ) );
				return;

			case 'textarea':
				update_option( $option, sanitize_textarea_field( (string) $raw ) );
				return;

			default:
				update_option( $option, sanitize_text_field( (string) $raw ) );
		}
	}

	/**
	 * Wipe plugin data.
	 */
	public function handle_reset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to reset plugin data.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_reset_data' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$confirm = strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['confirm'] ?? '' ) ) ) );

		if ( 'DELETE' !== $confirm ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => 'fflcs-settings',
						'tab'         => 'advanced',
						'fflcs_error' => rawurlencode( __( 'Nothing was deleted — the confirmation did not match.', 'ffl-checkout-solutions' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		Installer::drop_tables();
		Installer::install();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-settings',
					'tab'           => 'advanced',
					'fflcs_message' => rawurlencode( __( 'All plugin data was deleted and the tables rebuilt.', 'ffl-checkout-solutions' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
