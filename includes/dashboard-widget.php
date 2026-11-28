<?php
/**
 * Admin Dashboard Widget
 * 
 * Quick stats on WordPress dashboard: feed status, price suggestions,
 * research reports, GA4 events, Merchant Center sync.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Register dashboard widget
 */
add_action( 'wp_dashboard_setup', 'pca_register_dashboard_widget' );

function pca_register_dashboard_widget() {
	wp_add_dashboard_widget(
		'pca_dashboard_widget',
		'SI Content & Commerce Agent',
		'pca_dashboard_widget_callback'
	);
}

/**
 * Widget content callback
 */
function pca_dashboard_widget_callback() {
	$settings = pca_settings();
	$log = get_option( 'pca_log', array() );
	$suggestions = get_option( 'pca_price_suggestions', array() );
	$reports = get_option( 'pca_research_reports', array() );
	
	$last_feed = pca_last_run( 'feeds' );
	$last_price = pca_last_run( 'prices' );
	$last_research = pca_last_run( 'research' );
	$last_mc = pca_last_run( 'mc_content_api' );
	?>
	<div class="pca-dashboard-widget">
		<style>
			.pca-dashboard-widget .pca-stats-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
				gap: 12px;
				margin-bottom: 16px;
			}
			.pca-dashboard-widget .pca-stat {
				background: #fff;
				border: 1px solid #ddd;
				border-radius: 4px;
				padding: 16px;
				text-align: center;
			}
			.pca-dashboard-widget .pca-stat-value {
				font-size: 28px;
				font-weight: 600;
				color: #1d2327;
				line-height: 1.2;
			}
			.pca-dashboard-widget .pca-stat-label {
				font-size: 12px;
				color: #646970;
				text-transform: uppercase;
				letter-spacing: 0.5px;
				margin-top: 4px;
			}
			.pca-dashboard-widget .pca-stat.ok { border-left: 4px solid #28a745; }
			.pca-dashboard-widget .pca-stat.warn { border-left: 4px solid #ffb900; }
			.pca-dashboard-widget .pca-stat.error { border-left: 4px solid #dc3232; }
			.pca-dashboard-widget .pca-recent-log {
				background: #fff;
				border: 1px solid #ddd;
				border-radius: 4px;
				max-height: 200px;
				overflow-y: auto;
			}
			.pca-dashboard-widget .pca-log-item {
				padding: 10px 16px;
				border-bottom: 1px solid #eee;
				font-size: 13px;
				font-family: Menlo, Consolas, monospace;
			}
			.pca-dashboard-widget .pca-log-item:last-child { border-bottom: none; }
			.pca-dashboard-widget .pca-log-time { color: #646970; margin-right: 10px; }
			.pca-dashboard-widget .pca-actions {
				display: flex;
				gap: 8px;
				flex-wrap: wrap;
				margin-top: 16px;
			}
			.pca-dashboard-widget .pca-status-indicator {
				display: inline-block;
				width: 8px;
				height: 8px;
				border-radius: 50%;
				margin-right: 8px;
			}
			.pca-dashboard-widget .pca-status-indicator.active { background: #28a745; }
			.pca-dashboard-widget .pca-status-indicator.inactive { background: #dc3232; }
			.pca-dashboard-widget .pca-status-indicator.pending { background: #ffb900; }
		</style>
		
		<div class="pca-stats-grid">
			<div class="pca-stat <?php echo $settings['feeds'] ? 'ok' : 'warn'; ?>">
				<div class="pca-stat-value"><?php echo count( (array) $settings['feeds'] ); ?></div>
				<div class="pca-stat-label">RSS Feeds Configured</div>
			</div>
			
			<div class="pca-stat <?php echo $settings['x_handles'] ? 'ok' : 'warn'; ?>">
				<div class="pca-stat-value"><?php echo count( (array) $settings['x_handles'] ); ?></div>
				<div class="pca-stat-label">X Handles</div>
			</div>
			
			<div class="pca-stat <?php echo $settings['products'] ? 'ok' : 'warn'; ?>">
				<div class="pca-stat-value"><?php echo count( (array) $settings['products'] ); ?></div>
				<div class="pca-stat-label">Products Tracked</div>
			</div>
			
			<div class="pca-stat <?php echo ! empty( $suggestions ) ? 'ok' : 'warn'; ?>">
				<div class="pca-stat-value"><?php echo count( $suggestions ); ?></div>
				<div class="pca-stat-label">Price Suggestions</div>
			</div>
			
			<div class="pca-stat <?php echo ! empty( $reports ) ? 'ok' : 'warn'; ?>">
				<div class="pca-stat-value"><?php echo count( $reports ); ?></div>
				<div class="pca-stat-label">Research Reports</div>
			</div>
			
			<div class="pca-stat <?php echo ! empty( $settings['ai_endpoint'] ) ? 'ok' : 'warn'; ?>">
				<div class="pca-stat-value">
					<span class="pca-status-indicator <?php echo ! empty( $settings['ai_endpoint'] ) ? 'active' : 'inactive'; ?>"></span>
				</div>
				<div class="pca-stat-label">AI Enrichment</div>
			</div>
		</div>
		
		<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px;">
			<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px;">
				<strong>Last Feed Sync</strong><br>
				<span style="color: #646970; font-family: monospace;"><?php echo esc_html( $last_feed ); ?></span>
			</div>
			<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px;">
				<strong>Last Price Sync</strong><br>
				<span style="color: #646970; font-family: monospace;"><?php echo esc_html( $last_price ); ?></span>
			</div>
			<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px;">
				<strong>Last Research</strong><br>
				<span style="color: #646970; font-family: monospace;"><?php echo esc_html( $last_research ); ?></span>
			</div>
			<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px;">
				<strong>Last MC API Sync</strong><br>
				<span style="color: #646970; font-family: monospace;"><?php echo esc_html( $last_mc ); ?></span>
			</div>
		</div>
		
		<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 12px; margin-bottom: 16px;">
			<strong>Service Status</strong>
			<div style="display: flex; flex-wrap: wrap; gap: 16px; margin-top: 8px; font-size: 13px;">
				<span><span class="pca-status-indicator <?php echo class_exists( 'WooCommerce' ) ? 'active' : 'inactive'; ?>"></span>WooCommerce: <?php echo class_exists( 'WooCommerce' ) ? 'Active' : 'Not installed'; ?></span>
				<span><span class="pca-status-indicator <?php echo defined( 'WPSEO_VERSION' ) ? 'active' : 'inactive'; ?>"></span>Yoast SEO: <?php echo defined( 'WPSEO_VERSION' ) ? 'Active' : 'Not installed'; ?></span>
				<span><span class="pca-status-indicator <?php echo ! empty( $settings['merchant_id'] ) ? 'active' : 'inactive'; ?>"></span>Merchant Center: <?php echo ! empty( $settings['merchant_id'] ) ? 'Configured' : 'Not configured'; ?></span>
				<span><span class="pca-status-indicator <?php echo ! empty( $settings['google_service_account_json'] ) ? 'active' : 'inactive'; ?>"></span>MC Content API: <?php echo ! empty( $settings['google_service_account_json'] ) ? 'Configured' : 'Not configured'; ?></span>
				<span><span class="pca-status-indicator <?php echo ! empty( $settings['ga4_measurement_id'] ) ? 'active' : 'inactive'; ?>"></span>GA4: <?php echo ! empty( $settings['ga4_measurement_id'] ) ? 'Configured' : 'Not configured'; ?></span>
				<span><span class="pca-status-indicator <?php echo ! empty( $settings['cse_api_key'] ) ? 'active' : 'inactive'; ?>"></span>Google CSE: <?php echo ! empty( $settings['cse_api_key'] ) ? 'Configured' : 'Not configured'; ?></span>
			</div>
		</div>
		
		<div class="pca-recent-log">
			<strong style="display: block; padding: 12px 16px; border-bottom: 1px solid #eee;">Recent Activity</strong>
			<?php if ( empty( $log ) ) : ?>
				<div class="pca-log-item" style="color: #646970; text-align: center; padding: 24px;">No activity yet</div>
			<?php else : ?>
				<?php foreach ( array_slice( $log, 0, 10 ) as $entry ) : ?>
					<div class="pca-log-item">
						<span class="pca-log-time"><?php echo esc_html( $entry['time'] ); ?></span>
						<?php echo esc_html( $entry['message'] ); ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		
		<div class="pca-actions">
			<a href="<?php echo admin_url( 'tools.php?page=posmana-agent' ); ?>" class="button button-primary">Open Settings</a>
			<button type="button" class="button" onclick="location.href='<?php echo admin_url( 'admin-ajax.php?action=pca_export_settings' ); ?>'">Export Settings</button>
			<?php if ( class_exists( 'WooCommerce' ) ) : ?>
				<a href="<?php echo admin_url( 'admin.php?page=wc-settings&tab=products' ); ?>" class="button">WooCommerce Products</a>
			<?php endif; ?>
			<a href="<?php echo esc_url( home_url( '/wp-json/posmana-agent/v1/merchant/feed' ) ); ?>" target="_blank" class="button">View Merchant Feed</a>
		</div>
	</div>
	<?php
}
