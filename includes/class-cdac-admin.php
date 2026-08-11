<?php
/**
 * Private admin and agent screens for CtrlDeals.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDAC_Admin {
	private $repository;

	public function __construct( CDAC_Repository $repository ) {
		$this->repository = $repository;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_cdac_create_agent', array( $this, 'handle_create_agent' ) );
		add_action( 'admin_post_cdac_deactivate_agent', array( $this, 'handle_deactivate_agent' ) );
		add_action( 'admin_post_cdac_save_listing', array( $this, 'handle_save_listing' ) );
		add_action( 'admin_post_cdac_generate_cart_url', array( $this, 'handle_generate_cart_url' ) );
		add_action( 'admin_post_cdac_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_cdac_manual_sync', array( $this, 'handle_manual_sync' ) );
		add_action( 'admin_post_cdac_upload_report', array( $this, 'handle_upload_report' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'CtrlDeals', 'ctrldeals-agent-crm' ),
			__( 'CtrlDeals', 'ctrldeals-agent-crm' ),
			'read',
			'ctrldeals-agent-crm',
			array( $this, 'render_app' ),
			'dashicons-cart',
			26
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'ctrldeals-agent-crm' ) ) {
			return;
		}

		wp_enqueue_style( 'cdac-fonts', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap', array(), null );
		wp_enqueue_style( 'cdac-admin', CDAC_URL . 'assets/css/admin.css', array( 'cdac-fonts' ), CDAC_VERSION );
		wp_enqueue_script( 'cdac-admin', CDAC_URL . 'assets/js/admin.js', array(), CDAC_VERSION, true );
	}

	public function render_app() {
		if ( ! $this->can_access_private_area() ) {
			wp_die( esc_html__( 'You do not have permission to access CtrlDeals.', 'ctrldeals-agent-crm' ) );
		}

		$is_admin = current_user_can( 'cdac_manage_all_purchases' );
		$tab      = sanitize_key( $_GET['tab'] ?? ( $is_admin ? 'admin-dashboard' : 'agent-dashboard' ) );
		$tabs     = $is_admin ? $this->admin_tabs() : $this->agent_tabs();

		if ( ! isset( $tabs[ $tab ] ) ) {
			$keys = array_keys( $tabs );
			$tab  = $keys[0];
		}

		echo '<div class="wrap cdac-admin">';
		echo '<div class="cdac-hero"><div class="cdac-hero-copy">';
		echo '<div class="cdac-brand-line"><span class="cdac-brand-mark">' . esc_html__( 'Ctrl', 'ctrldeals-agent-crm' ) . '</span><span class="cdac-eyebrow">' . esc_html__( 'Deals.com affiliate desk', 'ctrldeals-agent-crm' ) . '</span></div>';
		echo '<h1>' . esc_html__( 'CtrlDeals Affiliate Operations', 'ctrldeals-agent-crm' ) . '</h1>';
		echo '<p>' . esc_html__( 'Search products, generate tracking-ID-specific Amazon Add to Cart URLs, log activity, and monitor sales attribution.', 'ctrldeals-agent-crm' ) . '</p>';
		echo '</div><a class="cdac-button cdac-button-primary" href="' . esc_url( $this->tab_url( $is_admin ? 'listings' : 'search' ) ) . '">' . esc_html( $is_admin ? __( 'Manage Listings', 'ctrldeals-agent-crm' ) : __( 'Search Products', 'ctrldeals-agent-crm' ) ) . '</a></div>';

		$this->render_notice();
		$this->render_generated_url();
		$this->render_tabs( $tabs, $tab );

		echo '<main class="cdac-panel">';

		switch ( $tab ) {
			case 'search':
				$this->render_agent_search();
				break;
			case 'deals':
				$this->render_agent_deals();
				break;
			case 'activity':
				$this->render_activity_log();
				break;
			case 'agents':
				$this->render_manage_agents();
				break;
			case 'sales':
				$this->render_sales_report();
				break;
			case 'listings':
				$this->render_manage_listings();
				break;
			case 'sync':
				$this->render_sync_status();
				break;
			case 'settings':
				$this->render_settings();
				break;
			case 'admin-dashboard':
				$this->render_admin_dashboard();
				break;
			default:
				$this->render_agent_dashboard();
				break;
		}

		echo '</main></div>';
	}

	public function handle_create_agent() {
		$this->require_admin();
		check_admin_referer( 'cdac_create_agent' );

		$result = $this->repository->create_agent(
			array(
				'display_name' => wp_unslash( $_POST['display_name'] ?? '' ),
				'username'     => wp_unslash( $_POST['username'] ?? '' ),
				'email'        => wp_unslash( $_POST['email'] ?? '' ),
				'phone'        => wp_unslash( $_POST['phone'] ?? '' ),
				'password'     => wp_unslash( $_POST['password'] ?? '' ),
				'tracking_id'  => wp_unslash( $_POST['tracking_id'] ?? '' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'agents', 'error', $result->get_error_message() );
		}

		$this->redirect( 'agents', 'agent_created' );
	}

	public function handle_deactivate_agent() {
		$this->require_admin();
		$agent_id = absint( $_POST['agent_id'] ?? 0 );
		check_admin_referer( 'cdac_deactivate_agent_' . $agent_id );

		$this->repository->deactivate_agent( $agent_id );
		$this->redirect( 'agents', 'agent_deactivated' );
	}

	public function handle_save_listing() {
		$this->require_admin();
		check_admin_referer( 'cdac_save_listing' );

		$asin = sanitize_text_field( wp_unslash( $_POST['asin'] ?? '' ) );

		if ( ! $asin ) {
			$this->redirect( 'listings', 'error', __( 'ASIN is required before a product can be listed.', 'ctrldeals-agent-crm' ) );
		}

		$this->repository->create_listing(
			array(
				'asin'           => $asin,
				'title'          => wp_unslash( $_POST['title'] ?? '' ),
				'image_url'      => wp_unslash( $_POST['image_url'] ?? '' ),
				'category'       => wp_unslash( $_POST['category'] ?? '' ),
				'sale_price'     => wp_unslash( $_POST['sale_price'] ?? 0 ),
				'original_price' => wp_unslash( $_POST['original_price'] ?? 0 ),
				'is_active'      => isset( $_POST['is_active'] ),
			)
		);

		$this->redirect( 'listings', 'listing_saved' );
	}

	public function handle_generate_cart_url() {
		$this->require_private_access();
		check_admin_referer( 'cdac_generate_cart_url' );

		$asin         = sanitize_text_field( wp_unslash( $_POST['asin'] ?? '' ) );
		$product_name = sanitize_text_field( wp_unslash( $_POST['product_name'] ?? '' ) );
		$quantity     = absint( $_POST['quantity'] ?? 1 );
		$agent        = $this->current_agent();
		$tracking_id  = $agent ? $agent->tracking_id : get_option( 'cdac_site_tracking_id', '' );
		$url          = $this->repository->generate_add_to_cart_url( $tracking_id, $asin, $quantity );

		if ( ! $url ) {
			$this->redirect( sanitize_key( $_POST['return_tab'] ?? 'search' ), 'error', __( 'Unable to generate URL. Check ASIN and tracking ID.', 'ctrldeals-agent-crm' ) );
		}

		if ( $agent && ! $this->repository->log_click( $agent->id, $asin, $product_name, $tracking_id, $url ) ) {
			error_log( 'CtrlDeals: failed to log generated Add to Cart URL.' );
		}

		set_transient(
			'cdac_generated_url_' . get_current_user_id(),
			array(
				'url'          => $url,
				'product_name' => $product_name,
				'asin'         => $asin,
			),
			MINUTE_IN_SECONDS * 10
		);

		$this->redirect( sanitize_key( $_POST['return_tab'] ?? 'activity' ), 'url_generated' );
	}

	public function handle_save_settings() {
		$this->require_admin();
		check_admin_referer( 'cdac_save_settings' );

		update_option( 'cdac_site_tracking_id', sanitize_text_field( wp_unslash( $_POST['site_tracking_id'] ?? '' ) ) );
		update_option( 'cdac_amazon_marketplace', esc_url_raw( wp_unslash( $_POST['amazon_marketplace'] ?? 'https://www.amazon.com' ) ) );
		update_option( 'cdac_amazon_api_endpoint', esc_url_raw( wp_unslash( $_POST['amazon_api_endpoint'] ?? '' ) ) );
		update_option( 'cdac_amazon_api_key', sanitize_text_field( wp_unslash( $_POST['amazon_api_key'] ?? '' ) ) );
		update_option( 'cdac_amazon_api_secret', sanitize_text_field( wp_unslash( $_POST['amazon_api_secret'] ?? '' ) ) );
		update_option( 'cdac_search_cache_ttl', max( HOUR_IN_SECONDS, absint( $_POST['search_cache_ttl'] ?? HOUR_IN_SECONDS ) ) );

		$this->redirect( 'settings', 'settings_saved' );
	}

	public function handle_manual_sync() {
		$this->require_admin();
		check_admin_referer( 'cdac_manual_sync' );
		global $wpdb;

		$now = current_time( 'mysql' );
		$wpdb->insert(
			$this->repository->sync_table(),
			array(
				'started_at'      => $now,
				'finished_at'     => $now,
				'matched_count'   => 0,
				'unmatched_count' => 0,
				'status'          => 'skipped',
				'message'         => __( 'Amazon Associates report credentials are not connected yet.', 'ctrldeals-agent-crm' ),
			)
		);
		update_option( 'cdac_last_sync_at', $now );

		$this->redirect( 'sync', 'sync_logged' );
	}

	public function handle_upload_report() {
		$this->require_admin();
		check_admin_referer( 'cdac_upload_report' );

		$file = $_FILES['associates_report'] ?? null;

		if ( ! $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$this->redirect( 'sync', 'error', __( 'Please choose a valid Amazon Associates report file.', 'ctrldeals-agent-crm' ) );
		}

		$name      = sanitize_file_name( $file['name'] ?? '' );
		$tmp_name  = $file['tmp_name'] ?? '';
		$file_size = absint( $file['size'] ?? 0 );
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			$this->redirect( 'sync', 'error', __( 'The uploaded report could not be verified.', 'ctrldeals-agent-crm' ) );
		}

		if ( ! in_array( $extension, array( 'csv', 'tsv', 'txt' ), true ) ) {
			$this->redirect( 'sync', 'error', __( 'Upload a CSV, TSV, or TXT export from Amazon Associates.', 'ctrldeals-agent-crm' ) );
		}

		if ( $file_size > 5 * MB_IN_BYTES ) {
			$this->redirect( 'sync', 'error', __( 'The report file is too large. Please upload a file under 5 MB.', 'ctrldeals-agent-crm' ) );
		}

		$parsed = $this->parse_report_file( $tmp_name, $extension );

		if ( is_wp_error( $parsed ) ) {
			$this->redirect( 'sync', 'error', $parsed->get_error_message() );
		}

		$result = $this->repository->import_sales_report( $parsed['rows'], $name );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'sync', 'error', $result->get_error_message() );
		}

		$message = sprintf(
			/* translators: 1: inserted rows, 2: updated rows, 3: matched rows, 4: unmatched rows, 5: skipped rows. */
			__( 'Report imported: %1$d inserted, %2$d updated, %3$d matched, %4$d unmatched, %5$d skipped.', 'ctrldeals-agent-crm' ),
			$result['inserted'],
			$result['updated'],
			$result['matched'],
			$result['unmatched'],
			$result['skipped'] + (int) ( $parsed['skipped'] ?? 0 )
		);

		set_transient( 'cdac_report_import_' . get_current_user_id(), $message, MINUTE_IN_SECONDS * 5 );
		$this->redirect( 'sync', 'report_imported' );
	}

	private function render_admin_dashboard() {
		$agents = $this->repository->get_agent_profiles();
		$stats  = $this->repository->get_activity_stats();
		$active_agents = 0;

		foreach ( $agents as $agent ) {
			if ( 'active' === $agent->status ) {
				$active_agents++;
			}
		}

		echo '<section class="cdac-grid cdac-stats">';
		$this->stat_card( __( 'Total Agents Active', 'ctrldeals-agent-crm' ), $active_agents );
		$this->stat_card( __( 'Searches This Month', 'ctrldeals-agent-crm' ), $stats['searches'] );
		$this->stat_card( __( 'URLs Generated This Month', 'ctrldeals-agent-crm' ), $stats['clicks'] );
		$this->stat_card( __( 'Confirmed Sales', 'ctrldeals-agent-crm' ), $stats['confirmed_sales'] );
		$this->stat_card( __( 'Commission', 'ctrldeals-agent-crm' ), $this->money( $stats['commission'] ) );
		$this->stat_card( __( '10-Sale Progress', 'ctrldeals-agent-crm' ), min( 10, $stats['confirmed_sales'] ) . '/10' );
		echo '</section>';

		echo '<section class="cdac-section"><div class="cdac-section-head"><h2>' . esc_html__( 'Agent Monthly Stats', 'ctrldeals-agent-crm' ) . '</h2><span class="cdac-muted">' . esc_html__( 'Current month', 'ctrldeals-agent-crm' ) . '</span></div>';
		echo '<div class="cdac-table-wrap"><table class="widefat striped cdac-table"><thead><tr><th>' . esc_html__( 'Agent', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Tracking ID', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Searches', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'URLs', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Sales', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Commission', 'ctrldeals-agent-crm' ) . '</th></tr></thead><tbody>';

		foreach ( $agents as $agent ) {
			$row_stats = $this->repository->get_activity_stats( $agent->id );
			echo '<tr><td><strong>' . esc_html( $agent->name ) . '</strong><span class="cdac-muted">' . esc_html( $agent->email ) . '</span></td><td>' . esc_html( $agent->tracking_id ) . '</td><td>' . esc_html( $row_stats['searches'] ) . '</td><td>' . esc_html( $row_stats['clicks'] ) . '</td><td>' . esc_html( $row_stats['confirmed_sales'] ) . '</td><td>' . esc_html( $this->money( $row_stats['commission'] ) ) . '</td></tr>';
		}

		echo '</tbody></table></div></section>';
	}

	private function render_agent_dashboard() {
		$agent = $this->current_agent();

		if ( ! $agent ) {
			echo '<section class="cdac-section"><h2>' . esc_html__( 'Agent profile missing', 'ctrldeals-agent-crm' ) . '</h2></section>';
			return;
		}

		$stats = $this->repository->get_activity_stats( $agent->id );
		echo '<section class="cdac-section"><h2>' . sprintf( esc_html__( 'Welcome, %s', 'ctrldeals-agent-crm' ), esc_html( $agent->name ) ) . '</h2><p class="cdac-muted">' . esc_html__( 'Use product search or listed deals to generate Add to Cart URLs for client browser profiles.', 'ctrldeals-agent-crm' ) . '</p></section>';
		echo '<section class="cdac-grid cdac-stats">';
		$this->stat_card( __( 'Total Searches', 'ctrldeals-agent-crm' ), $stats['searches'] );
		$this->stat_card( __( 'Add to Cart URLs', 'ctrldeals-agent-crm' ), $stats['clicks'] );
		$this->stat_card( __( 'Confirmed Sales', 'ctrldeals-agent-crm' ), $stats['confirmed_sales'] );
		$this->stat_card( __( 'Estimated Commission', 'ctrldeals-agent-crm' ), $this->money( $stats['commission'] ) );
		echo '</section>';
	}

	private function render_agent_search() {
		$agent   = $this->current_agent();
		$query   = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
		$results = array();
		$search_was_run = false;
		$search_requested = '1' === sanitize_text_field( wp_unslash( $_GET['cdac_run_search'] ?? '' ) );

		echo '<section class="cdac-section"><div class="cdac-section-head"><h2>' . esc_html__( 'Product Search', 'ctrldeals-agent-crm' ) . '</h2><span class="cdac-muted">' . esc_html__( 'Type the full phrase, then press Search or Enter.', 'ctrldeals-agent-crm' ) . '</span></div>';
		echo '<form class="cdac-filters" method="get"><input type="hidden" name="page" value="ctrldeals-agent-crm"><input type="hidden" name="tab" value="search"><input type="hidden" name="cdac_run_search" value="1">';
		wp_nonce_field( 'cdac_search', 'cdac_search_nonce', false );
		echo '<input type="search" name="q" value="' . esc_attr( $query ) . '" autocomplete="off" placeholder="' . esc_attr__( 'Search product title, ASIN, category...', 'ctrldeals-agent-crm' ) . '"><button class="cdac-button cdac-button-primary" type="submit">' . esc_html__( 'Search', 'ctrldeals-agent-crm' ) . '</button></form>';

		if ( $query && $search_requested ) {
			$nonce_ok = isset( $_GET['cdac_search_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['cdac_search_nonce'] ) ), 'cdac_search' );

			if ( ! $nonce_ok ) {
				echo '<div class="cdac-notice cdac-notice-error">' . esc_html__( 'Search could not be verified. Please try again.', 'ctrldeals-agent-crm' ) . '</div>';
				$this->render_product_grid( $results, 'search' );
				echo '</section>';
				return;
			}

			$cooldown_key = $agent ? 'cdac_search_cooldown_' . $agent->id : '';

			if ( $cooldown_key && get_transient( $cooldown_key ) ) {
				echo '<div class="cdac-notice cdac-notice-error">' . esc_html__( 'Please wait at least 1 second between searches.', 'ctrldeals-agent-crm' ) . '</div>';
			} else {
				if ( $cooldown_key ) {
					set_transient( $cooldown_key, true, 1 );
				}

				$results = $this->repository->search_products( $query );
				$search_was_run = true;
			}

			if ( $search_was_run && $agent ) {
				$this->repository->log_search( $agent->id, $query, count( $results ) );
			}
		}

		$this->render_product_grid( $results, 'search' );
		echo '</section>';
	}

	private function render_agent_deals() {
		$category = sanitize_text_field( wp_unslash( $_GET['category'] ?? '' ) );
		$listings = $this->repository->get_listings(
			array(
				'active'   => true,
				'category' => $category,
			)
		);

		echo '<section class="cdac-section"><div class="cdac-section-head"><h2>' . esc_html__( 'Listed Deals', 'ctrldeals-agent-crm' ) . '</h2><span class="cdac-muted">' . esc_html__( 'Products already visible on ctrldeals.com', 'ctrldeals-agent-crm' ) . '</span></div>';
		echo '<form class="cdac-filters" method="get"><input type="hidden" name="page" value="ctrldeals-agent-crm"><input type="hidden" name="tab" value="deals"><select name="category"><option value="">' . esc_html__( 'All categories', 'ctrldeals-agent-crm' ) . '</option>';
		foreach ( $this->repository->get_listing_categories() as $item ) {
			echo '<option value="' . esc_attr( $item ) . '" ' . selected( $category, $item, false ) . '>' . esc_html( $item ) . '</option>';
		}
		echo '</select><button class="cdac-button" type="submit">' . esc_html__( 'Filter', 'ctrldeals-agent-crm' ) . '</button></form>';
		$this->render_product_grid( $this->listings_to_products( $listings ), 'deals' );
		echo '</section>';
	}

	private function render_activity_log() {
		$current_agent = $this->current_agent();
		$agent_id      = current_user_can( 'cdac_manage_all_purchases' ) ? absint( $_GET['agent_id'] ?? 0 ) : ( $current_agent ? (int) $current_agent->id : 0 );

		echo '<section class="cdac-section"><h2>' . esc_html__( 'Activity Log', 'ctrldeals-agent-crm' ) . '</h2>';
		$this->render_searches_table( $this->repository->get_searches( $agent_id ) );
		$this->render_clicks_table( $this->repository->get_clicks( $agent_id ) );
		$this->render_sales_table( $this->repository->get_sales( $agent_id ) );
		echo '</section>';
	}

	private function render_manage_agents() {
		$this->require_admin();
		$agents = $this->repository->get_agent_profiles();

		echo '<section class="cdac-section cdac-two-col"><div><h2>' . esc_html__( 'Create Agent', 'ctrldeals-agent-crm' ) . '</h2><form class="cdac-form cdac-card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cdac_create_agent">';
		wp_nonce_field( 'cdac_create_agent' );
		$this->input( 'display_name', __( 'Agent Name', 'ctrldeals-agent-crm' ), 'text', true );
		$this->input( 'username', __( 'Username', 'ctrldeals-agent-crm' ), 'text', true );
		$this->input( 'email', __( 'Email', 'ctrldeals-agent-crm' ), 'email', true );
		$this->input( 'tracking_id', __( 'Amazon Tracking ID', 'ctrldeals-agent-crm' ), 'text', true );
		$this->input( 'password', __( 'Temporary Password', 'ctrldeals-agent-crm' ), 'text', false );
		echo '<button class="cdac-button cdac-button-primary" type="submit">' . esc_html__( 'Create Agent', 'ctrldeals-agent-crm' ) . '</button></form></div>';

		echo '<div><h2>' . esc_html__( 'All Agents', 'ctrldeals-agent-crm' ) . '</h2><div class="cdac-list">';
		foreach ( $agents as $agent ) {
			echo '<article class="cdac-agent-row"><div><strong>' . esc_html( $agent->name ) . '</strong><span>' . esc_html( $agent->email ) . '</span></div><div><b>' . esc_html( $agent->tracking_id ) . '</b><span>' . esc_html( ucfirst( $agent->status ) ) . '</span></div><div>';
			if ( 'active' === $agent->status ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cdac_deactivate_agent"><input type="hidden" name="agent_id" value="' . esc_attr( $agent->id ) . '">';
				wp_nonce_field( 'cdac_deactivate_agent_' . $agent->id );
				echo '<button class="cdac-button" type="submit">' . esc_html__( 'Deactivate', 'ctrldeals-agent-crm' ) . '</button></form>';
			}
			echo '</div></article>';
		}
		echo '</div></div></section>';
	}

	private function render_sales_report() {
		$this->require_admin();
		echo '<section class="cdac-section"><div class="cdac-section-head"><h2>' . esc_html__( 'Sales Report', 'ctrldeals-agent-crm' ) . '</h2><span class="cdac-muted">' . esc_html__( 'Amazon report sync populates this table.', 'ctrldeals-agent-crm' ) . '</span></div>';
		$this->render_sales_table( $this->repository->get_sales( 0, 300 ) );
		echo '</section>';
	}

	private function render_manage_listings() {
		$this->require_admin();
		$listings = $this->repository->get_listings();

		echo '<section class="cdac-section cdac-two-col"><div><h2>' . esc_html__( 'Add Public Deal Listing', 'ctrldeals-agent-crm' ) . '</h2><form class="cdac-form cdac-card" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cdac_save_listing">';
		wp_nonce_field( 'cdac_save_listing' );
		$this->input( 'asin', __( 'ASIN', 'ctrldeals-agent-crm' ), 'text', true );
		$this->input( 'title', __( 'Title', 'ctrldeals-agent-crm' ), 'text', true );
		$this->input( 'image_url', __( 'Amazon Image URL', 'ctrldeals-agent-crm' ), 'url', true );
		$this->input( 'category', __( 'Category', 'ctrldeals-agent-crm' ) );
		$this->input( 'sale_price', __( 'Sale Price', 'ctrldeals-agent-crm' ), 'number', false, '', 'step="0.01"' );
		$this->input( 'original_price', __( 'Original Price', 'ctrldeals-agent-crm' ), 'number', false, '', 'step="0.01"' );
		echo '<label class="cdac-check"><input type="checkbox" name="is_active" value="1" checked> ' . esc_html__( 'Publish listing', 'ctrldeals-agent-crm' ) . '</label>';
		echo '<button class="cdac-button cdac-button-primary" type="submit">' . esc_html__( 'Save Listing', 'ctrldeals-agent-crm' ) . '</button></form></div><div><h2>' . esc_html__( 'Current Listings', 'ctrldeals-agent-crm' ) . '</h2>';
		$this->render_listings_table( $listings );
		echo '</div></section>';
	}

	private function render_sync_status() {
		$this->require_admin();
		global $wpdb;
		$sync_table = $this->repository->sync_table();
		$logs       = $wpdb->get_results( "SELECT * FROM {$sync_table} ORDER BY started_at DESC LIMIT 30" );
		echo '<section class="cdac-section"><div class="cdac-section-head"><h2>' . esc_html__( 'Amazon Report Sync', 'ctrldeals-agent-crm' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cdac_manual_sync">';
		wp_nonce_field( 'cdac_manual_sync' );
		echo '<button class="cdac-button" type="submit">' . esc_html__( 'Log API Sync Check', 'ctrldeals-agent-crm' ) . '</button></form></div>';
		echo '<p class="cdac-muted">' . esc_html__( 'Amazon Associates reports refresh about every 24 hours. Upload the latest CSV or TSV export here to update matched sales and commissions.', 'ctrldeals-agent-crm' ) . '</p>';
		echo '<form class="cdac-form cdac-card cdac-upload-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cdac_upload_report">';
		wp_nonce_field( 'cdac_upload_report' );
		echo '<label>' . esc_html__( 'Amazon Associates Report File', 'ctrldeals-agent-crm' ) . '<input type="file" name="associates_report" accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values" required></label>';
		echo '<button class="cdac-button cdac-button-primary" type="submit">' . esc_html__( 'Upload and Update Sales', 'ctrldeals-agent-crm' ) . '</button></form>';
		echo '<div class="cdac-table-wrap"><table class="widefat striped cdac-table"><thead><tr><th>' . esc_html__( 'Started', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Status', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Matched', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Unmatched', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Message', 'ctrldeals-agent-crm' ) . '</th></tr></thead><tbody>';
		foreach ( $logs as $log ) {
			echo '<tr><td>' . esc_html( $log->started_at ) . '</td><td>' . esc_html( $log->status ) . '</td><td>' . esc_html( $log->matched_count ) . '</td><td>' . esc_html( $log->unmatched_count ) . '</td><td>' . esc_html( $log->message ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private function render_settings() {
		$this->require_admin();

		echo '<section class="cdac-section"><h2>' . esc_html__( 'Plugin Settings', 'ctrldeals-agent-crm' ) . '</h2><form class="cdac-form cdac-card cdac-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cdac_save_settings">';
		wp_nonce_field( 'cdac_save_settings' );
		$this->input( 'site_tracking_id', __( 'Public Site Tracking ID', 'ctrldeals-agent-crm' ), 'text', false, get_option( 'cdac_site_tracking_id', '' ) );
		$this->input( 'amazon_marketplace', __( 'Amazon Marketplace URL', 'ctrldeals-agent-crm' ), 'url', false, get_option( 'cdac_amazon_marketplace', 'https://www.amazon.com' ) );
		$this->input( 'amazon_api_endpoint', __( 'Amazon Creators API Endpoint', 'ctrldeals-agent-crm' ), 'url', false, get_option( 'cdac_amazon_api_endpoint', '' ) );
		$this->input( 'amazon_api_key', __( 'Amazon API Access Key', 'ctrldeals-agent-crm' ), 'text', false, get_option( 'cdac_amazon_api_key', '' ) );
		$this->input( 'amazon_api_secret', __( 'Amazon API Secret Key', 'ctrldeals-agent-crm' ), 'password', false, get_option( 'cdac_amazon_api_secret', '' ) );
		$this->input( 'search_cache_ttl', __( 'Search Cache TTL Seconds', 'ctrldeals-agent-crm' ), 'number', false, get_option( 'cdac_search_cache_ttl', HOUR_IN_SECONDS ), 'min="3600" max="86400"' );
		echo '<button class="cdac-button cdac-button-primary" type="submit">' . esc_html__( 'Save Settings', 'ctrldeals-agent-crm' ) . '</button></form></section>';
	}

	private function render_product_grid( $products, $return_tab ) {
		if ( ! $products ) {
			echo '<div class="cdac-empty">' . esc_html__( 'No products found.', 'ctrldeals-agent-crm' ) . '</div>';
			return;
		}

		echo '<div class="cdac-product-grid">';
		foreach ( $products as $product ) {
			echo '<article class="cdac-product-card">';
			if ( ! empty( $product['image_url'] ) ) {
				echo '<img src="' . esc_url( $product['image_url'] ) . '" alt="">';
			}
			echo '<div><h3>' . esc_html( $product['title'] ) . '</h3><p class="cdac-muted">' . esc_html( $product['asin'] ) . ( ! empty( $product['category'] ) ? ' | ' . esc_html( $product['category'] ) : '' ) . '</p>';
			if ( isset( $product['price'] ) && '' !== $product['price'] ) {
				echo '<strong>' . esc_html( $this->money( $product['price'] ) ) . '</strong><span class="cdac-muted">' . esc_html__( 'Price may vary - check Amazon for current price.', 'ctrldeals-agent-crm' ) . '</span>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cdac_generate_cart_url"><input type="hidden" name="return_tab" value="' . esc_attr( $return_tab ) . '"><input type="hidden" name="asin" value="' . esc_attr( $product['asin'] ) . '"><input type="hidden" name="product_name" value="' . esc_attr( $product['title'] ) . '"><input type="hidden" name="quantity" value="1">';
			wp_nonce_field( 'cdac_generate_cart_url' );
			echo '<button class="cdac-button cdac-button-primary" type="submit">' . esc_html__( 'Generate Add to Cart URL', 'ctrldeals-agent-crm' ) . '</button></form></div></article>';
		}
		echo '</div>';
	}

	private function render_searches_table( $rows ) {
		echo '<h3>' . esc_html__( 'Searches', 'ctrldeals-agent-crm' ) . '</h3><div class="cdac-table-wrap"><table class="widefat striped cdac-table"><thead><tr><th>' . esc_html__( 'Query', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Results', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Date', 'ctrldeals-agent-crm' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row->query ) . '</td><td>' . esc_html( $row->results_count ) . '</td><td>' . esc_html( $row->searched_at ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function render_clicks_table( $rows ) {
		echo '<h3>' . esc_html__( 'URLs Generated', 'ctrldeals-agent-crm' ) . '</h3><div class="cdac-table-wrap"><table class="widefat striped cdac-table"><thead><tr><th>' . esc_html__( 'Product', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'ASIN', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'URL', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Date', 'ctrldeals-agent-crm' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row->product_name ) . '</td><td>' . esc_html( $row->asin ) . '</td><td><input class="cdac-copy-input" readonly value="' . esc_attr( $row->generated_url ) . '"></td><td>' . esc_html( $row->clicked_at ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function render_sales_table( $rows ) {
		echo '<h3>' . esc_html__( 'Confirmed Sales', 'ctrldeals-agent-crm' ) . '</h3><div class="cdac-table-wrap"><table class="widefat striped cdac-table"><thead><tr><th>' . esc_html__( 'Date', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Product', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Agent', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Qty', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Revenue', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Commission', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Status', 'ctrldeals-agent-crm' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row->order_date ) . '</td><td>' . esc_html( trim( $row->product_name . ' ' . $row->asin ) ) . '</td><td>' . esc_html( $row->agent_name ?: $row->tracking_id ) . '</td><td>' . esc_html( isset( $row->quantity ) ? absint( $row->quantity ) : 0 ) . '</td><td>' . esc_html( $this->money( $row->revenue ?? 0 ) ) . '</td><td>' . esc_html( $this->money( $row->commission ) ) . '</td><td><span class="cdac-badge">' . esc_html( ucfirst( $row->status ) ) . '</span></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function render_listings_table( $rows ) {
		echo '<div class="cdac-table-wrap"><table class="widefat striped cdac-table"><thead><tr><th>' . esc_html__( 'Product', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'ASIN', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Category', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Price', 'ctrldeals-agent-crm' ) . '</th><th>' . esc_html__( 'Status', 'ctrldeals-agent-crm' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td><strong>' . esc_html( $row->title ) . '</strong></td><td>' . esc_html( $row->asin ) . '</td><td>' . esc_html( $row->category ) . '</td><td>' . esc_html( $this->money( $row->sale_price ) ) . '</td><td>' . esc_html( $row->is_active ? __( 'Live', 'ctrldeals-agent-crm' ) : __( 'Unpublished', 'ctrldeals-agent-crm' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function listings_to_products( $listings ) {
		return array_map(
			function ( $listing ) {
				return array(
					'asin'           => $listing->asin,
					'title'          => $listing->title,
					'image_url'      => $listing->image_url,
					'price'          => $listing->sale_price,
					'original_price' => $listing->original_price,
					'category'       => $listing->category,
				);
			},
			$listings
		);
	}

	private function admin_tabs() {
		return array(
			'admin-dashboard' => __( 'Dashboard', 'ctrldeals-agent-crm' ),
			'search'          => __( 'Product Search', 'ctrldeals-agent-crm' ),
			'agents'          => __( 'Agents', 'ctrldeals-agent-crm' ),
			'listings'        => __( 'Listings', 'ctrldeals-agent-crm' ),
			'sales'           => __( 'Sales', 'ctrldeals-agent-crm' ),
			'sync'            => __( 'Sync', 'ctrldeals-agent-crm' ),
			'settings'        => __( 'Settings', 'ctrldeals-agent-crm' ),
		);
	}

	private function agent_tabs() {
		return array(
			'agent-dashboard' => __( 'Dashboard', 'ctrldeals-agent-crm' ),
			'search'          => __( 'Product Search', 'ctrldeals-agent-crm' ),
			'deals'           => __( 'Listed Deals', 'ctrldeals-agent-crm' ),
			'activity'        => __( 'My Activity', 'ctrldeals-agent-crm' ),
		);
	}

	private function render_tabs( $tabs, $active_tab ) {
		echo '<nav class="cdac-tabs">';
		foreach ( $tabs as $tab => $label ) {
			echo '<a class="' . esc_attr( $active_tab === $tab ? 'is-active' : '' ) . '" href="' . esc_url( $this->tab_url( $tab ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	private function render_notice() {
		$notice = sanitize_key( $_GET['cdac_notice'] ?? '' );
		$error  = sanitize_text_field( wp_unslash( $_GET['cdac_error'] ?? '' ) );

		$messages = array(
			'agent_created'     => __( 'Agent created.', 'ctrldeals-agent-crm' ),
			'agent_deactivated' => __( 'Agent deactivated.', 'ctrldeals-agent-crm' ),
			'listing_saved'     => __( 'Listing saved.', 'ctrldeals-agent-crm' ),
			'url_generated'     => __( 'Add to Cart URL generated and logged.', 'ctrldeals-agent-crm' ),
			'settings_saved'    => __( 'Settings saved.', 'ctrldeals-agent-crm' ),
			'sync_logged'       => __( 'Sync event logged.', 'ctrldeals-agent-crm' ),
			'report_imported'   => $this->report_import_message(),
			'error'             => $error,
		);

		if ( $notice && ! empty( $messages[ $notice ] ) ) {
			echo '<div class="' . esc_attr( 'error' === $notice ? 'cdac-notice cdac-notice-error' : 'cdac-notice' ) . '">' . esc_html( $messages[ $notice ] ) . '</div>';
		}
	}

	private function report_import_message() {
		$message = get_transient( 'cdac_report_import_' . get_current_user_id() );

		if ( $message ) {
			delete_transient( 'cdac_report_import_' . get_current_user_id() );
			return $message;
		}

		return __( 'Report imported and sales data updated.', 'ctrldeals-agent-crm' );
	}

	private function render_generated_url() {
		$data = get_transient( 'cdac_generated_url_' . get_current_user_id() );

		if ( ! $data ) {
			return;
		}

		delete_transient( 'cdac_generated_url_' . get_current_user_id() );
		echo '<div class="cdac-copy-box"><strong>' . esc_html( $data['product_name'] ) . '</strong><span class="cdac-muted">' . esc_html( $data['asin'] ) . '</span><input readonly value="' . esc_attr( $data['url'] ) . '"><button type="button" class="cdac-button" data-cdac-copy-url>' . esc_html__( 'Copy URL', 'ctrldeals-agent-crm' ) . '</button></div>';
	}

	private function input( $name, $label, $type = 'text', $required = false, $value = '', $extra = '' ) {
		echo '<label>' . esc_html( $label ) . '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required' : '' ) . ' ' . $extra . '></label>';
	}

	private function stat_card( $label, $value ) {
		echo '<article class="cdac-stat"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></article>';
	}

	private function money( $value ) {
		return '$' . number_format_i18n( (float) $value, 2 );
	}

	private function parse_report_file( $path, $extension ) {
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'cdac_report_unreadable', __( 'The uploaded report could not be read.', 'ctrldeals-agent-crm' ) );
		}

		$handle = fopen( $path, 'r' );

		if ( ! $handle ) {
			return new WP_Error( 'cdac_report_open_failed', __( 'The uploaded report could not be opened.', 'ctrldeals-agent-crm' ) );
		}

		$first_line = fgets( $handle );

		if ( false === $first_line ) {
			fclose( $handle );
			return new WP_Error( 'cdac_report_empty', __( 'The uploaded report is empty.', 'ctrldeals-agent-crm' ) );
		}

		$delimiter = 'tsv' === $extension || substr_count( $first_line, "\t" ) > substr_count( $first_line, ',' ) ? "\t" : ',';
		rewind( $handle );

		$headers = fgetcsv( $handle, 0, $delimiter );

		if ( ! $headers || count( $headers ) < 2 ) {
			fclose( $handle );
			return new WP_Error( 'cdac_report_headers', __( 'The report must include a header row with multiple columns.', 'ctrldeals-agent-crm' ) );
		}

		$columns = array_map( array( $this, 'normalize_report_header' ), $headers );
		$rows    = array();
		$skipped = 0;

		while ( false !== ( $values = fgetcsv( $handle, 0, $delimiter ) ) ) {
			if ( ! array_filter( $values, 'strlen' ) ) {
				continue;
			}

			$row = $this->report_row_from_values( $columns, $values );

			if ( $row ) {
				$rows[] = $row;
			} else {
				$skipped++;
			}
		}

		fclose( $handle );

		if ( ! $rows ) {
			return new WP_Error( 'cdac_report_no_rows', __( 'No importable sales rows were found. Check that the report includes ASIN or tracking ID columns.', 'ctrldeals-agent-crm' ) );
		}

		return array(
			'rows'    => $rows,
			'skipped' => $skipped,
		);
	}

	private function report_row_from_values( $columns, $values ) {
		$raw = array();

		foreach ( $columns as $index => $column ) {
			if ( '' === $column ) {
				continue;
			}

			$raw[ $column ] = sanitize_text_field( wp_unslash( $values[ $index ] ?? '' ) );
		}

		$asin        = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $this->report_value( $raw, array( 'asin', 'item_asin', 'product_asin' ) ) ) );
		$product     = sanitize_text_field( $this->report_value( $raw, array( 'title', 'product_title', 'product_name', 'item_name', 'product', 'name' ) ) );
		$tracking_id = sanitize_text_field( $this->report_value( $raw, array( 'tracking_id', 'trackingid', 'associate_tag', 'tag', 'store_id' ) ) );

		if ( ! $asin && ! $tracking_id && ! $product ) {
			return null;
		}

		$commission = $this->report_decimal( $this->report_value( $raw, array( 'commission', 'commission_income', 'earnings', 'advertising_fees', 'advertising_fee', 'referral_fee', 'fees' ) ) );
		$revenue    = $this->report_decimal( $this->report_value( $raw, array( 'revenue', 'ordered_revenue', 'shipped_revenue', 'sales', 'price', 'item_price' ) ) );
		$quantity   = absint( $this->report_value( $raw, array( 'items_shipped', 'shipped_items', 'items_ordered', 'ordered_items', 'quantity', 'qty' ) ) );
		$status     = $this->normalize_report_status( $this->report_value( $raw, array( 'status', 'order_status', 'shipment_status' ) ), $quantity, $commission );

		return array(
			'asin'         => $asin,
			'product_name' => $product,
			'order_date'   => $this->parse_report_date( $this->report_value( $raw, array( 'date', 'order_date', 'ordered_date', 'ship_date', 'shipped_date', 'date_shipped', 'earnings_date' ) ) ),
			'commission'   => $commission,
			'revenue'      => $revenue,
			'quantity'     => $quantity,
			'tracking_id'  => $tracking_id,
			'status'       => $status,
			'raw'          => $raw,
		);
	}

	private function normalize_report_header( $header ) {
		$header = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header );
		$header = strtolower( trim( wp_strip_all_tags( $header ) ) );
		$header = preg_replace( '/[^a-z0-9]+/', '_', $header );

		return trim( $header, '_' );
	}

	private function report_value( $row, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return trim( (string) $row[ $key ] );
			}
		}

		return '';
	}

	private function parse_report_date( $value ) {
		$value = sanitize_text_field( $value );

		if ( '' === $value ) {
			return current_time( 'mysql' );
		}

		$timestamp = strtotime( $value );

		if ( ! $timestamp ) {
			return current_time( 'mysql' );
		}

		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $timestamp ) : date( 'Y-m-d H:i:s', $timestamp );
	}

	private function report_decimal( $value ) {
		return round( (float) preg_replace( '/[^0-9.\-]/', '', (string) $value ), 2 );
	}

	private function normalize_report_status( $value, $quantity, $commission ) {
		$status = strtolower( trim( (string) $value ) );

		if ( preg_match( '/return|refund|cancel|reversal/', $status ) ) {
			return 'returned';
		}

		if ( preg_match( '/ship|deliver|confirm|complete/', $status ) ) {
			return 'confirmed';
		}

		if ( preg_match( '/order|pending|open/', $status ) ) {
			return 'pending';
		}

		return ( $quantity > 0 || $commission > 0 ) ? 'confirmed' : 'pending';
	}

	private function current_agent() {
		return $this->repository->get_agent_by_user( get_current_user_id() );
	}

	private function can_access_private_area() {
		if ( current_user_can( 'cdac_manage_all_purchases' ) ) {
			return true;
		}

		$agent = $this->current_agent();

		return $agent && 'active' === $agent->status && current_user_can( 'cdac_manage_own_purchases' );
	}

	private function require_private_access() {
		if ( ! $this->can_access_private_area() ) {
			wp_die( esc_html__( 'You do not have permission to access this area.', 'ctrldeals-agent-crm' ) );
		}
	}

	private function require_admin() {
		if ( ! current_user_can( 'cdac_manage_all_purchases' ) ) {
			wp_die( esc_html__( 'Only admin accounts can access this section.', 'ctrldeals-agent-crm' ) );
		}
	}

	private function tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => 'ctrldeals-agent-crm',
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	private function redirect( $tab, $notice, $error = '' ) {
		$args = array(
			'page'        => 'ctrldeals-agent-crm',
			'tab'         => $tab,
			'cdac_notice' => $notice,
		);

		if ( $error ) {
			$args['cdac_error'] = $error;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
