<?php
/**
 * Customer transfer tracking page (Section 9.8).
 *
 * Public at /track-transfer/{ref}/{sig}/, HMAC-protected, no login. A customer
 * who bought a firearm wants to know where it is without creating an account,
 * and a dealer's phone number and address are exactly what they need next.
 *
 * The page shows only what the buyer already knows: their own transfer's
 * progress, and the public contact details of the dealer they chose.
 *
 * @package FFLCS
 */

namespace FFLCS\Frontend;

use FFLCS\Services\Carrier_Links;
use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Theming;
use FFLCS\Services\Tracking_Links;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the public tracking page.
 */
class Tracking_Page {

	/**
	 * The five milestones shown on the timeline.
	 *
	 * Compressed from the eleven lifecycle statuses: a buyer does not need to
	 * see NICS sub-states as separate steps, and showing them would invite
	 * questions the store cannot answer for them.
	 *
	 * @return array<int,array{key:string,label:string,statuses:string[]}>
	 */
	private function milestones(): array {
		return array(
			array(
				'key'      => 'ordered',
				'label'    => __( 'Order confirmed', 'ffl-checkout-solutions' ),
				'statuses' => array( 'dealer_selected', 'payment_confirmed' ),
			),
			array(
				'key'      => 'shipped',
				'label'    => __( 'Shipped to dealer', 'ffl-checkout-solutions' ),
				'statuses' => array( 'shipped_to_dealer' ),
			),
			array(
				'key'      => 'received',
				'label'    => __( 'Received by dealer', 'ffl-checkout-solutions' ),
				'statuses' => array( 'received_by_dealer' ),
			),
			array(
				'key'      => 'processing',
				'label'    => __( 'Background check', 'ffl-checkout-solutions' ),
				'statuses' => array( 'nics_pending', 'nics_delayed', 'nics_approved' ),
			),
			array(
				'key'      => 'complete',
				'label'    => __( 'Transfer complete', 'ffl-checkout-solutions' ),
				'statuses' => array( 'transferred' ),
			),
		);
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_action( 'init', array( $this, 'register_ics_endpoint' ) );
	}

	/**
	 * Register the calendar-invite query var.
	 */
	public function register_ics_endpoint(): void {
		add_rewrite_tag( '%fflcs_ics%', '([0-9]+)' );
	}

	/**
	 * Render the tracking page when the request carries a valid signature.
	 */
	public function maybe_render(): void {
		$reference = get_query_var( 'fflcs_track_ref' );
		$signature = get_query_var( 'fflcs_track_sig' );

		if ( ! $reference || ! $signature ) {
			return;
		}

		nocache_headers();

		if ( ! Tracking_Links::verify( (string) $reference, (string) $signature ) ) {
			status_header( 403 );
			$this->render_page(
				'<div class="fflcs-tracking__message"><h1>' . esc_html__( 'This tracking link is not valid', 'ffl-checkout-solutions' ) . '</h1>'
				. '<p>' . esc_html__( 'Check the link in your confirmation email, or contact us for help.', 'ffl-checkout-solutions' ) . '</p></div>'
			);
			exit;
		}

		$transfer = Transfer_Service::get_by_reference( (string) $reference );

		if ( ! $transfer ) {
			status_header( 404 );
			$this->render_page(
				'<div class="fflcs-tracking__message"><h1>' . esc_html__( 'Transfer not found', 'ffl-checkout-solutions' ) . '</h1>'
				. '<p>' . esc_html__( 'This transfer is no longer on file. Please contact us.', 'ffl-checkout-solutions' ) . '</p></div>'
			);
			exit;
		}

		// Serve the calendar invite instead of the page when asked for.
		if ( isset( $_GET['ics'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, signature-authenticated.
			$this->send_ics( $transfer );
			exit;
		}

		$this->render_page( $this->body( $transfer ) );
		exit;
	}

	/**
	 * Page body.
	 *
	 * @param object $transfer Transfer row.
	 */
	private function body( object $transfer ): string {
		$dealer  = Dealer_Service::get( (int) $transfer->dealer_id );
		$status  = (string) $transfer->status;
		$reached = $this->reached_milestones( $status );

		ob_start();
		?>
		<div class="fflcs-tracking__header">
			<p class="fflcs-tracking__eyebrow"><?php esc_html_e( 'Transfer', 'ffl-checkout-solutions' ); ?> <?php echo esc_html( (string) $transfer->transfer_ref ); ?></p>
			<h1><?php echo esc_html( (string) $transfer->product_name ); ?></h1>
			<p class="fflcs-tracking__status" style="color:<?php echo esc_attr( Theming::status_color( $status ) ); ?>">
				<?php echo esc_html( fflcs_status_label( $status ) ); ?>
			</p>
		</div>

		<?php if ( 'cancelled' === $status ) : ?>
			<div class="fflcs-tracking__alert fflcs-tracking__alert--danger">
				<?php esc_html_e( 'This transfer has been cancelled. Please contact us if you did not expect this.', 'ffl-checkout-solutions' ); ?>
			</div>
		<?php elseif ( 'issue_reported' === $status ) : ?>
			<div class="fflcs-tracking__alert fflcs-tracking__alert--warning">
				<?php esc_html_e( 'Your dealer reported a problem with this shipment. We are looking into it and will be in touch.', 'ffl-checkout-solutions' ); ?>
			</div>
		<?php else : ?>
			<ol class="fflcs-timeline">
				<?php foreach ( $this->milestones() as $index => $milestone ) : ?>
					<?php
					$is_done    = in_array( $milestone['key'], $reached, true );
					$is_current = ! $is_done && $index === count( $reached );
					$classes    = 'fflcs-timeline__step';
					$classes   .= $is_done ? ' is-done' : '';
					$classes   .= $is_current ? ' is-current' : '';
					?>
					<li class="<?php echo esc_attr( $classes ); ?>">
						<span class="fflcs-timeline__marker" aria-hidden="true"></span>
						<span class="fflcs-timeline__label"><?php echo esc_html( $milestone['label'] ); ?></span>
						<?php
						$date = $this->milestone_date( $milestone['key'], $transfer );
						if ( $date ) :
							?>
							<span class="fflcs-timeline__date"><?php echo esc_html( $date ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endif; ?>

		<?php if ( $transfer->tracking_number ) : ?>
			<?php $tracking_url = Carrier_Links::tracking_url( (string) $transfer->carrier, (string) $transfer->tracking_number ); ?>
			<section class="fflcs-tracking__panel">
				<h2><?php esc_html_e( 'Shipment', 'ffl-checkout-solutions' ); ?></h2>
				<p>
					<?php echo esc_html( Carrier_Links::label( (string) $transfer->carrier ) ); ?>
					<strong><?php echo esc_html( (string) $transfer->tracking_number ); ?></strong>
				</p>
				<?php if ( $tracking_url ) : ?>
					<a class="fflcs-tracking__btn" href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Track with carrier', 'ffl-checkout-solutions' ); ?>
					</a>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( $dealer ) : ?>
			<section class="fflcs-tracking__panel fflcs-tracking__dealer">
				<h2><?php esc_html_e( 'Your dealer', 'ffl-checkout-solutions' ); ?></h2>
				<p class="fflcs-tracking__dealer-name"><?php echo esc_html( (string) $dealer->business_name ); ?></p>
				<p class="fflcs-tracking__dealer-address">
					<?php echo esc_html( \FFLCS\Services\Mailer::dealer_address( $dealer ) ); ?>
				</p>
				<div class="fflcs-tracking__dealer-actions">
					<?php if ( $dealer->phone ) : ?>
						<a class="fflcs-tracking__btn" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', (string) $dealer->phone ) ); ?>">
							<?php echo esc_html( (string) $dealer->phone ); ?>
						</a>
					<?php endif; ?>
					<a class="fflcs-tracking__btn fflcs-tracking__btn--ghost" target="_blank" rel="noopener noreferrer"
						href="https://www.google.com/maps/dir/?api=1&destination=<?php echo rawurlencode( \FFLCS\Services\Mailer::dealer_address( $dealer ) ); ?>">
						<?php esc_html_e( 'Directions', 'ffl-checkout-solutions' ); ?>
					</a>
					<?php if ( 'received_by_dealer' === $status ) : ?>
						<a class="fflcs-tracking__btn fflcs-tracking__btn--ghost" href="<?php echo esc_url( add_query_arg( 'ics', '1' ) ); ?>">
							<?php esc_html_e( 'Add pickup to calendar', 'ffl-checkout-solutions' ); ?>
						</a>
					<?php endif; ?>
				</div>
				<?php if ( 'received_by_dealer' === $status ) : ?>
					<p class="fflcs-tracking__hint">
						<?php esc_html_e( 'Call ahead to confirm their hours, and bring valid government-issued photo identification. Your dealer will complete the background check and paperwork in person.', 'ffl-checkout-solutions' ); ?>
					</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<p class="fflcs-tracking__legal">
			<?php esc_html_e( 'Your licensed dealer is responsible for all background checks, paperwork and the final transfer decision. This page reports progress only.', 'ffl-checkout-solutions' ); ?>
		</p>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Which milestones the current status has passed.
	 *
	 * Milestones are cumulative: reaching "received" implies "shipped" even if
	 * the shipped status was never explicitly recorded, which happens on local
	 * transfers a dealer walked in.
	 *
	 * @param string $status Current status.
	 * @return string[]
	 */
	private function reached_milestones( string $status ): array {
		$reached = array();

		foreach ( $this->milestones() as $milestone ) {
			$reached[] = $milestone['key'];

			if ( in_array( $status, $milestone['statuses'], true ) ) {
				return $reached;
			}
		}

		// A status outside the happy path (denied, cancelled, issue) shows the
		// first milestone only rather than an arbitrary position.
		return array( 'ordered' );
	}

	/**
	 * The date to show against a milestone, when we have one.
	 *
	 * @param string $key      Milestone key.
	 * @param object $transfer Transfer row.
	 */
	private function milestone_date( string $key, object $transfer ): string {
		$map = array(
			'ordered'  => (string) $transfer->created_at,
			'shipped'  => (string) ( $transfer->shipped_date ?? '' ),
			'received' => (string) ( $transfer->received_date ?? '' ),
			'complete' => (string) ( $transfer->transferred_date ?? '' ),
		);

		$value = $map[ $key ] ?? '';

		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );

		return $timestamp ? date_i18n( (string) get_option( 'date_format' ), $timestamp ) : '';
	}

	// ── Calendar invite ──────────────────────────────────────────────────────

	/**
	 * Emit an .ics pickup reminder.
	 *
	 * @param object $transfer Transfer row.
	 */
	private function send_ics( object $transfer ): void {
		$dealer = Dealer_Service::get( (int) $transfer->dealer_id );
		$store  = (string) get_option( 'fflcs_store_name', get_option( 'blogname' ) );

		$start = strtotime( '+1 day 10:00' );
		$end   = $start + HOUR_IN_SECONDS;

		$summary  = sprintf(
			/* translators: %s: product name. */
			__( 'Firearm pickup: %s', 'ffl-checkout-solutions' ),
			(string) $transfer->product_name
		);
		$location = $dealer ? \FFLCS\Services\Mailer::dealer_address( $dealer ) : '';

		$description = sprintf(
			/* translators: 1: transfer reference, 2: store name. */
			__( 'Transfer %1$s from %2$s. Bring valid government-issued photo identification. Call the dealer first to confirm their hours.', 'ffl-checkout-solutions' ),
			(string) $transfer->transfer_ref,
			$store
		);

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//FFL Checkout Solutions//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:fflcs-' . $transfer->transfer_ref . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', $start ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', $end ),
			'SUMMARY:' . $this->ics_escape( $summary ),
			'LOCATION:' . $this->ics_escape( $location ),
			'DESCRIPTION:' . $this->ics_escape( $description ),
			'END:VEVENT',
			'END:VCALENDAR',
		);

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pickup-' . sanitize_file_name( (string) $transfer->transfer_ref ) . '.ics"' );

		// RFC 5545 requires CRLF line endings; some calendar clients reject LF.
		echo implode( "\r\n", $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCalendar body, escaped per RFC 5545 below.
	}

	/**
	 * Escape a value for an iCalendar property (RFC 5545 §3.3.11).
	 *
	 * @param string $value Raw value.
	 */
	private function ics_escape( string $value ): string {
		$value = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), $value );

		return str_replace( array( "\r\n", "\n", "\r" ), '\\n', $value );
	}

	// ── Page shell ───────────────────────────────────────────────────────────

	/**
	 * Emit the standalone page.
	 *
	 * @param string $body Body HTML.
	 */
	private function render_page( string $body ): void {
		$store = (string) get_option( 'fflcs_store_name', get_option( 'blogname' ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( __( 'Track your transfer', 'ffl-checkout-solutions' ) . ' — ' . $store ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( FFLCS_PLUGIN_URL . 'assets/css/tokens.css?ver=' . FFLCS_VERSION ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( FFLCS_PLUGIN_URL . 'assets/css/tracking-page.css?ver=' . FFLCS_VERSION ); ?>">
	<style><?php echo wp_strip_all_tags( Theming::custom_properties() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS custom properties, hex-validated. ?></style>
</head>
<body class="fflcs-tracking">
	<main class="fflcs-tracking__shell">
		<header class="fflcs-tracking__brand">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<img src="<?php echo esc_url( Theming::logo_url() ); ?>" alt="<?php echo esc_attr( $store ); ?>" height="32">
			</a>
		</header>

		<div class="fflcs-tracking__card">
			<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled from escaped fragments. ?>
		</div>

		<footer class="fflcs-tracking__footer">
			<p><?php echo esc_html( $store ); ?></p>
		</footer>
	</main>
</body>
</html>
		<?php
	}
}
