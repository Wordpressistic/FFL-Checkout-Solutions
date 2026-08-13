<?php
/**
 * ID and age verification (Section 10.7).
 *
 * Collects a document and routes it to staff. There is no automatic approval
 * path in this module, by design: an identity decision on a firearm purchase is
 * made by a person who can be held accountable for it.
 *
 * Uploaded documents go to the private directory, never the media library.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Installer;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Identity document intake and review.
 */
class Id_Verification {

	/**
	 * Accepted upload types.
	 *
	 * @var array<string,string>
	 */
	const ALLOWED_TYPES = array(
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'pdf'      => 'application/pdf',
	);

	/**
	 * Maximum upload size.
	 */
	const MAX_BYTES = 8388608; // 8 MB.

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_post_fflcs_review_id', array( $this, 'handle_review' ) );
		add_action( 'admin_post_nopriv_fflcs_submit_id', array( $this, 'handle_submission' ) );
		add_action( 'admin_post_fflcs_submit_id', array( $this, 'handle_submission' ) );
	}

	/**
	 * Accept a customer's document upload.
	 */
	public function handle_submission(): void {
		if ( ! isset( $_POST['fflcs_id_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fflcs_id_nonce'] ) ), 'fflcs_submit_id' ) ) {
			wp_die( esc_html__( 'This submission could not be verified.', 'ffl-checkout-solutions' ), 403 );
		}

		if ( \FFLCS\Services\Token::is_rate_limited( 'id_upload', 5, HOUR_IN_SECONDS ) ) {
			wp_die( esc_html__( 'Too many uploads. Please wait and try again.', 'ffl-checkout-solutions' ), 429 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$reference = sanitize_text_field( wp_unslash( $_POST['transfer_ref'] ?? '' ) );
		$transfer  = Transfer_Service::get_by_reference( $reference );

		if ( ! $transfer ) {
			wp_die( esc_html__( 'That transfer could not be found.', 'ffl-checkout-solutions' ), 404 );
		}

		if ( empty( $_FILES['document']['name'] ) ) {
			wp_die( esc_html__( 'No document was uploaded.', 'ffl-checkout-solutions' ), 400 );
		}

		$path = $this->store_upload( $_FILES['document'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( is_wp_error( $path ) ) {
			wp_die( esc_html( $path->get_error_message() ), 400 );
		}

		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'id_verifications' ),
			array(
				'transfer_id'   => (int) $transfer->id,
				'order_id'      => (int) $transfer->order_id,
				'provider'      => 'manual',
				'ship_to_state' => '',
				'document_path' => $path,
				'result_status' => 'pending',
				'created_at'    => fflcs_mysql_now(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		Transfer_Service::log_event(
			(int) $transfer->id,
			'id_document_received',
			array(
				'actor' => 'customer',
				'notes' => __( 'An identity document was submitted and is awaiting staff review. No automatic decision was made.', 'ffl-checkout-solutions' ),
			)
		);

		\FFLCS\Services\Mailer::send_admin_alert(
			__( 'An identity document is awaiting review', 'ffl-checkout-solutions' ),
			'<p>' . sprintf(
				/* translators: %s: transfer reference. */
				esc_html__( 'A customer submitted an identity document for transfer %s.', 'ffl-checkout-solutions' ),
				esc_html( $reference )
			) . '</p>'
		);

		wp_safe_redirect( \FFLCS\Services\Tracking_Links::customer_url( $reference ) );
		exit;
	}

	/**
	 * Validate and store an upload in the private directory.
	 *
	 * @param array<string,mixed> $file $_FILES entry.
	 * @return string|\WP_Error Relative stored path.
	 */
	private function store_upload( array $file ) {
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'fflcs_file_too_large',
				__( 'That file is too large. The maximum is 8 MB.', 'ffl-checkout-solutions' )
			);
		}

		// Checked against the file's actual contents, not the name or the
		// browser-supplied type — both are attacker-controlled.
		$check = wp_check_filetype_and_ext(
			(string) $file['tmp_name'],
			(string) $file['name'],
			self::ALLOWED_TYPES
		);

		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new \WP_Error(
				'fflcs_bad_file_type',
				__( 'Only JPEG, PNG and PDF files are accepted.', 'ffl-checkout-solutions' )
			);
		}

		Installer::ensure_private_upload_dir();

		$directory = Installer::private_dir() . 'id/';

		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		// Random filename: the directory is protected, but a guessable name is
		// one misconfiguration away from being a public identity document.
		$filename = 'id-' . bin2hex( random_bytes( 16 ) ) . '.' . $check['ext'];

		if ( ! move_uploaded_file( (string) $file['tmp_name'], $directory . $filename ) ) {
			return new \WP_Error(
				'fflcs_upload_failed',
				__( 'The upload could not be saved.', 'ffl-checkout-solutions' )
			);
		}

		return 'id/' . $filename;
	}

	/**
	 * Record a staff decision.
	 */
	public function handle_review(): void {
		global $wpdb;

		if ( ! current_user_can( 'view_fflcs_documents' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to review identity documents.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_review_id' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$id       = absint( wp_unslash( $_POST['verification_id'] ?? 0 ) );
		$decision = sanitize_key( wp_unslash( $_POST['decision'] ?? '' ) );
		$notes    = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! in_array( $decision, array( 'verified', 'rejected' ), true ) ) {
			wp_die( esc_html__( 'Unknown decision.', 'ffl-checkout-solutions' ), 400 );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'id_verifications' ),
			array(
				'result_status' => $decision,
				'reviewed_by'   => get_current_user_id(),
				'reviewed_at'   => fflcs_mysql_now(),
				'notes'         => $notes,
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'fflcs-compliance',
					'tab'           => 'verification',
					'fflcs_message' => rawurlencode( __( 'Decision recorded.', 'ffl-checkout-solutions' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
