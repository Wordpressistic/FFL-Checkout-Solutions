<?php
/**
 * Database schema installer and migrator.
 *
 * Every table in Section 5 is created here through dbDelta, which is idempotent
 * — re-running install() on an existing site adds missing tables and columns and
 * leaves existing data alone.
 *
 * SCHEMA_VERSION must be bumped whenever a table or column is added. The
 * bootstrap compares it against the stored fflcs_db_version on every load,
 * because a plugin ZIP upgrade never re-fires register_activation_hook — that
 * comparison is the only path that upgrades an already-activated site.
 *
 * @package FFLCS
 */

namespace FFLCS;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates all plugin tables.
 */
class Installer {

	/**
	 * Schema version. Bump on any DDL change.
	 */
	const SCHEMA_VERSION = '2.0.0';

	/**
	 * Every table this plugin owns, in dependency order.
	 *
	 * Also drives uninstall.php's teardown and the diagnostics screen's
	 * "missing table" check, so it must stay complete.
	 *
	 * @var string[]
	 */
	const TABLES = array(
		'dealers',
		'transfers',
		'events',
		'zip_cache',
		'state_rules',
		'dealer_tokens',
		'analytics_events',
		'signatures',
		'ad_ledger',
		'multi_sale_flags',
		'ffl_verification_documents',
		'ffl_verifications',
		'id_verifications',
		'fraud_scores',
		'regulatory_watch',
		'distributor_products',
		'distributor_orders',
		'webhook_connections',
		'webhook_deliveries',
		'gunbroker_listings',
		'gunbroker_orders',
	);

	/**
	 * Install or upgrade all tables, then seed reference data.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix . FFLCS_DB_PREFIX;

		foreach ( self::schema( $prefix, $collate ) as $sql ) {
			dbDelta( $sql );
		}

		self::seed_state_rules();
		self::ensure_private_upload_dir();

		update_option( 'fflcs_db_version', self::SCHEMA_VERSION );
	}

	/**
	 * All CREATE TABLE statements.
	 *
	 * dbDelta is fussy: two spaces after PRIMARY KEY, one field per line, and
	 * KEY names must be stable across versions or it will try to re-add them.
	 *
	 * @param string $p       Prefixed table stem.
	 * @param string $collate Charset/collation clause.
	 * @return string[]
	 */
	private static function schema( string $p, string $collate ): array {
		$tables = array();

		// ── dealers — ATF FFL licensee database ──────────────────────────────
		$tables[] = "CREATE TABLE {$p}dealers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_number VARCHAR(20) NOT NULL DEFAULT '',
  license_type VARCHAR(10) DEFAULT NULL,
  license_expires DATE DEFAULT NULL,
  business_name VARCHAR(255) NOT NULL DEFAULT '',
  premise_street VARCHAR(255) DEFAULT NULL,
  premise_city VARCHAR(100) DEFAULT NULL,
  premise_state CHAR(2) DEFAULT NULL,
  premise_zip VARCHAR(10) DEFAULT NULL,
  mailing_street VARCHAR(255) DEFAULT NULL,
  mailing_city VARCHAR(100) DEFAULT NULL,
  mailing_state CHAR(2) DEFAULT NULL,
  mailing_zip VARCHAR(10) DEFAULT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  email VARCHAR(190) DEFAULT NULL,
  lat DECIMAL(10,8) DEFAULT NULL,
  lng DECIMAL(11,8) DEFAULT NULL,
  transfer_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_preferred TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  wp_user_id BIGINT UNSIGNED DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  last_synced DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY license_number (license_number),
  KEY idx_state_zip (premise_state, premise_zip),
  KEY idx_geo (lat, lng),
  KEY idx_active (is_active),
  KEY idx_preferred (is_preferred),
  KEY idx_wp_user (wp_user_id)
) {$collate};";

		// ── transfers — one row per physical firearm unit ────────────────────
		// uidx_order_item_unit is load-bearing, not decorative: transfer creation
		// is hooked on two WooCommerce events that fire close together for the
		// same order, so a PHP-side "already exists?" check is check-then-act and
		// races. The unique index makes the database reject the duplicate insert.
		$tables[] = "CREATE TABLE {$p}transfers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  order_item_id BIGINT UNSIGNED DEFAULT NULL,
  order_item_unit INT UNSIGNED NOT NULL DEFAULT 1,
  dealer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  customer_id BIGINT UNSIGNED DEFAULT NULL,
  customer_name VARCHAR(150) DEFAULT NULL,
  customer_email VARCHAR(150) DEFAULT NULL,
  customer_phone VARCHAR(30) DEFAULT NULL,
  customer_ip VARCHAR(45) DEFAULT NULL,
  product_id BIGINT UNSIGNED DEFAULT NULL,
  product_name VARCHAR(255) DEFAULT NULL,
  product_sku VARCHAR(100) DEFAULT NULL,
  product_type VARCHAR(50) DEFAULT NULL,
  manufacturer VARCHAR(100) DEFAULT NULL,
  model VARCHAR(100) DEFAULT NULL,
  caliber VARCHAR(50) DEFAULT NULL,
  serial_number VARCHAR(100) DEFAULT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'dealer_selected',
  payment_status VARCHAR(50) DEFAULT NULL,
  nics_status VARCHAR(50) DEFAULT NULL,
  nics_response TEXT DEFAULT NULL,
  nics_source VARCHAR(50) DEFAULT NULL,
  nics_delay_expires DATE DEFAULT NULL,
  nics_ntn VARCHAR(50) DEFAULT NULL,
  nics_check_date DATE DEFAULT NULL,
  shipped_date DATE DEFAULT NULL,
  received_date DATE DEFAULT NULL,
  transferred_date DATE DEFAULT NULL,
  tracking_number VARCHAR(100) DEFAULT NULL,
  carrier VARCHAR(50) DEFAULT NULL,
  easypost_shipment_id VARCHAR(100) DEFAULT NULL,
  shipping_rates_json LONGTEXT DEFAULT NULL,
  shipping_label_url TEXT DEFAULT NULL,
  booking_appointment_id BIGINT UNSIGNED DEFAULT NULL,
  booking_slot_start DATETIME DEFAULT NULL,
  transfer_ref VARCHAR(50) NOT NULL DEFAULT '',
  form_4473_draft_url TEXT DEFAULT NULL,
  excise_tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_age_restricted TINYINT(1) NOT NULL DEFAULT 0,
  is_ffl_required TINYINT(1) NOT NULL DEFAULT 1,
  staff_notes TEXT DEFAULT NULL,
  source VARCHAR(50) NOT NULL DEFAULT 'woocommerce',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY transfer_ref (transfer_ref),
  UNIQUE KEY uidx_order_item_unit (order_id, order_item_id, order_item_unit),
  KEY idx_order (order_id),
  KEY idx_dealer (dealer_id),
  KEY idx_status (status),
  KEY idx_customer (customer_id),
  KEY idx_nics (nics_status, nics_delay_expires),
  KEY idx_created (created_at)
) {$collate};";

		// ── events — append-only audit log ───────────────────────────────────
		$tables[] = "CREATE TABLE {$p}events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED DEFAULT NULL,
  dealer_id BIGINT UNSIGNED DEFAULT NULL,
  event_type VARCHAR(50) NOT NULL DEFAULT 'status_change',
  event_data LONGTEXT DEFAULT NULL,
  old_status VARCHAR(50) DEFAULT NULL,
  new_status VARCHAR(50) DEFAULT NULL,
  user_id BIGINT UNSIGNED DEFAULT NULL,
  actor VARCHAR(150) NOT NULL DEFAULT 'system',
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_transfer (transfer_id),
  KEY idx_dealer (dealer_id),
  KEY idx_type (event_type),
  KEY idx_created (created_at)
) {$collate};";

		// ── zip_cache — ZIP centroid cache ───────────────────────────────────
		$tables[] = "CREATE TABLE {$p}zip_cache (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  zip VARCHAR(10) NOT NULL,
  lat DECIMAL(10,8) NOT NULL DEFAULT 0,
  lng DECIMAL(11,8) NOT NULL DEFAULT 0,
  city VARCHAR(100) DEFAULT NULL,
  state CHAR(2) DEFAULT NULL,
  country CHAR(2) DEFAULT NULL,
  source VARCHAR(50) NOT NULL DEFAULT 'zippopotam.us',
  queried_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY zip (zip),
  KEY idx_geo (lat, lng)
) {$collate};";

		// ── state_rules — compliance rules ───────────────────────────────────
		$tables[] = "CREATE TABLE {$p}state_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  state CHAR(2) NOT NULL,
  rule_key VARCHAR(50) NOT NULL,
  rule_value LONGTEXT DEFAULT NULL,
  rule_type VARCHAR(30) NOT NULL DEFAULT 'notice',
  item_types VARCHAR(200) NOT NULL DEFAULT '',
  is_blocking TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  source VARCHAR(50) NOT NULL DEFAULT 'seed',
  rule_version VARCHAR(10) NOT NULL DEFAULT '1.0',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY idx_state_key (state, rule_key),
  KEY idx_state (state)
) {$collate};";

		// ── dealer_tokens — portal magic links ───────────────────────────────
		$tables[] = "CREATE TABLE {$p}dealer_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dealer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  token_hash CHAR(64) NOT NULL,
  action VARCHAR(32) NOT NULL DEFAULT 'mark_received',
  token_expires DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  used_action VARCHAR(32) DEFAULT NULL,
  used_ip VARCHAR(45) DEFAULT NULL,
  used_ua VARCHAR(255) DEFAULT NULL,
  two_factor_method VARCHAR(20) NOT NULL DEFAULT 'none',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_reminder_at DATETIME DEFAULT NULL,
  created_ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY idx_transfer (transfer_id),
  KEY idx_dealer (dealer_id),
  KEY idx_expires (token_expires),
  KEY idx_status_expires (status, token_expires)
) {$collate};";

		// ── analytics_events — funnel ────────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}analytics_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED DEFAULT NULL,
  dealer_id BIGINT UNSIGNED DEFAULT NULL,
  token_id BIGINT UNSIGNED DEFAULT NULL,
  event_name VARCHAR(50) NOT NULL DEFAULT '',
  event_value VARCHAR(255) DEFAULT NULL,
  metadata LONGTEXT DEFAULT NULL,
  session_id VARCHAR(64) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_transfer (transfer_id),
  KEY idx_name (event_name),
  KEY idx_created (created_at)
) {$collate};";

		// ── signatures — 4473 captures, append-only ──────────────────────────
		$tables[] = "CREATE TABLE {$p}signatures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  signer_type VARCHAR(20) NOT NULL DEFAULT 'buyer',
  signer_name VARCHAR(200) NOT NULL DEFAULT '',
  signature_data LONGTEXT NOT NULL,
  signed_by BIGINT UNSIGNED DEFAULT NULL,
  signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip_address VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_transfer (transfer_id),
  KEY idx_transfer_role (transfer_id, signer_type)
) {$collate};";

		// ── ad_ledger — bound book (20-year retention) ───────────────────────
		$tables[] = "CREATE TABLE {$p}ad_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dealer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  manufacturer VARCHAR(100) NOT NULL DEFAULT '',
  importer VARCHAR(100) NOT NULL DEFAULT '',
  model VARCHAR(100) NOT NULL DEFAULT '',
  serial_number VARCHAR(100) NOT NULL DEFAULT '',
  item_type VARCHAR(50) NOT NULL DEFAULT 'handgun',
  caliber_gauge VARCHAR(50) NOT NULL DEFAULT '',
  acquisition_date DATE DEFAULT NULL,
  acquired_from VARCHAR(255) NOT NULL DEFAULT '',
  acquisition_type VARCHAR(40) NOT NULL DEFAULT 'transfer_in',
  disposition_date DATE DEFAULT NULL,
  disposed_to_name VARCHAR(200) NOT NULL DEFAULT '',
  disposed_to_type VARCHAR(40) NOT NULL DEFAULT 'individual_over_counter',
  form_4473_ref VARCHAR(100) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'in_inventory',
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_transfer (transfer_id),
  KEY idx_dealer (dealer_id),
  KEY idx_status (status),
  KEY idx_serial (serial_number)
) {$collate};";

		// ── multi_sale_flags — Form 3310.4 detection ─────────────────────────
		$tables[] = "CREATE TABLE {$p}multi_sale_flags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_email VARCHAR(200) NOT NULL DEFAULT '',
  customer_id BIGINT UNSIGNED DEFAULT NULL,
  transfer_ids VARCHAR(255) NOT NULL DEFAULT '',
  window_start DATE DEFAULT NULL,
  window_end DATE DEFAULT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending_review',
  filed_at DATETIME DEFAULT NULL,
  filed_by BIGINT UNSIGNED DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_email (customer_email),
  KEY idx_customer (customer_id),
  KEY idx_status (status)
) {$collate};";

		// ── verification hub ─────────────────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}ffl_verification_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dealer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  transfer_id BIGINT UNSIGNED DEFAULT NULL,
  document_type VARCHAR(80) NOT NULL DEFAULT 'certified_copy',
  file_path VARCHAR(255) NOT NULL DEFAULT '',
  file_name VARCHAR(255) NOT NULL DEFAULT '',
  mime_type VARCHAR(100) NOT NULL DEFAULT '',
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by BIGINT UNSIGNED DEFAULT NULL,
  received_method VARCHAR(80) NOT NULL DEFAULT 'staff_upload',
  expires_at DATE DEFAULT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'active',
  reminder_60_sent_at DATETIME DEFAULT NULL,
  reminder_30_sent_at DATETIME DEFAULT NULL,
  reminder_7_sent_at DATETIME DEFAULT NULL,
  reminder_0_sent_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_dealer (dealer_id),
  KEY idx_transfer (transfer_id),
  KEY idx_status (status),
  KEY idx_expires (expires_at)
) {$collate};";

		$tables[] = "CREATE TABLE {$p}ffl_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dealer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  transfer_id BIGINT UNSIGNED DEFAULT NULL,
  method VARCHAR(40) NOT NULL DEFAULT 'address_match',
  result_status VARCHAR(40) NOT NULL DEFAULT 'manual_review_required',
  checked_ffl_number VARCHAR(50) NOT NULL DEFAULT '',
  result_legal_name VARCHAR(255) NOT NULL DEFAULT '',
  result_trade_name VARCHAR(255) NOT NULL DEFAULT '',
  result_premises_address TEXT DEFAULT NULL,
  result_expiration_date DATE DEFAULT NULL,
  address_match TINYINT(1) DEFAULT NULL,
  expiration_valid TINYINT(1) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  verified_by BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_dealer (dealer_id),
  KEY idx_transfer (transfer_id),
  KEY idx_method (method),
  KEY idx_status (result_status)
) {$collate};";

		// ── id_verifications ─────────────────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}id_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED DEFAULT NULL,
  order_id BIGINT UNSIGNED DEFAULT NULL,
  provider VARCHAR(40) NOT NULL DEFAULT 'manual',
  provider_reference VARCHAR(120) NOT NULL DEFAULT '',
  ship_to_state VARCHAR(2) NOT NULL DEFAULT '',
  document_path VARCHAR(255) NOT NULL DEFAULT '',
  result_status VARCHAR(30) NOT NULL DEFAULT 'pending',
  result_age_over_21 TINYINT(1) DEFAULT NULL,
  reviewed_by BIGINT UNSIGNED DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_transfer (transfer_id),
  KEY idx_order (order_id),
  KEY idx_status (result_status)
) {$collate};";

		// ── fraud_scores ─────────────────────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}fraud_scores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  customer_email VARCHAR(200) NOT NULL DEFAULT '',
  score INT NOT NULL DEFAULT 0,
  risk_level VARCHAR(20) NOT NULL DEFAULT 'low',
  signals_json LONGTEXT DEFAULT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'open',
  reviewed_by BIGINT UNSIGNED DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  review_notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_transfer (transfer_id),
  KEY idx_score (score),
  KEY idx_status (status),
  KEY idx_email (customer_email)
) {$collate};";

		// ── regulatory_watch ─────────────────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}regulatory_watch (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_number VARCHAR(50) NOT NULL DEFAULT '',
  title VARCHAR(500) NOT NULL DEFAULT '',
  agency VARCHAR(200) NOT NULL DEFAULT '',
  document_type VARCHAR(60) NOT NULL DEFAULT '',
  matched_term VARCHAR(100) NOT NULL DEFAULT '',
  publication_date DATE DEFAULT NULL,
  html_url VARCHAR(500) NOT NULL DEFAULT '',
  notified_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_document (document_number),
  KEY idx_publication (publication_date)
) {$collate};";

		// ── distributor catalog + orders ─────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}distributor_products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  distributor VARCHAR(30) NOT NULL DEFAULT '',
  item_no VARCHAR(50) NOT NULL DEFAULT '',
  description VARCHAR(500) NOT NULL DEFAULT '',
  manufacturer VARCHAR(150) NOT NULL DEFAULT '',
  model VARCHAR(150) NOT NULL DEFAULT '',
  upc VARCHAR(50) NOT NULL DEFAULT '',
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  msrp DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  quantity INT NOT NULL DEFAULT 0,
  is_firearm TINYINT(1) NOT NULL DEFAULT 0,
  last_synced DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_distributor_item (distributor, item_no),
  KEY idx_upc (upc),
  KEY idx_distributor (distributor)
) {$collate};";

		$tables[] = "CREATE TABLE {$p}distributor_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  distributor VARCHAR(30) NOT NULL DEFAULT '',
  transfer_id BIGINT UNSIGNED DEFAULT NULL,
  po_number VARCHAR(60) NOT NULL DEFAULT '',
  item_no VARCHAR(50) NOT NULL DEFAULT '',
  quantity INT NOT NULL DEFAULT 1,
  ship_to_ffl VARCHAR(20) NOT NULL DEFAULT '',
  status VARCHAR(30) NOT NULL DEFAULT 'submitted',
  distributor_order_ref VARCHAR(100) NOT NULL DEFAULT '',
  response_json LONGTEXT DEFAULT NULL,
  submitted_by BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_transfer (transfer_id),
  KEY idx_status (status),
  KEY idx_distributor (distributor)
) {$collate};";

		// ── outbound webhooks ────────────────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}webhook_connections (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  label VARCHAR(150) NOT NULL DEFAULT '',
  target_url TEXT NOT NULL,
  secret TEXT DEFAULT NULL,
  events VARCHAR(500) NOT NULL DEFAULT '*',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_active (is_active)
) {$collate};";

		$tables[] = "CREATE TABLE {$p}webhook_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  event_name VARCHAR(60) NOT NULL DEFAULT '',
  payload LONGTEXT DEFAULT NULL,
  response_code INT NOT NULL DEFAULT 0,
  response_body TEXT DEFAULT NULL,
  attempt TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  next_retry_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_connection (connection_id),
  KEY idx_status_retry (status, next_retry_at)
) {$collate};";

		// ── GunBroker marketplace ────────────────────────────────────────────
		$tables[] = "CREATE TABLE {$p}gunbroker_listings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  gunbroker_item_id VARCHAR(30) DEFAULT NULL,
  category_id VARCHAR(20) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  last_error TEXT DEFAULT NULL,
  last_synced DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_product (product_id),
  UNIQUE KEY uidx_gunbroker_item (gunbroker_item_id),
  KEY idx_status (status)
) {$collate};";

		$tables[] = "CREATE TABLE {$p}gunbroker_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  gunbroker_order_id VARCHAR(40) NOT NULL DEFAULT '',
  wc_order_id BIGINT UNSIGNED DEFAULT NULL,
  ffl_license_number VARCHAR(50) NOT NULL DEFAULT '',
  dealer_id BIGINT UNSIGNED DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'needs_dealer',
  raw_json LONGTEXT DEFAULT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_gunbroker_order (gunbroker_order_id),
  KEY idx_status (status)
) {$collate};";

		return $tables;
	}

	// ── Seed data ────────────────────────────────────────────────────────────

	/**
	 * Seed state compliance rules for all 50 states + DC.
	 *
	 * Only inserts rows that do not already exist (the unique key on
	 * state+rule_key makes this safe to re-run), so an admin edit is never
	 * clobbered by a later upgrade re-running the seeder.
	 */
	public static function seed_state_rules(): void {
		global $wpdb;

		$table = fflcs_table( 'state_rules' );

		/**
		 * Filter the built-in state-rules seed.
		 *
		 * Each row: [ state, rule_key, rule_value, rule_type, is_blocking, item_types ].
		 *
		 * @param array<int,array> $rules Seed rows.
		 */
		$rules = (array) apply_filters( 'fflcs_state_rules_seed', self::default_state_rules() );

		/**
		 * Filter additional state rules appended after the main seed.
		 *
		 * Kept separate so a site can add rules without having to reproduce the
		 * entire built-in set to avoid losing it.
		 *
		 * @param array<int,array> $topup Additional rows.
		 */
		$topup = (array) apply_filters( 'fflcs_state_rules_seed_topup', array() );

		$version = (string) get_option( 'fflcs_state_rules_version', '1.0' );
		$now     = fflcs_mysql_now();

		foreach ( array_merge( $rules, $topup ) as $rule ) {
			if ( count( $rule ) < 3 ) {
				continue;
			}

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a constant-derived helper.
					"SELECT id FROM {$table} WHERE state = %s AND rule_key = %s LIMIT 1",
					$rule[0],
					$rule[1]
				)
			);
			if ( $exists ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'state'        => $rule[0],
					'rule_key'     => $rule[1],
					'rule_value'   => $rule[2],
					'rule_type'    => $rule[3] ?? 'notice',
					'is_blocking'  => (int) ( $rule[4] ?? 0 ),
					'item_types'   => $rule[5] ?? '',
					'is_active'    => 1,
					'source'       => 'seed',
					'rule_version' => $version,
					'created_at'   => $now,
					'updated_at'   => $now,
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Built-in compliance notices.
	 *
	 * Every state and DC gets at least a baseline row so the compliance panel is
	 * never blank for a buyer, and states with well-known additional
	 * requirements get a specific notice.
	 *
	 * These are informational defaults for staff and buyers — see
	 * docs/LEGAL-DISCLAIMER.md. Firearms law changes frequently; the admin CRUD
	 * screen exists precisely so a dealer maintains these against their own
	 * counsel's advice rather than trusting a plugin's shipped defaults.
	 *
	 * @return array<int,array{0:string,1:string,2:string,3:string,4:int,5:string}>
	 */
	private static function default_state_rules(): array {
		$baseline = __( 'Federal law requires this transfer to complete through a licensed FFL dealer, including a NICS background check. Confirm any additional state and local requirements with your dealer.', 'ffl-checkout-solutions' );

		$specific = array(
			array( 'CA', 'waiting_period', 'California: 10-day mandatory waiting period on all firearm transfers.', 'waiting_period', 0, 'handgun,rifle,shotgun,other' ),
			array( 'CA', 'handgun_roster', 'California: handguns must appear on the DOJ Roster of Certified Handguns for most civilian sales.', 'roster', 0, 'handgun' ),
			array( 'CA', 'permit_required', 'California: a valid Firearm Safety Certificate is required to take delivery.', 'permit', 0, 'handgun,rifle,shotgun,other' ),
			array( 'NY', 'permit_required', 'New York: a valid pistol licence is required before a handgun may be transferred.', 'permit', 0, 'handgun' ),
			array( 'NY', 'assault_weapon', 'New York: SAFE Act restrictions apply to certain semi-automatic rifles and magazines.', 'restriction', 0, 'rifle' ),
			array( 'NJ', 'permit_required', 'New Jersey: a Firearms Purchaser Identification Card is required for long guns; a separate permit is required per handgun.', 'permit', 0, 'handgun,rifle,shotgun' ),
			array( 'MA', 'license_required', 'Massachusetts: an FID Card or License to Carry is required to take delivery.', 'permit', 0, 'handgun,rifle,shotgun,other' ),
			array( 'MA', 'handgun_roster', 'Massachusetts: handguns must comply with the Attorney General regulations and the approved firearms roster.', 'roster', 0, 'handgun' ),
			array( 'MD', 'waiting_period', 'Maryland: regulated firearms require a 7-day waiting period and a completed 77R application.', 'waiting_period', 0, 'handgun' ),
			array( 'IL', 'permit_required', 'Illinois: a valid FOID card is required, and a 72-hour waiting period applies.', 'permit', 0, 'handgun,rifle,shotgun,other' ),
			array( 'HI', 'permit_required', 'Hawaii: a permit to acquire is required, with a 14-day waiting period.', 'permit', 0, 'handgun,rifle,shotgun,other' ),
			array( 'CT', 'permit_required', 'Connecticut: an eligibility certificate or permit is required to take delivery.', 'permit', 0, 'handgun,rifle,shotgun,other' ),
			array( 'RI', 'waiting_period', 'Rhode Island: a 7-day waiting period applies to firearm purchases.', 'waiting_period', 0, 'handgun,rifle,shotgun,other' ),
			array( 'WA', 'waiting_period', 'Washington: a 10-business-day waiting period applies, and semi-automatic rifle purchases require a certified safety training course.', 'waiting_period', 0, 'handgun,rifle,shotgun,other' ),
			array( 'CO', 'waiting_period', 'Colorado: a waiting period applies between purchase and delivery.', 'waiting_period', 0, 'handgun,rifle,shotgun,other' ),
			array( 'DC', 'permit_required', 'District of Columbia: firearms must be registered with MPD, and transfers run through the sole licensed DC dealer.', 'permit', 0, 'handgun,rifle,shotgun,other' ),
			array( 'MN', 'permit_required', 'Minnesota: a transferee permit or carry permit is required for handguns and certain semi-automatic rifles.', 'permit', 0, 'handgun,rifle' ),
			array( 'IA', 'permit_required', 'Iowa: verify current permit-to-acquire requirements with your dealer.', 'permit', 0, 'handgun' ),
			array( 'NE', 'permit_required', 'Nebraska: a handgun purchase certificate or valid concealed handgun permit is required.', 'permit', 0, 'handgun' ),
			array( 'NC', 'permit_required', 'North Carolina: verify current pistol purchase permit requirements with your dealer.', 'permit', 0, 'handgun' ),
			array( 'MI', 'permit_required', 'Michigan: a pistol purchase licence or concealed pistol licence may be required, plus a pistol sales record.', 'permit', 0, 'handgun' ),
			array( 'OR', 'background_check', 'Oregon: transfers may not complete until the state police issue a determination.', 'notice', 0, 'handgun,rifle,shotgun,other' ),
			array( 'NM', 'background_check', 'New Mexico: a waiting period applies to firearm transfers.', 'waiting_period', 0, 'handgun,rifle,shotgun,other' ),
			array( 'VT', 'background_check', 'Vermont: magazine capacity limits apply to firearm transfers.', 'restriction', 0, 'handgun,rifle' ),
			array( 'DE', 'waiting_period', 'Delaware: a waiting period applies between purchase and delivery.', 'waiting_period', 0, 'handgun,rifle,shotgun,other' ),
			array( 'FL', 'waiting_period', 'Florida: a 3-day waiting period applies to firearm purchases, with limited exemptions.', 'waiting_period', 0, 'handgun,rifle,shotgun,other' ),
		);

		$rows = $specific;

		foreach ( fflcs_us_states() as $state ) {
			$rows[] = array( $state, 'federal_baseline', $baseline, 'notice', 0, 'handgun,rifle,shotgun,other' );
		}

		return $rows;
	}

	/**
	 * Create the private upload directory used for certified copies and ID
	 * documents, with a deny rule and an index stub.
	 *
	 * Both guards matter: .htaccess covers Apache, and the empty index.php plus
	 * unguessable filenames limit exposure on nginx, where .htaccess is ignored.
	 */
	public static function ensure_private_upload_dir(): void {
		$dir = self::private_dir();

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" );
		}

		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Absolute path of the private upload directory.
	 */
	public static function private_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'fflcs-private/';
	}

	/**
	 * Drop every plugin table. Called only from uninstall.php.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		foreach ( array_reverse( self::TABLES ) as $table ) {
			$name = fflcs_table( $table );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
		}
	}

	/**
	 * Tables that exist versus tables that should. Feeds the diagnostics screen.
	 *
	 * @return array{present:string[],missing:string[]}
	 */
	public static function table_status(): array {
		global $wpdb;

		$present = array();
		$missing = array();

		foreach ( self::TABLES as $table ) {
			$name  = fflcs_table( $table );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
			if ( $found === $name ) {
				$present[] = $table;
			} else {
				$missing[] = $table;
			}
		}

		return compact( 'present', 'missing' );
	}
}
