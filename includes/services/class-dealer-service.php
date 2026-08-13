<?php
/**
 * Dealer directory queries.
 *
 * Owns the ZIP-radius search that the checkout widget calls on every keystroke
 * batch, so it is the one place in this plugin where query shape actually
 * matters for page speed.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes against the dealers table.
 */
class Dealer_Service {

	/**
	 * Columns safe to expose on a public (unauthenticated) endpoint.
	 *
	 * `notes` is conspicuously absent — it holds internal staff commentary
	 * about a dealer and must never reach the checkout widget.
	 *
	 * @var string[]
	 */
	const PUBLIC_COLUMNS = array(
		'id',
		'license_number',
		'license_type',
		'business_name',
		'premise_street',
		'premise_city',
		'premise_state',
		'premise_zip',
		'phone',
		'transfer_fee',
		'is_preferred',
		'lat',
		'lng',
	);

	/**
	 * Fetch a dealer by ID.
	 *
	 * @param int $id Dealer ID.
	 */
	public static function get( int $id ): ?object {
		global $wpdb;

		$table = fflcs_table( 'dealers' );

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
	 * Fetch a dealer by FFL licence number.
	 *
	 * @param string $license_number Licence number.
	 */
	public static function get_by_license( string $license_number ): ?object {
		global $wpdb;

		$table = fflcs_table( 'dealers' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE license_number = %s LIMIT 1",
				$license_number
			)
		);

		return $row ?: null;
	}

	/**
	 * Whether a dealer ID exists and is active.
	 *
	 * Section 13.7: order meta is written from a POST field, so the submitted
	 * dealer ID is verified against the database before it is persisted. Without
	 * this a shopper could point an order at any integer.
	 *
	 * @param int $id Dealer ID.
	 */
	public static function is_selectable( int $id ): bool {
		global $wpdb;

		$table = fflcs_table( 'dealers' );

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE id = %d AND is_active = 1 LIMIT 1",
				$id
			)
		);
	}

	// ── Search ───────────────────────────────────────────────────────────────

	/**
	 * Dealer search: ZIP radius, name, licence, phone, state, city.
	 *
	 * The ZIP path combines a Haversine radius test with an exact ZIP prefix
	 * match. Both are needed: roughly a fifth of ATF rows never resolve to
	 * coordinates (PO boxes, rural routes, malformed ZIPs), and a pure radius
	 * query silently hides them — including, often, the dealer the shopper was
	 * specifically looking for. The prefix match surfaces those with a null
	 * distance rather than dropping them.
	 *
	 * @param array<string,mixed> $args Search arguments.
	 * @return array{origin:?array,dealers:array<int,object>,count:int,radius:int}
	 */
	public static function search( array $args ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'zip'            => '',
				'name'           => '',
				'license'        => '',
				'phone'          => '',
				'state'          => '',
				'city'           => '',
				'type'           => '',
				'radius'         => (int) get_option( 'fflcs_checkout_default_radius', 50 ),
				'limit'          => 25,
				'include_no_geo' => true,
			)
		);

		$zip     = preg_replace( '/\D/', '', (string) $args['zip'] );
		$name    = trim( (string) $args['name'] );
		$license = trim( (string) $args['license'] );
		$phone   = preg_replace( '/\D/', '', (string) $args['phone'] );
		$state   = strtoupper( trim( (string) $args['state'] ) );
		$city    = trim( (string) $args['city'] );
		$type    = trim( (string) $args['type'] );
		$radius  = max( 1, min( 200, (int) $args['radius'] ) );
		$limit   = max( 1, min( 100, (int) $args['limit'] ) );

		// Require at least one filter. An unfiltered query would return an
		// arbitrary slice of 80,000 rows and makes the endpoint a scraping tool.
		if ( '' === $zip && '' === $name && '' === $license && '' === $phone && '' === $state && '' === $city ) {
			return array(
				'origin'  => null,
				'dealers' => array(),
				'count'   => 0,
				'radius'  => $radius,
			);
		}

		$origin   = null;
		$has_geo  = false;

		if ( preg_match( '/^\d{5}$/', (string) $zip ) ) {
			$coords = Zip_Engine::coordinates( $zip );
			if ( $coords ) {
				$origin  = array(
					'zip'   => $zip,
					'lat'   => $coords['lat'],
					'lng'   => $coords['lng'],
					'city'  => $coords['city'],
					'state' => $coords['state'],
				);
				$has_geo = true;
			}
		}

		$table  = fflcs_table( 'dealers' );
		$where  = array( 'd.is_active = 1' );
		$params = array();

		if ( '' !== $name ) {
			$where[]  = 'd.business_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $name ) . '%';
		}

		if ( '' !== $license ) {
			$where[]  = 'd.license_number LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $license ) . '%';
		}

		if ( '' !== $phone && strlen( (string) $phone ) >= 4 ) {
			// Strip formatting on both sides so "(480) 555-0100" matches "4805550100".
			$where[]  = "REPLACE(REPLACE(REPLACE(REPLACE(d.phone,'-',''),' ',''),'(',''),')','') LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $phone ) . '%';
		}

		if ( '' !== $state ) {
			$where[]  = 'd.premise_state = %s';
			$params[] = $state;
		}

		if ( '' !== $city ) {
			$where[]  = 'd.premise_city LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $city ) . '%';
		}

		if ( '' !== $type ) {
			$where[]  = 'd.license_type = %s';
			$params[] = $type;
		}

		if ( preg_match( '/^\d{5}$/', (string) $zip ) ) {
			if ( $has_geo ) {
				$where[]  = '( LEFT(d.premise_zip, 5) = %s OR ( d.lat IS NOT NULL AND d.lat <> 0 AND (
					3959 * acos(
						LEAST( 1.0, GREATEST( -1.0,
							cos( radians(%f) ) * cos( radians(d.lat) ) * cos( radians(d.lng) - radians(%f) )
							+ sin( radians(%f) ) * sin( radians(d.lat) )
						) )
					)
				) <= %d ) )';
				$params[] = $zip;
				$params[] = $origin['lat'];
				$params[] = $origin['lng'];
				$params[] = $origin['lat'];
				$params[] = $radius;
			} else {
				$where[]  = 'LEFT(d.premise_zip, 5) = %s';
				$params[] = $zip;
			}
		}

		// LEAST/GREATEST clamps the acos() argument to [-1, 1]. Floating-point
		// drift can push it a hair past 1 for a dealer at the exact origin
		// coordinates, which makes acos() return NULL and silently drops that
		// dealer from the results.
		$distance_select = $has_geo
			? '( 3959 * acos(
					LEAST( 1.0, GREATEST( -1.0,
						cos( radians(%f) ) * cos( radians(d.lat) ) * cos( radians(d.lng) - radians(%f) )
						+ sin( radians(%f) ) * sin( radians(d.lat) )
					) )
				) ) AS distance_miles'
			: 'NULL AS distance_miles';

		$select_params = $has_geo
			? array( $origin['lat'], $origin['lng'], $origin['lat'] )
			: array();

		// Preferred dealers first (the store's own recommendations), then
		// geocoded before non-geocoded so the list does not open with rows that
		// show no distance, then nearest first.
		$order_sql = $has_geo
			? 'ORDER BY d.is_preferred DESC, ( d.lat IS NULL OR d.lat = 0 ) ASC, distance_miles ASC, d.business_name ASC'
			: 'ORDER BY d.is_preferred DESC, d.business_name ASC';

		$columns = 'd.' . implode( ', d.', self::PUBLIC_COLUMNS );

		$sql = "SELECT {$columns}, {$distance_select}
			FROM {$table} d
			WHERE " . implode( ' AND ', $where ) . "
			{$order_sql}
			LIMIT %d";

		$all_params = array_merge( $select_params, $params, array( $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$all_params ) );

		$dealers = array_map(
			static function ( object $row ): object {
				$row->lat            = null !== $row->lat ? (float) $row->lat : null;
				$row->lng            = null !== $row->lng ? (float) $row->lng : null;
				$row->has_geo        = (bool) ( $row->lat && $row->lng );
				$row->distance_miles = null !== $row->distance_miles ? round( (float) $row->distance_miles, 1 ) : null;
				$row->is_preferred   = (int) $row->is_preferred;
				$row->transfer_fee   = (float) $row->transfer_fee;
				return $row;
			},
			$rows
		);

		if ( ! $args['include_no_geo'] ) {
			$dealers = array_values( array_filter( $dealers, static fn( $d ) => $d->has_geo ) );
		}

		return array(
			'origin'  => $origin,
			'dealers' => $dealers,
			'count'   => count( $dealers ),
			'radius'  => $radius,
		);
	}

	// ── Writes ───────────────────────────────────────────────────────────────

	/**
	 * Update store-owned fields on a dealer.
	 *
	 * Restricted to the columns a store legitimately curates. ATF-sourced
	 * identity fields (licence number, premises address) are not editable here
	 * — they are refreshed from the ATF feed and hand-editing them would make
	 * the next sync silently overwrite the edit.
	 *
	 * @param int                 $id     Dealer ID.
	 * @param array<string,mixed> $fields Field values.
	 * @return bool|\WP_Error
	 */
	public static function update( int $id, array $fields ) {
		global $wpdb;

		$allowed = array( 'transfer_fee', 'is_preferred', 'is_active', 'notes', 'email', 'phone', 'wp_user_id' );
		$data    = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $fields ) ) {
				continue;
			}

			switch ( $key ) {
				case 'transfer_fee':
					$data[ $key ] = round( max( 0, (float) $fields[ $key ] ), 2 );
					break;
				case 'is_preferred':
				case 'is_active':
					$data[ $key ] = (int) (bool) $fields[ $key ];
					break;
				case 'wp_user_id':
					$data[ $key ] = (int) $fields[ $key ] ?: null;
					break;
				case 'email':
					$data[ $key ] = sanitize_email( (string) $fields[ $key ] );
					break;
				case 'notes':
					$data[ $key ] = sanitize_textarea_field( (string) $fields[ $key ] );
					break;
				default:
					$data[ $key ] = sanitize_text_field( (string) $fields[ $key ] );
			}
		}

		if ( ! $data ) {
			return new \WP_Error(
				'fflcs_nothing_to_update',
				__( 'No editable dealer fields were supplied.', 'ffl-checkout-solutions' ),
				array( 'status' => 400 )
			);
		}

		$data['updated_at'] = fflcs_mysql_now();

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			fflcs_table( 'dealers' ),
			$data,
			array( 'id' => $id ),
			null,
			array( '%d' )
		);

		Transfer_Service::log_event(
			0,
			'dealer_updated',
			array(
				'dealer_id' => $id,
				'actor'     => wp_get_current_user()->user_login ?: 'system',
				'data'      => array_keys( $data ),
			)
		);

		return true;
	}

	/**
	 * Bulk-set the transfer fee for every dealer in a state (Section 10.12).
	 *
	 * @param string $state State code.
	 * @param float  $fee   Fee.
	 * @return int Rows updated.
	 */
	public static function bulk_set_fee_by_state( string $state, float $fee ): int {
		global $wpdb;

		$state = strtoupper( substr( trim( $state ), 0, 2 ) );
		if ( ! in_array( $state, fflcs_us_states(), true ) ) {
			return 0;
		}

		$table = fflcs_table( 'dealers' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET transfer_fee = %f, updated_at = %s WHERE premise_state = %s",
				round( max( 0, $fee ), 2 ),
				fflcs_mysql_now(),
				$state
			)
		);

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Public-safe dealer payload.
	 *
	 * @param object $dealer Dealer row.
	 * @return array<string,mixed>
	 */
	public static function to_public_array( object $dealer ): array {
		$public = array();

		foreach ( self::PUBLIC_COLUMNS as $column ) {
			if ( property_exists( $dealer, $column ) ) {
				$public[ $column ] = $dealer->{$column};
			}
		}

		$public['transfer_fee']   = (float) ( $public['transfer_fee'] ?? 0 );
		$public['is_preferred']   = (int) ( $public['is_preferred'] ?? 0 );
		$public['distance_miles'] = $dealer->distance_miles ?? null;

		return $public;
	}

	/**
	 * Dealers with an unusually high issue rate (Section 10.12).
	 *
	 * The minimum-transfer floor is what keeps this useful: without it, a
	 * dealer with one transfer that hit one problem reads as a 100% failure
	 * rate and drowns the real signal.
	 *
	 * @param float $threshold      Issue rate that triggers a flag, 0-1.
	 * @param int   $min_transfers  Minimum transfers before a dealer qualifies.
	 * @return array<int,object>
	 */
	public static function health_alerts( float $threshold = 0.25, int $min_transfers = 5 ): array {
		global $wpdb;

		$transfers = fflcs_table( 'transfers' );
		$dealers   = fflcs_table( 'dealers' );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT d.id, d.business_name, d.premise_state, d.email,
						COUNT(t.id) AS total_transfers,
						SUM( CASE WHEN t.status = %s THEN 1 ELSE 0 END ) AS issue_count,
						( SUM( CASE WHEN t.status = %s THEN 1 ELSE 0 END ) / COUNT(t.id) ) AS issue_rate
				 FROM {$dealers} d
				 INNER JOIN {$transfers} t ON t.dealer_id = d.id
				 GROUP BY d.id, d.business_name, d.premise_state, d.email
				 HAVING total_transfers >= %d AND issue_rate >= %f
				 ORDER BY issue_rate DESC
				 LIMIT 50",
				'issue_reported',
				'issue_reported',
				max( 1, $min_transfers ),
				max( 0, min( 1, $threshold ) )
			)
		);
	}
}
