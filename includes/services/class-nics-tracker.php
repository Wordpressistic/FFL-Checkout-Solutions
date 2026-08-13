<?php
/**
 * NICS tracking and the federal three-business-day rule (Section 10.2).
 *
 * This class records outcomes and computes dates. It does not decide them.
 *
 * The three-day computation is the part worth getting right. Under
 * 18 U.S.C. § 922(t)(1)(B)(ii) a dealer may proceed at their discretion once
 * three business days have elapsed. "Business day" means a day the licensee's
 * premises are open — so weekends and federal holidays do not count. Skipping
 * the holiday exclusion computes an expiry date *earlier* than the statute
 * allows, which is the dangerous direction to be wrong in.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Background-check outcome recording and delay tracking.
 */
class Nics_Tracker {

	/**
	 * Outcomes a staff member or provider can record.
	 *
	 * @return array<string,string>
	 */
	public static function outcomes(): array {
		return array(
			'pending'    => __( 'Submitted — awaiting response', 'ffl-checkout-solutions' ),
			'proceed'    => __( 'Proceed', 'ffl-checkout-solutions' ),
			'delayed'    => __( 'Delayed', 'ffl-checkout-solutions' ),
			'denied'     => __( 'Denied', 'ffl-checkout-solutions' ),
			'cancelled'  => __( 'Cancelled', 'ffl-checkout-solutions' ),
		);
	}

	/**
	 * Record an outcome against a transfer.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $outcome     Outcome slug.
	 * @param array  $context     ntn, response, source, actor.
	 * @return true|\WP_Error
	 */
	public static function record( int $transfer_id, string $outcome, array $context = array() ) {
		$outcome = sanitize_key( $outcome );

		if ( ! array_key_exists( $outcome, self::outcomes() ) ) {
			return new \WP_Error(
				'fflcs_invalid_outcome',
				__( 'Unknown background-check outcome.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$transfer = Transfer_Service::get( $transfer_id );

		if ( ! $transfer ) {
			return new \WP_Error(
				'fflcs_not_found',
				__( 'Transfer not found.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		$fields = array(
			'nics_status'     => $outcome,
			'nics_source'     => sanitize_key( (string) ( $context['source'] ?? 'manual' ) ),
			'nics_check_date' => current_time( 'Y-m-d' ),
		);

		if ( ! empty( $context['ntn'] ) ) {
			$fields['nics_ntn'] = sanitize_text_field( (string) $context['ntn'] );
		}

		if ( ! empty( $context['response'] ) ) {
			$fields['nics_response'] = sanitize_textarea_field( (string) $context['response'] );
		}

		// A delay starts the clock. Computed here so every path — manual entry,
		// webhook, import — gets the same date.
		if ( 'delayed' === $outcome ) {
			$fields['nics_delay_expires'] = self::three_business_days_from( current_time( 'Y-m-d' ) );
		}

		$actor = sanitize_text_field( (string) ( $context['actor'] ?? 'staff' ) );

		Transfer_Service::update( $transfer_id, $fields, $actor );

		// The transfer's own lifecycle status follows the outcome, except for a
		// proceed: a cleared check does not mean the firearm changed hands.
		// Only a person marking it transferred does that.
		$status_map = array(
			'pending' => 'nics_pending',
			'delayed' => 'nics_delayed',
			'proceed' => 'nics_approved',
			'denied'  => 'nics_denied',
		);

		if ( isset( $status_map[ $outcome ] ) ) {
			Transfer_Service::set_status(
				$transfer_id,
				$status_map[ $outcome ],
				$actor,
				sanitize_textarea_field( (string) ( $context['response'] ?? '' ) )
			);
		}

		Transfer_Service::log_event(
			$transfer_id,
			'nics_recorded',
			array(
				'dealer_id' => (int) $transfer->dealer_id,
				'actor'     => $actor,
				'notes'     => sprintf(
					/* translators: %s: outcome label. */
					__( 'Background check outcome recorded: %s.', 'ffl-checkout-solutions' ),
					self::outcomes()[ $outcome ]
				),
				'data'      => array(
					'outcome' => $outcome,
					'source'  => $fields['nics_source'],
				),
			)
		);

		/**
		 * Fires when a background-check outcome is recorded.
		 *
		 * @param int    $transfer_id Transfer ID.
		 * @param string $outcome     Outcome slug.
		 */
		do_action( 'fflcs_nics_status_updated', $transfer_id, $outcome );

		return true;
	}

	/**
	 * Apply an inbound webhook payload.
	 *
	 * @param array<string,mixed> $payload Decoded, filtered payload.
	 * @return string|\WP_Error Transfer reference on success.
	 */
	public static function apply_webhook_result( array $payload ) {
		$reference = sanitize_text_field( (string) ( $payload['transfer_ref'] ?? $payload['reference'] ?? '' ) );

		if ( '' === $reference ) {
			return new \WP_Error(
				'fflcs_missing_reference',
				__( 'The payload did not identify a transfer.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$transfer = Transfer_Service::get_by_reference( $reference );

		if ( ! $transfer ) {
			return new \WP_Error(
				'fflcs_not_found',
				__( 'No transfer matches that reference.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		$outcome = sanitize_key( (string) ( $payload['status'] ?? $payload['outcome'] ?? '' ) );

		$result = self::record(
			(int) $transfer->id,
			$outcome,
			array(
				'ntn'      => (string) ( $payload['ntn'] ?? $payload['transaction_number'] ?? '' ),
				'response' => (string) ( $payload['message'] ?? '' ),
				'source'   => 'webhook',
				'actor'    => 'provider_webhook',
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $reference;
	}

	/**
	 * Transfers whose delay window has elapsed and not yet been flagged.
	 *
	 * @return object[]
	 */
	public static function elapsed_delays(): array {
		global $wpdb;

		$table = fflcs_table( 'transfers' );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, transfer_ref, customer_name, nics_delay_expires, dealer_id
				 FROM {$table}
				 WHERE status = %s
				   AND nics_delay_expires IS NOT NULL
				   AND nics_delay_expires <= %s
				   AND ( staff_notes IS NULL OR staff_notes NOT LIKE %s )
				 LIMIT 50",
				'nics_delayed',
				current_time( 'Y-m-d' ),
				'%[nics-window-elapsed]%'
			)
		);
	}

	/**
	 * Nightly sweep: email staff once per transfer whose window has elapsed.
	 *
	 * Notifies. It does not advance, approve or complete anything.
	 */
	public static function alert_elapsed_delays(): void {
		global $wpdb;

		$rows = self::elapsed_delays();

		if ( ! $rows ) {
			return;
		}

		$table = fflcs_table( 'transfers' );

		foreach ( $rows as $row ) {
			Mailer::send_admin_alert(
				sprintf(
					/* translators: %s: transfer reference. */
					__( 'Three-business-day window reached — transfer %s', 'ffl-checkout-solutions' ),
					(string) $row->transfer_ref
				),
				'<p>' . esc_html__( 'The federal three-business-day delay window has elapsed for this transfer.', 'ffl-checkout-solutions' ) . '</p>'
				. '<p><strong>' . esc_html__( 'Transfer:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( (string) $row->transfer_ref ) . '<br>'
				. '<strong>' . esc_html__( 'Customer:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( (string) $row->customer_name ) . '<br>'
				. '<strong>' . esc_html__( 'Window expired:', 'ffl-checkout-solutions' ) . '</strong> ' . esc_html( (string) $row->nics_delay_expires ) . '</p>'
				. '<p>' . esc_html__( 'Under 18 U.S.C. § 922(t)(1)(B)(ii) the dealer may proceed at their discretion. That decision is theirs alone — this plugin has taken no action.', 'ffl-checkout-solutions' ) . '</p>'
				. '<p><a href="' . esc_url( admin_url( 'admin.php?page=fflcs-transfers&transfer=' . (int) $row->id ) ) . '">'
				. esc_html__( 'Open the transfer', 'ffl-checkout-solutions' ) . '</a></p>'
			);

			// A marker in staff_notes, so the sweep does not re-email nightly
			// for a transfer nobody has closed out yet.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$table} SET staff_notes = CONCAT( COALESCE(staff_notes, ''), %s ) WHERE id = %d",
					"\n[nics-window-elapsed " . fflcs_mysql_now() . ']',
					(int) $row->id
				)
			);

			Transfer_Service::log_event(
				(int) $row->id,
				'nics_window_elapsed',
				array(
					'dealer_id' => (int) $row->dealer_id,
					'actor'     => 'system',
					'notes'     => __( 'The three-business-day window elapsed. Staff were notified. No status was changed automatically.', 'ffl-checkout-solutions' ),
				)
			);
		}
	}

	// ── Business-day arithmetic ──────────────────────────────────────────────

	/**
	 * Three business days after a date, skipping weekends and federal holidays.
	 *
	 * @param string $from Y-m-d start date.
	 * @return string Y-m-d.
	 */
	public static function three_business_days_from( string $from ): string {
		$timestamp = strtotime( $from ) ?: time();
		$counted   = 0;

		while ( $counted < 3 ) {
			$timestamp = strtotime( '+1 day', $timestamp );
			$weekday   = (int) gmdate( 'N', $timestamp );

			if ( $weekday < 6 && ! self::is_federal_holiday( gmdate( 'Y-m-d', $timestamp ) ) ) {
				++$counted;
			}
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Whether a date is an observed federal holiday.
	 *
	 * @param string $date Y-m-d.
	 */
	public static function is_federal_holiday( string $date ): bool {
		return in_array( $date, self::federal_holidays( (int) substr( $date, 0, 4 ) ), true );
	}

	/**
	 * The eleven federal holidays for a year, as observed.
	 *
	 * Fixed-date holidays falling at a weekend shift to the adjacent Friday or
	 * Monday per 5 U.S.C. § 6103 — that is the day the dealer is actually shut,
	 * which is what matters for counting business days.
	 *
	 * @param int $year Year.
	 * @return string[]
	 */
	public static function federal_holidays( int $year ): array {
		static $cache = array();

		if ( isset( $cache[ $year ] ) ) {
			return $cache[ $year ];
		}

		$dates = array(
			self::observed( "{$year}-01-01" ),            // New Year's Day.
			self::nth_weekday( $year, 1, 1, 3 ),          // Martin Luther King Jr. Day.
			self::nth_weekday( $year, 2, 1, 3 ),          // Washington's Birthday.
			self::last_weekday( $year, 5, 1 ),            // Memorial Day.
			self::observed( "{$year}-06-19" ),            // Juneteenth.
			self::observed( "{$year}-07-04" ),            // Independence Day.
			self::nth_weekday( $year, 9, 1, 1 ),          // Labor Day.
			self::nth_weekday( $year, 10, 1, 2 ),         // Columbus Day.
			self::observed( "{$year}-11-11" ),            // Veterans Day.
			self::nth_weekday( $year, 11, 4, 4 ),         // Thanksgiving.
			self::observed( "{$year}-12-25" ),            // Christmas Day.
		);

		/**
		 * Filter the federal holiday list used for business-day arithmetic.
		 *
		 * Lets a store add its own closures — a state holiday, an inventory
		 * day — so the computed window matches when they are really shut.
		 *
		 * @param string[] $dates Y-m-d dates.
		 * @param int      $year  Year.
		 */
		$dates = (array) apply_filters( 'fflcs_federal_holidays', $dates, $year );

		$cache[ $year ] = array_values( array_unique( $dates ) );

		return $cache[ $year ];
	}

	/**
	 * Shift a fixed-date holiday off a weekend.
	 *
	 * @param string $date Y-m-d.
	 */
	private static function observed( string $date ): string {
		$timestamp = strtotime( $date );
		$weekday   = (int) gmdate( 'N', $timestamp );

		if ( 6 === $weekday ) {
			$timestamp = strtotime( '-1 day', $timestamp );
		} elseif ( 7 === $weekday ) {
			$timestamp = strtotime( '+1 day', $timestamp );
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * The nth occurrence of a weekday in a month.
	 *
	 * @param int $year    Year.
	 * @param int $month   Month.
	 * @param int $weekday ISO weekday, 1 = Monday.
	 * @param int $nth     Occurrence.
	 */
	private static function nth_weekday( int $year, int $month, int $weekday, int $nth ): string {
		$first     = mktime( 0, 0, 0, $month, 1, $year );
		$first_dow = (int) gmdate( 'N', $first );
		$offset    = ( $weekday - $first_dow + 7 ) % 7;
		$day       = 1 + $offset + ( ( $nth - 1 ) * 7 );

		return gmdate( 'Y-m-d', mktime( 0, 0, 0, $month, $day, $year ) );
	}

	/**
	 * The last occurrence of a weekday in a month.
	 *
	 * @param int $year    Year.
	 * @param int $month   Month.
	 * @param int $weekday ISO weekday, 1 = Monday.
	 */
	private static function last_weekday( int $year, int $month, int $weekday ): string {
		$last_day = (int) gmdate( 't', mktime( 0, 0, 0, $month, 1, $year ) );
		$last     = mktime( 0, 0, 0, $month, $last_day, $year );
		$last_dow = (int) gmdate( 'N', $last );
		$back     = ( $last_dow - $weekday + 7 ) % 7;

		return gmdate( 'Y-m-d', mktime( 0, 0, 0, $month, $last_day - $back, $year ) );
	}
}
