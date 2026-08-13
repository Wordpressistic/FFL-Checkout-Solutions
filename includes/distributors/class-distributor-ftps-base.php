<?php
/**
 * Shared FTPS behaviour for the file-exchange distributors.
 *
 * RSR and Bill Hicks both work by pulling an inventory file and pushing an
 * order file over FTPS, so the transport lives here once.
 *
 * FTPS, explicitly: ftp_ssl_connect(), never plain ftp_connect(). These
 * sessions carry dealer credentials and order contents, and the plugin refuses
 * to send them in the clear rather than silently falling back.
 *
 * @package FFLCS
 */

namespace FFLCS\Distributors;

defined( 'ABSPATH' ) || exit;

/**
 * FTPS transport base.
 */
abstract class Distributor_Ftps_Base extends Distributor_Support {

	/**
	 * Open an authenticated FTPS session.
	 *
	 * @return resource|\FTP\Connection|\WP_Error
	 */
	protected function connect() {
		if ( ! function_exists( 'ftp_ssl_connect' ) ) {
			return new \WP_Error(
				'fflcs_ftps_unavailable',
				__( 'This server does not have PHP FTP with SSL support, which this distributor requires.', 'ffl-checkout-solutions' ),
				array( 'status' => 501 )
			);
		}

		$host = $this->credential( 'host' );

		if ( '' === $host ) {
			return $this->not_configured();
		}

		$connection = @ftp_ssl_connect( $host, 21, 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $connection ) {
			return new \WP_Error(
				'fflcs_ftps_connect_failed',
				__( 'Could not open a secure FTPS connection to the distributor.', 'ffl-checkout-solutions' ),
				array( 'status' => 502 )
			);
		}

		$logged_in = @ftp_login( $connection, $this->credential( 'username' ), $this->credential( 'password' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! $logged_in ) {
			@ftp_close( $connection ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			return new \WP_Error(
				'fflcs_ftps_auth_failed',
				__( 'The distributor rejected those FTPS credentials.', 'ffl-checkout-solutions' ),
				array( 'status' => 401 )
			);
		}

		// Passive mode: an active-mode data connection needs an inbound port
		// open to the web server, which is almost never true on managed hosting.
		@ftp_pasv( $connection, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		return $connection;
	}

	/**
	 * Download a remote file to a temporary local path.
	 *
	 * @param resource|\FTP\Connection $connection FTPS session.
	 * @param string                   $remote     Remote path.
	 * @return string|\WP_Error Local path.
	 */
	protected function download( $connection, string $remote ) {
		$local = wp_tempnam( 'fflcs-' . $this->slug );

		if ( ! $local ) {
			return new \WP_Error(
				'fflcs_temp_failed',
				__( 'Could not create a temporary file for the download.', 'ffl-checkout-solutions' ),
				array( 'status' => 500 )
			);
		}

		if ( ! @ftp_get( $connection, $local, $remote, FTP_BINARY ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $local );

			return new \WP_Error(
				'fflcs_ftps_download_failed',
				sprintf(
					/* translators: %s: remote file path. */
					__( 'Could not download %s from the distributor.', 'ffl-checkout-solutions' ),
					$remote
				),
				array( 'status' => 502 )
			);
		}

		return $local;
	}

	/**
	 * Upload a string as a remote file.
	 *
	 * @param resource|\FTP\Connection $connection FTPS session.
	 * @param string                   $remote     Remote path.
	 * @param string                   $contents   File contents.
	 * @return true|\WP_Error
	 */
	protected function upload( $connection, string $remote, string $contents ) {
		$local = wp_tempnam( 'fflcs-upload-' . $this->slug );

		if ( ! $local ) {
			return new \WP_Error(
				'fflcs_temp_failed',
				__( 'Could not create a temporary file for the upload.', 'ffl-checkout-solutions' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $local, $contents );

		$sent = @ftp_put( $connection, $remote, $local, FTP_BINARY ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		wp_delete_file( $local );

		if ( ! $sent ) {
			return new \WP_Error(
				'fflcs_ftps_upload_failed',
				__( 'The distributor rejected the order file upload.', 'ffl-checkout-solutions' ),
				array( 'status' => 502 )
			);
		}

		return true;
	}

	/**
	 * Close a session.
	 *
	 * @param resource|\FTP\Connection $connection FTPS session.
	 */
	protected function disconnect( $connection ): void {
		if ( $connection ) {
			@ftp_close( $connection ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Read a delimited catalog file into normalised products.
	 *
	 * @param string   $path      Local file path.
	 * @param string   $delimiter Field delimiter.
	 * @param callable $mapper    Maps one row array to a product array or null.
	 * @return array<int,array<string,mixed>>
	 */
	protected function parse_delimited( string $path, string $delimiter, callable $mapper ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $path, 'r' );

		if ( ! $handle ) {
			return array();
		}

		$products = array();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			$product = $mapper( $row );

			if ( is_array( $product ) ) {
				$products[] = $product;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return $products;
	}
}
