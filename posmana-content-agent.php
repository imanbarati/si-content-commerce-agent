<?php
/**
 * Plugin Name: Posmana Content Agent
 * Description: Imports RSS and X.com feeds, syncs prices through configurable MCP bridges, and applies Yoast SEO metadata. All external endpoints are configured by the site owner; no credentials are bundled.
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
require_once PCA_DIR . 'includes/rest.php';
require_once PCA_DIR . 'includes/bootstrap.php';

if ( is_admin() ) {
	require_once PCA_DIR . 'includes/admin.php';
}
