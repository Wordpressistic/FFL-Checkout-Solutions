<?php
/**
 * The dealer selector widget rendered at checkout (Section 9.1).
 *
 * Vanilla JavaScript, no framework, no map API required. Search is a single
 * REST call against local data, so picking a dealer never triggers a page
 * reload and never depends on a third-party mapping service being up.
 *
 * Renders in both checkout modes: the classic shortcode checkout via
 * woocommerce_after_checkout_billing_form, and the block checkout via a
 * rendered container plus the same script, since the widget's state lives in
 * hidden inputs the block checkout submits along with everything else.
 *
 * @package FFLCS
 */

namespace FFLCS\Frontend;

use FFLCS\Checkout;
use FFLCS\Services\Compliance;
use FFLCS\Services\Theming;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout dealer selector.
 */
class Checkout_Widget {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'render' ) );
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'render_for_blocks' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Block checkout renders its form in React, so the widget is injected
		// into the store notice area instead of a PHP form hook.
		add_action( 'woocommerce_checkout_after_customer_details', array( $this, 'render' ), 20 );
	}

	/**
	 * Whether the widget should render on this request.
	 */
	private function should_render(): bool {
		if ( '1' !== (string) get_option( 'fflcs_checkout_widget_enabled', '1' ) ) {
			return false;
		}

		if ( '1' !== (string) get_option( 'fflcs_enabled', '1' ) ) {
			return false;
		}

		return Checkout::cart_requires_ffl();
	}

	/**
	 * Render the widget.
	 *
	 * Guarded against double-render: both the classic hook and the block hook
	 * can fire on the same request depending on the theme.
	 */
	public function render(): void {
		static $rendered = false;

		if ( $rendered || ! $this->should_render() ) {
			return;
		}

		$rendered = true;

		$default_zip = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$default_zip = (string) WC()->customer->get_billing_postcode();
		}

		$radius = (int) get_option( 'fflcs_checkout_default_radius', 50 );
		$notice = Compliance::checkout_notice_html();

		?>
		<div id="fflcs-widget" class="fflcs-widget" data-radius="<?php echo esc_attr( (string) $radius ); ?>">

			<div class="fflcs-widget__header">
				<h3 class="fflcs-widget__heading">
					<svg class="fflcs-widget__icon" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 1l9 4v6c0 5.55-3.84 10.74-9 12-5.16-1.26-9-6.45-9-12V5l9-4z"/></svg>
					<?php esc_html_e( 'Select your FFL dealer', 'ffl-checkout-solutions' ); ?>
				</h3>
				<p class="fflcs-widget__lede">
					<?php esc_html_e( 'This order contains items that must transfer through a licensed FFL dealer. Search by ZIP code, business name, licence number or phone.', 'ffl-checkout-solutions' ); ?>
				</p>
			</div>

			<?php if ( $notice ) : ?>
				<div class="fflcs-widget__compliance"><?php echo wp_kses_post( $notice ); ?></div>
			<?php endif; ?>

			<div id="fflcs-saved" class="fflcs-saved" hidden></div>

			<div class="fflcs-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Dealer search method', 'ffl-checkout-solutions' ); ?>">
				<button type="button" class="fflcs-tab is-active" data-tab="zip" role="tab" aria-selected="true" aria-controls="fflcs-panel-zip" id="fflcs-tab-zip"><?php esc_html_e( 'ZIP code', 'ffl-checkout-solutions' ); ?></button>
				<button type="button" class="fflcs-tab" data-tab="name" role="tab" aria-selected="false" aria-controls="fflcs-panel-name" id="fflcs-tab-name"><?php esc_html_e( 'Name', 'ffl-checkout-solutions' ); ?></button>
				<button type="button" class="fflcs-tab" data-tab="license" role="tab" aria-selected="false" aria-controls="fflcs-panel-license" id="fflcs-tab-license"><?php esc_html_e( 'Licence #', 'ffl-checkout-solutions' ); ?></button>
				<button type="button" class="fflcs-tab" data-tab="phone" role="tab" aria-selected="false" aria-controls="fflcs-panel-phone" id="fflcs-tab-phone"><?php esc_html_e( 'Phone', 'ffl-checkout-solutions' ); ?></button>
			</div>

			<div class="fflcs-filters">
				<div class="fflcs-panel is-active" data-panel="zip" id="fflcs-panel-zip" role="tabpanel" aria-labelledby="fflcs-tab-zip">
					<div class="fflcs-field">
						<label class="fflcs-label" for="fflcs-zip"><?php esc_html_e( 'ZIP code', 'ffl-checkout-solutions' ); ?></label>
						<input type="text" id="fflcs-zip" class="fflcs-input" inputmode="numeric" maxlength="5" autocomplete="postal-code" value="<?php echo esc_attr( $default_zip ); ?>" placeholder="<?php esc_attr_e( 'e.g. 85001', 'ffl-checkout-solutions' ); ?>">
					</div>
					<div class="fflcs-field fflcs-field--narrow">
						<label class="fflcs-label" for="fflcs-radius"><?php esc_html_e( 'Within', 'ffl-checkout-solutions' ); ?></label>
						<select id="fflcs-radius" class="fflcs-select">
							<?php foreach ( array( 10, 25, 50, 100, 200 ) as $option ) : ?>
								<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $radius, $option ); ?>>
									<?php
									printf(
										/* translators: %d: distance in miles. */
										esc_html__( '%d miles', 'ffl-checkout-solutions' ),
										(int) $option
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="fflcs-panel" data-panel="name" id="fflcs-panel-name" role="tabpanel" aria-labelledby="fflcs-tab-name" hidden>
					<div class="fflcs-field">
						<label class="fflcs-label" for="fflcs-name"><?php esc_html_e( 'Business name', 'ffl-checkout-solutions' ); ?></label>
						<input type="text" id="fflcs-name" class="fflcs-input" placeholder="<?php esc_attr_e( 'e.g. Cedar Ridge Firearms', 'ffl-checkout-solutions' ); ?>">
					</div>
					<div class="fflcs-field fflcs-field--narrow">
						<label class="fflcs-label" for="fflcs-state"><?php esc_html_e( 'State', 'ffl-checkout-solutions' ); ?></label>
						<select id="fflcs-state" class="fflcs-select">
							<option value=""><?php esc_html_e( 'Any', 'ffl-checkout-solutions' ); ?></option>
							<?php foreach ( fflcs_us_states() as $state ) : ?>
								<option value="<?php echo esc_attr( $state ); ?>"><?php echo esc_html( $state ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="fflcs-panel" data-panel="license" id="fflcs-panel-license" role="tabpanel" aria-labelledby="fflcs-tab-license" hidden>
					<div class="fflcs-field fflcs-field--full">
						<label class="fflcs-label" for="fflcs-license"><?php esc_html_e( 'FFL licence number (full or partial)', 'ffl-checkout-solutions' ); ?></label>
						<input type="text" id="fflcs-license" class="fflcs-input" placeholder="<?php esc_attr_e( 'e.g. 9-86-021-01-2A', 'ffl-checkout-solutions' ); ?>">
					</div>
				</div>

				<div class="fflcs-panel" data-panel="phone" id="fflcs-panel-phone" role="tabpanel" aria-labelledby="fflcs-tab-phone" hidden>
					<div class="fflcs-field fflcs-field--full">
						<label class="fflcs-label" for="fflcs-phone"><?php esc_html_e( 'Dealer phone number', 'ffl-checkout-solutions' ); ?></label>
						<input type="tel" id="fflcs-phone" class="fflcs-input" placeholder="<?php esc_attr_e( 'e.g. 480-555-0100', 'ffl-checkout-solutions' ); ?>">
					</div>
				</div>

				<button type="button" id="fflcs-search" class="fflcs-btn fflcs-btn--primary">
					<?php esc_html_e( 'Search dealers', 'ffl-checkout-solutions' ); ?>
				</button>
			</div>

			<div class="fflcs-resultsbar" hidden>
				<span id="fflcs-count" class="fflcs-resultsbar__count"></span>
			</div>

			<div id="fflcs-results" class="fflcs-results" aria-live="polite"></div>

			<div id="fflcs-selected" class="fflcs-selected" hidden>
				<div class="fflcs-selected__header">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" focusable="false"><polyline points="20 6 9 17 4 12"/></svg>
					<?php esc_html_e( 'Selected dealer', 'ffl-checkout-solutions' ); ?>
				</div>
				<div id="fflcs-selected-info" class="fflcs-selected__info"></div>
				<button type="button" id="fflcs-change" class="fflcs-btn fflcs-btn--ghost"><?php esc_html_e( 'Change dealer', 'ffl-checkout-solutions' ); ?></button>
			</div>

			<?php // Honeypot. Off-screen rather than display:none — some bots skip hidden fields but fill positioned ones. ?>
			<div class="fflcs-hp" aria-hidden="true">
				<label for="fflcs-hp-field"><?php esc_html_e( 'Leave this field blank', 'ffl-checkout-solutions' ); ?></label>
				<input type="text" id="fflcs-hp-field" name="fflcs_hp" value="" tabindex="-1" autocomplete="off">
			</div>

			<input type="hidden" id="fflcs-dealer-id" name="fflcs_dealer_id" value="">
			<input type="hidden" id="fflcs-dealer-name" name="fflcs_dealer_name" value="">
			<input type="hidden" id="fflcs-dealer-zip" name="fflcs_dealer_zip" value="">
		</div>
		<?php
	}

	/**
	 * Block checkout entry point.
	 *
	 * The block checkout does not run the classic form hooks, so the widget is
	 * rendered from woocommerce_checkout_after_customer_details instead. This
	 * method exists so the registration hook has a handler and the block
	 * integration is explicit rather than incidental.
	 */
	public function render_for_blocks(): void {
		// Rendering happens on the shared hook above; nothing extra to register
		// here yet. Kept as an explicit seam for a future block-native control.
	}

	/**
	 * Enqueue widget CSS and JS on checkout only.
	 */
	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if ( ! $this->should_render() ) {
			return;
		}

		wp_enqueue_style(
			'fflcs-checkout-widget',
			FFLCS_PLUGIN_URL . 'assets/css/checkout-widget.css',
			array( 'fflcs-tokens' ),
			FFLCS_VERSION
		);

		wp_enqueue_script(
			'fflcs-checkout-widget',
			FFLCS_PLUGIN_URL . 'assets/js/checkout-widget.js',
			array(),
			FFLCS_VERSION,
			true
		);

		/**
		 * Filter the data handed to the checkout script.
		 *
		 * Modules extend the widget through this rather than by enqueuing their
		 * own copy of it.
		 *
		 * @param array $data Localised data.
		 */
		$data = (array) apply_filters(
			'fflcs_checkout_localize',
			array(
				'searchUrl' => rest_url( FFLCS_REST_NS . '/dealers/search' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'radius'    => (int) get_option( 'fflcs_checkout_default_radius', 50 ),
				'strict'    => '1' === (string) get_option( 'fflcs_checkout_strict_mode', '1' ),
				'currency'  => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$',
				'i18n'      => array(
					'searching'    => __( 'Searching dealers…', 'ffl-checkout-solutions' ),
					'noResults'    => __( 'No dealers matched. Try a wider radius, a different ZIP, or search by name.', 'ffl-checkout-solutions' ),
					'error'        => __( 'We could not search for dealers just now. Please try again.', 'ffl-checkout-solutions' ),
					'rateLimited'  => __( 'Too many searches. Please wait a moment and try again.', 'ffl-checkout-solutions' ),
					'select'       => __( 'Select', 'ffl-checkout-solutions' ),
					'selected'     => __( 'Selected', 'ffl-checkout-solutions' ),
					'preferred'    => __( 'Recommended', 'ffl-checkout-solutions' ),
					'feeLabel'     => __( 'Transfer fee', 'ffl-checkout-solutions' ),
					'feeUnknown'   => __( 'Contact dealer for fee', 'ffl-checkout-solutions' ),
					'milesAway'    => __( 'miles away', 'ffl-checkout-solutions' ),
					'invalidZip'   => __( 'Please enter a valid 5-digit ZIP code.', 'ffl-checkout-solutions' ),
					'needFilter'   => __( 'Enter a ZIP code, name, licence number or phone to search.', 'ffl-checkout-solutions' ),
					'countOne'     => __( 'dealer found', 'ffl-checkout-solutions' ),
					'countMany'    => __( 'dealers found', 'ffl-checkout-solutions' ),
					'noDistance'   => __( 'Address on file — distance unavailable', 'ffl-checkout-solutions' ),
					'savedHeading' => __( 'Your saved dealers', 'ffl-checkout-solutions' ),
				),
			)
		);

		wp_localize_script( 'fflcs-checkout-widget', 'fflcsCheckout', $data );
	}
}
