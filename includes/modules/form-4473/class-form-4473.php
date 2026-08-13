<?php
/**
 * Form 4473 worksheet (Section 10.4).
 *
 * Produces a printable pre-fill worksheet from the transfer record, and captures
 * signatures.
 *
 * What this is not, stated on every page of the output and again here: it is not
 * ATF Form 4473, it is not a substitute for it, and it cannot be submitted. Only
 * the current official form, completed in person at the licensee's premises, is
 * a 4473. This worksheet saves re-keying and nothing else.
 *
 * Signatures are append-only. A re-signature adds a row; it never overwrites the
 * earlier capture, so the trail of who signed what and when survives.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Lib\Pdf_Writer;
use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Tracking_Links;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Worksheet generation and signature capture.
 */
class Form_4473 {

	/**
	 * The banner that appears on every rendering.
	 */
	const DRAFT_BANNER = 'DRAFT — NOT FOR ATF SUBMISSION';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_action( 'admin_post_fflcs_capture_signature', array( $this, 'handle_signature' ) );
	}

	/**
	 * Serve the worksheet when the signed URL is requested.
	 */
	public function maybe_render(): void {
		$transfer_id = (int) get_query_var( 'fflcs_form_id' );
		$signature   = (string) get_query_var( 'fflcs_form_sig' );

		if ( ! $transfer_id || '' === $signature ) {
			return;
		}

		// Signed *and* capability-checked. The signature stops URL guessing;
		// the capability check is what actually authorises the request, since
		// this document carries a buyer's identifying details.
		if ( ! Tracking_Links::verify_form_4473( $transfer_id, $signature ) || ! fflcs_can_manage() ) {
			wp_die(
				esc_html__( 'You do not have permission to view this worksheet.', 'ffl-checkout-solutions' ),
				403
			);
		}

		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer ) {
			wp_die( esc_html__( 'Transfer not found.', 'ffl-checkout-solutions' ), 404 );
		}

		nocache_headers();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only format switch on an already-authorised request.
		if ( isset( $_GET['pdf'] ) ) {
			$this->send_pdf( $transfer );
			exit;
		}

		$this->send_html( $transfer );
		exit;
	}

	/**
	 * The field values the worksheet pre-fills.
	 *
	 * @param object $transfer Transfer row.
	 * @return array<string,array<string,string>>
	 */
	private function sections( object $transfer ): array {
		$dealer = Dealer_Service::get( (int) $transfer->dealer_id );

		return array(
			__( 'Section A — Transferee', 'ffl-checkout-solutions' )   => array(
				__( 'Name', 'ffl-checkout-solutions' )  => (string) $transfer->customer_name,
				__( 'Email', 'ffl-checkout-solutions' ) => (string) $transfer->customer_email,
				__( 'Phone', 'ffl-checkout-solutions' ) => (string) $transfer->customer_phone,
			),
			__( 'Section B — Firearm', 'ffl-checkout-solutions' )      => array(
				__( 'Manufacturer', 'ffl-checkout-solutions' )  => (string) $transfer->manufacturer,
				__( 'Model', 'ffl-checkout-solutions' )         => (string) $transfer->model,
				__( 'Serial number', 'ffl-checkout-solutions' ) => (string) $transfer->serial_number,
				__( 'Type', 'ffl-checkout-solutions' )          => (string) $transfer->product_type,
				__( 'Caliber or gauge', 'ffl-checkout-solutions' ) => (string) $transfer->caliber,
			),
			__( 'Section C — Transferring dealer', 'ffl-checkout-solutions' ) => array(
				__( 'Business', 'ffl-checkout-solutions' ) => $dealer ? (string) $dealer->business_name : '',
				__( 'Licence', 'ffl-checkout-solutions' )  => $dealer ? (string) $dealer->license_number : '',
				__( 'Premises', 'ffl-checkout-solutions' ) => $dealer ? \FFLCS\Services\Mailer::dealer_address( $dealer ) : '',
			),
			__( 'Section D — Background check', 'ffl-checkout-solutions' ) => array(
				__( 'NICS transaction number', 'ffl-checkout-solutions' ) => (string) $transfer->nics_ntn,
				__( 'Outcome', 'ffl-checkout-solutions' )     => (string) $transfer->nics_status,
				__( 'Check date', 'ffl-checkout-solutions' )  => (string) $transfer->nics_check_date,
				__( 'Delay expires', 'ffl-checkout-solutions' ) => (string) $transfer->nics_delay_expires,
			),
		);
	}

	/**
	 * Render the printable HTML worksheet.
	 *
	 * @param object $transfer Transfer row.
	 */
	private function send_html( object $transfer ): void {
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$sections = $this->sections( $transfer );

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( __( '4473 worksheet', 'ffl-checkout-solutions' ) . ' — ' . $transfer->transfer_ref ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; padding: 32px; color: #0f172a; line-height: 1.5; }
		.banner { background: #fef3c7; border: 2px solid #d97706; color: #92400e; padding: 12px 16px; font-weight: 700; text-align: center; letter-spacing: .04em; margin-bottom: 24px; }
		h1 { font-size: 20px; margin: 0 0 4px; }
		.ref { color: #64748b; margin: 0 0 24px; }
		section { margin-bottom: 24px; page-break-inside: avoid; }
		h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: #64748b; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin: 0 0 12px; }
		dl { display: grid; grid-template-columns: 200px 1fr; gap: 8px 16px; margin: 0; }
		dt { color: #64748b; font-size: 13px; }
		dd { margin: 0; font-weight: 600; border-bottom: 1px solid #cbd5e1; min-height: 20px; }
		.note { font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 16px; margin-top: 32px; }
		.actions { margin-bottom: 24px; }
		.actions a { display: inline-block; padding: 8px 16px; border: 1px solid #cbd5e1; border-radius: 6px; text-decoration: none; color: #0f172a; margin-right: 8px; }
		@media print { .actions { display: none; } }
	</style>
</head>
<body>
	<div class="banner"><?php echo esc_html( self::DRAFT_BANNER ); ?></div>

	<div class="actions">
		<a href="#" onclick="window.print();return false;"><?php esc_html_e( 'Print', 'ffl-checkout-solutions' ); ?></a>
		<a href="<?php echo esc_url( add_query_arg( 'pdf', '1' ) ); ?>"><?php esc_html_e( 'Download PDF', 'ffl-checkout-solutions' ); ?></a>
	</div>

	<h1><?php esc_html_e( 'Firearms transaction worksheet', 'ffl-checkout-solutions' ); ?></h1>
	<p class="ref"><?php echo esc_html( (string) $transfer->transfer_ref ); ?></p>

	<?php foreach ( $sections as $heading => $fields ) : ?>
		<section>
			<h2><?php echo esc_html( (string) $heading ); ?></h2>
			<dl>
				<?php foreach ( $fields as $label => $value ) : ?>
					<dt><?php echo esc_html( (string) $label ); ?></dt>
					<dd><?php echo esc_html( (string) $value ); ?></dd>
				<?php endforeach; ?>
			</dl>
		</section>
	<?php endforeach; ?>

	<p class="note">
		<?php esc_html_e( 'This worksheet exists to save re-keying information you already hold. It is not ATF Form 4473, is not a substitute for it, and cannot be submitted to anyone. The transfer must be completed on the current official form, in person, at the licensed premises. Every eligibility determination remains the licensee\'s.', 'ffl-checkout-solutions' ); ?>
	</p>
</body>
</html>
		<?php
	}

	/**
	 * Render and stream the PDF.
	 *
	 * @param object $transfer Transfer row.
	 */
	private function send_pdf( object $transfer ): void {
		$pdf = new Pdf_Writer();
		$pdf->add_page();

		$pdf->rect( 40, 40, 532, 28 );
		$pdf->text( 160, 58, self::DRAFT_BANNER, 12, true );

		$pdf->text( 40, 100, __( 'Firearms transaction worksheet', 'ffl-checkout-solutions' ), 16, true );
		$pdf->text( 40, 120, (string) $transfer->transfer_ref, 10 );

		$y = 150;

		foreach ( $this->sections( $transfer ) as $heading => $fields ) {
			$pdf->text( 40, $y, (string) $heading, 11, true );
			$pdf->line( 40, $y + 4, 532 );

			$y += 22;

			foreach ( $fields as $label => $value ) {
				$pdf->text( 48, $y, (string) $label, 9 );
				$pdf->text( 240, $y, (string) $value, 10, true );
				$pdf->line( 236, $y + 3, 330 );

				$y += 20;
			}

			$y += 12;

			// Start a fresh page rather than running off the bottom.
			if ( $y > 680 ) {
				$pdf->add_page();
				$y = 60;
			}
		}

		$pdf->paragraph(
			40,
			$y + 20,
			532,
			__( 'This worksheet is not ATF Form 4473 and cannot be submitted. The transfer must be completed on the current official form, in person, at the licensed premises. Every eligibility determination remains the licensee\'s.', 'ffl-checkout-solutions' ),
			8
		);

		$body = $pdf->output();

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="worksheet-' . sanitize_file_name( (string) $transfer->transfer_ref ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $body ) );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF payload.
	}

	/**
	 * Store a signature capture.
	 */
	public function handle_signature(): void {
		global $wpdb;

		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to capture signatures.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_capture_signature' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$transfer_id = absint( wp_unslash( $_POST['transfer_id'] ?? 0 ) );
		$signer_type = sanitize_key( wp_unslash( $_POST['signer_type'] ?? 'buyer' ) );
		$signer_name = sanitize_text_field( wp_unslash( $_POST['signer_name'] ?? '' ) );
		$data        = (string) wp_unslash( $_POST['signature_data'] ?? '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Only a PNG data URL from the signature pad. Anything else is not
		// something we should be storing, let alone re-rendering later.
		if ( ! preg_match( '#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $data ) ) {
			wp_die( esc_html__( 'That signature could not be read.', 'ffl-checkout-solutions' ), 400 );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'signatures' ),
			array(
				'transfer_id'    => $transfer_id,
				'signer_type'    => in_array( $signer_type, array( 'buyer', 'dealer' ), true ) ? $signer_type : 'buyer',
				'signer_name'    => $signer_name,
				'signature_data' => $data,
				'signed_by'      => get_current_user_id(),
				'signed_at'      => fflcs_mysql_now(),
				'ip_address'     => \FFLCS\Services\Token::client_ip(),
				'user_agent'     => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'created_at'     => fflcs_mysql_now(),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		Transfer_Service::log_event(
			$transfer_id,
			'signature_captured',
			array(
				'actor' => wp_get_current_user()->user_login ?: 'staff',
				'notes' => sprintf(
					/* translators: %s: signer role. */
					__( 'A %s signature was captured on the worksheet. Earlier captures are retained.', 'ffl-checkout-solutions' ),
					$signer_type
				),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-transfers',
					'transfer'      => $transfer_id,
					'fflcs_message' => rawurlencode( __( 'Signature captured.', 'ffl-checkout-solutions' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
