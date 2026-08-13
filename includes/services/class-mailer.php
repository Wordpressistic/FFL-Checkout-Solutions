<?php
/**
 * Transactional email (Section 15).
 *
 * All plugin mail goes through send(), which owns templating, merge-tag
 * expansion and the mail log. Nothing else calls wp_mail() directly, so there
 * is one place that knows whether a message was actually accepted for delivery.
 *
 * Templates are inline-styled tables. That is not a stylistic choice: Gmail,
 * Outlook and Apple Mail each strip or rewrite <style> blocks differently, and
 * inline styles are the only thing all three render predictably.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Templated mail sender with logging.
 */
class Mailer {

	/**
	 * Mail log option key.
	 */
	const LOG_OPTION = 'fflcs_mail_log';

	/**
	 * Log entries retained (Section 15.4).
	 */
	const LOG_LIMIT = 100;

	/**
	 * Register the async send handler.
	 */
	public function init(): void {
		add_action( 'fflcs_async_send_mail', array( __CLASS__, 'handle_async_send' ), 10, 4 );
	}

	// ── Core send ────────────────────────────────────────────────────────────

	/**
	 * Send one templated message.
	 *
	 * @param string              $to       Recipient.
	 * @param string              $subject  Subject, merge tags allowed.
	 * @param string              $body     HTML body fragment, merge tags allowed.
	 * @param array<string,mixed> $tags     Merge tag values.
	 * @param array<string,mixed> $options  cta_url, cta_label, preheader.
	 */
	public static function send( string $to, string $subject, string $body, array $tags = array(), array $options = array() ): bool {
		$to = sanitize_email( $to );

		if ( ! is_email( $to ) ) {
			self::log( $to, $subject, false, __( 'Invalid recipient address.', 'ffl-checkout-solutions' ) );
			return false;
		}

		$tags = array_merge( self::global_tags(), $tags );

		$subject = self::expand( $subject, $tags );
		$body    = self::expand( $body, $tags );

		$html = self::wrap( $body, $subject, $options );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', self::from_name(), self::from_email() ),
		);

		$reply_to = (string) get_option( 'fflcs_email_reply_to', '' );
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		$sent = wp_mail( $to, wp_specialchars_decode( $subject, ENT_QUOTES ), $html, $headers );

		self::log( $to, $subject, $sent, $sent ? '' : __( 'wp_mail() returned false — check the site SMTP configuration.', 'ffl-checkout-solutions' ) );

		return (bool) $sent;
	}

	/**
	 * Queue a message rather than sending it inline.
	 *
	 * Used everywhere a send would otherwise sit inside a customer-facing
	 * request. A slow or unreachable SMTP host must never add seconds to a
	 * checkout.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @param array  $tags    Merge tags.
	 */
	public static function send_async( string $to, string $subject, string $body, array $tags = array() ): void {
		Scheduler::async( 'fflcs_async_send_mail', array( $to, $subject, $body, $tags ) );
	}

	/**
	 * Async queue handler.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @param array  $tags    Merge tags.
	 */
	public static function handle_async_send( string $to, string $subject, string $body, array $tags = array() ): void {
		self::send( $to, $subject, $body, (array) $tags );
	}

	// ── Transfer notifications ───────────────────────────────────────────────

	/**
	 * Notify the customer that their transfer changed status.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $status      New status.
	 */
	public static function send_status_update( int $transfer_id, string $status ): void {
		if ( '1' !== (string) get_option( 'fflcs_email_customer_enabled', '1' ) ) {
			return;
		}

		$transfer = Transfer_Service::get( $transfer_id );
		if ( ! $transfer || ! $transfer->customer_email ) {
			return;
		}

		$copy = self::status_copy( $status );
		if ( ! $copy ) {
			return;
		}

		$dealer = Dealer_Service::get( (int) $transfer->dealer_id );
		$tags   = self::transfer_tags( $transfer, $dealer );

		$body = '<p>' . esc_html__( 'Hello {customer_name},', 'ffl-checkout-solutions' ) . '</p>'
			. '<p>' . $copy['body'] . '</p>'
			. self::transfer_summary_table( $transfer, $dealer );

		self::send_async(
			(string) $transfer->customer_email,
			$copy['subject'],
			$body,
			$tags
		);

		Analytics::record( 'email_sent', $transfer_id, (int) $transfer->dealer_id, 'customer_status' );
	}

	/**
	 * Send a dealer their confirmation magic link.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $portal_url  Portal URL carrying the raw token.
	 */
	public static function send_dealer_invitation( int $transfer_id, string $portal_url ): bool {
		if ( '1' !== (string) get_option( 'fflcs_email_dealer_enabled', '1' ) ) {
			return false;
		}

		$transfer = Transfer_Service::get( $transfer_id );
		if ( ! $transfer ) {
			return false;
		}

		$dealer = Dealer_Service::get( (int) $transfer->dealer_id );
		if ( ! $dealer ) {
			return false;
		}

		$email = self::dealer_email( $dealer );
		if ( ! $email ) {
			// A dealer with no address on file is normal — most ATF rows carry
			// no email. Record it so staff can see why no invitation went out
			// rather than assuming the mail silently failed.
			Transfer_Service::log_event(
				$transfer_id,
				'dealer_email_missing',
				array(
					'dealer_id' => (int) $dealer->id,
					'notes'     => __( 'No dealer email address on file — confirmation link was not sent.', 'ffl-checkout-solutions' ),
				)
			);
			return false;
		}

		$tags               = self::transfer_tags( $transfer, $dealer );
		$tags['portal_url'] = $portal_url;

		$body = '<p>' . esc_html__( 'Hello {dealer_name},', 'ffl-checkout-solutions' ) . '</p>'
			. '<p>' . esc_html__( 'A firearm is on its way to you for transfer to a customer of {store_name}. Please confirm receipt using the button below once it arrives.', 'ffl-checkout-solutions' ) . '</p>'
			. self::transfer_summary_table( $transfer, $dealer )
			. '<p style="font-size:13px;color:#64748B;">' . esc_html__( 'This link is unique to this shipment and can only be used once.', 'ffl-checkout-solutions' ) . '</p>';

		$sent = self::send(
			$email,
			/* translators: {transfer_ref} is replaced with the transfer reference. */
			__( 'Action needed: confirm receipt of transfer {transfer_ref}', 'ffl-checkout-solutions' ),
			$body,
			$tags,
			array(
				'cta_url'   => $portal_url,
				'cta_label' => __( 'Confirm Receipt', 'ffl-checkout-solutions' ),
				'preheader' => __( 'Confirm you received this firearm shipment.', 'ffl-checkout-solutions' ),
			)
		);

		if ( $sent ) {
			Analytics::record( 'email_sent', $transfer_id, (int) $dealer->id, 'dealer_invitation' );
		}

		return $sent;
	}

	/**
	 * Remind a dealer who has not confirmed yet.
	 *
	 * @param int $transfer_id Transfer ID.
	 * @param int $token_id    Token row ID.
	 */
	public static function send_dealer_reminder( int $transfer_id, int $token_id ): void {
		$transfer = Transfer_Service::get( $transfer_id );
		if ( ! $transfer ) {
			return;
		}

		$dealer = Dealer_Service::get( (int) $transfer->dealer_id );
		if ( ! $dealer ) {
			return;
		}

		$email = self::dealer_email( $dealer );
		if ( ! $email ) {
			return;
		}

		// A reminder cannot re-send the original link: the raw token was never
		// stored, by design. Point the dealer at the store instead of minting a
		// second live token for the same shipment.
		$tags = self::transfer_tags( $transfer, $dealer );

		$body = '<p>' . esc_html__( 'Hello {dealer_name},', 'ffl-checkout-solutions' ) . '</p>'
			. '<p>' . esc_html__( 'We have not yet received your confirmation for transfer {transfer_ref}. If the firearm has arrived, please use the confirmation link in our earlier email. If you cannot find it, reply to this message and we will issue a new one.', 'ffl-checkout-solutions' ) . '</p>'
			. self::transfer_summary_table( $transfer, $dealer );

		self::send(
			$email,
			/* translators: {transfer_ref} is replaced with the transfer reference. */
			__( 'Reminder: transfer {transfer_ref} awaiting your confirmation', 'ffl-checkout-solutions' ),
			$body,
			$tags
		);

		Analytics::record( 'email_sent', $transfer_id, (int) $dealer->id, 'dealer_reminder' );
	}

	/**
	 * Send an operational alert to the store's notification address.
	 *
	 * @param string $subject Subject.
	 * @param string $body    HTML body.
	 * @param array  $tags    Merge tags.
	 */
	public static function send_admin_alert( string $subject, string $body, array $tags = array() ): bool {
		if ( '1' !== (string) get_option( 'fflcs_email_admin_enabled', '1' ) ) {
			return false;
		}

		return self::send( self::admin_email(), $subject, $body, $tags );
	}

	// ── Templating ───────────────────────────────────────────────────────────

	/**
	 * Per-status customer copy.
	 *
	 * Statuses with no entry produce no customer email — a NICS denial is
	 * deliberately absent, because that conversation belongs to the dealer in
	 * person, not to an automated message from a web store.
	 *
	 * @param string $status Status slug.
	 * @return array{subject:string,body:string}|null
	 */
	private static function status_copy( string $status ): ?array {
		$copy = array(
			'payment_confirmed'  => array(
				'subject' => __( 'Your FFL transfer {transfer_ref} is confirmed', 'ffl-checkout-solutions' ),
				'body'    => esc_html__( 'Thanks — your order is confirmed and we have started the transfer process with your selected dealer.', 'ffl-checkout-solutions' ),
			),
			'shipped_to_dealer'  => array(
				'subject' => __( 'Your firearm is on its way to {dealer_name}', 'ffl-checkout-solutions' ),
				'body'    => esc_html__( 'Your firearm has shipped to your selected FFL dealer. We will let you know as soon as they confirm it has arrived.', 'ffl-checkout-solutions' ),
			),
			'received_by_dealer' => array(
				'subject' => __( 'Your firearm has arrived at {dealer_name}', 'ffl-checkout-solutions' ),
				'body'    => esc_html__( 'Your dealer has confirmed the firearm arrived. Contact them to arrange your pickup appointment and bring valid government-issued photo identification.', 'ffl-checkout-solutions' ),
			),
			'transferred'        => array(
				'subject' => __( 'Transfer {transfer_ref} is complete', 'ffl-checkout-solutions' ),
				'body'    => esc_html__( 'Your transfer is complete. Thank you for your business.', 'ffl-checkout-solutions' ),
			),
			'cancelled'          => array(
				'subject' => __( 'Transfer {transfer_ref} has been cancelled', 'ffl-checkout-solutions' ),
				'body'    => esc_html__( 'This transfer has been cancelled. If you did not expect this, please contact us.', 'ffl-checkout-solutions' ),
			),
		);

		/**
		 * Filter the per-status customer email copy.
		 *
		 * @param array  $copy   Status copy map.
		 * @param string $status Current status.
		 */
		$copy = (array) apply_filters( 'fflcs_status_email_copy', $copy, $status );

		return $copy[ $status ] ?? null;
	}

	/**
	 * Merge tags available in every message (Section 15.3).
	 *
	 * @return array<string,string>
	 */
	private static function global_tags(): array {
		return array(
			'store_name'        => (string) get_option( 'fflcs_store_name', get_option( 'blogname' ) ),
			'store_email'       => self::admin_email(),
			'store_phone'       => (string) get_option( 'fflcs_store_phone', '' ),
			'store_ffl_license' => (string) get_option( 'fflcs_store_ffl_license', '' ),
			'store_url'         => home_url( '/' ),
			'current_year'      => gmdate( 'Y' ),
		);
	}

	/**
	 * Merge tags derived from a transfer.
	 *
	 * @param object      $transfer Transfer row.
	 * @param object|null $dealer   Dealer row.
	 * @return array<string,string>
	 */
	private static function transfer_tags( object $transfer, ?object $dealer ): array {
		$tracking_url = Carrier_Links::tracking_url( (string) $transfer->carrier, (string) $transfer->tracking_number );

		return array(
			'customer_name'      => (string) $transfer->customer_name,
			'customer_email'     => (string) $transfer->customer_email,
			'transfer_ref'       => (string) $transfer->transfer_ref,
			'status'             => fflcs_status_label( (string) $transfer->status ),
			'product_name'       => (string) $transfer->product_name,
			'dealer_name'        => $dealer ? (string) $dealer->business_name : '',
			'dealer_phone'       => $dealer ? (string) $dealer->phone : '',
			'dealer_address'     => $dealer ? self::dealer_address( $dealer ) : '',
			'tracking_number'    => (string) $transfer->tracking_number,
			'tracking_url'       => $tracking_url,
			'pickup_date'        => (string) ( $transfer->received_date ?? '' ),
			'nics_status'        => (string) ( $transfer->nics_status ?? '' ),
			'nics_delay_expires' => (string) ( $transfer->nics_delay_expires ?? '' ),
			'track_url'          => Tracking_Links::customer_url( (string) $transfer->transfer_ref ),
		);
	}

	/**
	 * Replace {tag} placeholders.
	 *
	 * Values are escaped here rather than at the call site so a merge tag can
	 * never inject markup into an email, whatever put it in the database.
	 *
	 * @param string                $text Template text.
	 * @param array<string,string>  $tags Values.
	 */
	private static function expand( string $text, array $tags ): string {
		foreach ( $tags as $key => $value ) {
			$text = str_replace( '{' . $key . '}', esc_html( (string) $value ), $text );
		}

		// Any tag with no value resolves to empty rather than being left as a
		// literal "{dealer_phone}" in a customer's inbox.
		return preg_replace( '/\{[a-z_]+\}/', '', $text ) ?? $text;
	}

	/**
	 * Wrap a body fragment in the responsive HTML shell.
	 *
	 * @param string              $body    Body fragment.
	 * @param string              $title   Message title.
	 * @param array<string,mixed> $options cta_url, cta_label, preheader.
	 */
	private static function wrap( string $body, string $title, array $options = array() ): string {
		$theme     = Theming::settings();
		$primary   = Theming::sanitize_color( $theme['color_primary'] ) ?: '#2563EB';
		$logo      = Theming::logo_url();
		$store     = (string) get_option( 'fflcs_store_name', get_option( 'blogname' ) );
		$preheader = (string) ( $options['preheader'] ?? '' );

		$cta = '';
		if ( ! empty( $options['cta_url'] ) ) {
			$cta = sprintf(
				'<tr><td style="padding:8px 32px 28px;">
					<a href="%1$s" style="display:inline-block;background:%2$s;color:#ffffff;font-weight:600;text-decoration:none;padding:14px 28px;border-radius:8px;font-size:15px;">%3$s</a>
				</td></tr>',
				esc_url( (string) $options['cta_url'] ),
				esc_attr( $primary ),
				esc_html( (string) ( $options['cta_label'] ?? __( 'View details', 'ffl-checkout-solutions' ) ) )
			);
		}

		return '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head>'
			. '<meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . esc_html( wp_strip_all_tags( $title ) ) . '</title>'
			. '</head>'
			. '<body style="margin:0;padding:0;background:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">'
			// Hidden preheader: the grey preview line email clients show next to
			// the subject. Without it they scrape the logo alt text instead.
			. '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . esc_html( $preheader ) . '</div>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1F5F9;padding:24px 12px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,.08);">'
			. '<tr><td style="padding:24px 32px;border-bottom:3px solid ' . esc_attr( $primary ) . ';">'
			. '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $store ) . '" height="36" style="height:36px;width:auto;border:0;">'
			. '</td></tr>'
			. '<tr><td style="padding:28px 32px 8px;font-size:15px;line-height:1.6;">' . $body . '</td></tr>'
			. $cta
			. '<tr><td style="padding:20px 32px;background:#F8FAFC;border-top:1px solid #E2E8F0;font-size:12px;line-height:1.6;color:#64748B;">'
			. '<p style="margin:0 0 6px;"><strong>' . esc_html( $store ) . '</strong></p>'
			. '<p style="margin:0;">' . esc_html__( 'This message relates to a firearm transfer through a licensed FFL dealer. Compliance decisions are made by the dealer, not by this notification.', 'ffl-checkout-solutions' ) . '</p>'
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Transfer summary table used inside message bodies.
	 *
	 * @param object      $transfer Transfer row.
	 * @param object|null $dealer   Dealer row.
	 */
	private static function transfer_summary_table( object $transfer, ?object $dealer ): string {
		$rows = array(
			__( 'Reference', 'ffl-checkout-solutions' ) => (string) $transfer->transfer_ref,
			__( 'Item', 'ffl-checkout-solutions' )      => (string) $transfer->product_name,
			__( 'Status', 'ffl-checkout-solutions' )    => fflcs_status_label( (string) $transfer->status ),
		);

		if ( $dealer ) {
			$rows[ __( 'Dealer', 'ffl-checkout-solutions' ) ]  = (string) $dealer->business_name;
			$rows[ __( 'Address', 'ffl-checkout-solutions' ) ] = self::dealer_address( $dealer );
		}

		if ( $transfer->tracking_number ) {
			$rows[ __( 'Tracking', 'ffl-checkout-solutions' ) ] = Carrier_Links::label( (string) $transfer->carrier ) . ' ' . $transfer->tracking_number;
		}

		$html = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:16px 0;border:1px solid #E2E8F0;border-radius:8px;border-collapse:separate;">';

		foreach ( $rows as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			$html .= '<tr>'
				. '<td style="padding:10px 14px;font-size:13px;color:#64748B;border-bottom:1px solid #F1F5F9;width:35%;">' . esc_html( $label ) . '</td>'
				. '<td style="padding:10px 14px;font-size:14px;font-weight:600;border-bottom:1px solid #F1F5F9;">' . esc_html( $value ) . '</td>'
				. '</tr>';
		}

		return $html . '</table>';
	}

	// ── Addresses ────────────────────────────────────────────────────────────

	/**
	 * Deliverable email for a dealer.
	 *
	 * @param object $dealer Dealer row.
	 */
	public static function dealer_email( object $dealer ): string {
		$email = sanitize_email( (string) ( $dealer->email ?? '' ) );

		/**
		 * Filter the dealer notification address.
		 *
		 * Sites that maintain contacts outside this plugin resolve them here.
		 *
		 * @param string $email     Resolved address.
		 * @param int    $dealer_id Dealer ID.
		 */
		$email = (string) apply_filters( 'fflcs_dealer_portal_email', $email, (int) $dealer->id );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * One-line dealer premises address.
	 *
	 * @param object $dealer Dealer row.
	 */
	public static function dealer_address( object $dealer ): string {
		$parts = array_filter(
			array(
				(string) $dealer->premise_street,
				(string) $dealer->premise_city,
				trim( $dealer->premise_state . ' ' . $dealer->premise_zip ),
			)
		);

		return implode( ', ', $parts );
	}

	/**
	 * Store notification address.
	 */
	public static function admin_email(): string {
		$email = sanitize_email( (string) get_option( 'fflcs_store_email', '' ) );

		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * From name.
	 */
	private static function from_name(): string {
		$name = (string) get_option( 'fflcs_email_from_name', '' );

		return $name ?: (string) get_option( 'fflcs_store_name', get_option( 'blogname' ) );
	}

	/**
	 * From address.
	 */
	private static function from_email(): string {
		$email = sanitize_email( (string) get_option( 'fflcs_email_from_address', '' ) );

		return is_email( $email ) ? $email : self::admin_email();
	}

	// ── Mail log (Section 15.4) ──────────────────────────────────────────────

	/**
	 * Record a send attempt, capped at LOG_LIMIT entries.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param bool   $success Whether wp_mail accepted it.
	 * @param string $reason  Failure reason.
	 */
	private static function log( string $to, string $subject, bool $success, string $reason = '' ): void {
		$log = (array) get_option( self::LOG_OPTION, array() );

		array_unshift(
			$log,
			array(
				'time'    => fflcs_mysql_now(),
				// Partially masked: the log is visible to any shop manager, and
				// a full customer address list is not something a diagnostics
				// screen needs to hand out.
				'to'      => self::mask_email( $to ),
				'subject' => wp_strip_all_tags( $subject ),
				'success' => $success,
				'reason'  => $reason,
			)
		);

		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_LIMIT ), false );
	}

	/**
	 * The mail log, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_log(): array {
		return (array) get_option( self::LOG_OPTION, array() );
	}

	/**
	 * Empty the mail log.
	 */
	public static function clear_log(): void {
		delete_option( self::LOG_OPTION );
	}

	/**
	 * Partially mask an address for log display.
	 *
	 * @param string $email Address.
	 */
	private static function mask_email( string $email ): string {
		if ( ! str_contains( $email, '@' ) ) {
			return $email;
		}

		list( $local, $domain ) = explode( '@', $email, 2 );

		$visible = substr( $local, 0, 2 );

		return $visible . str_repeat( '*', max( 1, strlen( $local ) - 2 ) ) . '@' . $domain;
	}
}
