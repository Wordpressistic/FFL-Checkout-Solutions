<?php
/**
 * Dealer portal REST endpoints (Section 12.2).
 *
 * Every route here is public by necessity — a dealer confirming a shipment is
 * not a WordPress user and has no session. Authorisation comes entirely from
 * the HMAC token, so each route rate-limits, verifies the token, and consumes
 * it exactly once.
 *
 * @package FFLCS
 */

namespace FFLCS\REST;

use FFLCS\REST_API;
use FFLCS\Services\Analytics;
use FFLCS\Services\Dealer_Service;
use FFLCS\Services\Mailer;
use FFLCS\Services\Token;
use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Portal routes.
 */
class Portal_Controller {

	/**
	 * Actions a dealer may take from the portal (Section 9.5).
	 *
	 * @var array<string,string>
	 */
	const ACTIONS = array(
		'received'     => 'received_by_dealer',
		'issue'        => 'issue_reported',
		'not_mine'     => 'issue_reported',
	);

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			FFLCS_REST_NS,
			'/portal/confirm',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => array( $this, 'check_submit_permission' ),
				'args'                => array(
					'token'  => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'action' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/portal/issue-token',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'issue_token' ),
				'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
			)
		);

		register_rest_route(
			FFLCS_REST_NS,
			'/portal/resend',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resend' ),
				'permission_callback' => array( REST_API::class, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Portal submissions: 10 per minute per IP (Section 13.2).
	 *
	 * @return true|\WP_Error
	 */
	public function check_submit_permission() {
		return REST_API::check_public_rate_limit( 'portal_submit', 10 );
	}

	// ── Dealer actions ───────────────────────────────────────────────────────

	/**
	 * Record a dealer's confirmation.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function confirm( \WP_REST_Request $request ) {
		// Honeypot: the form carries a field no human sees.
		if ( ! empty( $request->get_param( 'fflcs_hp' ) ) ) {
			return new \WP_Error(
				'fflcs_spam_rejected',
				__( 'This submission was rejected.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$raw_token = (string) $request->get_param( 'token' );
		$action    = (string) $request->get_param( 'action' );

		if ( ! array_key_exists( $action, self::ACTIONS ) ) {
			return new \WP_Error(
				'fflcs_invalid_action',
				__( 'Unknown action.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$verified = Token::verify( $raw_token );

		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$token       = $verified['row'];
		$transfer_id = (int) $token->transfer_id;

		$second_factor = $this->check_second_factor( $token, $request );
		if ( is_wp_error( $second_factor ) ) {
			return $second_factor;
		}

		$transfer = Transfer_Service::get( $transfer_id );
		if ( ! $transfer ) {
			return new \WP_Error(
				'fflcs_not_found',
				__( 'The transfer for this link no longer exists.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		// Consume before acting. If the status change fails, a spent token is
		// the safe failure: the dealer contacts the store rather than the link
		// staying live after a partial write.
		Token::consume( (int) $token->id, $action );

		$notes = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );

		$status = self::ACTIONS[ $action ];

		if ( 'not_mine' === $action ) {
			$notes = trim(
				__( 'Dealer reported this shipment was not theirs.', 'ffl-checkout-solutions' ) . ' ' . $notes
			);
		}

		Transfer_Service::set_status( $transfer_id, $status, 'dealer_portal', $notes );

		Transfer_Service::log_event(
			$transfer_id,
			'received' === $action ? 'dealer_confirmed' : 'issue_reported',
			array(
				'dealer_id' => (int) $token->dealer_id,
				'actor'     => 'dealer_portal',
				'notes'     => $notes,
			)
		);

		Analytics::record(
			'received' === $action ? 'dealer_confirmed' : 'issue_reported',
			$transfer_id,
			(int) $token->dealer_id
		);

		if ( 'received' !== $action ) {
			$this->alert_staff_of_issue( $transfer, $notes );
		}

		/**
		 * Fires when a dealer completes a portal action.
		 *
		 * @param int $transfer_id Transfer ID.
		 * @param int $dealer_id   Dealer ID.
		 */
		do_action( 'fflcs_dealer_confirmed', $transfer_id, (int) $token->dealer_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'status'  => $status,
				'message' => 'received' === $action
					? __( 'Thank you — receipt confirmed. The customer has been notified.', 'ffl-checkout-solutions' )
					: __( 'Thank you — the store has been notified and will be in touch.', 'ffl-checkout-solutions' ),
			)
		);
	}

	/**
	 * Enforce the configured second factor.
	 *
	 * Last-4-of-FFL is a weak factor and is treated as one: it exists to stop a
	 * misdirected email being actioned by the wrong recipient, not to resist a
	 * determined attacker. Email OTP is the stronger option.
	 *
	 * @param object           $token   Token row.
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	private function check_second_factor( object $token, \WP_REST_Request $request ) {
		$method = (string) ( $token->two_factor_method ?: 'none' );

		if ( 'none' === $method ) {
			return true;
		}

		$dealer = Dealer_Service::get( (int) $token->dealer_id );

		if ( ! $dealer ) {
			return new \WP_Error(
				'fflcs_dealer_missing',
				__( 'Dealer record not found.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		if ( 'last4_ffl' === $method ) {
			$supplied = preg_replace( '/[^A-Za-z0-9]/', '', (string) $request->get_param( 'second_factor' ) );
			$expected = substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) $dealer->license_number ), -4 );

			if ( '' === $supplied || ! hash_equals( strtoupper( $expected ), strtoupper( $supplied ) ) ) {
				return new \WP_Error(
					'fflcs_second_factor_failed',
					__( 'The last four characters of the FFL licence number did not match.', 'ffl-checkout-solutions' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		if ( 'email_otp' === $method ) {
			$supplied = preg_replace( '/\D/', '', (string) $request->get_param( 'second_factor' ) );
			$stored   = (string) get_transient( 'fflcs_otp_' . (int) $token->id );

			if ( '' === $stored ) {
				return new \WP_Error(
					'fflcs_otp_expired',
					__( 'Your verification code has expired. Please request a new one.', 'ffl-checkout-solutions' ),
					array( 'status' => 410 )
				);
			}

			if ( '' === $supplied || ! hash_equals( $stored, $supplied ) ) {
				return new \WP_Error(
					'fflcs_second_factor_failed',
					__( 'That verification code was not correct.', 'ffl-checkout-solutions' ),
					array( 'status' => 403 )
				);
			}

			delete_transient( 'fflcs_otp_' . (int) $token->id );

			return true;
		}

		return true;
	}

	/**
	 * Email staff when a dealer reports a problem.
	 *
	 * @param object $transfer Transfer row.
	 * @param string $notes    Dealer's notes.
	 */
	private function alert_staff_of_issue( object $transfer, string $notes ): void {
		Mailer::send_admin_alert(
			sprintf(
				/* translators: %s: transfer reference. */
				__( 'Dealer reported an issue with transfer %s', 'ffl-checkout-solutions' ),
				(string) $transfer->transfer_ref
			),
			'<p>' . sprintf(
				/* translators: %s: transfer reference. */
				esc_html__( 'A dealer reported a problem with transfer %s.', 'ffl-checkout-solutions' ),
				esc_html( (string) $transfer->transfer_ref )
			) . '</p>'
			. ( $notes ? '<p><strong>' . esc_html__( 'Notes:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( $notes ) . '</p>' : '' )
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=fflcs-transfers&transfer=' . (int) $transfer->id ) ) . '">'
			. esc_html__( 'Open the transfer', 'ffl-checkout-solutions' ) . '</a></p>'
		);
	}

	// ── Staff actions ────────────────────────────────────────────────────────

	/**
	 * Mint and email a fresh confirmation link.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function issue_token( \WP_REST_Request $request ) {
		$transfer_id = (int) $request->get_param( 'transfer_id' );
		$transfer    = Transfer_Service::get( $transfer_id );

		if ( ! $transfer ) {
			return new \WP_Error(
				'fflcs_not_found',
				__( 'Transfer not found.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		$token = Token::generate( $transfer_id, (int) $transfer->dealer_id );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url  = Token::build_url( $token );
		$sent = Mailer::send_dealer_invitation( $transfer_id, $url );

		return rest_ensure_response(
			array(
				'success'    => true,
				'emailed'    => $sent,
				// Returned so staff can pass the link on by phone when the
				// dealer has no email on file. This response only reaches an
				// authenticated staff member.
				'portal_url' => $url,
			)
		);
	}

	/**
	 * Re-send the dealer invitation, minting a new link.
	 *
	 * The previous link is revoked by Token::generate(), so a resend never
	 * leaves two live links for one shipment.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resend( \WP_REST_Request $request ) {
		return $this->issue_token( $request );
	}
}
