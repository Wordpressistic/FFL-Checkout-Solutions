<?php
/**
 * Procedural helpers.
 *
 * Loaded unconditionally by the bootstrap — before the dependency guard runs —
 * so nothing in this file may assume WooCommerce exists.
 *
 * @package FFLCS
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'fflcs_table' ) ) {
	/**
	 * Fully-qualified name of a plugin table.
	 *
	 * @param string $name Bare table key, e.g. 'transfers'.
	 */
	function fflcs_table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . FFLCS_DB_PREFIX . $name;
	}
}

if ( ! function_exists( 'fflcs_get_option' ) ) {
	/**
	 * Read a plugin option with a default.
	 *
	 * @param string $key     Option name, with or without the fflcs_ prefix.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	function fflcs_get_option( string $key, $default = '' ) {
		if ( 0 !== strpos( $key, 'fflcs_' ) ) {
			$key = 'fflcs_' . $key;
		}
		return get_option( $key, $default );
	}
}

if ( ! function_exists( 'fflcs_update_option' ) ) {
	/**
	 * Write a plugin option.
	 *
	 * @param string $key   Option name, with or without the fflcs_ prefix.
	 * @param mixed  $value Value.
	 */
	function fflcs_update_option( string $key, $value ): bool {
		if ( 0 !== strpos( $key, 'fflcs_' ) ) {
			$key = 'fflcs_' . $key;
		}
		return update_option( $key, $value );
	}
}

if ( ! function_exists( 'fflcs_client_ip' ) ) {
	/**
	 * Resolve the client IP.
	 *
	 * Section 13.3: proxy headers are only honoured when REMOTE_ADDR itself is
	 * in the trusted-proxy list, which defaults to empty. Without that rule any
	 * visitor could set X-Forwarded-For and poison rate limits and audit rows.
	 */
	function fflcs_client_ip(): string {
		return \FFLCS\Services\Token::client_ip();
	}
}

if ( ! function_exists( 'fflcs_log' ) ) {
	/**
	 * Debug logger. No-op unless fflcs_debug_mode is on.
	 *
	 * @param string $message Message.
	 * @param mixed  $context Optional context, JSON-encoded when not scalar.
	 */
	function fflcs_log( string $message, $context = null ): void {
		if ( '1' !== (string) get_option( 'fflcs_debug_mode', '0' ) ) {
			return;
		}
		if ( null !== $context ) {
			$message .= ' ' . ( is_scalar( $context ) ? (string) $context : wp_json_encode( $context ) );
		}
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[FFLCS] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

if ( ! function_exists( 'fflcs_transfer_statuses' ) ) {
	/**
	 * The 11 canonical transfer lifecycle stages (Section 9.4), keyed by slug.
	 *
	 * @return array<string,string> Slug => translated label.
	 */
	function fflcs_transfer_statuses(): array {
		return array(
			'dealer_selected'    => __( 'Dealer Selected', 'ffl-checkout-solutions' ),
			'payment_confirmed'  => __( 'Payment Confirmed', 'ffl-checkout-solutions' ),
			'shipped_to_dealer'  => __( 'Shipped to Dealer', 'ffl-checkout-solutions' ),
			'received_by_dealer' => __( 'Received by Dealer', 'ffl-checkout-solutions' ),
			'nics_pending'       => __( 'NICS Pending', 'ffl-checkout-solutions' ),
			'nics_delayed'       => __( 'NICS Delayed', 'ffl-checkout-solutions' ),
			'nics_approved'      => __( 'NICS Approved', 'ffl-checkout-solutions' ),
			'nics_denied'        => __( 'NICS Denied', 'ffl-checkout-solutions' ),
			'transferred'        => __( 'Transferred', 'ffl-checkout-solutions' ),
			'cancelled'          => __( 'Cancelled', 'ffl-checkout-solutions' ),
			'issue_reported'     => __( 'Issue Reported', 'ffl-checkout-solutions' ),
		);
	}
}

if ( ! function_exists( 'fflcs_status_label' ) ) {
	/**
	 * Human label for a transfer status slug.
	 *
	 * @param string $status Status slug.
	 */
	function fflcs_status_label( string $status ): string {
		$all = fflcs_transfer_statuses();
		return $all[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
	}
}

if ( ! function_exists( 'fflcs_item_types' ) ) {
	/**
	 * Canonical product classification vocabulary.
	 *
	 * Shared by compliance rules, the A&D ledger, the 4473 worksheet and
	 * multi-sale detection — they must agree or the reports disagree.
	 *
	 * @return array<string,string>
	 */
	function fflcs_item_types(): array {
		return array(
			'handgun' => __( 'Handgun (pistol/revolver)', 'ffl-checkout-solutions' ),
			'rifle'   => __( 'Rifle', 'ffl-checkout-solutions' ),
			'shotgun' => __( 'Shotgun', 'ffl-checkout-solutions' ),
			'other'   => __( 'Other firearm', 'ffl-checkout-solutions' ),
		);
	}
}

if ( ! function_exists( 'fflcs_us_states' ) ) {
	/**
	 * US states, DC and the territories that appear in ATF licensee data.
	 *
	 * @return string[]
	 */
	function fflcs_us_states(): array {
		return array(
			'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA',
			'HI', 'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD',
			'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ',
			'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC',
			'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
			'DC', 'GU', 'MP', 'PR', 'VI',
		);
	}
}

if ( ! function_exists( 'fflcs_is_url_safe' ) ) {
	/**
	 * SSRF guard for admin-supplied outbound URLs (Section 27.4).
	 *
	 * Rejects non-HTTP(S) schemes, credentials in the URL, and any host that
	 * resolves to a loopback, link-local, or RFC1918 address. Used by the
	 * outbound webhook dispatcher and every module that lets an admin paste a
	 * callback URL.
	 *
	 * @param string $url Candidate URL.
	 */
	function fflcs_is_url_safe( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		// Credentials embedded in the URL are a redirect/exfiltration smell.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		if ( in_array( $host, array( 'localhost', 'localhost.localdomain', '[::1]', '::1' ), true ) ) {
			return false;
		}

		// Resolve to compare against private ranges. A hostname that resolves
		// internally is the interesting SSRF case, not just a literal IP.
		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			foreach ( (array) $records as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$ips[] = $record['ip'];
				}
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
			// A host that will not resolve cannot be validated — refuse it
			// rather than letting the HTTP layer resolve it later differently.
			if ( empty( $ips ) ) {
				return false;
			}
		}

		foreach ( $ips as $ip ) {
			$public = filter_var(
				$ip,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			if ( false === $public ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'fflcs_csv_cell' ) ) {
	/**
	 * Neutralise CSV formula injection.
	 *
	 * Excel and Sheets execute a cell beginning with = + - @ or a control
	 * character. Dealer names and staff notes are attacker-influenced and land
	 * in exports an FFL opens locally, so every exported cell goes through this.
	 *
	 * @param mixed $value Raw cell value.
	 */
	function fflcs_csv_cell( $value ): string {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}
}

if ( ! function_exists( 'fflcs_can_manage' ) ) {
	/**
	 * Capability check for plugin admin surfaces.
	 *
	 * manage_woocommerce is the primary gate; manage_fflcs_transfers exists so
	 * a store can grant FFL staff access without full WooCommerce management.
	 */
	function fflcs_can_manage(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_fflcs_transfers' );
	}
}

if ( ! function_exists( 'fflcs_mysql_now' ) ) {
	/**
	 * Current UTC time in MySQL DATETIME format.
	 *
	 * Every timestamp column in this plugin stores UTC. Display conversion is
	 * the presentation layer's job.
	 */
	function fflcs_mysql_now(): string {
		return current_time( 'mysql', true );
	}
}
