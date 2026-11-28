<?php
/**
 * Multisite / Network Support
 * 
 * - Network-wide settings with per-site overrides
 * - Shared Merchant Center / Google configs
 * - Network admin dashboard
 * - Site cloning / provisioning
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Check if multisite
 */
function pca_is_multisite() {
	return is_multisite();
}

/** * Get network-wide settings
 */
function pca_get_network_settings() {
	if ( ! pca_is_multisite() ) { return array(); }
	
	$network_settings = get_site_option( 'pca_network_settings', array() );
	return $network_settings;
}

/**
 * Save network-wide settings
 */
function pca_save_network_settings( $settings ) {
	if ( ! pca_is_multisite() ) { return false; }
	return update_site_option( 'pca_network_settings', $settings );
}

/**
 * Get effective settings for current site (network + site overrides)
 */
function pca_get_effective_settings() {
	$site_settings = pca_settings();
	
	if ( ! pca_is_multisite() ) {
		return $site_settings;
	}
	
	$network_settings = pca_get_network_settings();
	
	// Merge: site settings override network settings
	$merged = array_merge( $network_settings, $site_settings );
	
	// Force site-specific fields to stay site-specific
	$site_only = array( 'feeds', 'x_handles', 'products', 'brand_name' );
	foreach ( $site_only as $key ) {
		if ( isset( $site_settings[ $key ] ) ) {
			$merged[ $key ] = $site_settings[ $key ];
		}
	}
	
	return $merged;
}

/**
 * Network admin menu
 */
add_action( 'network_admin_menu', function () {
	add_menu_page(
		'SI Agent Network',
		'SI Agent Network',
		'manage_network_options',
		'pca-network',
		'pca_network_admin_page',
		'dashicons-store',
		59
	);
} );

/**
 * Network admin page
 */
function pca_network_admin_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) { return; }
	
	$network_settings = pca_get_network_settings();
	$sites = get_sites( array( 'number' => 100, 'archived' => 0, 'spam' => 0, 'deleted' => 0 ) );
	
	// Handle save
	if ( isset( $_POST['pca_network_save'] ) ) {
		check_admin_referer( 'pca_network_settings' );
		
		$new_settings = $network_settings;
		$fields = array(
			'merchant_id', 'google_service_account_json', 'mc_content_api_sync',
			'ga4_measurement_id', 'ga4_api_secret', 'gtm_container_id',
			'cse_api_key', 'cse_cx', 'ai_endpoint', 'ai_model',
			'deepseek_endpoint', 'deepseek_key', 'mcp_digikala_url', 'mcp_torob_url',
			'mcp_tool', 'merchant_mode', 'merchant_to_url', 'merchant_token',
			'undercut', 'min_margin', 'price_sync_enabled', 'price_auto_apply',
			'interval', 'feed_status', 'feed_category', 'feed_limit',
			'research_provider', 'google_cse_key', 'google_cse_cx',
			'business_location_ids', 'owned_domains', 'affiliate_tags',
			'schema_enabled', 'seo_enabled', 'wc_price_sync', 'cache_enabled',
			'background_jobs', 'chunked_feeds',
		);
		
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$new_settings[ $field ] = is_array( $_POST[ $field ] ) ? array_map( 'sanitize_text_field', $_POST[ $field ] ) : sanitize_text_field( $_POST[ $field ] );
			}
		}
		
		// Handle checkboxes
		$checkboxes = array( 'mc_content_api_sync', 'price_sync_enabled', 'price_auto_apply', 'wc_price_sync', 'cache_enabled', 'background_jobs', 'chunked_feeds', 'schema_enabled', 'seo_enabled' );
		foreach ( $checkboxes as $field ) {
			$new_settings[ $field ] = ! empty( $_POST[ $field ] );
		}
		
		pca_save_network_settings( $new_settings );
		echo '<div class="notice notice-success"><p>Network settings saved.</p></div>';
		$network_settings = $new_settings;
	}
	
	// Handle site provisioning
	if ( isset( $_POST['pca_provision_site'] ) ) {
		check_admin_referer( 'pca_provision_site' );
		$new_site_url = esc_url_raw( $_POST['new_site_url'] );
		$new_site_title = sanitize_text_field( $_POST['new_site_title'] );
		$clone_from = absint( $_POST['clone_from'] );
		
		if ( $new_site_url && $new_site_title ) {
			$site_id = wpmu_create_blog( $new_site_url, '/', $new_site_title, get_current_user_id(), array( 'public' => 1 ) );
			if ( ! is_wp_error( $site_id ) ) {
				// Apply network settings to new site
				switch_to_blog( $site_id );
				$site_settings = pca_settings();
				$merged = array_merge( $network_settings, $site_settings );
				pca_update_settings( $merged );
				restore_current_blog();
				echo '<div class="notice notice-success"><p>Site created: ' . esc_html( $new_site_url ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>Failed: ' . $site_id->get_error_message() . '</p></div>';
			}
		}
		$sites = get_sites( array( 'number' => 100, 'archived' => 0, 'spam' => 0, 'deleted' => 0 ) );
	}
	?>
	<div class="wrap pca-network-wrap">
		<h1>SI Agent Network Settings</h1>
		<p class="pca-lede">Configure shared settings across the network. Sites can override feed-specific settings.</p>
		
		<form method="post" class="pca-form">
			<?php wp_nonce_field( 'pca_network_settings' ); ?>
			<input type="hidden" name="pca_network_save" value="1">
			
			<h2>Shared Google & Merchant Config</h2>
			<p><label>Merchant ID<br><input type="text" name="merchant_id" class="regular-text" value="<?php echo esc_attr( $network_settings['merchant_id'] ?? '' ); "></label></p>
			<p><label>Service Account JSON<br><textarea name="google_service_account_json" rows="6" class="large-text code"><?php echo esc_textarea( $network_settings['google_service_account_json'] ?? '' ); ?></textarea></label></p>
			<p><label><input type="checkbox" name="mc_content_api_sync" value="1" <?php checked( $network_settings['mc_content_api_sync'] ); ?>> Enable daily MC Content API sync</label></p>
			
			<h2>GA4 / GTM / CSE (Shared)</h2>
			<p><label>GA4 Measurement ID<br><input type="text" name="ga4_measurement_id" class="regular-text" placeholder="G-XXXXXXXXXX" value="<?php echo esc_attr( $network_settings['ga4_measurement_id'] ?? '' ); "></label></p>
			<p><label>GA4 API Secret<br><input type="text" name="ga4_api_secret" class="regular-text" value="<?php echo esc_attr( $network_settings['ga4_api_secret'] ?? '' ); "></label></p>
			<p><label>GTM Container ID<br><input type="text" name="gtm_container_id" class="regular-text" placeholder="GTM-XXXXXXX" value="<?php echo esc_attr( $network_settings['gtm_container_id'] ?? '' ); "></label></p>
			<p><label>Google CSE API Key<br><input type="text" name="cse_api_key" class="regular-text" value="<?php echo esc_attr( $network_settings['cse_api_key'] ?? '' ); "></label></p>
			<p><label>CSE Search Engine ID<br><input type="text" name="cse_cx" class="regular-text" value="<?php echo esc_attr( $network_settings['cse_cx'] ?? '' ); "></label></p>
			
			<h2>AI & Research (Shared)</h2>
			<p><label>AI Endpoint<br><input type="url" name="ai_endpoint" class="regular-text" value="<?php echo esc_attr( $network_settings['ai_endpoint'] ?? '' ); "></label></p>
			<p><label>AI Model<br><input type="text" name="ai_model" value="<?php echo esc_attr( $network_settings['ai_model'] ?? 'gpt-4o-mini' ); "></label></p>
			<p><label>DeepSeek Endpoint<br><input type="url" name="deepseek_endpoint" class="regular-text" value="<?php echo esc_attr( $network_settings['deepseek_endpoint'] ?? 'https://api.deepseek.com/v1/search' ); "></label></p>
			<p><label>DeepSeek Key<br><input type="password" name="deepseek_key" class="regular-text" autocomplete="off" placeholder="<?php echo $network_settings['deepseek_key'] ? 'configured' : 'not configured'; ?>"></label></p>
			<p><label>Research Provider
			<select name="research_provider">
				<option value="google_news" <?php selected( $network_settings['research_provider'] ?? 'google_news', 'google_news' ); ?>>Google News RSS</option>
				<option value="google_cse" <?php selected( $network_settings['research_provider'] ?? 'google_news', 'google_cse' ); ?>>Google CSE</option>
				<option value="deepseek" <?php selected( $network_settings['research_provider'] ?? 'google_news', 'deepseek' ); ?>>DeepSeek</option>
				<option value="mcp" <?php selected( $network_settings['research_provider'] ?? 'google_news', 'mcp' ); ?>>MCP</option>
			</select></label></p>
			<p><label>MCP Digikala URL<br><input type="url" name="mcp_digikala_url" class="regular-text" value="<?php echo esc_attr( $network_settings['mcp_digikala_url'] ?? '' ); "></label></p>
			<p><label>MCP Torob URL<br><input type="url" name="mcp_torob_url" class="regular-text" value="<?php echo esc_attr( $network_settings['mcp_torob_url'] ?? '' ); "></label></p>
			<p><label>MCP Tool<br><input type="text" name="mcp_tool" value="<?php echo esc_attr( $network_settings['mcp_tool'] ?? 'search_product' ); "></label></p>
			
			<h2>Merchant Bridge (Shared)</h2>
			<p><label>Merchant Mode
			<select name="merchant_mode">
				<option value="off" <?php selected( $network_settings['merchant_mode'] ?? 'off', 'off' ); ?>>Off</option>
				<option value="from" <?php selected( $network_settings['merchant_mode'] ?? 'off', 'from' ); ?>>From merchants</option>
				<option value="to" <?php selected( $network_settings['merchant_mode'] ?? 'off', 'to' ); ?>>To merchants</option>
				<option value="both" <?php selected( $network_settings['merchant_mode'] ?? 'off', 'both' ); ?>>Both</option>
			</select></label></p>
			<p><label>Outbound URL<br><input type="url" name="merchant_to_url" class="regular-text" value="<?php echo esc_attr( $network_settings['merchant_to_url'] ?? '' ); "></label></p>
			<p><label>Shared Token<br><input type="password" name="merchant_token" class="regular-text" autocomplete="off" placeholder="<?php echo $network_settings['merchant_token'] ? 'configured' : 'not configured'; ?>"></label></p>
			
			<h2>Pricing & Sync (Shared defaults)</h2>
			<p><label>Undercut<br><input type="number" name="undercut" value="<?php echo esc_attr( $network_settings['undercut'] ?? 1000 ); "></label>
			<label>Min Margin %<br><input type="number" name="min_margin" value="<?php echo esc_attr( $network_settings['min_margin'] ?? 5 ); "></label></p>
			<p><label><input type="checkbox" name="price_sync_enabled" value="1" <?php checked( $network_settings['price_sync_enabled'] ); ?>> Enable price sync</label></p>
			<p><label><input type="checkbox" name="price_auto_apply" value="1" <?php checked( $network_settings['price_auto_apply'] ); ?>> Auto-apply prices</label></p>
			<p><label><input type="checkbox" name="wc_price_sync" value="1" <?php checked( $network_settings['wc_price_sync'] ); ?>> Sync to WooCommerce</label></p>
			<p>Sync Interval
			<select name="interval">
				<option value="hourly" <?php selected( $network_settings['interval'] ?? 'hourly', 'hourly' ); ?>>Hourly</option>
				<option value="twicedaily" <?php selected( $network_settings['interval'] ?? 'hourly', 'twicedaily' ); ?>>Twice Daily</option>
				<option value="daily" <?php selected( $network_settings['interval'] ?? 'hourly', 'daily' ); ?>>Daily</option>
			</select></p>
			<p><label>Feed Status
			<select name="feed_status">
				<option value="draft" <?php selected( $network_settings['feed_status'] ?? 'draft', 'draft' ); ?>>Draft</option>
				<option value="pending" <?php selected( $network_settings['feed_status'] ?? 'draft', 'pending' ); ?>>Pending</option>
				<option value="publish" <?php selected( $network_settings['feed_status'] ?? 'draft', 'publish' ); ?>>Publish</option>
			</select></label>
			<label>Feed Category<br><?php wp_dropdown_categories( array( 'name' => 'feed_category', 'selected' => $network_settings['feed_category'] ?? 0, 'show_option_none' => 'Uncategorized' ) ); ?></label>
			<label>Items per Feed<br><input type="number" name="feed_limit" min="1" max="20" value="<?php echo esc_attr( $network_settings['feed_limit'] ?? 5 ); "></label>
			
			<h2>Performance (Shared)</h2>
			<p><label><input type="checkbox" name="cache_enabled" value="1" <?php checked( $network_settings['cache_enabled'] ); ?>> Enable object caching</label></p>
			<p><label><input type="checkbox" name="background_jobs" value="1" <?php checked( $network_settings['background_jobs'] ); ?>> Background job queue</label></p>
			<p><label><input type="checkbox" name="chunked_feeds" value="1" <?php checked( $network_settings['chunked_feeds'] ); ?>> Chunked feed processing</label></p>
			
			<?php submit_button( 'Save Network Settings' ); ?>
		</form>
		
		<hr style="margin: 32px 0;">
		
		<h2>Site Provisioning</h2>
		<form method="post" style="max-width: 600px;">
			<?php wp_nonce_field( 'pca_provision_site' ); ?>
			<input type="hidden" name="pca_provision_site" value="1">
			<table class="form-table">
				<tr><th><label for="new_site_url">Site URL (subdomain or subdirectory)</label></th><td><input type="url" name="new_site_url" id="new_site_url" class="regular-text" required placeholder="https://network.example.com/newsite"></td></tr>
				<tr><th><label for="new_site_title">Site Title</label></th><td><input type="text" name="new_site_title" id="new_site_title" class="regular-text" required></td></tr>
				<tr><th><label for="clone_from">Clone settings from site (optional)</label></th><td><select name="clone_from" id="clone_from"><option value="0">-- Use network defaults --</option><?php foreach ( $sites as $site ) { echo '<option value="' . $site->blog_id . '">' . esc_html( $site->domain . $site->path ) . ' (ID: ' . $site->blog_id . ')</option>'; } ?></select></td></tr>
			</table>
			<?php submit_button( 'Create Site with Network Settings', 'primary', 'pca_provision_site' ); ?>
		</form>
		
		<hr style="margin: 32px 0;">
		
		<h2>Network Sites Overview</h2>
		<table class="widefat striped">
			<thead><tr><th>Site</th><th>Feeds</th><th>Products</th><th>Last Feed Sync</th><th>Last Price Sync</th><th>Actions</th></tr></thead>
			<tbody>
			<?php foreach ( $sites as $site ) :
				switch_to_blog( $site->blog_id );
				$settings = pca_settings();
				$last_feed = pca_last_run( 'feeds' );
				$last_price = pca_last_run( 'prices' );
				$feed_count = count( (array) $settings['feeds'] );
				$product_count = count( (array) $settings['products'] );
				restore_current_blog();
			?>
			<tr>
				<td><strong><?php echo esc_html( $site->domain . $site->path ); ?></strong> (ID: <?php echo $site->blog_id; ?>)</td>
				<td><?php echo $feed_count; ?></td>
				<td><?php echo $product_count; ?></td>
				<td><?php echo esc_html( $last_feed ); ?></td>
				<td><?php echo esc_html( $last_price ); ?></td>
				<td><a href="<?php echo esc_url( network_admin_url( 'site-info.php?id=' . $site->blog_id ) ); " class="button button-small">Manage</a></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
} );

/**
 * Override pca_settings() to use effective settings on multisite
 */
remove_filter( 'pca_defaults', 'pca_defaults', 10 ); // Remove if exists
add_filter( 'pca_defaults', function( $defaults ) {
	// Network defaults are handled in pca_get_effective_settings()
	return $defaults;
}, 1 );

/**
 * Shortcode to display network status on any site
 */
add_shortcode( 'pca_network_status', function() {
	if ( ! pca_is_multisite() ) { return ''; }
	
	$network = pca_get_network_settings();
	$site = pca_settings();
	
	$html = '<div class="pca-network-status"><h4>Network Configuration</h4><ul>';
	$html .= '<li>Merchant Center: ' . ( ! empty( $network['merchant_id'] ) ? 'Configured (ID: ' . $network['merchant_id'] . ')' : 'Not configured' ) . '</li>';
	$html .= '<li>MC Content API: ' . ( ! empty( $network['mc_content_api_sync'] ) ? 'Enabled' : 'Disabled' ) . '</li>';
	$html .= '<li>GA4: ' . ( ! empty( $network['ga4_measurement_id'] ) ? 'Configured' : 'Not configured' ) . '</li>';
	$html .= '<li>CSE: ' . ( ! empty( $network['cse_api_key'] ) ? 'Configured' : 'Not configured' ) . '</li>';
	$html .= '<li>AI Endpoint: ' . ( ! empty( $network['ai_endpoint'] ) ? 'Configured' : 'Not configured' ) . '</li>';
	$html .= '</ul></div>';
	return $html;
} );
