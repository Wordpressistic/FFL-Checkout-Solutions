<?php
/**
 * ZIP centroid engine (Section 9.3).
 *
 * Dealer search needs a lat/lng for the shopper's ZIP to run a Haversine
 * distance query. Rather than shipping or importing a 43,000-row ZIP dataset at
 * activation — slow, stale, and a large chunk of the plugin's disk footprint —
 * centroids are fetched on demand from zippopotam.us the first time a ZIP is
 * searched and cached locally forever after.
 *
 * Practical effect: one ~100ms external call per distinct ZIP, ever. Every
 * subsequent search for that ZIP is a local indexed lookup with no network I/O.
 *
 * The only data sent is the five-digit ZIP itself — no customer PII. This is
 * documented in docs/PRIVACY.md.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * On-demand ZIP centroid lookup with local caching.
 */
class Zip_Engine {

	/**
	 * Free, key-less US ZIP centroid endpoint.
	 */
	const API_URL = 'https://api.zippopotam.us/us/';

	/**
	 * Timeout in seconds. Deliberately short: this can run during a checkout
	 * search, and a slow third party must degrade to "no distance sorting"
	 * rather than hanging the shopper's request.
	 */
	const TIMEOUT = 3;

	/**
	 * Coordinates for a ZIP, or null when unknown.
	 *
	 * @param string $zip Raw ZIP input.
	 * @return array{lat:float,lng:float,city:string,state:string}|null
	 */
	public static function coordinates( string $zip ): ?array {
		global $wpdb;

		$zip = self::normalise( $zip );
		if ( null === $zip ) {
			return null;
		}

		$table = fflcs_table( 'zip_cache' );

		$cached = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT lat, lng, city, state FROM {$table} WHERE zip = %s LIMIT 1",
				$zip
			)
		);

		if ( $cached ) {
			return array(
				'lat'   => (float) $cached->lat,
				'lng'   => (float) $cached->lng,
				'city'  => (string) $cached->city,
				'state' => (string) $cached->state,
			);
		}

		// A ZIP that just failed to resolve stays failed for an hour. Without
		// this, an invalid ZIP typed into checkout would hit the API on every
		// keystroke-triggered search.
		$failure_key = 'fflcs_zip_fail_' . $zip;
		if ( get_transient( $failure_key ) ) {
			return null;
		}

		$response = wp_remote_get(
			self::API_URL . $zip,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $failure_key, 1, HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['places'][0] ) ) {
			set_transient( $failure_key, 1, HOUR_IN_SECONDS );
			return null;
		}

		$place = $body['places'][0];
		$data  = array(
			'lat'   => (float) ( $place['latitude'] ?? 0 ),
			'lng'   => (float) ( $place['longitude'] ?? 0 ),
			'city'  => sanitize_text_field( (string) ( $place['place name'] ?? '' ) ),
			'state' => sanitize_text_field( (string) ( $place['state abbreviation'] ?? '' ) ),
		);

		if ( 0.0 === $data['lat'] && 0.0 === $data['lng'] ) {
			set_transient( $failure_key, 1, HOUR_IN_SECONDS );
			return null;
		}

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'zip'        => $zip,
				'lat'        => $data['lat'],
				'lng'        => $data['lng'],
				'city'       => $data['city'],
				'state'      => $data['state'],
				'country'    => 'US',
				'source'     => 'zippopotam.us',
				'queried_at' => fflcs_mysql_now(),
			),
			array( '%s', '%f', '%f', '%s', '%s', '%s', '%s', '%s' )
		);

		return $data;
	}

	/**
	 * Warm the cache for a batch of ZIPs.
	 *
	 * Used by the diagnostics screen to pre-resolve the ZIPs of dealers the
	 * store has actually shipped to, so those searches never pay the first-hit
	 * latency during a live checkout.
	 *
	 * @param string[] $zips     ZIP codes.
	 * @param int      $max_calls Cap on external calls this invocation.
	 * @return array{resolved:int,skipped:int,failed:int}
	 */
	public static function warm( array $zips, int $max_calls = 20 ): array {
		$resolved = 0;
		$skipped  = 0;
		$failed   = 0;
		$calls    = 0;

		foreach ( $zips as $zip ) {
			$zip = self::normalise( (string) $zip );
			if ( null === $zip ) {
				++$skipped;
				continue;
			}

			if ( self::is_cached( $zip ) ) {
				++$skipped;
				continue;
			}

			if ( $calls >= $max_calls ) {
				break;
			}

			++$calls;

			if ( self::coordinates( $zip ) ) {
				++$resolved;
			} else {
				++$failed;
			}
		}

		return compact( 'resolved', 'skipped', 'failed' );
	}

	/**
	 * Whether a ZIP is already cached locally.
	 *
	 * @param string $zip Normalised ZIP.
	 */
	public static function is_cached( string $zip ): bool {
		global $wpdb;

		$table = fflcs_table( 'zip_cache' );

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE zip = %s LIMIT 1",
				$zip
			)
		);
	}

	/**
	 * Number of cached centroids — shown on the diagnostics screen.
	 */
	public static function cache_size(): int {
		global $wpdb;

		$table = fflcs_table( 'zip_cache' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Great-circle distance in statute miles.
	 *
	 * Mirrors the SQL used by the dealer search so PHP-side and SQL-side
	 * distances agree; 3959 is the Earth's mean radius in miles.
	 *
	 * @param float $lat1 Origin latitude.
	 * @param float $lng1 Origin longitude.
	 * @param float $lat2 Target latitude.
	 * @param float $lng2 Target longitude.
	 */
	public static function haversine_miles( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$earth_radius = 3959.0;

		$delta_lat = deg2rad( $lat2 - $lat1 );
		$delta_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $delta_lat / 2 ) ** 2
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $delta_lng / 2 ) ** 2;

		return $earth_radius * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
	}

	/**
	 * Reduce arbitrary input to a five-digit ZIP, or null.
	 *
	 * Handles ZIP+4 by truncating, and left-pads the New England ZIPs that
	 * arrive without their leading zero.
	 *
	 * @param string $zip Raw input.
	 */
	private static function normalise( string $zip ): ?string {
		$digits = preg_replace( '/\D/', '', $zip );

		if ( '' === (string) $digits ) {
			return null;
		}

		$digits = substr( (string) $digits, 0, 5 );
		$digits = str_pad( $digits, 5, '0', STR_PAD_LEFT );

		return 5 === strlen( $digits ) ? $digits : null;
	}
}
