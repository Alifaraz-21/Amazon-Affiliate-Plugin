<?php
/**
 * Plugin activation setup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDAC_Activator {
	/**
	 * Create CRM tables, options, roles, and capabilities.
	 */
	public static function activate() {
		self::create_roles();
		self::create_tables();
		self::create_options();
		self::create_disclosure_page();
		self::create_login_page();
		self::schedule_sync();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'cdac_daily_associates_sync' );
	}

	/**
	 * Keep existing installs aligned when the plugin version changes.
	 */
	public static function maybe_upgrade() {
		if ( version_compare( (string) get_option( 'cdac_db_version', '0.0.0' ), CDAC_VERSION, '<' ) ) {
			self::create_tables();
			self::create_options();
			self::create_disclosure_page();
			self::create_login_page();
			self::schedule_sync();
		}
	}

	public static function run_scheduled_sync() {
		global $wpdb;

		$table = $wpdb->prefix . 'ctrldeals_sync_log';
		$now   = current_time( 'mysql' );
		$has_credentials = get_option( 'cdac_amazon_api_endpoint' ) && get_option( 'cdac_amazon_api_key' ) && get_option( 'cdac_amazon_api_secret' );

		$wpdb->insert(
			$table,
			array(
				'started_at'      => $now,
				'finished_at'     => $now,
				'matched_count'   => 0,
				'unmatched_count' => 0,
				'status'          => $has_credentials ? 'pending' : 'skipped',
				'message'         => $has_credentials ? __( 'Credentials found; report adapter is ready to be connected.', 'ctrldeals-agent-crm' ) : __( 'Amazon Associates report credentials are not connected yet.', 'ctrldeals-agent-crm' ),
			)
		);

		update_option( 'cdac_last_sync_at', $now );
	}

	private static function create_roles() {
		add_role(
			'ctrldeals_agent',
			__( 'CTRDeals Agent', 'ctrldeals-agent-crm' ),
			array(
				'read'                      => true,
				'cdac_manage_own_purchases' => true,
			)
		);

		$administrator = get_role( 'administrator' );

		if ( $administrator ) {
			$administrator->add_cap( 'cdac_manage_all_purchases' );
			$administrator->add_cap( 'cdac_manage_own_purchases' );
		}

		$shop_manager = get_role( 'shop_manager' );

		if ( $shop_manager ) {
			$shop_manager->add_cap( 'cdac_manage_all_purchases' );
			$shop_manager->add_cap( 'cdac_manage_own_purchases' );
		}
	}

	private static function schedule_sync() {
		if ( ! wp_next_scheduled( 'cdac_daily_associates_sync' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'cdac_daily_associates_sync' );
		}
	}

	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$clients_table   = $wpdb->prefix . 'cdac_clients';
		$purchases_table = $wpdb->prefix . 'cdac_purchases';
		$agents_table    = $wpdb->prefix . 'ctrldeals_agents';
		$searches_table  = $wpdb->prefix . 'ctrldeals_searches';
		$clicks_table    = $wpdb->prefix . 'ctrldeals_clicks';
		$sales_table     = $wpdb->prefix . 'ctrldeals_sales';
		$listings_table  = $wpdb->prefix . 'ctrldeals_listings';
		$sync_table      = $wpdb->prefix . 'ctrldeals_sync_log';

		$sql_clients = "CREATE TABLE {$clients_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			phone varchar(60) DEFAULT '',
			email varchar(190) DEFAULT '',
			address text NULL,
			notes text NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_by (created_by),
			KEY email (email)
		) {$charset_collate};";

		$sql_purchases = "CREATE TABLE {$purchases_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id bigint(20) unsigned NOT NULL DEFAULT 0,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			product_title varchar(255) NOT NULL,
			product_url text NULL,
			affiliate_url text NULL,
			product_source varchar(60) NOT NULL DEFAULT 'amazon',
			asin varchar(80) DEFAULT '',
			quantity int(11) NOT NULL DEFAULT 1,
			unit_cost decimal(12,2) NOT NULL DEFAULT 0.00,
			product_cost decimal(12,2) NOT NULL DEFAULT 0.00,
			service_fee decimal(12,2) NOT NULL DEFAULT 0.00,
			shipping_cost decimal(12,2) NOT NULL DEFAULT 0.00,
			tax_cost decimal(12,2) NOT NULL DEFAULT 0.00,
			total_cost decimal(12,2) NOT NULL DEFAULT 0.00,
			currency varchar(10) NOT NULL DEFAULT 'USD',
			amazon_order_id varchar(120) DEFAULT '',
			purchase_status varchar(40) NOT NULL DEFAULT 'pending_purchase',
			payment_status varchar(40) NOT NULL DEFAULT 'unpaid',
			notes text NULL,
			meta longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY client_id (client_id),
			KEY agent_id (agent_id),
			KEY purchase_status (purchase_status),
			KEY payment_status (payment_status)
		) {$charset_collate};";

		$sql_agents = "CREATE TABLE {$agents_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			name varchar(100) NOT NULL,
			email varchar(150) NOT NULL,
			password_hash text NULL,
			tracking_id varchar(50) NOT NULL,
			role varchar(20) NOT NULL DEFAULT 'agent',
			status varchar(20) NOT NULL DEFAULT 'active',
			last_active_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wp_user_id (wp_user_id),
			UNIQUE KEY email (email),
			UNIQUE KEY tracking_id (tracking_id),
			KEY status (status)
		) {$charset_collate};";

		$sql_searches = "CREATE TABLE {$searches_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			query varchar(255) NOT NULL,
			results_count int(11) NOT NULL DEFAULT 0,
			searched_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY agent_id (agent_id),
			KEY searched_at (searched_at)
		) {$charset_collate};";

		$sql_clicks = "CREATE TABLE {$clicks_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			asin varchar(20) NOT NULL,
			product_name varchar(255) DEFAULT '',
			tracking_id varchar(50) NOT NULL,
			generated_url text NOT NULL,
			clicked_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY agent_id (agent_id),
			KEY asin (asin),
			KEY tracking_id (tracking_id),
			KEY clicked_at (clicked_at)
		) {$charset_collate};";

		$sql_sales = "CREATE TABLE {$sales_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			asin varchar(20) NOT NULL,
			product_name varchar(255) DEFAULT '',
			report_key varchar(64) DEFAULT '',
			order_date datetime NOT NULL,
			quantity int(11) NOT NULL DEFAULT 0,
			revenue decimal(12,2) NOT NULL DEFAULT 0.00,
			commission decimal(10,2) NOT NULL DEFAULT 0.00,
			tracking_id varchar(50) DEFAULT '',
			matched_agent_id bigint(20) unsigned DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			synced_at datetime NOT NULL,
			source_name varchar(190) DEFAULT '',
			raw_report longtext NULL,
			last_reported_at datetime NULL,
			PRIMARY KEY  (id),
			KEY report_key (report_key),
			KEY asin (asin),
			KEY tracking_id (tracking_id),
			KEY matched_agent_id (matched_agent_id),
			KEY status (status),
			KEY order_date (order_date)
		) {$charset_collate};";

		$sql_listings = "CREATE TABLE {$listings_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			asin varchar(20) NOT NULL,
			title varchar(255) NOT NULL,
			image_url text NOT NULL,
			category varchar(100) DEFAULT '',
			sale_price decimal(10,2) DEFAULT 0.00,
			original_price decimal(10,2) DEFAULT 0.00,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY asin (asin),
			KEY category (category),
			KEY is_active (is_active)
		) {$charset_collate};";

		$sql_sync = "CREATE TABLE {$sync_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			matched_count int(11) NOT NULL DEFAULT 0,
			unmatched_count int(11) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			message text NULL,
			PRIMARY KEY  (id),
			KEY started_at (started_at),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql_clients );
		dbDelta( $sql_purchases );
		dbDelta( $sql_agents );
		dbDelta( $sql_searches );
		dbDelta( $sql_clicks );
		dbDelta( $sql_sales );
		dbDelta( $sql_listings );
		dbDelta( $sql_sync );

		update_option( 'cdac_db_version', CDAC_VERSION );
	}

	private static function create_options() {
		add_option( 'cdac_currency', 'USD' );
		add_option( 'cdac_default_service_fee', '0.00' );
		add_option( 'cdac_auto_show_product_form', 'no' );
		add_option( 'cdac_amazon_associate_tag', '' );
		add_option( 'cdac_amazon_marketplace', 'https://www.amazon.com' );
		add_option( 'cdac_site_tracking_id', '' );
		add_option( 'cdac_amazon_api_endpoint', '' );
		add_option( 'cdac_amazon_api_key', '' );
		add_option( 'cdac_amazon_api_secret', '' );
		add_option( 'cdac_search_cache_ttl', HOUR_IN_SECONDS );
		add_option( 'cdac_last_sync_at', '' );
		add_option( 'cdac_listings_cache_version', '1' );
	}

	private static function create_disclosure_page() {
		if ( get_page_by_path( 'affiliate-disclosure' ) ) {
			return;
		}

		wp_insert_post(
			array(
				'post_title'   => __( 'Affiliate Disclosure', 'ctrldeals-agent-crm' ),
				'post_name'    => 'affiliate-disclosure',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '[ctrldeals_affiliate_disclosure]',
			)
		);
	}

	private static function create_login_page() {
		if ( get_page_by_path( 'agent-login' ) ) {
			return;
		}

		wp_insert_post(
			array(
				'post_title'   => __( 'Agent Login', 'ctrldeals-agent-crm' ),
				'post_name'    => 'agent-login',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '[ctrldeals_agent_login]',
			)
		);
	}
}
