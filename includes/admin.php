<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'admin_menu', function () {
	add_management_page( 'Posmana Agent', 'Posmana Agent', 'manage_options', 'posmana-agent', 'pca_admin_page' );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false === strpos( $hook, 'posmana-agent' ) ) { return; }
	wp_enqueue_style( 'pca-admin', PCA_URL . 'assets/admin.css', array(), PCA_VERSION );
	wp_enqueue_script( 'pca-admin', PCA_URL . 'assets/admin.js', array(), PCA_VERSION, true );
	wp_localize_script( 'pca-admin', 'pcaNonce', wp_create_nonce( 'wp_rest' ) );
} );

function pca_handle_settings_save() {
	if ( ! isset( $_POST['pca_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pca_nonce'] ) ), 'pca_settings' ) ) { return; }
	if ( ! current_user_can( 'manage_options' ) ) { return; }

	$post = wp_unslash( $_POST );
	$settings = pca_settings();
	$settings['feeds']         = array_values( array_filter( array_map( 'esc_url_raw', preg_split( '/\r?\n/', $post['feeds'] ?? '' ) ) ) );
	$settings['x_handles']     = array_values( array_filter( array_map( 'sanitize_text_field', preg_split( '/\r?\n/', $post['x_handles'] ?? '' ) ) ) );
	$settings['feed_status']   = in_array( $post['feed_status'] ?? 'draft', array( 'draft', 'publish', 'pending' ), true ) ? $post['feed_status'] : 'draft';
	$settings['feed_category'] = absint( $post['feed_category'] ?? 0 );
	$settings['feed_limit']    = min( 20, max( 1, absint( $post['feed_limit'] ?? 5 ) ) );
	$settings['x_bearer']      = isset( $post['x_bearer'] ) ? sanitize_text_field( $post['x_bearer'] ) : $settings['x_bearer'];
	$settings['x_rss_bridge']  = esc_url_raw( $post['x_rss_bridge'] ?? '' );
	$settings['mcp_digikala_url'] = esc_url_raw( $post['mcp_digikala_url'] ?? '' );
	$settings['mcp_torob_url']    = esc_url_raw( $post['mcp_torob_url'] ?? '' );
	$settings['mcp_tool']         = sanitize_key( $post['mcp_tool'] ?? 'search_product' );
	$settings['price_sync_enabled'] = ! empty( $post['price_sync_enabled'] );
	$settings['price_auto_apply']   = ! empty( $post['price_auto_apply'] );
	$settings['undercut']         = absint( $post['undercut'] ?? 1000 );
	$settings['min_margin']       = absint( $post['min_margin'] ?? 5 );
	$settings['seo_enabled']      = ! empty( $post['seo_enabled'] );
	$settings['schema_enabled']   = ! empty( $post['schema_enabled'] );
	$tags = array();
	foreach ( preg_split( '/\r?\n/', $post['affiliate_tags'] ?? '' ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( count( $parts ) === 2 && '' !== $parts[0] ) { $tags[ sanitize_key( $parts[0] ) ] = sanitize_text_field( $parts[1] ); }
	}
	$settings['affiliate_tags'] = $tags;
	$settings['owned_domains']  = array_values( array_filter( array_map( 'esc_url_raw', preg_split( '/\r?\n/', $post['owned_domains'] ?? '' ) ) ) );
	$settings['brand_name']       = sanitize_text_field( $post['brand_name'] ?? $settings['brand_name'] );
	$settings['ai_enabled']       = ! empty( $post['ai_enabled'] );
	$settings['ai_endpoint']      = esc_url_raw( $post['ai_endpoint'] ?? '' );
	$settings['ai_key']           = isset( $post['ai_key'] ) ? sanitize_text_field( $post['ai_key'] ) : $settings['ai_key'];
	$settings['ai_model']         = sanitize_text_field( $post['ai_model'] ?? 'gpt-4o-mini' );
	$settings['research_provider']= in_array( $post['research_provider'] ?? 'google_news', array( 'google_news', 'google_cse', 'deepseek', 'mcp' ), true ) ? $post['research_provider'] : 'google_news';
	$settings['google_cse_key']   = isset( $post['google_cse_key'] ) ? sanitize_text_field( $post['google_cse_key'] ) : $settings['google_cse_key'];
	$settings['google_cse_cx']    = isset( $post['google_cse_cx'] ) ? sanitize_text_field( $post['google_cse_cx'] ) : $settings['google_cse_cx'];
	$settings['deepseek_endpoint']= esc_url_raw( $post['deepseek_endpoint'] ?? $settings['deepseek_endpoint'] );
	$settings['deepseek_key']     = isset( $post['deepseek_key'] ) ? sanitize_text_field( $post['deepseek_key'] ) : $settings['deepseek_key'];
	$settings['merchant_mode']    = in_array( $post['merchant_mode'] ?? 'off', array( 'off', 'from', 'to', 'both' ), true ) ? $post['merchant_mode'] : 'off';
	$settings['merchant_to_url']  = esc_url_raw( $post['merchant_to_url'] ?? '' );
	$settings['merchant_token']   = isset( $post['merchant_token'] ) ? sanitize_text_field( $post['merchant_token'] ) : $settings['merchant_token'];
	$settings['interval']         = in_array( $post['interval'] ?? 'hourly', array( 'hourly', 'twicedaily', 'daily' ), true ) ? $post['interval'] : 'hourly';

	$products = array();
	foreach ( preg_split( '/\r?\n/', $post['products'] ?? '' ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( count( $parts ) < 3 ) { continue; }
		$products[] = array(
			'sku'   => sanitize_key( $parts[0] ),
			'name'  => sanitize_text_field( $parts[1] ),
			'query' => sanitize_text_field( $parts[2] ),
			'cost'  => (float) ( $parts[3] ?? 0 ),
		);
	}
	$settings['products'] = $products;
	pca_update_settings( $settings );
	pca_log( 'Settings saved.' );
	echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
}

function pca_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	pca_handle_settings_save();
	$settings    = pca_settings();
	$status      = array(
		'yoast'    => pca_yoast_active() ? 'active' : 'not installed',
		'feeds'    => count( (array) $settings['feeds'] ),
		'x'        => count( (array) $settings['x_handles'] ),
		'ai'        => empty( $settings['ai_endpoint'] ) ? 'not configured' : 'configured',
		'research'  => sanitize_key( $settings['research_provider'] ),
		'merchant'  => sanitize_key( $settings['merchant_mode'] ),
		'digikala' => empty( $settings['mcp_digikala_url'] ) ? 'not configured' : 'configured',
		'torob'    => empty( $settings['mcp_torob_url'] ) ? 'not configured' : 'configured',
	);
	$log         = get_option( 'pca_log', array() );
	$suggestions = get_option( 'pca_price_suggestions', array() );
	?>
	<div class="wrap pca-wrap">
		<h1>Posmana Content Agent</h1>
		<p class="pca-lede">RSS/X import, MCP price suggestions and Yoast metadata. External endpoints are yours; no credentials are bundled.</p>

		<div class="pca-cards">
			<div class="pca-card"><b><?php echo esc_html( $status['yoast'] ); ?></b><span>Yoast SEO</span></div>
			<div class="pca-card"><b><?php echo esc_html( $status['feeds'] ); ?></b><span>RSS feeds</span></div>
			<div class="pca-card"><b><?php echo esc_html( $status['x'] ); ?></b><span>X handles</span></div>
			<div class="pca-card"><b><?php echo esc_html( $status['ai'] ); ?></b><span>SI enrichment</span></div>
			<div class="pca-card"><b><?php echo esc_html( $status['research'] ); ?></b><span>Research source</span></div>
			<div class="pca-card"><b><?php echo esc_html( $status['merchant'] ); ?></b><span>Merchant bridge</span></div>
			<div class="pca-card"><b><?php echo esc_html( $status['digikala'] ); ?></b><span>Digikala MCP</span></div>
			<div class="pca-card"><b><?php echo esc_html( $status['torob'] ); ?></b><span>Torob MCP</span></div>
		</div>

		<h2>SEO checklist</h2>
		<ul class="pca-checklist">
			<?php foreach ( pca_seo_checklist() as $item ) : ?>
				<li class="<?php echo $item['ok'] ? 'ok' : 'warn'; ?>"><b><?php echo esc_html( $item['label'] ); ?></b> — <?php echo esc_html( $item['hint'] ); ?></li>
			<?php endforeach; ?>
		</ul>

		<form method="post" class="pca-form">
			<?php wp_nonce_field( 'pca_settings', 'pca_nonce' ); ?>
			<h2>Feeds</h2>
			<p><label>RSS feed URLs (one per line)<br><textarea name="feeds" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings['feeds'] ) ); ?></textarea></label></p>
			<p><label>X handles (one per line)<br><textarea name="x_handles" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings['x_handles'] ) ); ?></textarea></label></p>
			<p><label>X API bearer token (leave blank to keep existing)<br><input type="password" name="x_bearer" class="regular-text" autocomplete="off" placeholder="<?php echo $settings['x_bearer'] ? 'already configured' : 'not configured'; ?>"></label></p>
			<p><label>X RSS bridge URL template (use {handle})<br><input type="url" name="x_rss_bridge" class="regular-text" value="<?php echo esc_attr( $settings['x_rss_bridge'] ); ?>"></label></p>
			<p>
				<label>Imported post status
					<select name="feed_status">
						<option value="draft" <?php selected( $settings['feed_status'], 'draft' ); ?>>Draft</option>
						<option value="pending" <?php selected( $settings['feed_status'], 'pending' ); ?>>Pending</option>
						<option value="publish" <?php selected( $settings['feed_status'], 'publish' ); ?>>Publish</option>
					</select>
				</label>
				<label> Category <?php wp_dropdown_categories( array( 'name' => 'feed_category', 'selected' => $settings['feed_category'], 'show_option_none' => 'Uncategorized', 'option_none_value' => 0, 'echo' => 0 ) ); ?></label>
				<label> Items per feed <input type="number" name="feed_limit" min="1" max="20" value="<?php echo absint( $settings['feed_limit'] ); ?>"></label>
			</p>

			<h2>Pricing MCP</h2>
			<p><label>Digikala MCP endpoint<br><input type="url" name="mcp_digikala_url" class="regular-text" value="<?php echo esc_attr( $settings['mcp_digikala_url'] ); ?>" placeholder="https://your-bridge.example/mcp"></label></p>
			<p><label>Torob MCP endpoint<br><input type="url" name="mcp_torob_url" class="regular-text" value="<?php echo esc_attr( $settings['mcp_torob_url'] ); ?>"></label></p>
			<p><label>Tool name <input type="text" name="mcp_tool" value="<?php echo esc_attr( $settings['mcp_tool'] ); ?>"></label></p>
			<p><label><input type="checkbox" name="price_sync_enabled" value="1" <?php checked( $settings['price_sync_enabled'] ); ?>> Enable scheduled price sync</label></p>
			<p><label><input type="checkbox" name="price_auto_apply" value="1" <?php checked( $settings['price_auto_apply'] ); ?>> Auto-apply prices (WooCommerce or local price table)</label></p>
			<p><label>Undercut amount <input type="number" name="undercut" value="<?php echo absint( $settings['undercut'] ); ?>"></label>
			<label>Minimum margin % <input type="number" name="min_margin" value="<?php echo absint( $settings['min_margin'] ); ?>"></label></p>
			<p><label>Products (sku|name|query|cost, one per line)<br><textarea name="products" rows="5" class="large-text code"><?php echo esc_textarea( pca_products_to_text( $settings['products'] ) ); ?></textarea></label></p>

			<h2>White label and AI</h2>
			<p><label>Brand name shown to editors<br><input type="text" name="brand_name" class="regular-text" value="<?php echo esc_attr( $settings['brand_name'] ); ?>"></label></p>
			<p><label><input type="checkbox" name="ai_enabled" value="1" <?php checked( $settings['ai_enabled'] ); ?>> AI-enrich imported posts</label></p>
			<p><label>OpenAI-compatible chat endpoint<br><input type="url" name="ai_endpoint" class="regular-text" value="<?php echo esc_attr( $settings['ai_endpoint'] ); ?>" placeholder="https://provider.example/v1/chat/completions"></label></p>
			<p><label>SI key (leave blank to keep existing)<br><input type="password" name="ai_key" class="regular-text" autocomplete="off" placeholder="<?php echo $settings['ai_key'] ? 'configured' : 'not configured'; ?>"></label></p>
			<p><label>Model <input type="text" name="ai_model" value="<?php echo esc_attr( $settings['ai_model'] ); ?>"></label></p>

			<h2>Research and merchant bridge</h2>
			<p><label>Research source
				<select name="research_provider">
					<option value="google_news" <?php selected( $settings['research_provider'], 'google_news' ); ?>>Google News RSS (no key)</option>
					<option value="google_cse" <?php selected( $settings['research_provider'], 'google_cse' ); ?>>Google Custom Search API (your key)</option>
					<option value="deepseek" <?php selected( $settings['research_provider'], 'deepseek' ); ?>>DeepSeek Search (your key)</option>
					<option value="mcp" <?php selected( $settings['research_provider'], 'mcp' ); ?>>Configured MCP endpoint</option>
				</select>
			</label></p>
			<p><label>Google CSE key <input type="text" name="google_cse_key" class="regular-text" value="<?php echo esc_attr( $settings['google_cse_key'] ); ?>"></label>
			<label>CX <input type="text" name="google_cse_cx" value="<?php echo esc_attr( $settings['google_cse_cx'] ); ?>"></label></p>
			<p><label>DeepSeek endpoint <input type="url" name="deepseek_endpoint" class="regular-text" value="<?php echo esc_attr( $settings['deepseek_endpoint'] ); ?>"></label>
			<label>DeepSeek key <input type="password" name="deepseek_key" class="regular-text" autocomplete="off" placeholder="<?php echo $settings['deepseek_key'] ? 'configured' : 'not configured'; ?>"></label></p>
			<p><label>Merchant mode
				<select name="merchant_mode">
					<option value="off" <?php selected( $settings['merchant_mode'], 'off' ); ?>>Off</option>
					<option value="from" <?php selected( $settings['merchant_mode'], 'from' ); ?>>From merchants (inbound)</option>
					<option value="to" <?php selected( $settings['merchant_mode'], 'to' ); ?>>To merchants (outbound)</option>
					<option value="both" <?php selected( $settings['merchant_mode'], 'both' ); ?>>Both</option>
				</select>
			</label></p>
			<p><label>Outbound URL <input type="url" name="merchant_to_url" class="regular-text" value="<?php echo esc_attr( $settings['merchant_to_url'] ); ?>"></label>
			<label>Shared token <input type="password" name="merchant_token" class="regular-text" autocomplete="off" placeholder="<?php echo $settings['merchant_token'] ? 'configured' : 'not configured'; ?>"></label></p>

			<h2>Growth, schema and affiliate</h2>
			<p><label><input type="checkbox" name="schema_enabled" value="1" <?php checked( $settings['schema_enabled'] ); ?>> Output Product/Article JSON-LD alongside Yoast SEO</label></p>
			<p><label>Affiliate tags (platform|tag, one per line: shopify, woocommerce, aliexpress, cj, spocket, zendrop, printify)<br><textarea name="affiliate_tags" rows="3" class="large-text code"><?php echo esc_textarea( pca_tags_to_text( $settings['affiliate_tags'] ) ); ?></textarea></label></p>
			<p><label>Owned domains to audit (one per line)<br><textarea name="owned_domains" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings['owned_domains'] ) ); ?></textarea></label></p>

			<h2>SEO and schedule</h2>
			<p><label><input type="checkbox" name="seo_enabled" value="1" <?php checked( $settings['seo_enabled'] ); ?>> Write Yoast meta fields on imported posts</label></p>
			<p><label>Sync interval
				<select name="interval">
					<option value="hourly" <?php selected( $settings['interval'], 'hourly' ); ?>>Hourly</option>
					<option value="twicedaily" <?php selected( $settings['interval'], 'twicedaily' ); ?>>Twice daily</option>
					<option value="daily" <?php selected( $settings['interval'], 'daily' ); ?>>Daily</option>
				</select>
			</label></p>
			<?php submit_button( 'Save settings' ); ?>
		</form>

		<h2>Manual actions</h2>
		<p>
			<button class="button pca-sync" data-action="feeds">Sync feeds now</button>
			<button class="button pca-sync" data-action="prices">Sync prices now</button>
			<input type="text" id="pca-research-sku" placeholder="product SKU" size="18">
			<button class="button" type="button" id="pca-research">Research merchant</button>
			<input type="text" id="pca-keyword-seed" placeholder="keyword seed" size="18">
			<button class="button" type="button" id="pca-keywords">Keyword variants</button>
			<input type="number" id="pca-clean-post-id" placeholder="post ID" size="8">
			<button class="button" type="button" id="pca-clean-post">Clean hidden characters</button>
			<span id="pca-sync-status" aria-live="polite"></span>
		</p>

		<h2>Price suggestions</h2>
		<?php if ( empty( $suggestions ) ) : ?><p>No suggestions yet.</p><?php else : ?>
		<table class="widefat"><thead><tr><th>SKU</th><th>Name</th><th>Market</th><th>Suggested</th></tr></thead><tbody>
			<?php foreach ( (array) $suggestions as $sku => $row ) : ?>
				<tr><td><?php echo esc_html( $sku ); ?></td><td><?php echo esc_html( $row['name'] ?? '' ); ?></td><td><?php echo esc_html( pca_money( $row['market'] ?? 0 ) ); ?></td><td><?php echo esc_html( pca_money( $row['price'] ?? 0 ) ); ?></td></tr>
			<?php endforeach; ?>
		</tbody></table>
		<?php endif; ?>

		<h2>Recent log</h2>
		<ul class="pca-log">
			<?php foreach ( array_slice( (array) $log, 0, 20 ) as $entry ) : ?>
				<li><code><?php echo esc_html( $entry['time'] ); ?></code> <?php echo esc_html( $entry['message'] ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}

function pca_products_to_text( $products ) {
	$lines = array();
	foreach ( (array) $products as $product ) {
		$lines[] = sprintf( '%s|%s|%s|%s', $product['sku'] ?? '', $product['name'] ?? '', $product['query'] ?? '', $product['cost'] ?? '' );
	}
	return implode( "\n", $lines );
}

function pca_tags_to_text( $tags ) {
	$lines = array();
	foreach ( (array) $tags as $platform => $tag ) { $lines[] = sanitize_key( $platform ) . '|' . sanitize_text_field( $tag ); }
	return implode( "\n", $lines );
}
