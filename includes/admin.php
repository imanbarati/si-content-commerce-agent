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
