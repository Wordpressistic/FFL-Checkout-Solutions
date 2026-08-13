<?php
/**
 * Add-on registry (Section 8).
 *
 * The single source of truth for what modules exist, what tier each requires,
 * whether it defaults on, and — importantly for Section 14 — what data each one
 * sends to a third party. That last field is surfaced on the module's own card
 * in the Add-ons screen, at the moment someone decides to switch it on, rather
 * than being buried in a privacy document nobody opens.
 *
 * @package FFLCS
 */

namespace FFLCS;

use FFLCS\License\Entitlement;

defined( 'ABSPATH' ) || exit;

/**
 * Module manifest registry and activation state.
 */
class Addon_Manager {

	/**
	 * Option holding the active-module map.
	 */
	const OPTION_ACTIVE = 'fflcs_active_modules';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_post_fflcs_toggle_module', array( $this, 'handle_toggle' ) );
	}

	/**
	 * Every module manifest, keyed by module ID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function manifests(): array {
		static $manifests = null;

		if ( null !== $manifests ) {
			return $manifests;
		}

		$manifests = array();

		foreach ( self::definitions() as $definition ) {
			$manifests[ $definition['id'] ] = wp_parse_args(
				$definition,
				array(
					'tier'           => 'free',
					'status'         => 'active',
					'category'       => 'core',
					'icon'           => 'dashicons-admin-generic',
					'color'          => '#2563EB',
					'default_active' => false,
					'bootstrap'      => '',
					'configure'      => '',
					'sends_data'     => '',
				)
			);
		}

		/**
		 * Filter the module registry.
		 *
		 * @param array $manifests Module manifests.
		 */
		$manifests = (array) apply_filters( 'fflcs_module_manifests', $manifests );

		return $manifests;
	}

	/**
	 * The built-in module definitions (Section 8.4).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function definitions(): array {
		return array(
			// ── Core (free, on by default) ───────────────────────────────────
			array(
				'id'             => 'core_checkout',
				'name'           => __( 'FFL Checkout', 'ffl-checkout-solutions' ),
				'desc'           => __( 'Dealer selection at checkout, transfer records, and the order integration. The foundation everything else builds on.', 'ffl-checkout-solutions' ),
				'tier'           => 'free',
				'category'       => 'core',
				'icon'           => 'dashicons-cart',
				'default_active' => true,
				'required'       => true,
				'configure'      => 'admin.php?page=fflcs-settings&tab=checkout',
			),
			array(
				'id'             => 'atf_sync',
				'name'           => __( 'ATF Dealer Sync', 'ffl-checkout-solutions' ),
				'desc'           => __( 'Monthly import of the complete ATF federal firearms licensee list, around 80,000 dealers, in resumable batches.', 'ffl-checkout-solutions' ),
				'tier'           => 'free',
				'category'       => 'core',
				'icon'           => 'dashicons-update',
				'default_active' => true,
				'sends_data'     => __( 'Sends nothing. Downloads the public ATF licensee file.', 'ffl-checkout-solutions' ),
				'configure'      => 'admin.php?page=fflcs-dealers',
			),
			array(
				'id'             => 'zip_engine',
				'name'           => __( 'ZIP Distance Search', 'ffl-checkout-solutions' ),
				'desc'           => __( 'Distance-ranked dealer search. Looks up a ZIP centroid once, then answers every later search from local data.', 'ffl-checkout-solutions' ),
				'tier'           => 'free',
				'category'       => 'core',
				'icon'           => 'dashicons-location',
				'default_active' => true,
				'sends_data'     => __( 'Sends the five-digit ZIP code only, to zippopotam.us, once per new ZIP. No customer data.', 'ffl-checkout-solutions' ),
			),
			array(
				'id'             => 'dealer_portal',
				'name'           => __( 'Dealer Confirmation Portal', 'ffl-checkout-solutions' ),
				'desc'           => __( 'One-click magic links so dealers confirm receipt without an account. Optional second factor and configurable expiry.', 'ffl-checkout-solutions' ),
				'tier'           => 'free',
				'category'       => 'core',
				'icon'           => 'dashicons-shield',
				'default_active' => true,
				'configure'      => 'admin.php?page=fflcs-settings&tab=portal',
			),
			array(
				'id'             => 'email_notifications',
				'name'           => __( 'Email Notifications', 'ffl-checkout-solutions' ),
				'desc'           => __( 'Branded transactional email to customers, dealers and staff at every status change.', 'ffl-checkout-solutions' ),
				'tier'           => 'free',
				'category'       => 'core',
				'icon'           => 'dashicons-email',
				'default_active' => true,
				'configure'      => 'admin.php?page=fflcs-settings&tab=email',
			),
			array(
				'id'             => 'state_compliance',
				'name'           => __( 'State Compliance Notices', 'ffl-checkout-solutions' ),
				'desc'           => __( 'Editable per-state notices shown at checkout. Informational by default; you decide which, if any, block an order.', 'ffl-checkout-solutions' ),
				'tier'           => 'free',
				'category'       => 'compliance',
				'icon'           => 'dashicons-info',
				'default_active' => true,
				'configure'      => 'admin.php?page=fflcs-compliance&tab=state-rules',
			),
			array(
				'id'             => 'advanced_design',
				'name'           => __( 'Design & Theming', 'ffl-checkout-solutions' ),
				'desc'           => __( 'Colours, logo and custom CSS across the checkout widget, dealer portal, tracking page and emails.', 'ffl-checkout-solutions' ),
				'tier'           => 'free',
				'category'       => 'design',
				'icon'           => 'dashicons-art',
				'default_active' => true,
				'configure'      => 'admin.php?page=fflcs-settings&tab=theme',
			),
			array(
				'id'             => 'gdpr_tools',
				'name'           => __( 'GDPR Tools', 'ffl-checkout-solutions' ),
				'desc'           => __( 'Personal data export and erasure. Anonymises the customer while preserving the transfer record ATF retention requires.', 'ffl-checkout-solutions' ),
				'tier'           => 'free',
				'category'       => 'compliance',
				'icon'           => 'dashicons-privacy',
				'default_active' => true,
			),
			array(
				'id'             => 'customer_tracking',
				'name'           => __( 'Customer Tracking Page', 'ffl-checkout-solutions' ),
				'desc'           => __( 'A signed public page where a customer follows their transfer, calls their dealer and gets directions.', 'ffl-checkout-solutions' ),
				'tier'           => 'free',
				'category'       => 'customer',
				'icon'           => 'dashicons-visibility',
				'default_active' => true,
			),
			array(
				'id'             => 'saved_dealers',
				'name'           => __( 'Saved Dealers', 'ffl-checkout-solutions' ),
				'desc'           => __( 'Remembers the dealers a returning customer has used, as one-tap options above the search.', 'ffl-checkout-solutions' ),
				'tier'           => 'free',
				'category'       => 'customer',
				'icon'           => 'dashicons-star-filled',
				'default_active' => true,
			),

			// ── Pro ──────────────────────────────────────────────────────────
			array(
				'id'         => 'carrier_tracking',
				'name'       => __( 'Carrier Tracking & Labels', 'ffl-checkout-solutions' ),
				'desc'       => __( 'Live shipment status via EasyPost, and label purchase from the admin — always on an explicit click, never automatically.', 'ffl-checkout-solutions' ),
				'tier'       => 'pro',
				'category'   => 'operations',
				'icon'       => 'dashicons-car',
				'sends_data' => __( 'Sends the ship-to name, address and package weight to EasyPost when you quote rates or buy a label.', 'ffl-checkout-solutions' ),
				'configure'  => 'admin.php?page=fflcs-carriers',
			),
			array(
				'id'         => 'nics_tracking',
				'name'       => __( 'NICS Tracking', 'ffl-checkout-solutions' ),
				'desc'       => __( 'Records background-check outcomes, computes the federal three-business-day window around holidays, and alerts you when it elapses.', 'ffl-checkout-solutions' ),
				'tier'       => 'pro',
				'category'   => 'compliance',
				'icon'       => 'dashicons-clock',
				'sends_data' => __( 'Sends nothing on its own. If you connect a background-check provider, that provider posts results to this site.', 'ffl-checkout-solutions' ),
			),
			array(
				'id'        => 'bound_book',
				'name'      => __( 'Bound Book (A&D Ledger)', 'ffl-checkout-solutions' ),
				'desc'      => __( 'Serial-level acquisition and disposition records with an ATF-format CSV export and a 20-year retention rule.', 'ffl-checkout-solutions' ),
				'tier'      => 'pro',
				'category'  => 'compliance',
				'icon'      => 'dashicons-book',
				'configure' => 'admin.php?page=fflcs-compliance&tab=bound-book',
			),
			array(
				'id'       => 'form_4473',
				'name'     => __( 'Form 4473 Worksheet', 'ffl-checkout-solutions' ),
				'desc'     => __( 'A printable pre-fill worksheet and signature capture. Clearly marked as a draft — never a substitute for the ATF form.', 'ffl-checkout-solutions' ),
				'tier'     => 'pro',
				'category' => 'compliance',
				'icon'     => 'dashicons-media-document',
			),
			array(
				'id'        => 'multi_sale_watcher',
				'name'      => __( 'Multiple Sale Watcher', 'ffl-checkout-solutions' ),
				'desc'      => __( 'Flags two or more handgun transfers to one buyer within five business days for Form 3310.4 review. Detection only — it never files anything.', 'ffl-checkout-solutions' ),
				'tier'      => 'pro',
				'category'  => 'compliance',
				'icon'      => 'dashicons-warning',
				'configure' => 'admin.php?page=fflcs-compliance&tab=multi-sale',
			),
			array(
				'id'        => 'verification_hub',
				'name'      => __( 'FFL Verification Hub', 'ffl-checkout-solutions' ),
				'desc'      => __( 'Certified-copy storage with expiry tracking and reminders, plus a manual eZ Check log. Files are kept outside the public media library.', 'ffl-checkout-solutions' ),
				'tier'      => 'pro',
				'category'  => 'compliance',
				'icon'      => 'dashicons-yes-alt',
				'configure' => 'admin.php?page=fflcs-compliance&tab=verification',
			),
			array(
				'id'       => 'id_verification',
				'name'     => __( 'ID & Age Verification', 'ffl-checkout-solutions' ),
				'desc'     => __( 'Collects identity documents for age-restricted orders and routes them to staff. Every decision is made by a person.', 'ffl-checkout-solutions' ),
				'tier'     => 'pro',
				'category' => 'compliance',
				'icon'     => 'dashicons-id',
			),
			array(
				'id'       => 'fraud_scoring',
				'name'     => __( 'Risk Scoring', 'ffl-checkout-solutions' ),
				'desc'     => __( 'A transparent, rules-based risk score for staff review. Advisory only: it never blocks, holds or cancels an order.', 'ffl-checkout-solutions' ),
				'tier'     => 'pro',
				'category' => 'operations',
				'icon'     => 'dashicons-chart-bar',
			),
			array(
				'id'         => 'regulatory_watch',
				'name'       => __( 'Regulatory Watch', 'ffl-checkout-solutions' ),
				'desc'       => __( 'Nightly sweep of the Federal Register for ATF documents matching your terms. Alerts you; changes nothing.', 'ffl-checkout-solutions' ),
				'tier'       => 'pro',
				'category'   => 'compliance',
				'icon'       => 'dashicons-megaphone',
				'sends_data' => __( 'Sends nothing. Reads the public Federal Register API.', 'ffl-checkout-solutions' ),
			),
			array(
				'id'        => 'analytics',
				'name'      => __( 'Analytics & Reporting', 'ffl-checkout-solutions' ),
				'desc'      => __( 'Transfer volume, status mix, dealer funnel and KPI cards, charted locally with no external service.', 'ffl-checkout-solutions' ),
				'tier'      => 'pro',
				'category'  => 'analytics',
				'icon'      => 'dashicons-chart-area',
				'configure' => 'admin.php?page=fflcs-analytics',
			),
			array(
				'id'         => 'webhooks_out',
				'name'       => __( 'Outbound Webhooks', 'ffl-checkout-solutions' ),
				'desc'       => __( 'Post every FFL event to your own endpoints, HMAC-signed, with retries and a delivery log.', 'ffl-checkout-solutions' ),
				'tier'       => 'pro',
				'category'   => 'integrations',
				'icon'       => 'dashicons-share',
				'sends_data' => __( 'Sends transfer event data to the endpoints you configure, and nowhere else.', 'ffl-checkout-solutions' ),
				'configure'  => 'admin.php?page=fflcs-settings&tab=integrations',
			),
			array(
				'id'        => 'ops_tools',
				'name'      => __( 'Operations Toolset', 'ffl-checkout-solutions' ),
				'desc'      => __( 'Bulk dealer fee updates by state, customer lifetime value lookup, and dealer health alerts.', 'ffl-checkout-solutions' ),
				'tier'      => 'pro',
				'category'  => 'operations',
				'icon'      => 'dashicons-admin-tools',
				'configure' => 'admin.php?page=fflcs-ops',
			),
			array(
				'id'         => 'sms_notifications',
				'name'       => __( 'SMS Notifications', 'ffl-checkout-solutions' ),
				'desc'       => __( 'Text customers at key milestones through your own Twilio or Verifyistic account.', 'ffl-checkout-solutions' ),
				'tier'       => 'pro',
				'category'   => 'notifications',
				'icon'       => 'dashicons-smartphone',
				'sends_data' => __( 'Sends the customer phone number and message text to your configured SMS provider.', 'ffl-checkout-solutions' ),
			),
			array(
				'id'       => 'admin_2fa',
				'name'     => __( 'Admin Two-Factor', 'ffl-checkout-solutions' ),
				'desc'     => __( 'Opt-in TOTP for staff accounts, with single-use backup codes and encrypted secrets.', 'ffl-checkout-solutions' ),
				'tier'     => 'pro',
				'category' => 'security',
				'icon'     => 'dashicons-lock',
			),
			array(
				'id'         => 'payment_nmi',
				'name'       => __( 'NMI Payments', 'ffl-checkout-solutions' ),
				'desc'       => __( 'A firearms-tolerant WooCommerce gateway using Collect.js tokenisation, so card data never touches your server.', 'ffl-checkout-solutions' ),
				'tier'       => 'pro',
				'category'   => 'payments',
				'icon'       => 'dashicons-money-alt',
				'sends_data' => __( 'Card details go from the browser directly to NMI. Your server sees only a token.', 'ffl-checkout-solutions' ),
				'configure'  => 'admin.php?page=fflcs-settings&tab=payments',
			),

			// ── Business ─────────────────────────────────────────────────────
			array(
				'id'         => 'distributor_lipseys',
				'name'       => __( "Lipsey's Drop-Ship", 'ffl-checkout-solutions' ),
				'desc'       => __( "Catalog sync and wholesale ordering through Lipsey's dealer API. Orders are only ever submitted on an explicit click.", 'ffl-checkout-solutions' ),
				'tier'       => 'business',
				'category'   => 'distributors',
				'icon'       => 'dashicons-store',
				'sends_data' => __( "Sends order line items and the ship-to dealer's FFL details when you submit an order.", 'ffl-checkout-solutions' ),
				'configure'  => 'admin.php?page=fflcs-distributors&tab=lipseys',
			),
			array(
				'id'         => 'distributor_sports_south',
				'name'       => __( 'Sports South Drop-Ship', 'ffl-checkout-solutions' ),
				'desc'       => __( 'Catalog sync and wholesale ordering through the Sports South SOAP service.', 'ffl-checkout-solutions' ),
				'tier'       => 'business',
				'category'   => 'distributors',
				'icon'       => 'dashicons-store',
				'sends_data' => __( "Sends order line items and the ship-to dealer's FFL details when you submit an order.", 'ffl-checkout-solutions' ),
				'configure'  => 'admin.php?page=fflcs-distributors&tab=sports-south',
			),
			array(
				'id'         => 'distributor_rsr',
				'name'       => __( 'RSR Group Drop-Ship', 'ffl-checkout-solutions' ),
				'desc'       => __( 'Inventory download and fixed-format order upload over RSR file exchange, with acknowledgement polling.', 'ffl-checkout-solutions' ),
				'tier'       => 'business',
				'category'   => 'distributors',
				'icon'       => 'dashicons-store',
				'sends_data' => __( "Sends order line items and the ship-to dealer's FFL details when you submit an order.", 'ffl-checkout-solutions' ),
				'configure'  => 'admin.php?page=fflcs-distributors&tab=rsr',
			),
			array(
				'id'         => 'distributor_bill_hicks',
				'name'       => __( 'Bill Hicks & Co. Drop-Ship', 'ffl-checkout-solutions' ),
				'desc'       => __( 'Catalog sync, order upload and acknowledgement reading over Bill Hicks file exchange.', 'ffl-checkout-solutions' ),
				'tier'       => 'business',
				'category'   => 'distributors',
				'icon'       => 'dashicons-store',
				'sends_data' => __( "Sends order line items and the ship-to dealer's FFL details when you submit an order.", 'ffl-checkout-solutions' ),
				'configure'  => 'admin.php?page=fflcs-distributors&tab=bill-hicks',
			),
			array(
				'id'         => 'distributor_chattanooga',
				'name'       => __( 'Chattanooga Shooting Supplies', 'ffl-checkout-solutions' ),
				'desc'       => __( 'Catalog sync and ordering through the Chattanooga REST API, including the FFL-on-file precondition check.', 'ffl-checkout-solutions' ),
				'tier'       => 'business',
				'category'   => 'distributors',
				'icon'       => 'dashicons-store',
				'sends_data' => __( "Sends order line items and the ship-to dealer's FFL details when you submit an order.", 'ffl-checkout-solutions' ),
				'configure'  => 'admin.php?page=fflcs-distributors&tab=chattanooga',
			),
			array(
				'id'         => 'gunbroker_sync',
				'name'       => __( 'GunBroker Sync', 'ffl-checkout-solutions' ),
				'desc'       => __( 'List WooCommerce products on GunBroker and import sold orders into the same transfer pipeline.', 'ffl-checkout-solutions' ),
				'tier'       => 'business',
				'category'   => 'marketplaces',
				'icon'       => 'dashicons-networking',
				'sends_data' => __( 'Sends product catalog data to GunBroker and receives order data back.', 'ffl-checkout-solutions' ),
			),
			array(
				'id'         => 'payment_credova',
				'name'       => __( 'Credova Financing', 'ffl-checkout-solutions' ),
				'desc'       => __( 'Firearms-friendly consumer financing at checkout. The order is held until the lender confirms approval.', 'ffl-checkout-solutions' ),
				'tier'       => 'business',
				'category'   => 'payments',
				'icon'       => 'dashicons-money',
				'sends_data' => __( 'Sends the order total and the customer name and email to Credova to start a financing application.', 'ffl-checkout-solutions' ),
				'configure'  => 'admin.php?page=fflcs-settings&tab=payments',
			),
		);
	}

	// ── Activation state ─────────────────────────────────────────────────────

	/**
	 * The active-module map, with defaults filled in for anything unseen.
	 *
	 * @return array<string,bool>
	 */
	public static function active_map(): array {
		$stored = (array) get_option( self::OPTION_ACTIVE, array() );
		$map    = array();

		foreach ( self::manifests() as $id => $manifest ) {
			$map[ $id ] = array_key_exists( $id, $stored )
				? (bool) $stored[ $id ]
				: (bool) $manifest['default_active'];
		}

		return $map;
	}

	/**
	 * Whether a module is switched on *and* entitled.
	 *
	 * Both conditions, always. A module left switched on when a licence lapses
	 * must stop running, not keep running because the toggle says so.
	 *
	 * @param string $id Module ID.
	 */
	public static function is_active( string $id ): bool {
		$manifests = self::manifests();

		if ( ! isset( $manifests[ $id ] ) ) {
			return false;
		}

		$manifest = $manifests[ $id ];

		if ( ! empty( $manifest['required'] ) ) {
			return true;
		}

		if ( empty( self::active_map()[ $id ] ) ) {
			return false;
		}

		return Entitlement::can( $id, (string) $manifest['tier'] );
	}

	/**
	 * Switch a module on or off.
	 *
	 * @param string $id     Module ID.
	 * @param bool   $active Desired state.
	 * @return true|\WP_Error
	 */
	public static function set_active( string $id, bool $active ) {
		$manifests = self::manifests();

		if ( ! isset( $manifests[ $id ] ) ) {
			return new \WP_Error(
				'fflcs_unknown_module',
				__( 'Unknown module.', 'ffl-checkout-solutions' )
			);
		}

		$manifest = $manifests[ $id ];

		if ( ! empty( $manifest['required'] ) && ! $active ) {
			return new \WP_Error(
				'fflcs_module_required',
				__( 'This module is required and cannot be switched off.', 'ffl-checkout-solutions' )
			);
		}

		if ( $active && ! Entitlement::can( $id, (string) $manifest['tier'] ) ) {
			return new \WP_Error(
				'fflcs_not_entitled',
				sprintf(
					/* translators: %s: required plan name. */
					__( 'This module requires the %s plan.', 'ffl-checkout-solutions' ),
					Entitlement::tier_label( (string) $manifest['tier'] )
				)
			);
		}

		$stored        = (array) get_option( self::OPTION_ACTIVE, array() );
		$stored[ $id ] = $active;

		update_option( self::OPTION_ACTIVE, $stored );

		// Switching a module off must stop its scheduled work. Settings,
		// credentials and history are deliberately preserved (Section 11.1).
		if ( ! $active ) {
			self::clear_module_schedules( $id );
		}

		/**
		 * Fires when a module is toggled.
		 *
		 * @param string $id     Module ID.
		 * @param bool   $active New state.
		 */
		do_action( 'fflcs_module_toggled', $id, $active );

		return true;
	}

	/**
	 * Cancel the cron events a module owns.
	 *
	 * @param string $id Module ID.
	 */
	private static function clear_module_schedules( string $id ): void {
		$hooks = array(
			'atf_sync'          => array( 'fflcs_atf_sync', 'fflcs_atf_sync_chunk' ),
			'carrier_tracking'  => array( 'fflcs_carrier_poll' ),
			'nics_tracking'     => array( 'fflcs_nics_delay_alert' ),
			'verification_hub'  => array( 'fflcs_compliance_reminder' ),
			'regulatory_watch'  => array( 'fflcs_regulatory_watch' ),
			'ops_tools'         => array( 'fflcs_dealer_health_check' ),
			'webhooks_out'      => array( 'fflcs_webhook_retry' ),
			'gunbroker_sync'    => array( 'fflcs_gunbroker_pull_orders' ),
			'payment_credova'   => array( 'fflcs_credova_status_sweep' ),
		);

		foreach ( $hooks[ $id ] ?? array() as $hook ) {
			Services\Scheduler::cancel( $hook );
		}
	}

	/**
	 * Manifests grouped by category, for the Add-ons screen.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public static function grouped(): array {
		$grouped = array();

		foreach ( self::manifests() as $id => $manifest ) {
			$grouped[ (string) $manifest['category'] ][ $id ] = $manifest;
		}

		// Present in a deliberate order rather than however the array happened
		// to be built.
		$order  = array( 'core', 'compliance', 'operations', 'customer', 'payments', 'distributors', 'marketplaces', 'integrations', 'analytics', 'notifications', 'security', 'design' );
		$sorted = array();

		foreach ( $order as $category ) {
			if ( isset( $grouped[ $category ] ) ) {
				$sorted[ $category ] = $grouped[ $category ];
			}
		}

		return $sorted + $grouped;
	}

	/**
	 * Translated category label.
	 *
	 * @param string $category Category slug.
	 */
	public static function category_label( string $category ): string {
		$labels = array(
			'core'          => __( 'Core', 'ffl-checkout-solutions' ),
			'compliance'    => __( 'Compliance', 'ffl-checkout-solutions' ),
			'operations'    => __( 'Operations', 'ffl-checkout-solutions' ),
			'customer'      => __( 'Customer experience', 'ffl-checkout-solutions' ),
			'payments'      => __( 'Payments', 'ffl-checkout-solutions' ),
			'distributors'  => __( 'Distributors', 'ffl-checkout-solutions' ),
			'marketplaces'  => __( 'Marketplaces', 'ffl-checkout-solutions' ),
			'integrations'  => __( 'Integrations', 'ffl-checkout-solutions' ),
			'analytics'     => __( 'Analytics', 'ffl-checkout-solutions' ),
			'notifications' => __( 'Notifications', 'ffl-checkout-solutions' ),
			'security'      => __( 'Security', 'ffl-checkout-solutions' ),
			'design'        => __( 'Design', 'ffl-checkout-solutions' ),
		);

		return $labels[ $category ] ?? ucfirst( $category );
	}

	/**
	 * Handle the Add-ons screen toggle form.
	 */
	public function handle_toggle(): void {
		if ( ! fflcs_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage add-ons.', 'ffl-checkout-solutions' ), 403 );
		}

		check_admin_referer( 'fflcs_toggle_module' );

		$id     = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$active = ! empty( $_POST['active'] );

		$result = self::set_active( $id, $active );

		$redirect = admin_url( 'admin.php?page=fflcs-addons' );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'fflcs_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg( 'fflcs_toggled', $id, $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
