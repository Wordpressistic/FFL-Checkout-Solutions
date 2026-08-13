<?php
/**
 * ATF Federal Firearms Licensee database sync (Section 9.2).
 *
 * The ATF publishes the complete FFL licensee list monthly as a ZIP of
 * per-state CSVs (~80,000 rows total):
 *   https://www.atf.gov/firearms/listing-federal-firearms-licensees
 *
 * Import is chunked and fully resumable: state lives in options, each cron tick
 * processes CHUNK_SIZE lines from the current file and records its byte offset.
 * A tick that times out loses at most one chunk, and the next tick resumes from
 * the last recorded offset rather than restarting the file.
 *
 * CSV column order (ATF standard):
 *   0 Lic_Regn  1 Lic_Dist  2 Lic_Cnty  3 Lic_Type  4 Lic_Xprdte  5 Lic_Seqn
 *   6 License_Name  7 Business_Name  8 Premise_Street  9 Premise_City
 *   10 Premise_State  11 Premise_Zip  12 Mail_Street  13 Mail_City
 *   14 Mail_State  15 Mail_Zip  16 Voice_Phone
 *
 * License number is assembled as Regn-Dist-Cnty-Type-Seqn.
 *
 * @package FFLCS
 */

namespace FFLCS\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Chunked ATF licensee importer.
 */
class ATF_Sync {

	/**
	 * Rows processed per cron tick. Small enough to finish inside a
	 * conservative max_execution_time on shared hosting.
	 */
	const CHUNK_SIZE = 500;

	/**
	 * Rows per multi-row INSERT. Keeps peak memory and packet size bounded.
	 */
	const FLUSH_SIZE = 50;

	// ── Entry points ─────────────────────────────────────────────────────────

	/**
	 * Download the current ATF archive and queue the chunked import.
	 */
	public static function start_full_sync(): void {
		if ( 'running' === get_option( 'fflcs_atf_sync_status', 'idle' ) ) {
			return;
		}

		$work_dir = self::work_dir();
		wp_mkdir_p( $work_dir );

		$url = self::resolve_download_url();
		if ( ! $url ) {
			self::fail( 'error_no_url', __( 'Could not determine the current ATF download URL.', 'ffl-checkout-solutions' ) );
			return;
		}

		if ( ! class_exists( '\ZipArchive' ) ) {
			self::fail( 'error_no_zip', __( 'The PHP ZipArchive extension is required to import the ATF list.', 'ffl-checkout-solutions' ) );
			return;
		}

		$zip_path = $work_dir . 'atf-ffl-list.zip';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => 120,
				'stream'   => true,
				'filename' => $zip_path,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::fail( 'error_download', $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			self::fail( 'error_download', sprintf( __( 'The ATF download returned HTTP %d.', 'ffl-checkout-solutions' ), $code ) );
			return;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			self::fail( 'error_zip_open', __( 'Could not open the downloaded ATF archive.', 'ffl-checkout-solutions' ) );
			return;
		}
		$zip->extractTo( $work_dir );
		$zip->close();
		wp_delete_file( $zip_path );

		$files = self::find_csv_files( $work_dir );
		if ( ! $files ) {
			self::fail( 'error_no_csv', __( 'No CSV files were found inside the ATF archive.', 'ffl-checkout-solutions' ) );
			return;
		}

		update_option( 'fflcs_atf_sync_status', 'running' );
		update_option( 'fflcs_atf_sync_message', '' );
		update_option( 'fflcs_atf_sync_files', wp_json_encode( $files ) );
		update_option( 'fflcs_atf_sync_file_index', 0 );
		update_option( 'fflcs_atf_sync_file_offset', 0 );
		update_option( 'fflcs_atf_sync_total_done', 0 );
		update_option( 'fflcs_atf_sync_started_at', fflcs_mysql_now() );

		if ( ! wp_next_scheduled( 'fflcs_atf_sync_chunk' ) ) {
			wp_schedule_event( time() + 5, 'fflcs_every_minute', 'fflcs_atf_sync_chunk' );
		}
	}

	/**
	 * Process one chunk. Runs every minute while a sync is in progress.
	 */
	public static function process_chunk(): void {
		if ( 'running' !== get_option( 'fflcs_atf_sync_status', 'idle' ) ) {
			wp_clear_scheduled_hook( 'fflcs_atf_sync_chunk' );
			return;
		}

		$files      = json_decode( (string) get_option( 'fflcs_atf_sync_files', '[]' ), true );
		$file_index = (int) get_option( 'fflcs_atf_sync_file_index', 0 );
		$offset     = (int) get_option( 'fflcs_atf_sync_file_offset', 0 );
		$total_done = (int) get_option( 'fflcs_atf_sync_total_done', 0 );

		if ( ! is_array( $files ) || $file_index >= count( $files ) ) {
			self::finish( $total_done );
			return;
		}

		$path = $files[ $file_index ];

		if ( ! file_exists( $path ) ) {
			// A vanished temp file (cleanup, host reaper) must not stall the run.
			update_option( 'fflcs_atf_sync_file_index', $file_index + 1 );
			update_option( 'fflcs_atf_sync_file_offset', 0 );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			self::fail( 'error_file_open', __( 'Could not read an extracted ATF CSV file.', 'ffl-checkout-solutions' ) );
			return;
		}

		// Resume by byte offset rather than by counting lines from the top —
		// re-scanning a 20MB file every tick is what makes naive chunked
		// importers get slower the further they get.
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$processed = 0;
		$imported  = 0;
		$batch     = array();

		while ( $processed < self::CHUNK_SIZE && ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}

			++$processed;

			$line = trim( $line );
			if ( '' === $line || 0 === stripos( $line, 'Lic_Regn' ) || 0 === stripos( $line, '"Lic_Regn"' ) ) {
				continue;
			}

			$columns = str_getcsv( $line );
			if ( count( $columns ) < 17 ) {
				continue;
			}

			$dealer = self::parse_row( $columns );
			if ( ! $dealer ) {
				continue;
			}

			$batch[] = $dealer;
			++$imported;

			if ( count( $batch ) >= self::FLUSH_SIZE ) {
				self::upsert( $batch );
				$batch = array();
			}
		}

		$new_offset = ftell( $handle );
		$eof        = feof( $handle );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		if ( $batch ) {
			self::upsert( $batch );
		}

		$total_done += $imported;
		update_option( 'fflcs_atf_sync_total_done', $total_done );

		if ( $eof || $processed < self::CHUNK_SIZE ) {
			update_option( 'fflcs_atf_sync_file_index', $file_index + 1 );
			update_option( 'fflcs_atf_sync_file_offset', 0 );

			if ( $file_index + 1 >= count( $files ) ) {
				self::finish( $total_done );
			}
			return;
		}

		update_option( 'fflcs_atf_sync_file_offset', (int) $new_offset );
	}

	/**
	 * Progress snapshot for the admin dashboard.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		global $wpdb;

		$dealers = fflcs_table( 'dealers' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dealers}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dealers} WHERE is_active = 1" );

		$files       = json_decode( (string) get_option( 'fflcs_atf_sync_files', '[]' ), true );
		$total_files = is_array( $files ) ? count( $files ) : 0;
		$file_index  = (int) get_option( 'fflcs_atf_sync_file_index', 0 );

		return array(
			'status'       => (string) get_option( 'fflcs_atf_sync_status', 'idle' ),
			'message'      => (string) get_option( 'fflcs_atf_sync_message', '' ),
			'total_done'   => (int) get_option( 'fflcs_atf_sync_total_done', 0 ),
			'file_index'   => $file_index,
			'total_files'  => $total_files,
			'percentage'   => $total_files > 0 ? min( 100, (int) round( ( $file_index / $total_files ) * 100 ) ) : 0,
			'dealer_count' => $total,
			'active_count' => $active,
			'started_at'   => (string) get_option( 'fflcs_atf_sync_started_at', '' ),
			'completed_at' => (string) get_option( 'fflcs_atf_sync_completed_at', '' ),
		);
	}

	/**
	 * Abort an in-progress run and clear its schedule.
	 */
	public static function cancel(): void {
		wp_clear_scheduled_hook( 'fflcs_atf_sync_chunk' );
		update_option( 'fflcs_atf_sync_status', 'cancelled' );
		self::cleanup_work_dir();
	}

	// ── Internals ────────────────────────────────────────────────────────────

	/**
	 * Find the most recent published ATF archive.
	 *
	 * The URL embeds the publication month, and the current month's file does
	 * not exist until ATF publishes it — so walk back up to four months and
	 * take the first that responds 200 to a HEAD.
	 *
	 * HONESTY NOTE: the mYYYY filename pattern below is the long-standing ATF
	 * convention observed in the published data directory, not a documented,
	 * versioned API contract. ATF can change it without notice; when they do,
	 * this returns null, the admin screen surfaces "could not determine URL",
	 * and a manual URL override is the intended fallback.
	 */
	private static function resolve_download_url(): ?string {
		/**
		 * Filter the ATF archive URL.
		 *
		 * Returning a non-empty string skips discovery entirely — the escape
		 * hatch for when ATF changes their file naming.
		 *
		 * @param string $url Empty by default.
		 */
		$override = (string) apply_filters( 'fflcs_atf_download_url', (string) get_option( 'fflcs_atf_url_override', '' ) );
		if ( '' !== $override ) {
			return $override;
		}

		for ( $months_back = 0; $months_back <= 3; $months_back++ ) {
			$timestamp = strtotime( "-{$months_back} months" );
			$url       = 'https://www.atf.gov/firearms/docs/data/' . gmdate( 'mY', $timestamp ) . 'ffllistcsv.zip';

			$response = wp_remote_head(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 3,
				)
			);

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				return $url;
			}
		}

		return null;
	}

	/**
	 * Every CSV in the extracted archive, sorted for deterministic resume.
	 *
	 * @param string $dir Directory.
	 * @return string[]
	 */
	private static function find_csv_files( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'csv' === strtolower( $file->getExtension() ) ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Turn one ATF CSV row into a dealer array, or null when unusable.
	 *
	 * @param string[] $columns CSV columns.
	 * @return array<string,mixed>|null
	 */
	private static function parse_row( array $columns ): ?array {
		$region   = trim( $columns[0] ?? '' );
		$district = trim( $columns[1] ?? '' );
		$county   = trim( $columns[2] ?? '' );
		$type     = trim( $columns[3] ?? '' );
		$expires  = trim( $columns[4] ?? '' );
		$sequence = trim( $columns[5] ?? '' );

		if ( '' === $region || '' === $type || '' === $sequence ) {
			return null;
		}

		$state = strtoupper( trim( $columns[10] ?? '' ) );
		if ( ! in_array( $state, fflcs_us_states(), true ) ) {
			return null;
		}

		$zip = preg_replace( '/[^0-9]/', '', trim( $columns[11] ?? '' ) );
		$zip = substr( (string) $zip, 0, 5 );

		return array(
			'license_number'  => sanitize_text_field( "{$region}-{$district}-{$county}-{$type}-{$sequence}" ),
			'license_type'    => sanitize_text_field( $type ),
			'license_expires' => self::parse_date( $expires ),
			'business_name'   => sanitize_text_field( trim( $columns[7] ?? '' ) ),
			'premise_street'  => sanitize_text_field( trim( $columns[8] ?? '' ) ),
			'premise_city'    => sanitize_text_field( trim( $columns[9] ?? '' ) ),
			'premise_state'   => $state,
			'premise_zip'     => sanitize_text_field( $zip ),
			'mailing_street'  => sanitize_text_field( trim( $columns[12] ?? '' ) ),
			'mailing_city'    => sanitize_text_field( trim( $columns[13] ?? '' ) ),
			'mailing_state'   => sanitize_text_field( strtoupper( trim( $columns[14] ?? '' ) ) ),
			'mailing_zip'     => sanitize_text_field( trim( $columns[15] ?? '' ) ),
			'phone'           => sanitize_text_field( trim( $columns[16] ?? '' ) ),
			'last_synced'     => fflcs_mysql_now(),
		);
	}

	/**
	 * Normalise the several date formats ATF has used.
	 *
	 * @param string $raw Raw value.
	 */
	private static function parse_date( string $raw ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2] );
		}
		if ( preg_match( '/^(\d{2})(\d{2})(\d{4})$/', $raw, $m ) ) {
			return sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2] );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return $raw;
		}
		return null;
	}

	/**
	 * Multi-row upsert, then backfill coordinates from the ZIP cache.
	 *
	 * ON DUPLICATE KEY UPDATE deliberately leaves transfer_fee, is_preferred,
	 * notes, email and wp_user_id untouched — those are the store's own data
	 * about a dealer, and a monthly ATF refresh must never wipe them.
	 *
	 * @param array<int,array<string,mixed>> $dealers Rows.
	 */
	private static function upsert( array $dealers ): void {
		global $wpdb;

		if ( ! $dealers ) {
			return;
		}

		$table  = fflcs_table( 'dealers' );
		$now    = fflcs_mysql_now();
		$rows   = array();
		$values = array();

		foreach ( $dealers as $dealer ) {
			$rows[]   = '(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)';
			$values[] = $dealer['license_number'];
			$values[] = $dealer['license_type'];
			$values[] = $dealer['license_expires'];
			$values[] = $dealer['business_name'];
			$values[] = $dealer['premise_street'];
			$values[] = $dealer['premise_city'];
			$values[] = $dealer['premise_state'];
			$values[] = $dealer['premise_zip'];
			$values[] = $dealer['mailing_street'];
			$values[] = $dealer['mailing_city'];
			$values[] = $dealer['mailing_state'];
			$values[] = $dealer['mailing_zip'];
			$values[] = $dealer['phone'];
			$values[] = $dealer['last_synced'];
			$values[] = $now;
			$values[] = $now;
		}

		$sql = "INSERT INTO {$table}
			(license_number, license_type, license_expires, business_name,
			 premise_street, premise_city, premise_state, premise_zip,
			 mailing_street, mailing_city, mailing_state, mailing_zip,
			 phone, last_synced, created_at, updated_at)
			VALUES " . implode( ',', $rows ) . '
			ON DUPLICATE KEY UPDATE
				license_type    = VALUES(license_type),
				license_expires = VALUES(license_expires),
				business_name   = VALUES(business_name),
				premise_street  = VALUES(premise_street),
				premise_city    = VALUES(premise_city),
				premise_state   = VALUES(premise_state),
				premise_zip     = VALUES(premise_zip),
				mailing_street  = VALUES(mailing_street),
				mailing_city    = VALUES(mailing_city),
				mailing_state   = VALUES(mailing_state),
				mailing_zip     = VALUES(mailing_zip),
				phone           = VALUES(phone),
				is_active       = 1,
				last_synced     = VALUES(last_synced),
				updated_at      = VALUES(updated_at)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( $sql, ...$values ) );

		self::backfill_coordinates();
	}

	/**
	 * Copy cached ZIP centroids onto dealers that still lack coordinates.
	 */
	private static function backfill_coordinates(): void {
		global $wpdb;

		$dealers = fflcs_table( 'dealers' );
		$zips    = fflcs_table( 'zip_cache' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"UPDATE {$dealers} d
			 INNER JOIN {$zips} z ON z.zip = LEFT(d.premise_zip, 5)
			 SET d.lat = z.lat, d.lng = z.lng
			 WHERE d.lat IS NULL OR d.lat = 0"
		);
	}

	/**
	 * Close out a completed run.
	 *
	 * @param int $total_done Rows imported.
	 */
	private static function finish( int $total_done ): void {
		$started_at = (string) get_option( 'fflcs_atf_sync_started_at', '' );

		// Only sweep when the run actually imported something. Mass-inactivating
		// up front — or after a run that failed to import — would disable every
		// dealer on the site and break checkout.
		if ( $total_done > 0 && '' !== $started_at ) {
			self::deactivate_unseen( $started_at );
		}

		update_option( 'fflcs_atf_sync_status', 'complete' );
		update_option( 'fflcs_atf_sync_total_done', $total_done );
		update_option( 'fflcs_atf_sync_completed_at', fflcs_mysql_now() );

		wp_clear_scheduled_hook( 'fflcs_atf_sync_chunk' );
		self::cleanup_work_dir();

		/**
		 * Fires when an ATF sync finishes.
		 *
		 * @param int $total_done Rows imported.
		 */
		do_action( 'fflcs_atf_sync_complete', $total_done );
	}

	/**
	 * Mark dealers absent from this run inactive (Section 9.2).
	 *
	 * @param string $started_at Run start timestamp.
	 * @return int Rows affected.
	 */
	private static function deactivate_unseen( string $started_at ): int {
		global $wpdb;

		$table = fflcs_table( 'dealers' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET is_active = 0
				 WHERE ( last_synced IS NULL OR last_synced < %s ) AND is_active = 1",
				$started_at
			)
		);

		return (int) $wpdb->rows_affected;
	}

	/**
	 * Record a failure for the admin screen.
	 *
	 * @param string $status  Status slug.
	 * @param string $message Human-readable reason.
	 */
	private static function fail( string $status, string $message ): void {
		update_option( 'fflcs_atf_sync_status', $status );
		update_option( 'fflcs_atf_sync_message', $message );
		wp_clear_scheduled_hook( 'fflcs_atf_sync_chunk' );
		fflcs_log( 'ATF sync failed: ' . $status . ' — ' . $message );
	}

	/**
	 * Remove extracted CSVs.
	 */
	private static function cleanup_work_dir(): void {
		$dir = self::work_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}
	}

	/**
	 * Temp directory for downloads and extraction.
	 */
	private static function work_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'fflcs/atf-sync/';
	}
}
