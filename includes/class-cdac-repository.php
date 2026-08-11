<?php
/**
 * Data access for the CRM.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDAC_Repository {
	public function clients_table() {
		global $wpdb;

		return $wpdb->prefix . 'cdac_clients';
	}

	public function purchases_table() {
		global $wpdb;

		return $wpdb->prefix . 'cdac_purchases';
	}

	public function agents_table() {
		global $wpdb;

		return $wpdb->prefix . 'ctrldeals_agents';
	}

	public function searches_table() {
		global $wpdb;

		return $wpdb->prefix . 'ctrldeals_searches';
	}

	public function clicks_table() {
		global $wpdb;

		return $wpdb->prefix . 'ctrldeals_clicks';
	}

	public function sales_table() {
		global $wpdb;

		return $wpdb->prefix . 'ctrldeals_sales';
	}

	public function listings_table() {
		global $wpdb;

		return $wpdb->prefix . 'ctrldeals_listings';
	}

	public function sync_table() {
		global $wpdb;

		return $wpdb->prefix . 'ctrldeals_sync_log';
	}

	public function create_client( $data, $created_by = 0 ) {
		global $wpdb;

		$now = current_time( 'mysql' );
		$name = sanitize_text_field( $data['name'] ?? '' );

		$wpdb->insert(
			$this->clients_table(),
			array(
				'name'       => $name ? $name : __( 'Client', 'ctrldeals-agent-crm' ),
				'phone'      => sanitize_text_field( $data['phone'] ?? '' ),
				'email'      => sanitize_email( $data['email'] ?? '' ),
				'address'    => sanitize_textarea_field( $data['address'] ?? '' ),
				'notes'      => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_by' => absint( $created_by ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function get_client( $client_id ) {
		global $wpdb;
		$table = $this->clients_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				absint( $client_id )
			)
		);
	}

	public function get_clients( $args = array() ) {
		global $wpdb;
		$table = $this->clients_table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['created_by'] ) ) {
			$where[]  = 'created_by = %d';
			$params[] = absint( $args['created_by'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[]  = '(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$limit    = isset( $args['limit'] ) ? absint( $args['limit'] ) : 50;
		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC LIMIT {$limit}";

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql );
	}

	public function create_purchase( $data, $agent_id ) {
		global $wpdb;
		$purchases_table = $this->purchases_table();
		$clients_table   = $this->clients_table();

		$quantity      = max( 1, absint( $data['quantity'] ?? 1 ) );
		$unit_cost     = $this->money( $data['unit_cost'] ?? 0 );
		$product_cost  = $quantity * $unit_cost;
		$service_fee   = $this->money( $data['service_fee'] ?? 0 );
		$shipping_cost = $this->money( $data['shipping_cost'] ?? 0 );
		$tax_cost      = $this->money( $data['tax_cost'] ?? 0 );
		$total_cost    = $product_cost + $service_fee + $shipping_cost + $tax_cost;
		$affiliate_url = esc_url_raw( $data['affiliate_url'] ?? '' );
		$now           = current_time( 'mysql' );

		if ( ! $affiliate_url ) {
			$affiliate_url = $this->build_affiliate_url( $data['product_url'] ?? '', $data['asin'] ?? '' );
		}

		$wpdb->insert(
			$this->purchases_table(),
			array(
				'client_id'        => absint( $data['client_id'] ?? 0 ),
				'agent_id'         => absint( $agent_id ),
				'product_title'    => sanitize_text_field( $data['product_title'] ?? '' ),
				'product_url'      => esc_url_raw( $data['product_url'] ?? '' ),
				'affiliate_url'    => $affiliate_url,
				'product_source'   => sanitize_key( $data['product_source'] ?? 'amazon' ),
				'asin'             => sanitize_text_field( $data['asin'] ?? '' ),
				'quantity'         => $quantity,
				'unit_cost'        => $unit_cost,
				'product_cost'     => $product_cost,
				'service_fee'      => $service_fee,
				'shipping_cost'    => $shipping_cost,
				'tax_cost'         => $tax_cost,
				'total_cost'       => $total_cost,
				'currency'         => sanitize_text_field( $data['currency'] ?? get_option( 'cdac_currency', 'USD' ) ),
				'amazon_order_id'  => sanitize_text_field( $data['amazon_order_id'] ?? '' ),
				'purchase_status'  => sanitize_key( $data['purchase_status'] ?? 'pending_purchase' ),
				'payment_status'   => sanitize_key( $data['payment_status'] ?? 'unpaid' ),
				'notes'            => sanitize_textarea_field( $data['notes'] ?? '' ),
				'meta'             => wp_json_encode( $data['meta'] ?? array() ),
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array(
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%f',
				'%f',
				'%f',
				'%f',
				'%f',
				'%f',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function get_purchases( $args = array() ) {
		global $wpdb;
		$purchases_table = $this->purchases_table();
		$clients_table   = $this->clients_table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['agent_id'] ) ) {
			$where[]  = 'p.agent_id = %d';
			$params[] = absint( $args['agent_id'] );
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'p.purchase_status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[]  = '(p.product_title LIKE %s OR p.amazon_order_id LIKE %s OR c.name LIKE %s OR c.phone LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$limit     = isset( $args['limit'] ) ? absint( $args['limit'] ) : 100;
		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT p.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email
			FROM {$purchases_table} p
			LEFT JOIN {$clients_table} c ON p.client_id = c.id
			WHERE {$where_sql}
			ORDER BY p.created_at DESC
			LIMIT {$limit}";

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql );
	}

	public function get_purchase( $purchase_id ) {
		global $wpdb;
		$purchases_table = $this->purchases_table();
		$clients_table   = $this->clients_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.*, c.name AS client_name, c.phone AS client_phone, c.email AS client_email, c.address AS client_address
				FROM {$purchases_table} p
				LEFT JOIN {$clients_table} c ON p.client_id = c.id
				WHERE p.id = %d",
				absint( $purchase_id )
			)
		);
	}

	public function update_purchase( $purchase_id, $data ) {
		global $wpdb;
		$purchases_table = $this->purchases_table();

		$allowed = array(
			'purchase_status',
			'payment_status',
			'amazon_order_id',
			'notes',
			'affiliate_url',
			'unit_cost',
			'quantity',
			'service_fee',
			'shipping_cost',
			'tax_cost',
		);

		$update = array();

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}

			if ( in_array( $key, array( 'unit_cost', 'service_fee', 'shipping_cost', 'tax_cost' ), true ) ) {
				$update[ $key ] = $this->money( $data[ $key ] );
			} elseif ( 'quantity' === $key ) {
				$update[ $key ] = max( 1, absint( $data[ $key ] ) );
			} elseif ( in_array( $key, array( 'purchase_status', 'payment_status' ), true ) ) {
				$update[ $key ] = sanitize_key( $data[ $key ] );
			} elseif ( 'notes' === $key ) {
				$update[ $key ] = sanitize_textarea_field( $data[ $key ] );
			} elseif ( 'affiliate_url' === $key ) {
				$update[ $key ] = esc_url_raw( $data[ $key ] );
			} else {
				$update[ $key ] = sanitize_text_field( $data[ $key ] );
			}
		}

		if ( isset( $update['unit_cost'] ) || isset( $update['quantity'] ) || isset( $update['service_fee'] ) || isset( $update['shipping_cost'] ) || isset( $update['tax_cost'] ) ) {
			$current       = $this->get_purchase( $purchase_id );

			if ( ! $current ) {
				return false;
			}

			$quantity      = $update['quantity'] ?? (int) $current->quantity;
			$unit_cost     = $update['unit_cost'] ?? (float) $current->unit_cost;
			$service_fee   = $update['service_fee'] ?? (float) $current->service_fee;
			$shipping_cost = $update['shipping_cost'] ?? (float) $current->shipping_cost;
			$tax_cost      = $update['tax_cost'] ?? (float) $current->tax_cost;

			$update['product_cost'] = $quantity * $unit_cost;
			$update['total_cost']   = $update['product_cost'] + $service_fee + $shipping_cost + $tax_cost;
		}

		$update['updated_at'] = current_time( 'mysql' );

		return false !== $wpdb->update(
			$this->purchases_table(),
			$update,
			array( 'id' => absint( $purchase_id ) )
		);
	}

	public function get_dashboard_stats( $agent_id = 0 ) {
		global $wpdb;
		$purchases_table = $this->purchases_table();

		$where  = '1=1';
		$params = array();

		if ( $agent_id ) {
			$where    = 'agent_id = %d';
			$params[] = absint( $agent_id );
		}

		$sql = "SELECT
			COUNT(*) AS total_purchases,
			SUM(total_cost) AS total_value,
			SUM(CASE WHEN purchase_status = 'pending_purchase' THEN 1 ELSE 0 END) AS pending_purchase,
			SUM(CASE WHEN purchase_status = 'ordered' THEN 1 ELSE 0 END) AS ordered,
			SUM(CASE WHEN purchase_status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
			SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid
			FROM {$purchases_table}
			WHERE {$where}";

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		$row = $wpdb->get_row( $sql );

		return array(
			'total_purchases'  => (int) ( $row->total_purchases ?? 0 ),
			'total_value'      => (float) ( $row->total_value ?? 0 ),
			'pending_purchase' => (int) ( $row->pending_purchase ?? 0 ),
			'ordered'          => (int) ( $row->ordered ?? 0 ),
			'delivered'        => (int) ( $row->delivered ?? 0 ),
			'unpaid'           => (int) ( $row->unpaid ?? 0 ),
		);
	}

	public function create_agent( $data ) {
		$email    = sanitize_email( $data['email'] ?? '' );
		$parts    = explode( '@', $email );
		$username = sanitize_user( $data['username'] ?? ( $parts[0] ?? '' ) );
		$password = ! empty( $data['password'] ) ? (string) $data['password'] : wp_generate_password( 14, true );
		$tracking_id = sanitize_text_field( $data['tracking_id'] ?? '' );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Please enter a valid agent email address.', 'ctrldeals-agent-crm' ) );
		}

		if ( username_exists( $username ) ) {
			return new WP_Error( 'username_exists', __( 'This username is already taken.', 'ctrldeals-agent-crm' ) );
		}

		if ( email_exists( $email ) ) {
			return new WP_Error( 'email_exists', __( 'This email is already registered.', 'ctrldeals-agent-crm' ) );
		}

		if ( ! $tracking_id ) {
			return new WP_Error( 'missing_tracking_id', __( 'Please assign a unique Amazon tracking ID to this agent.', 'ctrldeals-agent-crm' ) );
		}

		if ( $this->tracking_id_exists( $tracking_id ) ) {
			return new WP_Error( 'tracking_id_exists', __( 'This tracking ID is already assigned to another agent.', 'ctrldeals-agent-crm' ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_pass'    => $password,
				'user_email'   => $email,
				'display_name' => sanitize_text_field( $data['display_name'] ?? $username ),
				'role'         => 'ctrldeals_agent',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, 'cdac_agent_phone', sanitize_text_field( $data['phone'] ?? '' ) );
		$this->upsert_agent_profile(
			$user_id,
			array(
				'name'        => sanitize_text_field( $data['display_name'] ?? $username ),
				'email'       => $email,
				'tracking_id' => $tracking_id,
				'role'        => 'agent',
				'status'      => 'active',
			)
		);

		return array(
			'user_id'  => $user_id,
			'password' => $password,
		);
	}

	public function get_agents() {
		return $this->get_agent_profiles();
	}

	public function get_website_products( $limit = 200 ) {
		$post_types = array();

		if ( post_type_exists( 'product' ) ) {
			$post_types[] = 'product';
		}

		if ( ! $post_types ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => absint( $limit ),
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		$products = array();

		foreach ( $query->posts as $post_id ) {
			$price      = '';
			$sku        = '';
			$amazon_url = $this->first_meta_value( $post_id, array( '_amazon_url', 'amazon_url', '_product_url', 'product_url' ) );
			$asin       = $this->first_meta_value( $post_id, array( '_amazon_asin', 'amazon_asin', 'asin', '_sku' ) );

			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $post_id );

				if ( $product ) {
					$price = $product->get_price();
					$sku   = $product->get_sku();
				}
			}

			if ( ! $asin && $sku ) {
				$asin = $sku;
			}

			$products[] = array(
				'id'          => $post_id,
				'title'       => get_the_title( $post_id ),
				'url'         => get_permalink( $post_id ),
				'amazon_url'  => $amazon_url,
				'asin'        => $asin,
				'unit_cost'   => $price,
				'affiliate'   => $this->build_affiliate_url( $amazon_url ? $amazon_url : get_permalink( $post_id ), $asin ),
			);
		}

		return $products;
	}

	public function build_affiliate_url( $url = '', $asin = '' ) {
		$tracking_id = sanitize_text_field( get_option( 'cdac_site_tracking_id', get_option( 'cdac_amazon_associate_tag', '' ) ) );

		if ( ! $tracking_id || ! $asin ) {
			return '';
		}

		return $this->generate_add_to_cart_url( $tracking_id, $asin );
	}

	public function generate_add_to_cart_url( $tracking_id, $asin, $quantity = 1 ) {
		$tracking_id = sanitize_text_field( $tracking_id );
		$asin        = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) $asin ) );
		$quantity    = max( 1, absint( $quantity ) );
		$marketplace = untrailingslashit( esc_url_raw( get_option( 'cdac_amazon_marketplace', 'https://www.amazon.com' ) ) );

		if ( ! $tracking_id || ! $asin ) {
			return '';
		}

		return add_query_arg(
			array(
				'AssociateTag' => $tracking_id,
				'ASIN.1'       => $asin,
				'Quantity.1'   => $quantity,
			),
			$marketplace . '/gp/aws/cart/add.html'
		);
	}

	public function get_agent_by_user( $user_id ) {
		global $wpdb;
		$table = $this->agents_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE wp_user_id = %d",
				absint( $user_id )
			)
		);
	}

	public function get_agent_by_id( $agent_id ) {
		global $wpdb;
		$table = $this->agents_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				absint( $agent_id )
			)
		);
	}

	public function get_agent_by_tracking_id( $tracking_id ) {
		global $wpdb;
		$table = $this->agents_table();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE tracking_id = %s",
				sanitize_text_field( $tracking_id )
			)
		);
	}

	public function get_agent_profiles( $args = array() ) {
		global $wpdb;
		$table = $this->agents_table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( $args['status'] );
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC';

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql );
	}

	public function upsert_agent_profile( $user_id, $data ) {
		global $wpdb;

		$now      = current_time( 'mysql' );
		$existing = $this->get_agent_by_user( $user_id );

		$row = array(
			'wp_user_id'  => absint( $user_id ),
			'name'        => sanitize_text_field( $data['name'] ?? '' ),
			'email'       => sanitize_email( $data['email'] ?? '' ),
			'tracking_id' => sanitize_text_field( $data['tracking_id'] ?? '' ),
			'role'        => sanitize_key( $data['role'] ?? 'agent' ),
			'status'      => sanitize_key( $data['status'] ?? 'active' ),
			'updated_at'  => $now,
		);

		if ( $existing ) {
			return false !== $wpdb->update(
				$this->agents_table(),
				$row,
				array( 'wp_user_id' => absint( $user_id ) )
			);
		}

		$row['created_at'] = $now;

		return false !== $wpdb->insert( $this->agents_table(), $row );
	}

	public function tracking_id_exists( $tracking_id, $exclude_user_id = 0 ) {
		global $wpdb;
		$table = $this->agents_table();

		$sql    = "SELECT id FROM {$table} WHERE tracking_id = %s";
		$params = array( sanitize_text_field( $tracking_id ) );

		if ( $exclude_user_id ) {
			$sql     .= ' AND wp_user_id != %d';
			$params[] = absint( $exclude_user_id );
		}

		return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	public function deactivate_agent( $agent_id ) {
		global $wpdb;

		$agent = $this->get_agent_by_id( $agent_id );

		if ( ! $agent ) {
			return false;
		}

		$user = get_user_by( 'id', $agent->wp_user_id );

		if ( $user ) {
			$user->remove_role( 'ctrldeals_agent' );
		}

		return false !== $wpdb->update(
			$this->agents_table(),
			array(
				'status'     => 'inactive',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $agent_id ) )
		);
	}

	public function log_search( $agent_id, $query, $results_count ) {
		global $wpdb;

		return false !== $wpdb->insert(
			$this->searches_table(),
			array(
				'agent_id'      => absint( $agent_id ),
				'query'         => sanitize_text_field( $query ),
				'results_count' => absint( $results_count ),
				'searched_at'   => current_time( 'mysql' ),
			)
		);
	}

	public function log_click( $agent_id, $asin, $product_name, $tracking_id, $generated_url ) {
		global $wpdb;

		return false !== $wpdb->insert(
			$this->clicks_table(),
			array(
				'agent_id'      => absint( $agent_id ),
				'asin'          => strtoupper( sanitize_text_field( $asin ) ),
				'product_name'  => sanitize_text_field( $product_name ),
				'tracking_id'   => sanitize_text_field( $tracking_id ),
				'generated_url' => esc_url_raw( $generated_url ),
				'clicked_at'    => current_time( 'mysql' ),
			)
		);
	}

	public function get_searches( $agent_id = 0, $limit = 100 ) {
		global $wpdb;
		$table = $this->searches_table();

		$where = '1=1';
		$args  = array();

		if ( $agent_id ) {
			$where  = 'agent_id = %d';
			$args[] = absint( $agent_id );
		}

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY searched_at DESC LIMIT " . absint( $limit );

		if ( $args ) {
			$sql = $wpdb->prepare( $sql, $args );
		}

		return $wpdb->get_results( $sql );
	}

	public function get_clicks( $agent_id = 0, $limit = 100 ) {
		global $wpdb;
		$clicks_table = $this->clicks_table();
		$agents_table = $this->agents_table();

		$where = '1=1';
		$args  = array();

		if ( $agent_id ) {
			$where  = 'agent_id = %d';
			$args[] = absint( $agent_id );
		}

		$sql = "SELECT c.*, a.name AS agent_name FROM {$clicks_table} c LEFT JOIN {$agents_table} a ON c.agent_id = a.id WHERE {$where} ORDER BY c.clicked_at DESC LIMIT " . absint( $limit );

		if ( $args ) {
			$sql = $wpdb->prepare( $sql, $args );
		}

		return $wpdb->get_results( $sql );
	}

	public function get_sales( $agent_id = 0, $limit = 100 ) {
		global $wpdb;
		$sales_table  = $this->sales_table();
		$agents_table = $this->agents_table();

		$where = '1=1';
		$args  = array();

		if ( $agent_id ) {
			$where  = 'matched_agent_id = %d';
			$args[] = absint( $agent_id );
		}

		$sql = "SELECT s.*, a.name AS agent_name FROM {$sales_table} s LEFT JOIN {$agents_table} a ON s.matched_agent_id = a.id WHERE {$where} ORDER BY s.order_date DESC LIMIT " . absint( $limit );

		if ( $args ) {
			$sql = $wpdb->prepare( $sql, $args );
		}

		return $wpdb->get_results( $sql );
	}

	public function import_sales_report( $rows, $source_name = '' ) {
		global $wpdb;

		if ( ! is_array( $rows ) || ! $rows ) {
			return new WP_Error( 'cdac_report_empty_rows', __( 'No sales rows were provided for import.', 'ctrldeals-agent-crm' ) );
		}

		$summary = array(
			'inserted'  => 0,
			'updated'   => 0,
			'matched'   => 0,
			'unmatched' => 0,
			'skipped'   => 0,
		);

		foreach ( $rows as $row ) {
			$result = $this->upsert_report_sale( $row, $source_name );

			if ( 'skipped' === $result['action'] ) {
				$summary['skipped']++;
				continue;
			}

			$summary[ $result['action'] ]++;

			if ( $result['matched'] ) {
				$summary['matched']++;
			} else {
				$summary['unmatched']++;
			}
		}

		$now    = current_time( 'mysql' );
		$status = ( $summary['inserted'] || $summary['updated'] ) ? 'completed' : 'skipped';

		$wpdb->insert(
			$this->sync_table(),
			array(
				'started_at'      => $now,
				'finished_at'     => $now,
				'matched_count'   => $summary['matched'],
				'unmatched_count' => $summary['unmatched'],
				'status'          => $status,
				'message'         => sprintf(
					/* translators: 1: source filename, 2: inserted rows, 3: updated rows, 4: skipped rows. */
					__( 'Manual report upload %1$s processed: %2$d inserted, %3$d updated, %4$d skipped.', 'ctrldeals-agent-crm' ),
					$source_name ? sanitize_text_field( $source_name ) : __( 'Amazon Associates report', 'ctrldeals-agent-crm' ),
					$summary['inserted'],
					$summary['updated'],
					$summary['skipped']
				),
			)
		);

		update_option( 'cdac_last_sync_at', $now );

		return $summary;
	}

	private function upsert_report_sale( $row, $source_name = '' ) {
		global $wpdb;

		$asin             = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', (string) ( $row['asin'] ?? '' ) ) );
		$product_name     = sanitize_text_field( $row['product_name'] ?? '' );
		$order_date       = $this->normalize_report_datetime( $row['order_date'] ?? '' );
		$commission       = $this->money( $row['commission'] ?? 0 );
		$revenue          = $this->money( $row['revenue'] ?? 0 );
		$quantity         = absint( $row['quantity'] ?? 0 );
		$tracking_id      = sanitize_text_field( $row['tracking_id'] ?? '' );
		$status           = $this->normalize_sale_status( $row['status'] ?? 'pending' );
		$matched_agent_id = $this->resolve_report_agent_id( $tracking_id, $asin );

		if ( ! $asin && ! $tracking_id && ! $product_name ) {
			return array(
				'action'  => 'skipped',
				'matched' => false,
			);
		}

		$report_key = $this->report_sale_key(
			array(
				'asin'         => $asin,
				'product_name' => $product_name,
				'order_date'   => $order_date,
				'tracking_id'  => $tracking_id,
			)
		);
		$now        = current_time( 'mysql' );
		$data       = array(
			'asin'              => $asin,
			'product_name'      => $product_name,
			'order_date'        => $order_date,
			'commission'        => $commission,
			'tracking_id'       => $tracking_id,
			'matched_agent_id'  => $matched_agent_id,
			'status'            => $status,
			'synced_at'         => $now,
			'report_key'        => $report_key,
			'quantity'          => $quantity,
			'revenue'           => $revenue,
			'source_name'       => sanitize_text_field( $source_name ),
			'raw_report'        => wp_json_encode( $row['raw'] ?? array() ),
			'last_reported_at'  => $now,
		);
		$formats    = array( '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s' );
		$table      = $this->sales_table();
		$existing   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE report_key = %s LIMIT 1",
				$report_key
			)
		);

		if ( $existing ) {
			$updated = false !== $wpdb->update( $table, $data, array( 'id' => absint( $existing ) ), $formats, array( '%d' ) );

			return array(
				'action'  => $updated ? 'updated' : 'skipped',
				'matched' => (bool) $matched_agent_id,
			);
		}

		$inserted = false !== $wpdb->insert( $table, $data, $formats );

		return array(
			'action'  => $inserted ? 'inserted' : 'skipped',
			'matched' => (bool) $matched_agent_id,
		);
	}

	private function report_sale_key( $row ) {
		$parts = array(
			strtolower( sanitize_text_field( $row['tracking_id'] ?? '' ) ),
			strtoupper( sanitize_text_field( $row['asin'] ?? '' ) ),
			substr( sanitize_text_field( $row['order_date'] ?? '' ), 0, 10 ),
			strtolower( preg_replace( '/\s+/', ' ', sanitize_text_field( $row['product_name'] ?? '' ) ) ),
		);

		return sha1( implode( '|', $parts ) );
	}

	private function resolve_report_agent_id( $tracking_id, $asin ) {
		global $wpdb;

		if ( $tracking_id ) {
			$agent = $this->get_agent_by_tracking_id( $tracking_id );

			if ( $agent ) {
				return (int) $agent->id;
			}
		}

		if ( $asin ) {
			$clicks_table = $this->clicks_table();
			$agent_ids    = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT agent_id FROM {$clicks_table} WHERE asin = %s AND agent_id > 0 LIMIT 2",
					$asin
				)
			);

			if ( 1 === count( $agent_ids ) ) {
				return (int) $agent_ids[0];
			}
		}

		return 0;
	}

	private function normalize_report_datetime( $value ) {
		$value = sanitize_text_field( $value );
		$time  = $value ? strtotime( $value ) : false;

		if ( ! $time ) {
			return current_time( 'mysql' );
		}

		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $time ) : date( 'Y-m-d H:i:s', $time );
	}

	private function normalize_sale_status( $status ) {
		$status = sanitize_key( $status );

		return in_array( $status, array( 'pending', 'confirmed', 'returned' ), true ) ? $status : 'pending';
	}

	public function create_listing( $data ) {
		global $wpdb;
		$table = $this->listings_table();

		$now = current_time( 'mysql' );

		$result = false !== $wpdb->replace(
			$table,
			array(
				'asin'           => strtoupper( sanitize_text_field( $data['asin'] ?? '' ) ),
				'title'          => sanitize_text_field( $data['title'] ?? '' ),
				'image_url'      => esc_url_raw( $data['image_url'] ?? '' ),
				'category'       => sanitize_text_field( $data['category'] ?? '' ),
				'sale_price'     => $this->money( $data['sale_price'] ?? 0 ),
				'original_price' => $this->money( $data['original_price'] ?? 0 ),
				'is_active'      => ! empty( $data['is_active'] ) ? 1 : 0,
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);

		if ( $result ) {
			update_option( 'cdac_listings_cache_version', (string) time() );
		}

		return $result;
	}

	public function get_listings( $args = array() ) {
		global $wpdb;
		$table = $this->listings_table();

		$where  = array( '1=1' );
		$params = array();

		if ( isset( $args['active'] ) ) {
			$where[]  = 'is_active = %d';
			$params[] = $args['active'] ? 1 : 0;
		}

		if ( ! empty( $args['category'] ) ) {
			$where[]  = 'category = %s';
			$params[] = sanitize_text_field( $args['category'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$search   = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[]  = '(title LIKE %s OR asin LIKE %s OR category LIKE %s)';
			$params[] = $search;
			$params[] = $search;
			$params[] = $search;
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC LIMIT 200';

		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql );
	}

	public function get_listing_categories() {
		global $wpdb;
		$table = $this->listings_table();

		return $wpdb->get_col( "SELECT DISTINCT category FROM {$table} WHERE category != '' ORDER BY category ASC" );
	}

	public function search_products( $query ) {
		$query = trim( sanitize_text_field( $query ) );

		if ( '' === $query ) {
			return array();
		}

		$cache_key = 'cdac_search_' . get_option( 'cdac_listings_cache_version', '1' ) . '_' . md5( strtolower( $query ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$results = array();

		foreach ( $this->get_listings( array( 'active' => true, 'search' => $query ) ) as $listing ) {
			$results[] = array(
				'asin'           => $listing->asin,
				'title'          => $listing->title,
				'image_url'      => $listing->image_url,
				'price'          => $listing->sale_price,
				'original_price' => $listing->original_price,
				'rating'         => '',
				'category'       => $listing->category,
				'source'         => 'listing',
			);
		}

		set_transient( $cache_key, $results, min( DAY_IN_SECONDS, max( HOUR_IN_SECONDS, absint( get_option( 'cdac_search_cache_ttl', HOUR_IN_SECONDS ) ) ) ) );

		return $results;
	}

	public function get_activity_stats( $agent_id = 0 ) {
		global $wpdb;
		$searches_table = $this->searches_table();
		$clicks_table   = $this->clicks_table();
		$sales_table    = $this->sales_table();

		$month_start = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp', true ) );
		$search_where = $agent_id ? $wpdb->prepare( 'agent_id = %d AND searched_at >= %s', absint( $agent_id ), $month_start ) : $wpdb->prepare( 'searched_at >= %s', $month_start );
		$click_where  = $agent_id ? $wpdb->prepare( 'agent_id = %d AND clicked_at >= %s', absint( $agent_id ), $month_start ) : $wpdb->prepare( 'clicked_at >= %s', $month_start );
		$sales_where  = $agent_id ? $wpdb->prepare( 'matched_agent_id = %d AND order_date >= %s', absint( $agent_id ), $month_start ) : $wpdb->prepare( 'order_date >= %s', $month_start );

		return array(
			'searches'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$searches_table} WHERE {$search_where}" ),
			'clicks'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clicks_table} WHERE {$click_where}" ),
			'confirmed_sales' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sales_table} WHERE {$sales_where} AND status = 'confirmed'" ),
			'commission'      => (float) $wpdb->get_var( "SELECT SUM(commission) FROM {$sales_table} WHERE {$sales_where} AND status IN ('pending','confirmed')" ),
		);
	}

	private function first_meta_value( $post_id, $keys ) {
		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );

			if ( '' !== $value ) {
				return (string) $value;
			}
		}

		return '';
	}

	private function money( $value ) {
		return round( (float) preg_replace( '/[^0-9.\-]/', '', (string) $value ), 2 );
	}
}
