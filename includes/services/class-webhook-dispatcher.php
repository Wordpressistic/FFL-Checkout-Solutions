<?php
/**
 * Outbound webhook dispatcher (Section 10.11).
 *
 * Posts FFL events to endpoints the store configures, HMAC-signed, with
 * exponential retry and a delivery log.
 *
 * Two safeguards matter here:
 *
 *   Every URL passes the SSRF guard before a request is made. A webhook target
 *   is admin-supplied, and a plugin that will POST anywhere on command is a
 *   pivot into a customer's internal network.
 *
 *   Payloads carry transfer state, not customer identity documents. What leaves
 *   the site is deliberately narrow.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

use FFLCS\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Signed outbound event delivery.
 */
class Webhook_Dispatcher {

	/**
	 * Retry backoff in seconds (Section 10.11): 1m, 5m, 30m, 2h, 12h.
	 *
	 * @var int[]
	 */
	const BACKOFF = array( 60, 300, 1800, 7200, 43200 );

	/**
	 * Signature header.
	 */
	const SIGNATURE_HEADER = 'X-Fflcs-Signature';

	/**
	 * Queue an event for every subscribed endpoint.
	 *
	 * @param string              $event   Event name.
	 * @param array<string,mixed> $payload Event payload.
	 */
	public static function dispatch( string $event, array $payload ): void {
		global $wpdb;

		$table = fflcs_table( 'webhook_connections' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$connections = (array) $wpdb->get_results( "SELECT * FROM {$table} WHERE is_active = 1" );

		foreach ( $connections as $connection ) {
			if ( ! self::subscribes( $connection, $event ) ) {
				continue;
			}

			$delivery_id = self::queue( (int) $connection->id, $event, $payload );

			// Attempt immediately; failures fall back to the retry cron.
			Scheduler::async( 'fflcs_webhook_deliver', array( $delivery_id ) );
		}
	}

	/**
	 * Whether a connection subscribes to an event.
	 *
	 * @param object $connection Connection row.
	 * @param string $event      Event name.
	 */
	private static function subscribes( object $connection, string $event ): bool {
		$events = trim( (string) $connection->events );

		if ( '' === $events || '*' === $events ) {
			return true;
		}

		return in_array( $event, array_map( 'trim', explode( ',', $events ) ), true );
	}

	/**
	 * Record a pending delivery.
	 *
	 * @param int                 $connection_id Connection ID.
	 * @param string              $event         Event name.
	 * @param array<string,mixed> $payload       Payload.
	 */
	private static function queue( int $connection_id, string $event, array $payload ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'webhook_deliveries' ),
			array(
				'connection_id' => $connection_id,
				'event_name'    => substr( $event, 0, 60 ),
				'payload'       => wp_json_encode( $payload ),
				'attempt'       => 0,
				'status'        => 'pending',
				'created_at'    => fflcs_mysql_now(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Attempt one delivery.
	 *
	 * @param int $delivery_id Delivery row ID.
	 * @return bool Whether it succeeded.
	 */
	public static function deliver( int $delivery_id ): bool {
		global $wpdb;

		$deliveries  = fflcs_table( 'webhook_deliveries' );
		$connections = fflcs_table( 'webhook_connections' );

		$delivery = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$deliveries} WHERE id = %d LIMIT 1",
				$delivery_id
			)
		);

		if ( ! $delivery || 'delivered' === $delivery->status ) {
			return false;
		}

		$connection = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$connections} WHERE id = %d LIMIT 1",
				(int) $delivery->connection_id
			)
		);

		if ( ! $connection ) {
			self::mark( $delivery_id, 'failed', 0, __( 'The target endpoint no longer exists.', 'ffl-checkout-solutions' ), (int) $delivery->attempt + 1 );
			return false;
		}

		$url = (string) $connection->target_url;

		// Re-checked on every attempt, not just at save time: DNS can change
		// under a hostname that was public when it was configured.
		if ( ! fflcs_is_url_safe( $url ) ) {
			self::mark(
				$delivery_id,
				'failed',
				0,
				__( 'Refused: the endpoint resolves to a private or non-HTTP address.', 'ffl-checkout-solutions' ),
				(int) $delivery->attempt + 1
			);
			return false;
		}

		$body      = (string) $delivery->payload;
		$secret    = Crypto::decrypt( (string) $connection->secret, 'fflcs_webhook' );
		$signature = hash_hmac( 'sha256', $body, $secret );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'         => 'application/json',
					self::SIGNATURE_HEADER => $signature,
					'X-Fflcs-Event'        => (string) $delivery->event_name,
					'X-Fflcs-Delivery'     => (string) $delivery_id,
					'User-Agent'           => 'FFLCheckoutSolutions/' . FFLCS_VERSION,
				),
				'body'    => $body,
			)
		);

		$attempt = (int) $delivery->attempt + 1;

		if ( is_wp_error( $response ) ) {
			self::schedule_retry( $delivery_id, $attempt, 0, $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			self::mark( $delivery_id, 'delivered', $code, '', $attempt );

			/**
			 * Fires after a webhook is delivered successfully.
			 *
			 * @param int    $connection_id Connection ID.
			 * @param string $payload       JSON payload.
			 * @param int    $code          HTTP status.
			 */
			do_action( 'fflcs_webhook_sent', (int) $connection->id, $body, $code );

			return true;
		}

		self::schedule_retry(
			$delivery_id,
			$attempt,
			$code,
			substr( (string) wp_remote_retrieve_body( $response ), 0, 500 )
		);

		return false;
	}

	/**
	 * Record a failure and schedule the next attempt, if any remain.
	 *
	 * @param int    $delivery_id Delivery ID.
	 * @param int    $attempt     Attempt number just completed.
	 * @param int    $code        HTTP status.
	 * @param string $body        Response body or error.
	 */
	private static function schedule_retry( int $delivery_id, int $attempt, int $code, string $body ): void {
		global $wpdb;

		if ( $attempt >= count( self::BACKOFF ) ) {
			self::mark( $delivery_id, 'failed', $code, $body, $attempt );
			return;
		}

		$next = time() + self::BACKOFF[ $attempt ];

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'webhook_deliveries' ),
			array(
				'status'        => 'retrying',
				'attempt'       => $attempt,
				'response_code' => $code,
				'response_body' => $body,
				'next_retry_at' => gmdate( 'Y-m-d H:i:s', $next ),
			),
			array( 'id' => $delivery_id ),
			array( '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Finalise a delivery.
	 *
	 * @param int    $delivery_id Delivery ID.
	 * @param string $status      delivered|failed.
	 * @param int    $code        HTTP status.
	 * @param string $body        Response body.
	 * @param int    $attempt     Attempt count.
	 */
	private static function mark( int $delivery_id, string $status, int $code, string $body, int $attempt ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'webhook_deliveries' ),
			array(
				'status'        => $status,
				'attempt'       => $attempt,
				'response_code' => $code,
				'response_body' => $body,
				'next_retry_at' => null,
			),
			array( 'id' => $delivery_id ),
			array( '%s', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Retry every delivery that is due. Runs every five minutes.
	 */
	public static function process_retries(): void {
		global $wpdb;

		$table = fflcs_table( 'webhook_deliveries' );

		$due = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table}
				 WHERE status IN (%s, %s) AND ( next_retry_at IS NULL OR next_retry_at <= %s )
				 ORDER BY id ASC
				 LIMIT 25",
				'pending',
				'retrying',
				fflcs_mysql_now()
			)
		);

		foreach ( $due as $delivery_id ) {
			self::deliver( (int) $delivery_id );
		}
	}

	/**
	 * Send a test payload to one endpoint.
	 *
	 * @param int $connection_id Connection ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function send_test( int $connection_id ) {
		global $wpdb;

		$table = fflcs_table( 'webhook_connections' );

		$connection = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE id = %d LIMIT 1",
				$connection_id
			)
		);

		if ( ! $connection ) {
			return new \WP_Error(
				'fflcs_not_found',
				__( 'That webhook endpoint does not exist.', 'ffl-checkout-solutions' ),
				array( 'status' => 404 )
			);
		}

		$delivery_id = self::queue(
			$connection_id,
			'test',
			array(
				'event' => 'test',
				'site'  => home_url( '/' ),
				'sent'  => fflcs_mysql_now(),
			)
		);

		$delivered = self::deliver( $delivery_id );

		return array(
			'delivered'   => $delivered,
			'delivery_id' => $delivery_id,
		);
	}

	/**
	 * Register a new endpoint.
	 *
	 * @param string   $label  Human label.
	 * @param string   $url    Target URL.
	 * @param string[] $events Event names, or ['*'].
	 * @return int|\WP_Error New connection ID.
	 */
	public static function create_connection( string $label, string $url, array $events = array( '*' ) ) {
		global $wpdb;

		if ( ! fflcs_is_url_safe( $url ) ) {
			return new \WP_Error(
				'fflcs_unsafe_url',
				__( 'That URL was refused. Endpoints must be public HTTP or HTTPS addresses.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		// Generated, not supplied: the store copies it into the receiving
		// system, which means there is no path where a weak secret gets chosen.
		$secret = wp_generate_password( 48, false );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'webhook_connections' ),
			array(
				'label'      => sanitize_text_field( $label ),
				'target_url' => esc_url_raw( $url ),
				'secret'     => Crypto::encrypt( $secret, 'fflcs_webhook' ),
				'events'     => implode( ',', array_map( 'sanitize_key', $events ) ),
				'is_active'  => 1,
				'created_by' => get_current_user_id(),
				'created_at' => fflcs_mysql_now(),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}
}
