<?php
/**
 * GDPR export and erasure (Section 9.10).
 *
 * The interesting part is what erasure does *not* remove. ATF requires a
 * licensee to retain acquisition and disposition records for 20 years. A
 * privacy request cannot override a federal record-keeping obligation, so
 * erasure anonymises the customer's identifying fields on the transfer and
 * leaves the compliance record itself standing — and says so in the response,
 * rather than quietly retaining data the requester believes was deleted.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Services\Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Personal data exporter and eraser.
 */
class Gdpr_Tools {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['fflcs-transfers'] = array(
			'exporter_friendly_name' => __( 'FFL Transfers', 'ffl-checkout-solutions' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['fflcs-transfers'] = array(
			'eraser_friendly_name' => __( 'FFL Transfers', 'ffl-checkout-solutions' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export a person's transfer records.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array<string,mixed>
	 */
	public function export( string $email, int $page = 1 ): array {
		global $wpdb;

		$table = fflcs_table( 'transfers' );

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE customer_email = %s ORDER BY created_at ASC",
				$email
			)
		);

		$items = array();

		foreach ( $rows as $transfer ) {
			$items[] = array(
				'group_id'    => 'fflcs-transfers',
				'group_label' => __( 'FFL Transfers', 'ffl-checkout-solutions' ),
				'item_id'     => 'fflcs-transfer-' . (int) $transfer->id,
				'data'        => array(
					array(
						'name'  => __( 'Reference', 'ffl-checkout-solutions' ),
						'value' => (string) $transfer->transfer_ref,
					),
					array(
						'name'  => __( 'Status', 'ffl-checkout-solutions' ),
						'value' => fflcs_status_label( (string) $transfer->status ),
					),
					array(
						'name'  => __( 'Item', 'ffl-checkout-solutions' ),
						'value' => (string) $transfer->product_name,
					),
					array(
						'name'  => __( 'Name', 'ffl-checkout-solutions' ),
						'value' => (string) $transfer->customer_name,
					),
					array(
						'name'  => __( 'Email', 'ffl-checkout-solutions' ),
						'value' => (string) $transfer->customer_email,
					),
					array(
						'name'  => __( 'Phone', 'ffl-checkout-solutions' ),
						'value' => (string) $transfer->customer_phone,
					),
					array(
						'name'  => __( 'Created', 'ffl-checkout-solutions' ),
						'value' => (string) $transfer->created_at,
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Anonymise a person's transfers, preserving the compliance record.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array<string,mixed>
	 */
	public function erase( string $email, int $page = 1 ): array {
		global $wpdb;

		$transfers = fflcs_table( 'transfers' );

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, transfer_ref FROM {$transfers} WHERE customer_email = %s",
				$email
			)
		);

		$messages = array();
		$removed  = false;

		foreach ( $rows as $transfer ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$transfers,
				array(
					'customer_name'  => __( '[removed]', 'ffl-checkout-solutions' ),
					'customer_email' => '',
					'customer_phone' => '',
					'customer_ip'    => null,
					'customer_id'    => null,
					'updated_at'     => fflcs_mysql_now(),
				),
				array( 'id' => (int) $transfer->id ),
				array( '%s', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);

			Transfer_Service::log_event(
				(int) $transfer->id,
				'gdpr_anonymised',
				array(
					'actor' => 'privacy_request',
					'notes' => __( 'Customer identity fields were anonymised in response to a privacy request. The transfer and bound-book records were retained under ATF record-keeping requirements.', 'ffl-checkout-solutions' ),
				)
			);

			$removed = true;
		}

		if ( $removed ) {
			$messages[] = __( 'Customer name, email, phone and IP were removed from the FFL transfer records.', 'ffl-checkout-solutions' );

			// Stated plainly rather than left implicit — the requester is
			// entitled to know what was kept and why.
			$messages[] = __( 'The transfer records themselves, and any bound book (acquisition and disposition) entries, were retained. Federal law requires a licensee to keep these for 20 years, and that obligation is not overridden by an erasure request.', 'ffl-checkout-solutions' );
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $removed,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
