<?php
/**
 * Plugin Name: SI Content & Commerce Agent
 * Description: White-label content agent: RSS/X import, SI enrichment, merchant research, MCP price suggestions and Yoast SEO. All endpoints and credentials are configured by the site owner; nothing is bundled.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Text Domain: posmana-content-agent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCA_VERSION', '0.1.0' );
define( 'PCA_FILE', __FILE__ );
define( 'PCA_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCA_URL', plugin_dir_url( __FILE__ ) );

require_once PCA_DIR . 'includes/helpers.php';
require_once PCA_DIR . 'includes/feeds.php';
require_once PCA_DIR . 'includes/x-feeds.php';
require_once PCA_DIR . 'includes/pricing.php';
require_once PCA_DIR . 'includes/seo.php';
require_once PCA_DIR . 'includes/ai.php';
require_once PCA_DIR . 'includes/research.php';
require_once PCA_DIR . 'includes/merchant.php';
require_once PCA_DIR . 'includes/privacy.php';
require_once PCA_DIR . 'includes/growth.php';
require_once PCA_DIR . 'includes/rest.php';
require_once PCA_DIR . 'includes/bootstrap.php';

if ( is_admin() ) {
	require_once PCA_DIR . 'includes/admin.php';
}
