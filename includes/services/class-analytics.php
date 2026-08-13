<?php
/**
 * Funnel and event analytics (Section 10.10).
 *
 * Self-contained: no third-party analytics service, no outbound call, no
 * cookie. Everything recorded here stays in the site's own database, which is
 * the only defensible posture for a plugin whose event stream describes
 * firearm transfers.
 *
 * Recording is always on and free — it costs one indexed insert. The premium
 * `analytics` module gates the *reporting* surface (charts, KPI cards, REST
 * endpoints), not the collection.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Analytics event recorder and aggregator.
 */
class Analytics {

	/**
	 * Record an event.
	 *
	 * Never throws and never blocks: an analytics failure must not break a
	 * checkout or a dealer confirmation.
	 *
	 * @param string               $name        Event name, e.g. 'token_issued'.
	 * @param int|null             $transfer_id Related transfer.
	 * @param int|null             $dealer_id   Related dealer.
	 * @param string               $value       Optional scalar value.
	 * @param array<string,mixed>  $metadata    Optional structured context.
	 */
	public static function record( string $name, ?int $transfer_id = null, ?int $dealer_id = null, string $value = '', array $metadata = array() ): void {
		global $wpdb;

		if ( '' === $name ) {
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'analytics_events' ),
			array(
				'transfer_id' => $transfer_id ?: null,
				'dealer_id'   => $dealer_id ?: null,
				'event_name'  => substr( $name, 0, 50 ),
				'event_value' => substr( $value, 0, 255 ),
				'metadata'    => $metadata ? wp_json_encode( $metadata ) : null,
				'session_id'  => self::session_id(),
				'created_at'  => fflcs_mysql_now(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Daily counts for one event over a trailing window.
	 *
	 * Returns a dense series — days with no events appear as zero — because a
	 * sparse series makes a line chart lie about its own shape.
	 *
	 * @param string $name Event name.
	 * @param int    $days Days back, inclusive of today.
	 * @return array<string,int> Y-m-d => count.
	 */
	public static function daily_series( string $name, int $days = 30 ): array {
		global $wpdb;

		$days  = max( 1, min( 365, $days ) );
		$table = fflcs_table( 'analytics_events' );
		$since = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DATE(created_at) AS day, COUNT(*) AS total
				 FROM {$table}
				 WHERE event_name = %s AND created_at >= %s
				 GROUP BY DATE(created_at)
				 ORDER BY day ASC",
				$name,
				$since
			)
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->day ] = (int) $row->total;
		}

		$series = array();
		for ( $offset = $days - 1; $offset >= 0; $offset-- ) {
			$day            = gmdate( 'Y-m-d', strtotime( "-{$offset} days" ) );
			$series[ $day ] = $counts[ $day ] ?? 0;
		}

		return $series;
	}

	/**
	 * Transfer counts grouped by status.
	 *
	 * @return array<string,int>
	 */
	public static function transfers_by_status(): array {
		global $wpdb;

		$table = fflcs_table( 'transfers' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" );

		$counts = array_fill_keys( array_keys( fflcs_transfer_statuses() ), 0 );

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->status ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * The dealer-portal funnel: issued to confirmed.
	 *
	 * @param int $days Window.
	 * @return array<string,int>
	 */
	public static function portal_funnel( int $days = 30 ): array {
		global $wpdb;

		$table = fflcs_table( 'analytics_events' );
		$since = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . max( 1, $days ) . ' days' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT event_name, COUNT(*) AS total
				 FROM {$table}
				 WHERE created_at >= %s
				 GROUP BY event_name",
				$since
			)
		);

		$totals = array();
		foreach ( (array) $rows as $row ) {
			$totals[ (string) $row->event_name ] = (int) $row->total;
		}

		return array(
			'token_issued'   => $totals['token_issued'] ?? 0,
			'email_sent'     => $totals['email_sent'] ?? 0,
			'portal_viewed'  => $totals['portal_viewed'] ?? 0,
			'confirmed'      => $totals['dealer_confirmed'] ?? 0,
			'issue_reported' => $totals['issue_reported'] ?? 0,
		);
	}

	/**
	 * Headline KPIs for the dashboard.
	 *
	 * @return array<string,int|float>
	 */
	public static function kpis(): array {
		global $wpdb;

		$transfers = fflcs_table( 'transfers' );
		$dealers   = fflcs_table( 'dealers' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total_transfers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$transfers}" );

		$open_statuses = array( 'dealer_selected', 'payment_confirmed', 'shipped_to_dealer', 'received_by_dealer', 'nics_pending', 'nics_delayed' );
		$placeholders  = implode( ',', array_fill( 0, count( $open_statuses ), '%s' ) );

		$open = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$transfers} WHERE status IN ({$placeholders})",
				...$open_statuses
			)
		);

		$completed = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$transfers} WHERE status = %s",
				'transferred'
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$active_dealers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dealers} WHERE is_active = 1" );

		$last_30 = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$transfers} WHERE created_at >= %s",
				gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
			)
		);

		// Median days from creation to completion. Median, not mean, because a
		// handful of abandoned transfers would drag a mean into uselessness.
		$durations = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DATEDIFF(transferred_date, DATE(created_at))
				 FROM {$transfers}
				 WHERE status = %s AND transferred_date IS NOT NULL",
				'transferred'
			)
		);

		$durations = array_values( array_filter( array_map( 'intval', (array) $durations ), static fn( $d ) => $d >= 0 ) );
		sort( $durations );

		$median = 0.0;
		$count  = count( $durations );
		if ( $count > 0 ) {
			$middle = intdiv( $count, 2 );
			$median = 0 === $count % 2
				? ( $durations[ $middle - 1 ] + $durations[ $middle ] ) / 2
				: (float) $durations[ $middle ];
		}

		return array(
			'total_transfers'     => $total_transfers,
			'open_transfers'      => $open,
			'completed_transfers' => $completed,
			'active_dealers'      => $active_dealers,
			'transfers_30d'       => $last_30,
			'median_days'         => round( $median, 1 ),
		);
	}

	/**
	 * Prune analytics rows past the configured retention window.
	 *
	 * Only touches this table. Transfers, events and the bound book have their
	 * own retention rules and are never swept from here.
	 */
	public static function prune(): void {
		global $wpdb;

		$days = (int) get_option( 'fflcs_analytics_retention_days', 365 );
		if ( $days < 30 ) {
			return;
		}

		$table = fflcs_table( 'analytics_events' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) )
			)
		);
	}

	/**
	 * A stable per-visitor identifier for funnel stitching.
	 *
	 * Derived by hashing the IP with the site's own token secret and the
	 * current date. It is deliberately not reversible to an IP, and it rotates
	 * daily, so it cannot be used to build a long-term profile of a shopper.
	 */
	private static function session_id(): string {
		return substr(
			hash_hmac( 'sha256', Token::client_ip() . '|' . gmdate( 'Y-m-d' ), Token::secret() ),
			0,
			32
		);
	}
}
