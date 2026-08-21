<?php
/**
 * Plugin Name: CtrlDeals Affiliate Operations
 * Description: Public deals/search plus private agent/admin Add to Cart URL generation, logging, listings, and sales attribution.
 * Version: 0.3.2
 * Author: CTRDeals
 * Text Domain: ctrldeals-agent-crm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CDAC_VERSION', '0.3.2' );
define( 'CDAC_FILE', __FILE__ );
define( 'CDAC_PATH', plugin_dir_path( __FILE__ ) );
define( 'CDAC_URL', plugin_dir_url( __FILE__ ) );

require_once CDAC_PATH . 'includes/class-cdac-activator.php';
require_once CDAC_PATH . 'includes/class-cdac-repository.php';
require_once CDAC_PATH . 'includes/class-cdac-admin.php';
require_once CDAC_PATH . 'includes/class-cdac-agent.php';

register_deactivation_hook( __FILE__, array( 'CDAC_Activator', 'deactivate' ) );
add_action( 'cdac_daily_associates_sync', array( 'CDAC_Activator', 'run_scheduled_sync' ) );