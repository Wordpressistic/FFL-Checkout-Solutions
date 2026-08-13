<?php
/**
 * Public dealer application shortcode.
 *
 * [fflcs_dealer_onboard] renders a form an FFL can use to ask to be added to a
 * store's recommended-dealer list. Submissions create nothing and change
 * nothing: they email staff and write an audit event. Adding or recommending a
 * dealer is always a deliberate staff action, because a recommended dealer is
 * an endorsement the store is making to its customers.
 *
 * @package FFLCS
 */

namespace FFLCS\Frontend;

use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Mailer;
use FFLCS\Services\Token;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Dealer application form.
 */
class Dealer_Onboard {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_shortcode( 'fflcs_dealer_onboard', array( $this, 'render' ) );

		/**
		 * Filter additional shortcode tags that render this form.
		 *
		 * The legacy tag from the 1.x line is deliberately not registered here.
		 * The release gate requires zero occurrences of prior branding anywhere
		 * in the shipped package, and a hardcoded legacy tag would be a
		 * customer-visible violation of it. A site upgrading from 1.x that used
		 * the old tag can restore it in one line:
		 *
		 *     add_filter( 'fflcs_dealer_onboard_shortcodes', function ( $tags ) {
		 *         $tags[] = 'old_tag_name';
		 *         return $tags;
		 *     } );
		 *
		 * @param string[] $tags Additional shortcode tags.
		 */
		foreach ( (array) apply_filters( 'fflcs_dealer_onboard_shortcodes', array() ) as $tag ) {
			$tag = sanitize_key( (string) $tag );

			if ( '' !== $tag && ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, array( $this, 'render' ) );
			}
		}

		add_action( 'admin_post_nopriv_fflcs_dealer_apply', array( $this, 'handle_submission' ) );
		add_action( 'admin_post_fflcs_dealer_apply', array( $this, 'handle_submission' ) );
	}

	/**
	 * Render the application form.
	 *
	 * @param array<string,mixed> $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'title' => __( 'Apply to become a recommended dealer', 'ffl-checkout-solutions' ),
			),
			(array) $atts,
			'fflcs_dealer_onboard'
		);

		wp_enqueue_style(
			'fflcs-checkout-widget',
			FFLCS_PLUGIN_URL . 'assets/css/checkout-widget.css',
			array( 'fflcs-tokens' ),
			FFLCS_VERSION
		);

		$status  = isset( $_GET['fflcs_applied'] ) ? sanitize_key( wp_unslash( $_GET['fflcs_applied'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display state.

		ob_start();
		?>
		<div class="fflcs-widget fflcs-onboard">
			<div class="fflcs-widget__header">
				<h3 class="fflcs-widget__heading"><?php echo esc_html( (string) $atts['title'] ); ?></h3>
				<p class="fflcs-widget__lede">
					<?php esc_html_e( 'Tell us about your business and we will be in touch. We verify every licence before adding a dealer to our list.', 'ffl-checkout-solutions' ); ?>
				</p>
			</div>

			<?php if ( 'ok' === $status ) : ?>
				<p class="fflcs-message">
					<?php esc_html_e( 'Thank you — your application has been received. We will review it and contact you.', 'ffl-checkout-solutions' ); ?>
				</p>
			<?php elseif ( 'error' === $status ) : ?>
				<p class="fflcs-message fflcs-message--error">
					<?php esc_html_e( 'We could not accept that submission. Please check the required fields and try again.', 'ffl-checkout-solutions' ); ?>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fflcs-onboard__form">
				<input type="hidden" name="action" value="fflcs_dealer_apply">
				<?php wp_nonce_field( 'fflcs_dealer_apply', 'fflcs_onboard_nonce' ); ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( get_permalink() ?: home_url( '/' ) ); ?>">

				<div class="fflcs-field fflcs-field--full">
					<label class="fflcs-label" for="fflcs-onboard-business"><?php esc_html_e( 'Business name', 'ffl-checkout-solutions' ); ?> *</label>
					<input class="fflcs-input" type="text" id="fflcs-onboard-business" name="business_name" required maxlength="255">
				</div>

				<div class="fflcs-field fflcs-field--full">
					<label class="fflcs-label" for="fflcs-onboard-license"><?php esc_html_e( 'FFL licence number', 'ffl-checkout-solutions' ); ?> *</label>
					<input class="fflcs-input" type="text" id="fflcs-onboard-license" name="license_number" required maxlength="20">
				</div>

				<div class="fflcs-field">
					<label class="fflcs-label" for="fflcs-onboard-email"><?php esc_html_e( 'Contact email', 'ffl-checkout-solutions' ); ?> *</label>
					<input class="fflcs-input" type="email" id="fflcs-onboard-email" name="email" required>
				</div>

				<div class="fflcs-field">
					<label class="fflcs-label" for="fflcs-onboard-phone"><?php esc_html_e( 'Phone', 'ffl-checkout-solutions' ); ?></label>
					<input class="fflcs-input" type="tel" id="fflcs-onboard-phone" name="phone" maxlength="20">
				</div>

				<div class="fflcs-field">
					<label class="fflcs-label" for="fflcs-onboard-fee"><?php esc_html_e( 'Your transfer fee', 'ffl-checkout-solutions' ); ?></label>
					<input class="fflcs-input" type="number" step="0.01" min="0" id="fflcs-onboard-fee" name="transfer_fee">
				</div>

				<div class="fflcs-field fflcs-field--full">
					<label class="fflcs-label" for="fflcs-onboard-notes"><?php esc_html_e( 'Anything else we should know', 'ffl-checkout-solutions' ); ?></label>
					<textarea class="fflcs-input" id="fflcs-onboard-notes" name="notes" rows="3" maxlength="1000"></textarea>
				</div>

				<div class="fflcs-hp" aria-hidden="true">
					<label for="fflcs-onboard-hp"><?php esc_html_e( 'Leave this field blank', 'ffl-checkout-solutions' ); ?></label>
					<input type="text" id="fflcs-onboard-hp" name="fflcs_hp" value="" tabindex="-1" autocomplete="off">
				</div>

				<button type="submit" class="fflcs-btn fflcs-btn--primary">
					<?php esc_html_e( 'Submit application', 'ffl-checkout-solutions' ); ?>
				</button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Handle a submission.
	 *
	 * Notifies staff and logs. Deliberately does not create or modify a dealer
	 * record — an unauthenticated form must never be able to write into the
	 * dealer directory that checkout reads from.
	 */
	public function handle_submission(): void {
		$redirect = isset( $_POST['redirect_to'] )
			? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) )
			: home_url( '/' );

		// Never redirect off-site, whatever was posted.
		$redirect = wp_validate_redirect( $redirect, home_url( '/' ) );

		if ( ! isset( $_POST['fflcs_onboard_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fflcs_onboard_nonce'] ) ), 'fflcs_dealer_apply' ) ) {
			wp_safe_redirect( add_query_arg( 'fflcs_applied', 'error', $redirect ) );
			exit;
		}

		// Honeypot and rate limit — this endpoint is public and unauthenticated.
		if ( ! empty( $_POST['fflcs_hp'] ) || Token::is_rate_limited( 'dealer_apply', 3, 10 * MINUTE_IN_SECONDS ) ) {
			wp_safe_redirect( add_query_arg( 'fflcs_applied', 'error', $redirect ) );
			exit;
		}

		$business = sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) );
		$license  = sanitize_text_field( wp_unslash( $_POST['license_number'] ?? '' ) );
		$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$fee      = round( max( 0, (float) ( $_POST['transfer_fee'] ?? 0 ) ), 2 );
		$notes    = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );

		if ( '' === $business || '' === $license || ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'fflcs_applied', 'error', $redirect ) );
			exit;
		}

		// If the licence is already in the ATF-synced directory, say so in the
		// alert — it turns a "who is this?" email into a one-click action.
		$existing = Dealer_Service::get_by_license( $license );

		Transfer_Service::log_event(
			0,
			'dealer_application',
			array(
				'dealer_id' => $existing ? (int) $existing->id : 0,
				'actor'     => 'public_form',
				'notes'     => sprintf(
					/* translators: 1: business name, 2: FFL licence number. */
					__( 'Dealer application received from %1$s (licence %2$s).', 'ffl-checkout-solutions' ),
					$business,
					$license
				),
				'data'      => array(
					'email'        => $email,
					'phone'        => $phone,
					'transfer_fee' => $fee,
					'matched_id'   => $existing ? (int) $existing->id : null,
				),
			)
		);

		$body = '<p>' . esc_html__( 'A dealer has applied to be listed at checkout.', 'ffl-checkout-solutions' ) . '</p>'
			. '<ul>'
			. '<li><strong>' . esc_html__( 'Business:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( $business ) . '</li>'
			. '<li><strong>' . esc_html__( 'Licence:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( $license ) . '</li>'
			. '<li><strong>' . esc_html__( 'Email:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( $email ) . '</li>'
			. '<li><strong>' . esc_html__( 'Phone:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( $phone ) . '</li>'
			. '<li><strong>' . esc_html__( 'Transfer fee:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( (string) $fee ) . '</li>'
			. '</ul>'
			. ( $notes ? '<p><strong>' . esc_html__( 'Notes:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( $notes ) . '</p>' : '' );

		if ( $existing ) {
			$body .= '<p>' . sprintf(
				/* translators: %s: admin URL for the matching dealer record. */
				wp_kses_post( __( 'This licence matches an existing dealer record — <a href="%s">review it</a>.', 'ffl-checkout-solutions' ) ),
				esc_url( admin_url( 'admin.php?page=fflcs-dealers&dealer=' . (int) $existing->id ) )
			) . '</p>';
		} else {
			$body .= '<p>' . esc_html__( 'This licence number does not match any dealer in the synced ATF directory. Verify it before adding them.', 'ffl-checkout-solutions' ) . '</p>';
		}

		$body .= '<p>' . esc_html__( 'No dealer record was created or changed by this application.', 'ffl-checkout-solutions' ) . '</p>';

		Mailer::send_admin_alert(
			sprintf(
				/* translators: %s: business name. */
				__( 'Dealer application: %s', 'ffl-checkout-solutions' ),
				$business
			),
			$body
		);

		wp_safe_redirect( add_query_arg( 'fflcs_applied', 'ok', $redirect ) );
		exit;
	}
}
