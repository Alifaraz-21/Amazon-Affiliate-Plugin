<?php
/**
 * Public-facing search, deals, and disclosure shortcodes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CDAC_Agent {
	private $repository;

	public function __construct( CDAC_Repository $repository ) {
		$this->repository = $repository;

		add_shortcode( 'ctrldeals_public_search', array( $this, 'render_public_search' ) );
		add_shortcode( 'ctrldeals_deals', array( $this, 'render_public_deals' ) );
		add_shortcode( 'ctrldeals_affiliate_disclosure', array( $this, 'render_disclosure' ) );
		add_shortcode( 'ctrldeals_agent_login', array( $this, 'render_login' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_footer_disclosure' ) );
		add_filter( 'authenticate', array( $this, 'block_locked_login' ), 30, 3 );
		add_action( 'wp_login_failed', array( $this, 'record_failed_login' ) );
		add_action( 'wp_login', array( $this, 'clear_failed_login' ), 10, 1 );
		add_filter( 'login_redirect', array( $this, 'login_redirect' ), 10, 3 );
		add_filter( 'auth_cookie_expiration', array( $this, 'agent_cookie_expiration' ), 10, 3 );
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'cdac-fonts', 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap', array(), null );
		wp_enqueue_style( 'cdac-front', CDAC_URL . 'assets/css/frontend.css', array( 'cdac-fonts' ), CDAC_VERSION );
	}

	public function render_public_search() {
		$query            = sanitize_text_field( wp_unslash( $_GET['cdac_q'] ?? '' ) );
		$search_requested = '1' === sanitize_text_field( wp_unslash( $_GET['cdac_public_search'] ?? '' ) );
		$results          = $query && $search_requested ? $this->repository->search_products( $query ) : array();

		ob_start();
		echo '<div class="cdac-front"><form class="cdac-public-search" method="get">';
		echo '<input type="hidden" name="cdac_public_search" value="1">';
		echo '<input type="search" name="cdac_q" value="' . esc_attr( $query ) . '" autocomplete="off" placeholder="' . esc_attr__( 'Type the full product search...', 'ctrldeals-agent-crm' ) . '">';
		echo '<button class="cdac-front-button" type="submit">' . esc_html__( 'Search', 'ctrldeals-agent-crm' ) . '</button></form>';

		if ( $query && $search_requested ) {
			$this->render_public_products( $results );
		}

		echo '</div>';
		return ob_get_clean();
	}

	public function render_public_deals() {
		$listings = $this->repository->get_listings( array( 'active' => true ) );
		$products = array();

		foreach ( $listings as $listing ) {
			$products[] = array(
				'asin'      => $listing->asin,
				'title'     => $listing->title,
				'image_url' => $listing->image_url,
				'price'     => $listing->sale_price,
				'category'  => $listing->category,
			);
		}

		ob_start();
		echo '<div class="cdac-front">';
		$this->render_public_products( $products );
		echo '</div>';
		return ob_get_clean();
	}

	public function render_disclosure() {
		return '<div class="cdac-front-message">' . esc_html__( 'As an Amazon Associate, ctrldeals.com earns from qualifying purchases.', 'ctrldeals-agent-crm' ) . '</div>';
	}

	public function render_login() {
		if ( is_user_logged_in() ) {
			return '<div class="cdac-front-message"><a href="' . esc_url( admin_url( 'admin.php?page=ctrldeals-agent-crm' ) ) . '">' . esc_html__( 'Open CtrlDeals dashboard', 'ctrldeals-agent-crm' ) . '</a></div>';
		}

		return '<div class="cdac-front cdac-login-box">' . wp_login_form(
			array(
				'echo'           => false,
				'redirect'       => admin_url( 'admin.php?page=ctrldeals-agent-crm' ),
				'label_username' => __( 'Email', 'ctrldeals-agent-crm' ),
				'label_password' => __( 'Password', 'ctrldeals-agent-crm' ),
				'label_log_in'   => __( 'Login', 'ctrldeals-agent-crm' ),
				'remember'       => false,
			)
		) . '</div>';
	}

	public function block_locked_login( $user, $username, $password ) {
		if ( ! $username ) {
			return $user;
		}

		$key = $this->login_key( $username );

		if ( get_transient( $key . '_locked' ) ) {
			return new WP_Error( 'cdac_locked', __( 'Too many failed attempts. Please try again in 15 minutes.', 'ctrldeals-agent-crm' ) );
		}

		return $user;
	}

	public function record_failed_login( $username ) {
		$key   = $this->login_key( $username );
		$count = (int) get_transient( $key . '_count' ) + 1;

		set_transient( $key . '_count', $count, 15 * MINUTE_IN_SECONDS );

		if ( $count >= 5 ) {
			set_transient( $key . '_locked', true, 15 * MINUTE_IN_SECONDS );
		}
	}

	public function clear_failed_login( $user_login ) {
		$key = $this->login_key( $user_login );
		delete_transient( $key . '_count' );
		delete_transient( $key . '_locked' );
	}

	public function login_redirect( $redirect_to, $request, $user ) {
		if ( $user instanceof WP_User && ( in_array( 'ctrldeals_agent', (array) $user->roles, true ) || user_can( $user, 'cdac_manage_all_purchases' ) ) ) {
			return admin_url( 'admin.php?page=ctrldeals-agent-crm' );
		}

		return $redirect_to;
	}

	public function agent_cookie_expiration( $expiration, $user_id, $remember ) {
		$user = get_user_by( 'id', $user_id );

		if ( $user && in_array( 'ctrldeals_agent', (array) $user->roles, true ) ) {
			return 8 * HOUR_IN_SECONDS;
		}

		return $expiration;
	}

	public function render_footer_disclosure() {
		if ( is_admin() ) {
			return;
		}

		$page = get_page_by_path( 'affiliate-disclosure' );
		$link = $page ? get_permalink( $page ) : home_url( '/affiliate-disclosure/' );
		echo '<div class="cdac-footer-disclosure">' . esc_html__( 'As an Amazon Associate, ctrldeals.com earns from qualifying purchases.', 'ctrldeals-agent-crm' ) . ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Affiliate disclosure', 'ctrldeals-agent-crm' ) . '</a></div>';
	}

	private function render_public_products( $products ) {
		if ( ! $products ) {
			echo '<div class="cdac-front-message">' . esc_html__( 'No products found.', 'ctrldeals-agent-crm' ) . '</div>';
			return;
		}

		echo '<div class="cdac-public-grid">';

		foreach ( $products as $product ) {
			$url = $this->repository->generate_add_to_cart_url( get_option( 'cdac_site_tracking_id', '' ), $product['asin'] ?? '', 1 );
			echo '<article class="cdac-public-card">';
			if ( ! empty( $product['image_url'] ) ) {
				echo '<img src="' . esc_url( $product['image_url'] ) . '" alt="">';
			}
			echo '<h3>' . esc_html( $product['title'] ?? '' ) . '</h3>';
			echo '<span class="cdac-public-asin">' . esc_html( $product['asin'] ?? '' ) . '</span>';
			if ( isset( $product['price'] ) && '' !== $product['price'] ) {
				echo '<strong>$' . esc_html( number_format_i18n( (float) $product['price'], 2 ) ) . '</strong>';
				echo '<small>' . esc_html__( 'Price may vary - check Amazon for current price.', 'ctrldeals-agent-crm' ) . '</small>';
			}
			if ( $url ) {
				echo '<a class="cdac-front-button" href="' . esc_url( $url ) . '" target="_blank" rel="nofollow sponsored noopener noreferrer">' . esc_html__( 'Buy on Amazon', 'ctrldeals-agent-crm' ) . '</a>';
				echo '<small>' . esc_html__( 'As an Amazon Associate, ctrldeals.com earns from qualifying purchases.', 'ctrldeals-agent-crm' ) . '</small>';
			}
			echo '</article>';
		}

		echo '</div>';
	}

	private function login_key( $username ) {
		return 'cdac_login_' . md5( strtolower( sanitize_user( $username ) ) );
	}
}
