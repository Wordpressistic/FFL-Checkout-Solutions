<?php
/**
 * Regulatory watch (Section 10.9).
 *
 * Nightly sweep of the Federal Register API for ATF documents matching the
 * store's terms. Alert-only: nothing here changes a setting, a state rule or a
 * policy. A regulation changing is a signal for a human to read it.
 *
 * @package FFLCS
 */

namespace FFLCS\Modules;

use FFLCS\Services\Mailer;

defined( 'ABSPATH' ) || exit;

/**
 * Federal Register monitoring.
 */
class Regulatory_Watch {

	/**
	 * Public Federal Register search endpoint.
	 */
	const API_URL = 'https://www.federalregister.gov/api/v1/documents.json';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'fflcs_regulatory_watch', array( $this, 'sweep' ) );
	}

	/**
	 * Terms to watch for.
	 *
	 * @return string[]
	 */
	public static function terms(): array {
		$terms = array(
			'federal firearms license',
			'firearms licensee',
			'Bureau of Alcohol, Tobacco, Firearms',
			'National Instant Criminal Background Check',
			'Form 4473',
		);

		/**
		 * Filter the regulatory watch terms.
		 *
		 * @param string[] $terms Search terms.
		 */
		return (array) apply_filters( 'fflcs_regulatory_watch_terms', $terms );
	}

	/**
	 * Run the sweep.
	 */
	public function sweep(): void {
		global $wpdb;

		$table   = fflcs_table( 'regulatory_watch' );
		$matches = array();

		foreach ( self::terms() as $term ) {
			$url = add_query_arg(
				array(
					'conditions[term]'            => rawurlencode( $term ),
					'conditions[publication_date][gte]' => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
					'per_page'                    => 20,
					'order'                       => 'newest',
				),
				self::API_URL
			);

			$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			foreach ( (array) ( $data['results'] ?? array() ) as $document ) {
				$number = (string) ( $document['document_number'] ?? '' );

				if ( '' === $number ) {
					continue;
				}

				// The unique index makes re-running the sweep harmless; only a
				// genuinely new document produces a row and an alert.
				$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'document_number'  => $number,
						'title'            => substr( (string) ( $document['title'] ?? '' ), 0, 500 ),
						'agency'           => substr( (string) ( $document['agencies'][0]['name'] ?? '' ), 0, 200 ),
						'document_type'    => substr( (string) ( $document['type'] ?? '' ), 0, 60 ),
						'matched_term'     => substr( $term, 0, 100 ),
						'publication_date' => (string) ( $document['publication_date'] ?? '' ),
						'html_url'         => substr( (string) ( $document['html_url'] ?? '' ), 0, 500 ),
						'created_at'       => fflcs_mysql_now(),
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);

				if ( $inserted ) {
					$matches[] = $document;
				}
			}
		}

		if ( ! $matches ) {
			return;
		}

		$list = '';

		foreach ( $matches as $document ) {
			$list .= '<li><a href="' . esc_url( (string) ( $document['html_url'] ?? '' ) ) . '">'
				. esc_html( (string) ( $document['title'] ?? '' ) ) . '</a> — '
				. esc_html( (string) ( $document['publication_date'] ?? '' ) ) . '</li>';
		}

		Mailer::send_admin_alert(
			__( 'New Federal Register documents matching your firearms terms', 'ffl-checkout-solutions' ),
			'<p>' . esc_html__( 'These were published in the last week and match the terms you are watching.', 'ffl-checkout-solutions' ) . '</p>'
			. '<ul>' . $list . '</ul>'
			. '<p>' . esc_html__( 'This is an alert only. No setting, state rule or policy in this plugin has been changed.', 'ffl-checkout-solutions' ) . '</p>'
		);

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET notified_at = %s WHERE notified_at IS NULL",
				fflcs_mysql_now()
			)
		);
	}
}
