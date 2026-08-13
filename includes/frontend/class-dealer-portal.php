<?php
/**
 * Dealer confirmation portal (Section 9.5).
 *
 * A standalone page at /ffl-confirm/{token}/ that renders outside the theme.
 * That is deliberate: the audience is an FFL dealer's front-counter staff on a
 * phone, opening a link from an email, who have never seen this store before.
 * Inheriting an arbitrary theme's checkout styling would make a compliance
 * action look like a marketing page.
 *
 * @package FFLCS
 */

namespace FFLCS\Frontend;

use FFLCS\Services\Analytics;
use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Mailer;
use FFLCS\Services\Theming;
use FFLCS\Services\Token;
use FFLCS\Services\Tracking_Links;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the dealer-facing confirmation page.
 */
class Dealer_Portal {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'init', array( Tracking_Links::class, 'register_rewrites' ) );
		add_filter( 'query_vars', array( Tracking_Links::class, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_action( 'wp_ajax_nopriv_fflcs_portal_otp', array( $this, 'ajax_send_otp' ) );
		add_action( 'wp_ajax_fflcs_portal_otp', array( $this, 'ajax_send_otp' ) );
	}

	/**
	 * Render the portal when the request carries a token.
	 */
	public function maybe_render(): void {
		$raw_token = get_query_var( 'fflcs_portal_token' );

		if ( ! $raw_token ) {
			return;
		}

		// A confirmation page must never be served from a page cache: the
		// single-use state would be wrong for the next visitor.
		nocache_headers();

		$verified = Token::verify( (string) $raw_token );

		if ( is_wp_error( $verified ) ) {
			$this->render_page( $this->error_body( $verified ), __( 'Confirmation link', 'ffl-checkout-solutions' ) );
			exit;
		}

		$token    = $verified['row'];
		$transfer = Transfer_Service::get( (int) $token->transfer_id );
		$dealer   = Dealer_Service::get( (int) $token->dealer_id );

		if ( ! $transfer || ! $dealer ) {
			$this->render_page(
				$this->message_body(
					__( 'This shipment is no longer on file', 'ffl-checkout-solutions' ),
					__( 'Please contact the store that sent you this link.', 'ffl-checkout-solutions' ),
					'warning'
				),
				__( 'Confirmation link', 'ffl-checkout-solutions' )
			);
			exit;
		}

		Analytics::record( 'portal_viewed', (int) $transfer->id, (int) $dealer->id );

		$this->render_page(
			$this->form_body( (string) $raw_token, $transfer, $dealer, (string) $token->two_factor_method ),
			__( 'Confirm firearm receipt', 'ffl-checkout-solutions' )
		);

		exit;
	}

	// ── Bodies ───────────────────────────────────────────────────────────────

	/**
	 * The confirmation form.
	 *
	 * @param string $raw_token       Raw token from the URL.
	 * @param object $transfer        Transfer row.
	 * @param object $dealer          Dealer row.
	 * @param string $two_factor      Configured second factor.
	 */
	private function form_body( string $raw_token, object $transfer, object $dealer, string $two_factor ): string {
		ob_start();
		?>
		<div class="fflcs-portal__intro">
			<h1><?php esc_html_e( 'Confirm firearm receipt', 'ffl-checkout-solutions' ); ?></h1>
			<p class="fflcs-portal__lede">
				<?php
				printf(
					/* translators: %s: store name. */
					esc_html__( '%s has shipped a firearm to your premises for transfer to their customer. Please confirm what happened.', 'ffl-checkout-solutions' ),
					esc_html( (string) get_option( 'fflcs_store_name', get_option( 'blogname' ) ) )
				);
				?>
			</p>
		</div>

		<dl class="fflcs-portal__facts">
			<div><dt><?php esc_html_e( 'Reference', 'ffl-checkout-solutions' ); ?></dt><dd><?php echo esc_html( (string) $transfer->transfer_ref ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Item', 'ffl-checkout-solutions' ); ?></dt><dd><?php echo esc_html( (string) $transfer->product_name ); ?></dd></div>
			<?php if ( $transfer->manufacturer || $transfer->model ) : ?>
				<div><dt><?php esc_html_e( 'Make / model', 'ffl-checkout-solutions' ); ?></dt><dd><?php echo esc_html( trim( $transfer->manufacturer . ' ' . $transfer->model ) ); ?></dd></div>
			<?php endif; ?>
			<div><dt><?php esc_html_e( 'Your business', 'ffl-checkout-solutions' ); ?></dt><dd><?php echo esc_html( (string) $dealer->business_name ); ?></dd></div>
			<?php if ( $transfer->tracking_number ) : ?>
				<div><dt><?php esc_html_e( 'Tracking', 'ffl-checkout-solutions' ); ?></dt><dd><?php echo esc_html( \FFLCS\Services\Carrier_Links::label( (string) $transfer->carrier ) . ' ' . $transfer->tracking_number ); ?></dd></div>
			<?php endif; ?>
		</dl>

		<form id="fflcs-portal-form" class="fflcs-portal__form" method="post" novalidate>
			<input type="hidden" name="token" value="<?php echo esc_attr( $raw_token ); ?>">
			<input type="hidden" name="action" id="fflcs-portal-action" value="received">

			<?php if ( 'last4_ffl' === $two_factor ) : ?>
				<div class="fflcs-portal__field">
					<label for="fflcs-second-factor"><?php esc_html_e( 'Last 4 characters of your FFL licence number', 'ffl-checkout-solutions' ); ?></label>
					<input type="text" id="fflcs-second-factor" name="second_factor" maxlength="8" autocomplete="off" required>
					<p class="fflcs-portal__hint"><?php esc_html_e( 'This confirms the link reached the right dealer.', 'ffl-checkout-solutions' ); ?></p>
				</div>
			<?php elseif ( 'email_otp' === $two_factor ) : ?>
				<div class="fflcs-portal__field">
					<label for="fflcs-second-factor"><?php esc_html_e( 'Verification code', 'ffl-checkout-solutions' ); ?></label>
					<div class="fflcs-portal__otp">
						<input type="text" id="fflcs-second-factor" name="second_factor" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
						<button type="button" id="fflcs-send-otp" class="fflcs-portal__btn fflcs-portal__btn--ghost"><?php esc_html_e( 'Email me a code', 'ffl-checkout-solutions' ); ?></button>
					</div>
				</div>
			<?php endif; ?>

			<div class="fflcs-portal__field">
				<label for="fflcs-notes"><?php esc_html_e( 'Notes (optional)', 'ffl-checkout-solutions' ); ?></label>
				<textarea id="fflcs-notes" name="notes" rows="3" placeholder="<?php esc_attr_e( 'Anything the store should know', 'ffl-checkout-solutions' ); ?>"></textarea>
			</div>

			<div class="fflcs-hp" aria-hidden="true">
				<label for="fflcs-portal-hp"><?php esc_html_e( 'Leave this field blank', 'ffl-checkout-solutions' ); ?></label>
				<input type="text" id="fflcs-portal-hp" name="fflcs_hp" value="" tabindex="-1" autocomplete="off">
			</div>

			<div class="fflcs-portal__actions">
				<button type="submit" class="fflcs-portal__btn fflcs-portal__btn--primary" data-action="received">
					<?php esc_html_e( 'I received this firearm', 'ffl-checkout-solutions' ); ?>
				</button>
				<button type="submit" class="fflcs-portal__btn fflcs-portal__btn--warning" data-action="issue">
					<?php esc_html_e( 'Report a problem', 'ffl-checkout-solutions' ); ?>
				</button>
				<button type="submit" class="fflcs-portal__btn fflcs-portal__btn--ghost" data-action="not_mine">
					<?php esc_html_e( 'This is not my shipment', 'ffl-checkout-solutions' ); ?>
				</button>
			</div>
		</form>

		<div id="fflcs-portal-result" class="fflcs-portal__result" role="status" aria-live="polite" hidden></div>

		<p class="fflcs-portal__legal">
			<?php esc_html_e( 'This form records what you tell us. It does not perform a background check, complete a Form 4473, or make any compliance determination — those remain entirely your responsibility as the licensed dealer.', 'ffl-checkout-solutions' ); ?>
		</p>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Body for a token failure.
	 *
	 * The already-used case gets its own friendly treatment rather than reading
	 * as an error: a dealer clicking their own link twice has done nothing
	 * wrong, and telling them "invalid link" would generate a support call.
	 *
	 * @param \WP_Error $error Verification error.
	 */
	private function error_body( \WP_Error $error ): string {
		$data = (array) $error->get_error_data();

		if ( ! empty( $data['already_used'] ) ) {
			return $this->message_body(
				__( 'Already confirmed', 'ffl-checkout-solutions' ),
				sprintf(
					/* translators: %s: date and time the link was used. */
					__( 'This shipment was already confirmed on %s. No further action is needed.', 'ffl-checkout-solutions' ),
					esc_html( (string) ( $data['used_at'] ?? '' ) )
				),
				'success'
			);
		}

		return $this->message_body(
			__( 'This link cannot be used', 'ffl-checkout-solutions' ),
			$error->get_error_message() . ' ' . __( 'Please contact the store that sent it.', 'ffl-checkout-solutions' ),
			'warning'
		);
	}

	/**
	 * Simple message body.
	 *
	 * @param string $heading Heading.
	 * @param string $message Message.
	 * @param string $tone    success|warning|error.
	 */
	private function message_body( string $heading, string $message, string $tone = 'info' ): string {
		return '<div class="fflcs-portal__message fflcs-portal__message--' . esc_attr( $tone ) . '">'
			. '<h1>' . esc_html( $heading ) . '</h1>'
			. '<p>' . esc_html( $message ) . '</p>'
			. '</div>';
	}

	// ── Page shell ───────────────────────────────────────────────────────────

	/**
	 * Emit the standalone page.
	 *
	 * @param string $body  Body HTML.
	 * @param string $title Page title.
	 */
	private function render_page( string $body, string $title ): void {
		$store = (string) get_option( 'fflcs_store_name', get_option( 'blogname' ) );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		// Not a public page — keep it out of search results.
		header( 'X-Robots-Tag: noindex, nofollow', true );

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title . ' — ' . $store ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( FFLCS_PLUGIN_URL . 'assets/css/tokens.css?ver=' . FFLCS_VERSION ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( FFLCS_PLUGIN_URL . 'assets/css/dealer-portal.css?ver=' . FFLCS_VERSION ); ?>">
	<style><?php echo wp_strip_all_tags( Theming::custom_properties() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS custom properties, values validated as hex colours. ?></style>
</head>
<body class="fflcs-portal">
	<main class="fflcs-portal__shell">
		<header class="fflcs-portal__brand">
			<img src="<?php echo esc_url( Theming::logo_url() ); ?>" alt="<?php echo esc_attr( $store ); ?>" height="34">
		</header>

		<div class="fflcs-portal__card">
			<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Body assembled from escaped fragments above. ?>
		</div>

		<footer class="fflcs-portal__footer">
			<p><?php echo esc_html( $store ); ?></p>
		</footer>
	</main>

	<script>
		window.fflcsPortal = <?php echo wp_json_encode(
			array(
				'confirmUrl' => rest_url( FFLCS_REST_NS . '/portal/confirm' ),
				'otpUrl'     => admin_url( 'admin-ajax.php' ),
				'i18n'       => array(
					'submitting' => __( 'Submitting…', 'ffl-checkout-solutions' ),
					'error'      => __( 'Something went wrong. Please try again, or contact the store.', 'ffl-checkout-solutions' ),
					'otpSent'    => __( 'We emailed you a code. It expires in 10 minutes.', 'ffl-checkout-solutions' ),
					'otpFailed'  => __( 'We could not send a code. Please contact the store.', 'ffl-checkout-solutions' ),
				),
			)
		); ?>;
	</script>
	<script src="<?php echo esc_url( FFLCS_PLUGIN_URL . 'assets/js/dealer-portal.js?ver=' . FFLCS_VERSION ); ?>"></script>
</body>
</html>
		<?php
	}

	// ── OTP ──────────────────────────────────────────────────────────────────

	/**
	 * Email a one-time code to the dealer on file.
	 *
	 * The code is sent to the address stored for the dealer, never to an
	 * address supplied in the request — otherwise the second factor would be
	 * controlled by whoever holds the link, which defeats the point.
	 */
	public function ajax_send_otp(): void {
		if ( \FFLCS\Services\Token::is_rate_limited( 'portal_otp', 5, MINUTE_IN_SECONDS ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Too many code requests. Please wait a moment.', 'ffl-checkout-solutions' ) ),
				429
			);
		}

		$raw_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		$verified = Token::verify( $raw_token );

		if ( is_wp_error( $verified ) ) {
			wp_send_json_error( array( 'message' => $verified->get_error_message() ), 400 );
		}

		$token  = $verified['row'];
		$dealer = Dealer_Service::get( (int) $token->dealer_id );

		if ( ! $dealer ) {
			wp_send_json_error( array( 'message' => __( 'Dealer not found.', 'ffl-checkout-solutions' ) ), 404 );
		}

		$email = Mailer::dealer_email( $dealer );

		if ( '' === $email ) {
			wp_send_json_error(
				array( 'message' => __( 'No email address is on file for this dealer. Please contact the store.', 'ffl-checkout-solutions' ) ),
				400
			);
		}

		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

		set_transient( 'fflcs_otp_' . (int) $token->id, $code, 10 * MINUTE_IN_SECONDS );

		Mailer::send(
			$email,
			__( 'Your confirmation code', 'ffl-checkout-solutions' ),
			'<p>' . esc_html__( 'Your verification code is:', 'ffl-checkout-solutions' ) . '</p>'
			. '<p style="font-size:28px;font-weight:700;letter-spacing:.15em;">' . esc_html( $code ) . '</p>'
			. '<p>' . esc_html__( 'It expires in 10 minutes.', 'ffl-checkout-solutions' ) . '</p>'
		);

		wp_send_json_success( array( 'sent' => true ) );
	}
}
