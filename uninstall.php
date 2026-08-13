<?php
/**
 * Uninstall handler.
 *
 * Runs only when a site administrator deletes the plugin, and only removes data
 * if they explicitly opted in beforehand.
 *
 * Off by default, deliberately. This plugin stores a licensee's transfer records
 * and bound-book entries — records federal law requires them to keep for 20
 * years. Deleting a plugin is a routine troubleshooting step; destroying
 * compliance records must never be a side effect of one.
 *
 * @package FFLCS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( '1' !== (string) get_option( 'fflcs_delete_data_on_uninstall', '0' ) ) {
	return;
}

// Load only what teardown needs. The plugin itself is not booting here.
require_once plugin_dir_path( __FILE__ ) . 'includes/helpers/functions.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-installer.php';

if ( ! defined( 'FFLCS_DB_PREFIX' ) ) {
	define( 'FFLCS_DB_PREFIX', 'fflcs_' );
}

global $wpdb;

\FFLCS\Installer::drop_tables();

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'fflcs\\_%'" );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_fflcs\\_%'" );

// The dealer role goes; the user accounts holding it do not. Those accounts may
// have other uses on this site, and deleting a person's login because a plugin
// was removed would be well beyond what was asked for.
remove_role( 'ffl_dealer' );

foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
	$role = get_role( $role_name );

	if ( $role ) {
		$role->remove_cap( 'manage_fflcs_transfers' );
		$role->remove_cap( 'view_fflcs_documents' );
	}
}

// Certified licence copies and identity documents live on disk, not in the
// database — remove them too, or deleting the plugin would leave a directory of
// scanned IDs behind.
$uploads = wp_upload_dir();
$private = trailingslashit( $uploads['basedir'] ) . 'fflcs-private/';

if ( is_dir( $private ) ) {
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $private, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		} else {
			wp_delete_file( $item->getPathname() );
		}
	}

	@rmdir( $private ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
