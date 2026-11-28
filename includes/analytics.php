<?php
/**
 * Advanced Analytics Dashboard
 * 
 * - Revenue attribution by channel/source
 * - ROAS tracking (Google Ads, Merchant Center, organic)
 * - Conversion funnels (view -> cart -> checkout -> purchase)
 * - Cohort analysis (retention, LTV)
 * - Product performance (revenue, margin, velocity)
 * - Real-time dashboard widget
 * - Exportable reports (CSV, JSON)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Analytics event tracking (server-side)
 */
function pca_analytics_track( $event, $properties = array() ) {
	$settings = pca_get_effective_settings();
	if ( empty( $settings['ga4_measurement_id'] ) ) { return false; }
	
	$defaults = array(
		'currency' => 'IRR',
		'value'    => 0,
	);
	$props = array_merge( $defaults, $properties );
	
	return pca_ga4_ecommerce_event( $event, $props['items'] ?? array(), $props );
}

/**
 * Track product view with attribution
 */
function pca_analytics_track_view( $product_id, $attribution = array() ) {
	if ( ! function_exists( 'wc_get_product' ) ) { return false; }
	$product = wc_get_product( $product_id );
	if ( ! $product ) { return false; }
	
	$items = array( array(
		'item_id'       => $product->get_sku(),
		'item_name'     => $product->get_name(),
		'price'         => (float) $product->get_price(),
		'item_brand'    => $product->get_attribute( 'pa_brand' ) ?: pca_brand(),
		'item_category' => $product->get_meta( '_pca_google_category' ) ?: '',
		'quantity'      => 1,
	) );
	
	$props = array_merge( array(
		'items'        => $items,
		'value'        => (float) $product->get_price(),
	), $attribution );
	
	return pca_ga4_ecommerce_event( 'view_item', $items, $props );
}

/**
 * Revenue attribution by source
 */
function pca_analytics_revenue_by_source( $days = 30 ) {
	global $wpdb;
	
	$table = $wpdb->prefix . 'wc_order_stats';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) !== $table ) {
		return array();
	}
	
	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT 
			COALESCE(meta1.meta_value, 'direct') as source,
			COUNT(DISTINCT o.ID) as orders,
			SUM(o.total_sales) as revenue,
			AVG(o.total_sales) as aov
		FROM {$wpdb->posts} o
		LEFT JOIN {$wpdb->postmeta} meta1 ON o.ID = meta1.post_id AND meta1.meta_key = '_pca_source'
		WHERE o.post_type = 'shop_order'
		  AND o.post_status IN ('wc-completed','wc-processing','wc-on-hold')
		  AND o.post_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
		GROUP BY source
		ORDER BY revenue DESC",
		$days
	), ARRAY_A );
	
	return $results ?: array();
}

/**
 * ROAS calculation (requires Google Ads cost data)
 */
function pca_analytics_roas( $days = 30 ) {
	$settings = pca_get_effective_settings();
	
	if ( empty( $settings['google_ads_customer_id'] ) || empty( $settings['google_ads_developer_token'] ) ) {
		return array( 'error' => 'Google Ads not configured' );
	}
	
	// This would call Google Ads API for cost data
	// For now, return placeholder structure
	return array(
		'period_days' => $days,
		'campaigns'   => array(),
		'total_spend' => 0,
		'total_revenue' => 0,
		'roas'        => 0,
		'note'        => 'Requires Google Ads API integration'
	);
}

/**
 * Conversion funnel
 */
function pca_analytics_funnel( $days = 30 ) {
	if ( ! class_exists( 'WooCommerce' ) ) { return array(); }
	
	$orders = wc_get_orders( array(
		'limit'   => -1,
		'status'  => array( 'completed', 'processing', 'on-hold' ),
		'date_created' => ( new DateTime() )->modify( "-{$days} days" ),
	) );
	
	$funnel = array(
		'sessions'       => 0, // Would need GA4 data
		'product_views'  => 0, // Would need GA4 data
		'add_to_cart'    => 0, // Would need GA4 data
		'begin_checkout' => 0, // Would need GA4 data
		'purchases'      => count( $orders ),
		'revenue'        => 0,
	);
	
	foreach ( $orders as $order ) {
		$funnel['revenue'] += $order->get_total();
	}
	
	// Calculate rates
	$prev = $funnel['sessions'];
	foreach ( array( 'product_views', 'add_to_cart', 'begin_checkout', 'purchases' ) as $step ) {
		$funnel[ $step . '_rate' ] = $prev > 0 ? round( $funnel[ $step ] / $prev * 100, 2 ) : 0;
		$prev = $funnel[ $step ];
	}
	
	return $funnel;
}

/**
 * Cohort analysis (monthly cohorts)
 */
function pca_analytics_cohorts( $months = 6 ) {
	if ( ! class_exists( 'WooCommerce' ) ) { return array(); }
	
	global $wpdb;
	
	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT 
			DATE_FORMAT(u.user_registered, '%%Y-%%m') as cohort,
			COUNT(DISTINCT u.ID) as users,
			COUNT(DISTINCT o.ID) as orders,
			SUM(o.total_sales) as revenue
		FROM {$wpdb->users} u
		LEFT JOIN {$wpdb->posts} o ON o.post_author = u.ID AND o.post_type = 'shop_order' AND o.post_status IN ('wc-completed','wc-processing')
		WHERE u.user_registered >= DATE_SUB(NOW(), INTERVAL %d MONTH)
		GROUP BY cohort
		ORDER BY cohort",
		$months
	), ARRAY_A );
	
	$cohorts = array();
	foreach ( $results as $row ) {
		$cohort = $row['cohort'];
		$cohorts[ $cohort ] = array(
			'users'    => (int) $row['users'],
			'orders'   => (int) $row['orders'],
			'revenue'  => (float) $row['revenue'],
			'arpdau'   => $row['users'] > 0 ? round( $row['revenue'] / $row['users'], 2 ) : 0,
		);
	}
	
	return $cohorts;
}

/**
 * Product performance
 */
function pca_analytics_product_performance( $limit = 50, $days = 30 ) {
	if ( ! class_exists( 'WooCommerce' ) ) { return array(); }
	
	global $wpdb;
	
	$results = $wpdb->get_results( $wpdb->prepare(
		"SELECT 
			pm.meta_value as sku,
			p.post_title as name,
			COUNT(DISTINCT oi.order_id) as orders,
			SUM(oi.qty) as units_sold,
			SUM(oi.total) as revenue,
			AVG(oi.total / oi.qty) as avg_price
		FROM {$wpdb->postmeta} pm
		JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND pm.meta_key = '_sku'
		JOIN {$wpdb->woocommerce_order_items} oi ON oi.order_item_name = p.post_title
		JOIN {$wpdb->posts} o ON o.ID = oi.order_id AND o.post_type = 'shop_order' AND o.post_status IN ('wc-completed','wc-processing')
		WHERE o.post_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
		GROUP BY pm.meta_value, p.post_title
		ORDER BY revenue DESC
		LIMIT %d",
		$days, $limit
	), ARRAY_A );
	
	$products = array();
	foreach ( $results as $row ) {
		$products[] = array(
			'sku'         => $row['sku'],
			'name'        => $row['name'],
			'orders'      => (int) $row['orders'],
			'units_sold'  => (int) $row['units_sold'],
			'revenue'     => (float) $row['revenue'],
			'avg_price'   => (float) $row['avg_price'],
			'velocity'    => (int) $row['units_sold'] / max( 1, $days ), // units/day
		);
	}
	
	return $products;
}

/**
 * Margin analysis (requires cost data)
 */
function pca_analytics_margins( $days = 30 ) {
	$settings = pca_get_effective_settings();
	$products = (array) $settings['products'];
	
	$margin_data = array();
	foreach ( $products as $p ) {
		$sku = $p['sku'];
		$cost = (float) ( $p['cost'] ?? 0 );
		if ( $cost <= 0 ) { continue; }
		
		$suggestions = get_option( 'pca_price_suggestions', array() );
		$price = isset( $suggestions[ $sku ]['price'] ) ? $suggestions[ $sku ]['price'] : ( (float) $p['cost'] * 1.2 );
		
		$margin_data[ $sku ] = array(
			'name'   => $p['name'],
			'cost'   => $cost,
			'price'  => $price,
			'margin' => round( ( $price - $cost ) / $price * 100, 2 ),
			'markup' => round( ( $price - $cost ) / $cost * 100, 2 ),
		);
	}
	
	return $margin_data;
}

/**
 * Admin: Analytics Dashboard
 */
add_action( 'admin_menu', function () {
	add_submenu_page(
		'tools.php',
		'SI Analytics',
		'Analytics',
		'manage_options',
		'pca-analytics',
		'pca_analytics_page'
	);
} );

function pca_analytics_page() {
	if ( ! current_user_can( 'manage_options' ) ) { return; }
	
	$days = absint( $_GET['days'] ?? 30 );
	$days = in_array( $days, array( 7, 30, 60, 90 ) ) ? $days : 30;
	
	$revenue_by_source = pca_analytics_revenue_by_source( $days );
	$funnel = pca_analytics_funnel( $days );
	$cohorts = pca_analytics_cohorts( 6 );
	$top_products = pca_analytics_product_performance( 20, $days );
	$margins = pca_analytics_margins();
	
	?>
	<div class="wrap pca-analytics-wrap">
		<h1>SI Analytics Dashboard</h1>
		
		<form method="get" style="margin-bottom: 20px;">
			<input type="hidden" name="page" value="pca-analytics">
			<label>Period:
			<select name="days" onchange="this.form.submit()">
				<option value="7" <?php selected( $days, 7 ); ?>>Last 7 days</option>
				<option value="30" <?php selected( $days, 30 ); ?>>Last 30 days</option>
				<option value="60" <?php selected( $days, 60 ); ?>>Last 60 days</option>
				<option value="90" <?php selected( $days, 90 ); ?>>Last 90 days</option>
			</select>
			</label>
			<button type="submit" class="button">Apply</button>
			<a href="?page=pca-analytics&export=csv&days=<?php echo $days; ?>" class="button button-secondary">Export CSV</a>
		</form>
		
		<?php if ( isset( $_GET['export'] ) ) :
			header( 'Content-Type: text/csv' );
			header( 'Content-Disposition: attachment; filename="analytics-' . $days . 'd.csv"' );
			echo "Metric,Value\n";
			echo "Period (days),{$days}\n";
			echo "Revenue by Source\n";
			echo "Source,Orders,Revenue,AOV\n";
			foreach ( $revenue_by_source as $r ) { echo "{$r['source']},{$r['orders']},{$r['revenue']},{$r['aov']}\n"; }
			echo "\nFunnel\n";
			foreach ( $funnel as $k => $v ) { echo "{$k},{$v}\n"; }
			exit;
		endif; ?>
		
		<div class="pca-analytics-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
			<!-- Revenue by Source -->
			<div class="pca-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:20px;">
				<h2 style="margin-top:0">Revenue by Source (<?php echo $days; ?>d)</h2>
				<?php if ( empty( $revenue_by_source ) ) : ?>
				<p>No order data or WooCommerce not active.</p>
				<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>Source</th><th>Orders</th><th>Revenue</th><th>AOV</th></tr></thead>
					<tbody>
					<?php foreach ( $revenue_by_source as $r ) : ?>
						<tr><td><?php echo esc_html( $r['source'] ); ?></td><td><?php echo number_format( $r['orders'] ); ?></td><td><?php echo number_format( $r['revenue'], 0, '.', ',' ); ?> IRR</td><td><?php echo number_format( $r['aov'], 0, '.', ',' ); ?> IRR</td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
			
			<!-- Conversion Funnel -->
			<div class="pca-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:20px;">
				<h2 style="margin-top:0">Conversion Funnel</h2>
				<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
					<div style="flex:1;min-width:120px;text-align:center;padding:15px;background:#f6f7f7;border-radius:4px;">
						<div style="font-size:24px;font-weight:600;"><?php echo number_format( $funnel['sessions'] ?? 0 ); ?></div>
						<div style="font-size:12px;color:#646970;">Sessions</div>
					</div>
					<div style="flex:1;min-width:120px;text-align:center;padding:15px;background:#f6f7f7;border-radius:4px;">
						<div style="font-size:24px;font-weight:600;"><?php echo number_format( $funnel['product_views'] ?? 0 ); ?></div>
						<div style="font-size:12px;color:#646970;">Product Views</div>
					</div>
					<div style="flex:1;min-width:120px;text-align:center;padding:15px;background:#f6f7f7;border-radius:4px;">
						<div style="font-size:24px;font-weight:600;"><?php echo number_format( $funnel['add_to_cart'] ?? 0 ); ?></div>
						<div style="font-size:12px;color:#646970;">Add to Cart</div>
					</div>
					<div style="flex:1;min-width:120px;text-align:center;padding:15px;background:#f6f7f7;border-radius:4px;">
						<div style="font-size:24px;font-weight:600;"><?php echo number_format( $funnel['begin_checkout'] ?? 0 ); ?></div>
						<div style="font-size:12px;color:#646970;">Begin Checkout</div>
					</div>
					<div style="flex:1;min-width:120px;text-align:center;padding:15px;background:#f6f7f7;border-radius:4px;">
						<div style="font-size:24px;font-weight:600;"><?php echo number_format( $funnel['purchases'] ); ?></div>
						<div style="font-size:12px;color:#646970;">Purchases</div>
					</div>
				</div>
				<table class="widefat" style="margin-top:10px;">
					<tr><th>Step</th><th>Count</th><th>Rate</th></tr>
					<tr><td>Product Views</td><td><?php echo number_format( $funnel['product_views'] ?? 0 ); ?></td><td><?php echo $funnel['product_views_rate'] ?? 0; ?>%</td></tr>
					<tr><td>Add to Cart</td><td><?php echo number_format( $funnel['add_to_cart'] ?? 0 ); ?></td><td><?php echo $funnel['add_to_cart_rate'] ?? 0; ?>%</td></tr>
					<tr><td>Begin Checkout</td><td><?php echo number_format( $funnel['begin_checkout'] ?? 0 ); ?></td><td><?php echo $funnel['begin_checkout_rate'] ?? 0; ?>%</td></tr>
					<tr><td>Purchases</td><td><?php echo number_format( $funnel['purchases'] ); ?></td><td><?php echo $funnel['purchases_rate'] ?? 0; ?>%</td></tr>
				</table>
				<p style="font-size:12px;color:#646970;">Revenue: <?php echo number_format( $funnel['revenue'], 0, '.', ',' ); ?> IRR</p>
			</div>
			
			<!-- Top Products -->
			<div class="pca-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:20px;grid-column:1/-1;">
				<h2 style="margin-top:0">Top Products by Revenue (<?php echo $days; ?>d)</h2>
				<?php if ( empty( $top_products ) ) : ?>
				<p>No product data.</p>
				<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>SKU</th><th>Name</th><th>Orders</th><th>Units</th><th>Revenue</th><th>Avg Price</th><th>Velocity (units/day)</th></tr></thead>
					<tbody>
					<?php foreach ( $top_products as $p ) : ?>
						<tr><td><?php echo esc_html( $p['sku'] ); ?></td><td><?php echo esc_html( $p['name'] ); ?></td><td><?php echo $p['orders']; ?></td><td><?php echo $p['units_sold']; ?></td><td><?php echo number_format( $p['revenue'], 0, '.', ',' ); ?> IRR</td><td><?php echo number_format( $p['avg_price'], 0, '.', ',' ); ?> IRR</td><td><?php echo number_format( $p['velocity'], 2 ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
			
			<!-- Margins -->
			<div class="pca-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:20px;grid-column:1/-1;">
				<h2 style="margin-top:0">Product Margins</h2>
				<?php if ( empty( $margins ) ) : ?>
				<p>Configure product costs in settings to see margins.</p>
				<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>SKU</th><th>Name</th><th>Cost</th><th>Price</th><th>Margin %</th><th>Markup %</th></tr></thead>
					<tbody>
					<?php foreach ( $margins as $sku => $m ) : ?>
						<tr><td><?php echo esc_html( $sku ); ?></td><td><?php echo esc_html( $m['name'] ); ?></td><td><?php echo number_format( $m['cost'], 0, '.', ',' ); ?></td><td><?php echo number_format( $m['price'], 0, '.', ',' ); ?></td><td><?php echo $m['margin']; ?>%</td><td><?php echo $m['markup']; ?>%</td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
			
			<!-- Cohorts -->
			<div class="pca-card" style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:20px;grid-column:1/-1;">
				<h2 style="margin-top:0">Monthly Cohorts (6 months)</h2>
				<?php if ( empty( $cohorts ) ) : ?>
				<p>No cohort data.</p>
				<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>Cohort</th><th>Users</th><th>Orders</th><th>Revenue</th><th>ARPDAU</th></tr></thead>
					<tbody>
					<?php foreach ( $cohorts as $cohort => $c ) : ?>
						<tr><td><?php echo esc_html( $cohort ); ?></td><td><?php echo $c['users']; ?></td><td><?php echo $c['orders']; ?></td><td><?php echo number_format( $c['revenue'], 0, '.', ',' ); ?></td><td><?php echo number_format( $c['arpdau'], 2 ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * REST API for analytics
 */
add_action( 'rest_api_init', function () {
	rest_register_route( 'posmana-agent/v2', '/analytics/revenue', array(
		'methods'  => 'GET',
		'callback' => function( $req ) { return pca_analytics_revenue_by_source( $req->get_param( 'days' ) ?? 30 ); },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'analytics' ); },
	));
	
	rest_register_route( 'posmana-agent/v2', '/analytics/funnel', array(
		'methods'  => 'GET',
		'callback' => function( $req ) { return pca_analytics_funnel( $req->get_param( 'days' ) ?? 30 ); },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'analytics' ); },
	));
	
	rest_register_route( 'posmana-agent/v2', '/analytics/cohorts', array(
		'methods'  => 'GET',
		'callback' => function( $req ) { return pca_analytics_cohorts( $req->get_param( 'months' ) ?? 6 ); },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'analytics' ); },
	));
	
	rest_register_route( 'posmana-agent/v2', '/analytics/products', array(
		'methods'  => 'GET',
		'callback' => function( $req ) { return pca_analytics_product_performance( $req->get_param( 'limit' ) ?? 50, $req->get_param( 'days' ) ?? 30 ); },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'analytics' ); },
	));
	
	rest_register_route( 'posmana-agent/v2', '/analytics/margins', array(
		'methods'  => 'GET',
		'callback' => function() { return pca_analytics_margins(); },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'analytics' ); },
	));
} );

/**
 * Widget for dashboard
 */
add_action( 'wp_dashboard_setup', function () {
	wp_add_dashboard_widget(
		'pca_analytics_widget',
		'SI Analytics Summary',
		function() {
			$funnel = pca_analytics_funnel( 30 );
			$revenue = array_sum( array_column( pca_analytics_revenue_by_source( 30 ), 'revenue' ) );
			?>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;">
				<div style="background:#fff;border:1px solid #ddd;padding:15px;border-radius:4px;text-align:center;"><div style="font-size:20px;font-weight:600;"><?php echo number_format( $revenue, 0, '.', ',' ); ?></div><div style="font-size:11px;color:#646970;">30d Revenue (IRR)</div></div>
				<div style="background:#fff;border:1px solid #ddd;padding:15px;border-radius:4px;text-align:center;"><div style="font-size:20px;font-weight:600;"><?php echo number_format( $funnel['purchases'] ); ?></div><div style="font-size:11px;color:#646970;">30d Orders</div></div>
				<div style="background:#fff;border:1px solid #ddd;padding:15px;border-radius:4px;text-align:center;"><div style="font-size:20px;font-weight:600;"><?php echo $funnel['purchases_rate'] ?? 0; ?>%</div><div style="font-size:11px;color:#646970;">Conversion Rate</div></div>
				<div style="background:#fff;border:1px solid #ddd;padding:15px;border-radius:4px;text-align:center;"><div style="font-size:20px;font-weight:600;"><?php echo count( pca_analytics_product_performance( 10, 30 ) ); ?></div><div style="font-size:11px;color:#646970;">Active Products</div></div>
			</div>
			<?php
		}
	);
} );
