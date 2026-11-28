<?php
/**
 * WooCommerce Integration
 * 
 * - Sync price suggestions to WC products by SKU
 * - Generate Merchant Center feed from WC catalog
 * - GA4 Enhanced Ecommerce events
 * - Product import from feeds → WC products
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Check if WooCommerce is active
 */
function pca_wc_active() {
	return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
}

/**
 * Get WC product by SKU
 */
function pca_wc_get_product_by_sku( $sku ) {
	if ( ! pca_wc_active() ) { return null; }
	$product_id = wc_get_product_id_by_sku( $sku );
	if ( ! $product_id ) { return null; }
	return wc_get_product( $product_id );
}

/**
 * Update WC product price from suggestion
 */
function pca_wc_update_product_price( $sku, $price ) {
	if ( ! pca_wc_active() ) { return false; }
	$product = pca_wc_get_product_by_sku( $sku );
	if ( ! $product ) { return false; }
	
	$product->set_price( (float) $price );
	$product->save();
	pca_log( 'WC price updated: ' . $sku . ' = ' . $price );
	return true;
}

/**
 * Get all WC products with SKU for Merchant Center feed
 */
function pca_wc_get_products_for_feed() {
	if ( ! pca_wc_active() ) { return array(); }
	
	$products = wc_get_products( array(
		'limit'    => -1,
		'status'   => 'publish',
		'meta_key' => '_sku',
		'return'   => 'objects',
	) );
	
	$feed = array();
	foreach ( $products as $product ) {
		$sku = $product->get_sku();
		if ( empty( $sku ) ) { continue; }
		
		$price = $product->get_price();
		$sale_price = $product->get_sale_price();
		
		$feed[] = array(
			'id'                   => $sku,
			'title'                => $product->get_name(),
			'description'          => wp_kses_post( $product->get_description() ),
			'link'                 => $product->get_permalink(),
			'image_link'           => $product->get_image_id() ? wp_get_attachment_url( $product->get_image_id() ) : '',
			'availability'         => $product->is_in_stock() ? 'in stock' : 'out of stock',
			'price'                => number_format( (float) $price, 2, '.', '' ) . ' IRR',
			'sale_price'           => $sale_price ? number_format( (float) $sale_price, 2, '.', '' ) . ' IRR' : '',
			'google_product_category' => $product->get_meta( '_pca_google_category' ) ?: '',
			'brand'                => $product->get_attribute( 'pa_brand' ) ?: pca_brand(),
			'condition'            => 'new',
			'identifier_exists'    => 'false',
			'gtin'                 => $product->get_global_unique_id() ?: '',
			'mpn'                  => $product->get_sku(),
			'item_group_id'        => $product->get_parent_id() ? $product->get_parent_id() : '',
			'gender'               => '',
			'age_group'            => '',
			'color'                => $product->get_attribute( 'pa_color' ) ?: '',
			'size'                 => $product->get_attribute( 'pa_size' ) ?: '',
			'shipping_weight'      => $product->get_weight() ? $product->get_weight() . ' kg' : '',
		);
	}
	return $feed;
}

/**
 * Sync price suggestions to WooCommerce
 */
function pca_wc_sync_prices() {
	if ( ! pca_wc_active() ) { return 0; }
	
	$settings = pca_settings();
	if ( empty( $settings['price_auto_apply'] ) ) { return 0; }
	
	$suggestions = get_option( 'pca_price_suggestions', array() );
	$applied = 0;
	
	foreach ( (array) $suggestions as $sku => $data ) {
		if ( pca_wc_update_product_price( $sku, $data['price'] ) ) {
			$applied++;
		}
	}
	
	if ( $applied ) {
		pca_log( 'WC price sync: ' . $applied . ' product(s) updated' );
	}
	return $applied;
}

/**
 * Create WC product from imported feed item
 */
function pca_wc_create_product_from_feed( $item, $settings ) {
	if ( ! pca_wc_active() ) { return false; }
	
	$sku = sanitize_key( $item['guid'] ?? 'feed-' . wp_generate_uuid4() );
	
	// Check if product already exists
	$existing = pca_wc_get_product_by_sku( $sku );
	if ( $existing ) { return $existing->get_id(); }
	
	$product = new WC_Product_Simple();
	$product->set_name( $item['title'] );
	$product->set_sku( $sku );
	$product->set_description( $item['description'] ?? '' );
	$product->set_short_description( wp_trim_words( $item['description'] ?? '', 30 ) );
	$product->set_status( 'draft' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_price( 0 ); // Will be updated by price sync
	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
	
	$product_id = $product->save();
	
	if ( $product_id && ! is_wp_error( $product_id ) ) {
		// Store source metadata
		update_post_meta( $product_id, '_pca_source', 'feed' );
		update_post_meta( $product_id, '_pca_source_url', $item['url'] ?? '' );
		update_post_meta( $product_id, '_pca_guid', $item['guid'] );
		pca_log( 'WC product created from feed: ' . $sku . ' (ID: ' . $product_id . ')' );
	}
	
	return $product_id;
}

/**
 * GA4 Enhanced Ecommerce - Server-side events
 */
function pca_ga4_ecommerce_event( $event_name, $items = array(), $params = array() ) {
	$settings = pca_settings();
	$measurement_id = $settings['ga4_measurement_id'] ?? '';
	$api_secret = $settings['ga4_api_secret'] ?? '';
	
	if ( empty( $measurement_id ) ) { return false; }
	
	$client_id = pca_get_client_id();
	
	$event_params = array_merge( array(
		'currency' => 'IRR',
		'value'    => 0,
		'items'    => $items,
	), $params );
	
	$payload = array(
		'client_id' => $client_id,
		'events'    => array( array(
			'name'   => $event_name,
			'params' => $event_params,
		) ),
	);
	
	$url = 'https://www.google-analytics.com/mp/collect';
	$url = add_query_arg( 'measurement_id', $measurement_id, $url );
	if ( ! empty( $api_secret ) ) {
		$url = add_query_arg( 'api_secret', $api_secret, $url );
	}
	
	$response = wp_remote_post( $url, array(
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( $payload ),
		'timeout' => 5,
	) );
	
	return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 204;
}

/**
 * Track product view
 */
function pca_ga4_track_view_item( $product_id ) {
	if ( ! pca_wc_active() ) { return false; }
	$product = wc_get_product( $product_id );
	if ( ! $product ) { return false; }
	
	$items = array( array(
		'item_id'      => $product->get_sku(),
		'item_name'    => $product->get_name(),
		'price'        => (float) $product->get_price(),
		'item_brand'   => $product->get_attribute( 'pa_brand' ) ?: pca_brand(),
		'item_category'=> $product->get_meta( '_pca_google_category' ) ?: '',
		'quantity'     => 1,
	) );
	
	return pca_ga4_ecommerce_event( 'view_item', $items );
}

/**
 * Track add to cart
 */
function pca_ga4_track_add_to_cart( $product_id, $quantity = 1 ) {
	if ( ! pca_wc_active() ) { return false; }
	$product = wc_get_product( $product_id );
	if ( ! $product ) { return false; }
	
	$items = array( array(
		'item_id'      => $product->get_sku(),
		'item_name'    => $product->get_name(),
		'price'        => (float) $product->get_price(),
		'item_brand'   => $product->get_attribute( 'pa_brand' ) ?: pca_brand(),
		'item_category'=> $product->get_meta( '_pca_google_category' ) ?: '',
		'quantity'     => (int) $quantity,
	) );
	
	return pca_ga4_ecommerce_event( 'add_to_cart', $items, array( 'value' => (float) $product->get_price() * $quantity ) );
}

/**
 * Track purchase (call from WC order hooks)
 */
function pca_ga4_track_purchase( $order_id ) {
	if ( ! pca_wc_active() ) { return false; }
	$order = wc_get_order( $order_id );
	if ( ! $order ) { return false; }
	
	$items = array();
	$value = 0;
	
	foreach ( $order->get_items() as $item ) {
		$product = $item->get_product();
		if ( ! $product ) { continue; }
		
		$price = (float) $item->get_total() / max( 1, $item->get_quantity() );
		$qty = $item->get_quantity();
		$value += $price * $qty;
		
		$items[] = array(
			'item_id'       => $product->get_sku(),
			'item_name'     => $product->get_name(),
			'price'         => $price,
			'item_brand'    => $product->get_attribute( 'pa_brand' ) ?: pca_brand(),
			'item_category' => $product->get_meta( '_pca_google_category' ) ?: '',
			'quantity'      => $qty,
		);
	}
	
	return pca_ga4_ecommerce_event( 'purchase', $items, array(
		'transaction_id' => (string) $order_id,
		'value'          => $value,
		'coupon'         => implode( ',', $order->get_coupon_codes() ),
	) );
}

/**
 * Hook into WC actions
 */
if ( pca_wc_active() ) {
	// Track product views
	add_action( 'woocommerce_before_single_product', function () {
		global $product;
		if ( $product ) { pca_ga4_track_view_item( $product->get_id() ); }
	} );
	
	// Track add to cart
	add_action( 'woocommerce_ajax_add_to_cart', function ( $product_id ) {
		pca_ga4_track_add_to_cart( $product_id );
	} );
	add_action( 'woocommerce_add_to_cart', function ( $cart_item_key, $product_id, $quantity ) {
		pca_ga4_track_add_to_cart( $product_id, $quantity );
	}, 10, 3 );
	
	// Track purchase
	add_action( 'woocommerce_thankyou', function ( $order_id ) {
		pca_ga4_track_purchase( $order_id );
	} );
	
	// Track checkout start
	add_action( 'woocommerce_checkout_order_processed', function ( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				if ( ! $product ) { continue; }
				$items[] = array(
					'item_id' => $product->get_sku(),
					'item_name' => $product->get_name(),
					'price' => (float) $item->get_total() / max( 1, $item->get_quantity() ),
					'quantity' => $item->get_quantity(),
				);
			}
			pca_ga4_ecommerce_event( 'begin_checkout', $items, array(
				'value' => (float) $order->get_total(),
			) );
		}
	} );
}

/**
 * Merchant Center feed from WC catalog
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'posmana-agent/v1', '/merchant/feed/wc', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $request ) {
			if ( ! pca_wc_active() ) {
				return new WP_Error( 'pca_wc_not_active', 'WooCommerce not active', array( 'status' => 503 ) );
			}
			$feed = pca_wc_get_products_for_feed();
			$format = $request->get_param( 'format' );
			
			if ( $format === 'tsv' ) {
				$headers = array( 'id', 'title', 'description', 'link', 'image_link', 'availability', 'price', 'sale_price', 'google_product_category', 'brand', 'condition', 'identifier_exists', 'gtin', 'mpn', 'item_group_id', 'gender', 'age_group', 'color', 'size', 'shipping_weight' );
				$output = implode( "	", $headers ) . "
";
				foreach ( $feed as $item ) {
					$row = array();
					foreach ( $headers as $h ) {
						$row[] = str_replace( "	", " ", (string) ( $item[ $h ] ?? '' ) );
					}
					$output .= implode( "	", $row ) . "
";
				}
				return new WP_REST_Response( $output, 200, array( 'Content-Type' => 'text/tab-separated-values' ) );
			}
			return rest_ensure_response( array( 'products' => $feed, 'count' => count( $feed ) ) );
		},
	) );
} );

/**
 * WC price sync cron
 */
add_action( 'pca_price_sync_hook', function () {
	if ( pca_wc_active() ) {
		pca_wc_sync_prices();
	}
} );

/**
 * Admin: WC status card
 */
add_filter( 'pca_admin_status_cards', function ( $cards ) {
	if ( pca_wc_active() ) {
		$wc_products = wp_count_posts( 'product' );
		$cards[] = array(
			'label' => 'WooCommerce',
			'value' => 'Active',
			'hint'  => $wc_products->publish . ' products published',
		);
	} else {
		$cards[] = array(
			'label' => 'WooCommerce',
			'value' => 'Not active',
			'hint'  => 'Install WC for price sync & feed',
		);
	}
	return $cards;
} );

/**
 * AJAX: Sync prices to WooCommerce
 */
add_action( 'wp_ajax_pca_wc_sync_prices', function () {
	check_ajax_referer( 'pca_settings', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
	
	if ( ! function_exists( 'pca_wc_sync_prices' ) || ! pca_wc_active() ) {
		wp_send_json_error( 'WooCommerce not active' );
	}
	
	$applied = pca_wc_sync_prices();
	wp_send_json_success( $applied . ' product(s) updated' );
} );

/**
 * Defaults
 */
add_filter( 'pca_defaults', function ( $defaults ) {
	$defaults['wc_price_sync'] = false;
	return $defaults;
} );
