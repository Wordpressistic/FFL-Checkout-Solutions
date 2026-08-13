<?php
/**
 * Transfer lifecycle domain service.
 *
 * The single place that creates transfers, changes their status, and writes
 * audit events. Everything else — REST controllers, the admin screens, the
 * dealer portal, the status bridge — routes through here, so there is exactly
 * one code path that can move a transfer and exactly one that logs it.
 *
 * Compliance rule, enforced here and not negotiable (Section 22.2, Section 29.11):
 * nothing in this class ever *decides* a compliance outcome. It records what a
 * human decided. There is no code path that approves or denies a NICS check,
 * files a form, or completes a transfer on its own.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

use FFLCS\License\Entitlement;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, reads and transitions transfer records.
 */
class Transfer_Service {

	/**
	 * Statuses that mean the firearm is still in flight, used by the carrier
	 * poll and the dashboard's "open work" count.
	 *
	 * @var string[]
	 */
	const OPEN_STATUSES = array(
		'dealer_selected',
		'payment_confirmed',
		'shipped_to_dealer',
		'received_by_dealer',
		'nics_pending',
		'nics_delayed',
		'nics_approved',
	);

	/**
	 * Terminal statuses — a transfer here is done moving.
	 *
	 * @var string[]
	 */
	const TERMINAL_STATUSES = array( 'transferred', 'cancelled', 'nics_denied' );

	// ── Reads ────────────────────────────────────────────────────────────────

	/**
	 * Fetch one transfer by ID.
	 *
	 * @param int $id Transfer ID.
	 */
	public static function get( int $id ): ?object {
		global $wpdb;

		$table = fflcs_table( 'transfers' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$id
			)
		);

		return $row ?: null;
	}

	/**
	 * Fetch one transfer by its public reference.
	 *
	 * @param string $reference Transfer reference.
	 */
	public static function get_by_reference( string $reference ): ?object {
		global $wpdb;

		$table = fflcs_table( 'transfers' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE transfer_ref = %s LIMIT 1",
				$reference
			)
		);

		return $row ?: null;
	}

	/**
	 * Every transfer belonging to a WooCommerce order.
	 *
	 * @param int $order_id Order ID.
	 * @return object[]
	 */
	public static function for_order( int $order_id ): array {
		global $wpdb;

		$table = fflcs_table( 'transfers' );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC",
				$order_id
			)
		);
	}

	/**
	 * Paginated, filtered transfer query for admin lists and REST.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array{items:object[],total:int,pages:int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'dealer_id' => 0,
				'search'   => '',
				'from'     => '',
				'to'       => '',
				'orderby'  => 'created_at',
				'order'    => 'DESC',
				'page'     => 1,
				'per_page' => 25,
			)
		);

		$table = fflcs_table( 'transfers' );
		$where = array( '1=1' );
		$params = array();

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}

		if ( $args['dealer_id'] ) {
			$where[]  = 'dealer_id = %d';
			$params[] = (int) $args['dealer_id'];
		}

		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '( transfer_ref LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR serial_number LIKE %s OR product_name LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $args['from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( (string) $args['from'] ) );
		}

		if ( $args['to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( (string) $args['to'] ) );
		}

		// Allow-list ORDER BY — these are interpolated, so they can never come
		// from user input directly.
		$sortable = array( 'id', 'created_at', 'updated_at', 'status', 'transfer_ref', 'customer_name' );
		$orderby  = in_array( (string) $args['orderby'], $sortable, true ) ? (string) $args['orderby'] : 'created_at';
		$order    = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			: $wpdb->get_var( $count_sql ) );

		$list_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$items = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) );

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	// ── Creation ─────────────────────────────────────────────────────────────

	/**
	 * Create a transfer row.
	 *
	 * Returns the new ID, or the ID of the row that already covers this
	 * (order, item, unit) when a concurrent call won the race. The caller must
	 * check `is_new` before firing side effects — see Checkout for why that
	 * matters.
	 *
	 * @param array<string,mixed> $data Column values.
	 * @return array{id:int,is_new:bool}
	 */
	public static function create( array $data ): array {
		global $wpdb;

		$table = fflcs_table( 'transfers' );
		$now   = fflcs_mysql_now();

		$row = wp_parse_args(
			$data,
			array(
				'order_id'        => 0,
				'order_item_id'   => null,
				'order_item_unit' => 1,
				'dealer_id'       => 0,
				'status'          => 'dealer_selected',
				'transfer_ref'    => self::generate_reference(),
				'source'          => 'woocommerce',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		// Only real columns, so a caller passing extra keys cannot break the
		// insert with an unknown-column error.
		$row = array_intersect_key( $row, array_flip( self::columns() ) );

		$inserted = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id       = (int) $wpdb->insert_id;

		if ( $inserted && $id ) {
			return array(
				'id'     => $id,
				'is_new' => true,
			);
		}

		// The insert lost a race against uidx_order_item_unit. Find the winner.
		$existing = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table}
				 WHERE order_id = %d AND order_item_id = %d AND order_item_unit = %d
				 LIMIT 1",
				(int) $row['order_id'],
				(int) ( $row['order_item_id'] ?? 0 ),
				(int) $row['order_item_unit']
			)
		);

		return array(
			'id'     => $existing,
			'is_new' => false,
		);
	}

	/**
	 * A collision-resistant public reference.
	 *
	 * Format FFL-XXXXXXXX from random bytes rather than a sequence, so the
	 * reference does not leak the store's order volume and cannot be walked by
	 * an outsider guessing neighbouring references on the tracking page.
	 */
	public static function generate_reference(): string {
		return 'FFL-' . strtoupper( bin2hex( random_bytes( 4 ) ) );
	}

	// ── Transitions ──────────────────────────────────────────────────────────

	/**
	 * Change a transfer's status and log it.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param string $new_status  Target status.
	 * @param string $actor       Who did it, for the audit row.
	 * @param string $notes       Optional note.
	 * @return bool|\WP_Error True on change, false when already in that status.
	 */
	public static function set_status( int $transfer_id, string $new_status, string $actor = 'system', string $notes = '' ) {
		global $wpdb;

		if ( ! array_key_exists( $new_status, fflcs_transfer_statuses() ) ) {
			return new \WP_Error(
				'fflcs_invalid_status',
				__( 'Unknown transfer status.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$transfer = self::get( $transfer_id );
		if ( ! $transfer ) {
			return new \WP_Error(
				'fflcs_not_found',
				__( 'Transfer not found.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		$old_status = (string) $transfer->status;
		if ( $old_status === $new_status ) {
			return false;
		}

		$update = array(
			'status'     => $new_status,
			'updated_at' => fflcs_mysql_now(),
		);

		// Stamp the lifecycle dates the reports and the bound book read from.
		$today = current_time( 'Y-m-d' );
		switch ( $new_status ) {
			case 'shipped_to_dealer':
				$update['shipped_date'] = $today;
				break;
			case 'received_by_dealer':
				$update['received_date'] = $today;
				break;
			case 'transferred':
				$update['transferred_date'] = $today;
				break;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'transfers' ),
			$update,
			array( 'id' => $transfer_id ),
			null,
			array( '%d' )
		);

		self::log_event(
			$transfer_id,
			'status_change',
			array(
				'old_status' => $old_status,
				'new_status' => $new_status,
				'notes'      => $notes,
				'actor'      => $actor,
				'dealer_id'  => (int) $transfer->dealer_id,
			)
		);

		Analytics::record( 'status_' . $new_status, $transfer_id, (int) $transfer->dealer_id );

		/**
		 * Fires after a transfer changes status.
		 *
		 * @param int    $transfer_id Transfer ID.
		 * @param string $new_status  New status.
		 * @param string $old_status  Previous status.
		 */
		do_action( 'fflcs_transfer_status_changed', $transfer_id, $new_status, $old_status );

		// Section 21.2 — legacy hook alias, fired alongside the new one for two
		// major versions so v1.x integrations keep working.
		do_action( 'wpistic_ffl_transfer_status_changed', $transfer_id, $old_status, $new_status );

		return true;
	}

	/**
	 * Update arbitrary transfer fields with an audit trail.
	 *
	 * Status changes are routed to set_status() rather than written directly,
	 * so a caller cannot bypass the event log by passing 'status' here.
	 *
	 * @param int                 $transfer_id Transfer ID.
	 * @param array<string,mixed> $fields      Column values.
	 * @param string              $actor       Audit actor.
	 * @return bool|\WP_Error
	 */
	public static function update( int $transfer_id, array $fields, string $actor = 'system' ) {
		global $wpdb;

		$transfer = self::get( $transfer_id );
		if ( ! $transfer ) {
			return new \WP_Error(
				'fflcs_not_found',
				__( 'Transfer not found.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		$status_change = null;
		if ( isset( $fields['status'] ) ) {
			$status_change = (string) $fields['status'];
			unset( $fields['status'] );
		}

		$fields = array_intersect_key( $fields, array_flip( self::columns() ) );

		if ( $fields ) {
			$fields['updated_at'] = fflcs_mysql_now();

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				fflcs_table( 'transfers' ),
				$fields,
				array( 'id' => $transfer_id ),
				null,
				array( '%d' )
			);

			self::log_event(
				$transfer_id,
				'updated',
				array(
					'actor'     => $actor,
					'notes'     => __( 'Transfer record updated.', 'ffl-checkout-solutions' ),
					'dealer_id' => (int) $transfer->dealer_id,
					'data'      => array_keys( $fields ),
				)
			);
		}

		if ( null !== $status_change ) {
			$result = self::set_status( $transfer_id, $status_change, $actor );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Reassign a transfer to a different dealer.
	 *
	 * Revokes outstanding portal tokens: the previous dealer must lose the
	 * ability to confirm receipt of a shipment that is no longer theirs.
	 *
	 * @param int    $transfer_id Transfer ID.
	 * @param int    $dealer_id   New dealer ID.
	 * @param string $actor       Audit actor.
	 * @return bool|\WP_Error
	 */
	public static function reassign_dealer( int $transfer_id, int $dealer_id, string $actor = 'admin' ) {
		$dealer = Dealer_Service::get( $dealer_id );
		if ( ! $dealer ) {
			return new \WP_Error(
				'fflcs_invalid_dealer',
				__( 'That dealer does not exist.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		Token::revoke_all_for_transfer( $transfer_id );

		$result = self::update( $transfer_id, array( 'dealer_id' => $dealer_id ), $actor );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::log_event(
			$transfer_id,
			'dealer_reassigned',
			array(
				'actor'     => $actor,
				'dealer_id' => $dealer_id,
				'notes'     => sprintf(
					/* translators: %s: dealer business name. */
					__( 'Transfer reassigned to %s. Any outstanding confirmation links were revoked.', 'ffl-checkout-solutions' ),
					$dealer->business_name
				),
			)
		);

		return true;
	}

	// ── Audit log ────────────────────────────────────────────────────────────

	/**
	 * Append an audit event.
	 *
	 * The events table is append-only by convention and by code: nothing in
	 * this plugin updates or deletes a row here, which is what makes it usable
	 * as an audit trail rather than just a log.
	 *
	 * @param int                 $transfer_id Transfer ID, or 0.
	 * @param string              $type        Event type.
	 * @param array<string,mixed> $context     Optional keys: old_status,
	 *                                         new_status, notes, actor,
	 *                                         dealer_id, data.
	 */
	public static function log_event( int $transfer_id, string $type, array $context = array() ): void {
		global $wpdb;

		$user_id = get_current_user_id();

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'events' ),
			array(
				'transfer_id' => $transfer_id ?: null,
				'dealer_id'   => (int) ( $context['dealer_id'] ?? 0 ) ?: null,
				'event_type'  => substr( $type, 0, 50 ),
				'event_data'  => isset( $context['data'] ) ? wp_json_encode( $context['data'] ) : null,
				'old_status'  => isset( $context['old_status'] ) ? substr( (string) $context['old_status'], 0, 50 ) : null,
				'new_status'  => isset( $context['new_status'] ) ? substr( (string) $context['new_status'], 0, 50 ) : null,
				'user_id'     => $user_id ?: null,
				'actor'       => substr( (string) ( $context['actor'] ?? 'system' ), 0, 150 ),
				'ip_address'  => Token::client_ip(),
				'user_agent'  => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'created_at'  => fflcs_mysql_now(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Events for one transfer, newest first.
	 *
	 * @param int $transfer_id Transfer ID.
	 * @param int $limit       Max rows.
	 * @return object[]
	 */
	public static function events( int $transfer_id, int $limit = 100 ): array {
		global $wpdb;

		$table = fflcs_table( 'events' );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE transfer_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
				$transfer_id,
				max( 1, min( 500, $limit ) )
			)
		);
	}

	// ── Cron ─────────────────────────────────────────────────────────────────

	/**
	 * Nightly housekeeping.
	 *
	 * Deliberately narrow: expire stale tokens, send dealer reminders, prune
	 * analytics. It does not advance any transfer's status — an unattended
	 * cron job is exactly the wrong place to make a compliance decision.
	 */
	public static function daily_runner(): void {
		self::expire_stale_tokens();
		self::send_dealer_reminders();
		Analytics::prune();

		/**
		 * Fires on the nightly maintenance run, after built-in housekeeping.
		 */
		do_action( 'fflcs_daily_maintenance' );
	}

	/**
	 * Mark past-expiry tokens expired so the portal reports the right reason.
	 */
	private static function expire_stale_tokens(): void {
		global $wpdb;

		$table = fflcs_table( 'dealer_tokens' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET status = %s WHERE status = %s AND token_expires < %s",
				'expired',
				'active',
				fflcs_mysql_now()
			)
		);
	}

	/**
	 * Email dealers who have not confirmed receipt yet.
	 *
	 * Reminders go out on the configured day offsets (default 3, 7, 14 days
	 * after issue). reminder_count is what stops a dealer being emailed twice
	 * for the same threshold.
	 */
	private static function send_dealer_reminders(): void {
		global $wpdb;

		if ( '1' !== (string) get_option( 'fflcs_email_dealer_enabled', '1' ) ) {
			return;
		}

		$schedule = array_values(
			array_filter(
				array_map( 'absint', explode( ',', (string) get_option( 'fflcs_reminder_schedule', '3,7,14' ) ) )
			)
		);

		if ( ! $schedule ) {
			return;
		}

		sort( $schedule );

		$tokens = fflcs_table( 'dealer_tokens' );

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$tokens}
				 WHERE status = %s AND token_expires > %s AND reminder_count < %d
				 ORDER BY created_at ASC
				 LIMIT 50",
				'active',
				fflcs_mysql_now(),
				count( $schedule )
			)
		);

		foreach ( $rows as $token ) {
			$age_days  = (int) floor( ( time() - strtotime( (string) $token->created_at ) ) / DAY_IN_SECONDS );
			$sent      = (int) $token->reminder_count;
			$threshold = $schedule[ $sent ] ?? null;

			if ( null === $threshold || $age_days < $threshold ) {
				continue;
			}

			Mailer::send_dealer_reminder( (int) $token->transfer_id, (int) $token->id );

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$tokens,
				array(
					'reminder_count'   => $sent + 1,
					'last_reminder_at' => fflcs_mysql_now(),
				),
				array( 'id' => (int) $token->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Writable columns on the transfers table.
	 *
	 * @return string[]
	 */
	public static function columns(): array {
		return array(
			'order_id',
			'order_item_id',
			'order_item_unit',
			'dealer_id',
			'customer_id',
			'customer_name',
			'customer_email',
			'customer_phone',
			'customer_ip',
			'product_id',
			'product_name',
			'product_sku',
			'product_type',
			'manufacturer',
			'model',
			'caliber',
			'serial_number',
			'status',
			'payment_status',
			'nics_status',
			'nics_response',
			'nics_source',
			'nics_delay_expires',
			'nics_ntn',
			'nics_check_date',
			'shipped_date',
			'received_date',
			'transferred_date',
			'tracking_number',
			'carrier',
			'easypost_shipment_id',
			'shipping_rates_json',
			'shipping_label_url',
			'booking_appointment_id',
			'booking_slot_start',
			'transfer_ref',
			'form_4473_draft_url',
			'excise_tax_amount',
			'is_age_restricted',
			'is_ffl_required',
			'staff_notes',
			'source',
			'created_at',
			'updated_at',
		);
	}

	/**
	 * Public-safe representation for REST and the tracking page.
	 *
	 * Deliberately narrow: no staff notes, no customer IP, no internal dealer
	 * notes, no NICS response text. The tracking page is reachable by anyone
	 * holding the link, so it must never carry more than the buyer already
	 * knows.
	 *
	 * @param object $transfer Transfer row.
	 * @return array<string,mixed>
	 */
	public static function to_public_array( object $transfer ): array {
		return array(
			'reference'    => (string) $transfer->transfer_ref,
			'status'       => (string) $transfer->status,
			'status_label' => fflcs_status_label( (string) $transfer->status ),
			'product'      => (string) $transfer->product_name,
			'created_at'   => (string) $transfer->created_at,
			'shipped_date' => $transfer->shipped_date,
			'received_date' => $transfer->received_date,
			'transferred_date' => $transfer->transferred_date,
			'tracking'     => array(
				'number'  => (string) $transfer->tracking_number,
				'carrier' => (string) $transfer->carrier,
				'url'     => Carrier_Links::tracking_url( (string) $transfer->carrier, (string) $transfer->tracking_number ),
			),
		);
	}

	/**
	 * Admin representation — everything, for an authenticated staff request.
	 *
	 * @param object $transfer Transfer row.
	 * @return array<string,mixed>
	 */
	public static function to_admin_array( object $transfer ): array {
		$data           = (array) $transfer;
		$data['status_label'] = fflcs_status_label( (string) $transfer->status );
		$data['dealer'] = Dealer_Service::get( (int) $transfer->dealer_id );

		if ( ! Entitlement::can( 'fraud_scoring', 'pro' ) ) {
			unset( $data['fraud_score'] );
		}

		return $data;
	}
}
