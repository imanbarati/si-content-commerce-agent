<?php
/**
 * REST API v2 + Webhooks
 * 
 * - Versioned API with backward compatibility
 * - Webhook system for real-time events
 * - API key authentication
 * - Rate limiting per key
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const PCA_API_VERSION = 'v2';
const PCA_API_BASE = 'posmana-agent/' . PCA_API_VERSION;

/**
 * API Key management
 */
function pca_get_api_keys() {
	return get_option( 'pca_api_keys', array() );
}

function pca_save_api_keys( $keys ) {
	return update_option( 'pca_api_keys', $keys, false );
}

function pca_create_api_key( $name, $permissions = array() ) {
	$keys = pca_get_api_keys();
	$key = 'pca_' . wp_generate_password( 32, false );
	
	$keys[ $key ] = array(
		'name'        => sanitize_text_field( $name ),
		'key'         => $key,
		'permissions' => $permissions,
		'created'     => current_time( 'mysql' ),
		'last_used'   => null,
		'active'      => true,
	);
	
	pca_save_api_keys( $keys );
	return $key;
}

function pca_validate_api_key( $key, $required_permission = '' ) {
	$keys = pca_get_api_keys();
	
	if ( ! isset( $keys[ $key ] ) || ! $keys[ $key ]['active'] ) {
		return false;
	}
	
	if ( $required_permission && ! in_array( $required_permission, $keys[ $key ]['permissions'] ) ) {
		return false;
	}
	
	// Update last used
	$keys[ $key ]['last_used'] = current_time( 'mysql' );
	pca_save_api_keys( $keys );
	
	return $keys[ $key ];
}

function pca_revoke_api_key( $key ) {
	$keys = pca_get_api_keys();
	if ( isset( $keys[ $key ] ) ) {
		unset( $keys[ $key ] );
		pca_save_api_keys( $keys );
		return true;
	}
	return false;
}

/**
 * API Key authentication for REST routes
 */
function pca_api_key_auth( $request ) {
	$auth_header = $request->get_header( 'Authorization' );
	if ( ! $auth_header ) { return false; }
	
	if ( ! preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
		return false;
	}
	
	$key = trim( $matches[1] );
	$key_data = pca_validate_api_key( $key );
	
	if ( ! $key_data ) { return false; }
	
	// Store key data for permission checks
	$request->set_param( 'pca_api_key', $key );
	$request->set_param( 'pca_api_key_data', $key_data );
	
	return true;
}

/**
 * Webhook system
 */
function pca_get_webhooks() {
	return get_option( 'pca_webhooks', array() );
}

function pca_save_webhooks( $webhooks ) {
	return update_option( 'pca_webhooks', $webhooks, false );
}

function pca_register_webhook( $url, $events, $secret = '' ) {
	$webhooks = pca_get_webhooks();
	$id = wp_generate_uuid4();
	
	$webhooks[ $id ] = array(
		'id'        => $id,
		'url'       => esc_url_raw( $url ),
		'events'    => array_map( 'sanitize_key', (array) $events ),
		'secret'    => $secret ?: wp_generate_password( 32, false ),
		'active'    => true,
		'created'   => current_time( 'mysql' ),
		'last_triggered' => null,
		'failure_count' => 0,
	);
	
	pca_save_webhooks( $webhooks );
	return $id;
}

function pca_unregister_webhook( $id ) {
	$webhooks = pca_get_webhooks();
	if ( isset( $webhooks[ $id ] ) ) {
		unset( $webhooks[ $id ] );
		pca_save_webhooks( $webhooks );
		return true;
	}
	return false;
}

function pca_trigger_webhook( $event, $payload ) {
	$webhooks = pca_get_webhooks();
	
	foreach ( $webhooks as $id => $webhook ) {
		if ( ! $webhook['active'] ) { continue; }
		if ( ! in_array( $event, $webhook['events'] ) && ! in_array( '*', $webhook['events'] ) ) { continue; }
		
		$signature = hash_hmac( 'sha256', wp_json_encode( $payload ), $webhook['secret'] );
		
		$args = array(
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-PCA-Event'  => $event,
				'X-PCA-Signature' => $signature,
				'X-PCA-Delivery' => $id . '_' . time(),
			),
			'body'    => wp_json_encode( array(
				'event'     => $event,
				'timestamp' => current_time( 'mysql', 1 ),
				'data'      => $payload,
			) ),
			'timeout' => 10,
			'blocking' => false,
		);
		
		$response = wp_remote_post( $webhook['url'], $args );
		
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
			$webhook['failure_count']++;
			if ( $webhook['failure_count'] >= 10 ) {
				$webhook['active'] = false;
			}
		} else {
			$webhook['failure_count'] = 0;
		}
		
		$webhook['last_triggered'] = current_time( 'mysql' );
		$webhooks[ $id ] = $webhook;
	}
	
	pca_save_webhooks( $webhooks );
}

/**
 * Core events that trigger webhooks
 */
add_action( 'pca_feed_sync_hook', function() {
	pca_trigger_webhook( 'feed.sync.completed', array( 'timestamp' => current_time( 'mysql' ) ) );
} );

add_action( 'pca_price_sync_hook', function() {
	pca_trigger_webhook( 'price.sync.completed', array( 'timestamp' => current_time( 'mysql' ) ) );
} );

add_action( 'pca_research_run', function( $sku ) {
	pca_trigger_webhook( 'research.completed', array( 'sku' => $sku, 'timestamp' => current_time( 'mysql' ) ) );
} );

add_action( 'pca_merchant_inbound', function( $post_id ) {
	pca_trigger_webhook( 'merchant.post.created', array( 'post_id' => $post_id, 'timestamp' => current_time( 'mysql' ) ) );
} );

add_action( 'pca_wc_price_sync', function() {
	pca_trigger_webhook( 'wc.price.sync.completed', array( 'timestamp' => current_time( 'mysql' ) ) );
} );

/**
 * REST API v2 Routes
 */
add_action( 'rest_api_init', function () {
	// API Key authentication for all v2 routes
	$auth_callback = function( $request ) {
		return pca_api_key_auth( $request );
	};
	
	// Health & Status
	rest_register_route( PCA_API_BASE, '/health', array(
		'methods'  => 'GET',
		'callback' => function() { return array( 'status' => 'ok', 'service' => 'si-content-commerce-agent', 'version' => PCA_API_VERSION ); },
		'permission_callback' => '__return_true',
	));
	
	rest_register_route( PCA_API_BASE, '/status', array(
		'methods'  => 'GET',
		'callback' => function() {
			$settings = pca_get_effective_settings();
			$suggestions = get_option( 'pca_price_suggestions', array() );
			$reports = get_option( 'pca_research_reports', array() );
			return array(
				'feeds' => count( (array) $settings['feeds'] ),
			'products' => count( (array) $settings['products'] ),
			'price_suggestions' => count( $suggestions ),
			'research_reports' => count( $reports ),
			'last_feed_sync' => pca_last_run( 'feeds' ),
			'last_price_sync' => pca_last_run( 'prices' ),
			'last_research' => pca_last_run( 'research' ),
			'last_mc_sync' => pca_last_run( 'mc_content_api' ),
			'woocommerce' => class_exists( 'WooCommerce' ),
			'multisite' => is_multisite(),
			);
		},
		'permission_callback' => $auth_callback,
	));
	
	// Feeds
	rest_register_route( PCA_API_BASE, '/feeds/sync', array(
		'methods'  => 'POST',
		'callback' => function() { return array( 'ok' => true, 'created' => pca_import_all_feeds() ); },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'feeds' ); },
	));
	
	rest_register_route( PCA_API_BASE, '/feeds/(?P<feed_url>.+)', array(
		'methods'  => 'POST',
		'callback' => function( $req ) {
			$url = urldecode( $req->get_param( 'feed_url' ) );
			$items = pca_fetch_feed_items( $url, 10 );
			if ( is_wp_error( $items ) ) { return $items; }
			$created = 0;
			foreach ( $items as $item ) { if ( pca_create_post_from_item( $item, 'rss' ) ) $created++; }
			return array( 'ok' => true, 'created' => $created );
		},
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'feeds' ); },
	));
	
	// Products
	rest_register_route( PCA_API_BASE, '/products', array(
		'methods'  => 'GET',
		'callback' => function() {
			$settings = pca_get_effective_settings();
			$suggestions = get_option( 'pca_price_suggestions', array() );
			$products = array();
			foreach ( (array) $settings['products'] as $p ) {
				$sku = $p['sku'];
				$products[ $sku ] = array_merge( $p, array( 'suggestion' => $suggestions[ $sku ] ?? null ) );
			}
			return $products;
		},
		'permission_callback' => $auth_callback,
	));
	
	rest_register_route( PCA_API_BASE, '/products/(?P<sku>[a-zA-Z0-9_-]+)', array(
		'methods'  => 'GET',
		'callback' => function( $req ) {
			$sku = $req->get_param( 'sku' );
			$settings = pca_get_effective_settings();
			$product = null;
			foreach ( (array) $settings['products'] as $p ) { if ( $p['sku'] === $sku ) { $product = $p; break; } }
			if ( ! $product ) { return new WP_Error( 'not_found', 'Product not found', array( 'status' => 404 ) ); }
			$suggestions = get_option( 'pca_price_suggestions', array() );
			$product['suggestion'] = $suggestions[ $sku ] ?? null;
			return $product;
		},
		'permission_callback' => $auth_callback,
	));
	
	rest_register_route( PCA_API_BASE, '/products', array(
		'methods'  => 'POST',
		'callback' => function( $req ) {
			$data = $req->get_json_params();
			$settings = pca_get_effective_settings();
			$products = $settings['products'];
			$products[ $data['sku'] ] = array(
				'sku'   => sanitize_key( $data['sku'] ),
				'name'  => sanitize_text_field( $data['name'] ),
				'query' => sanitize_text_field( $data['query'] ),
				'cost'  => (float) ( $data['cost'] ?? 0 ),
			);
			pca_update_settings( array_merge( $settings, array( 'products' => $products ) ) );
			return array( 'ok' => true, 'product' => $products[ $data['sku'] ] );
		},
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'products' ); },
	));
	
	rest_register_route( PCA_API_BASE, '/products/(?P<sku>[a-zA-Z0-9_-]+)', array(
		'methods'  => 'DELETE',
		'callback' => function( $req ) {
			$sku = $req->get_param( 'sku' );
			$settings = pca_get_effective_settings();
			$products = $settings['products'];
			unset( $products[ $sku ] );
			pca_update_settings( array_merge( $settings, array( 'products' => $products ) ) );
			return array( 'ok' => true );
		},
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'products' ); },
	));
	
	// Price Sync
	rest_register_route( PCA_API_BASE, '/prices/sync', array(
		'methods'  => 'POST',
		'callback' => function() { $result = pca_sync_prices(); if ( function_exists( 'pca_wc_sync_prices' ) ) { pca_wc_sync_prices(); } return array( 'ok' => true, 'suggestions' => get_option( 'pca_price_suggestions', array() ) ); },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'prices' ); },
	));
	
	rest_register_route( PCA_API_BASE, '/prices/suggestions', array(
		'methods'  => 'GET',
		'callback' => function() { return get_option( 'pca_price_suggestions', array() ); },
		'permission_callback' => $auth_callback,
	));
	
	// Research
	rest_register_route( PCA_API_BASE, '/research/(?P<sku>[a-zA-Z0-9_-]+)', array(
		'methods'  => 'POST',
		'callback' => function( $req ) { return pca_research_run( $req->get_param( 'sku' ) ); },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'research' ); },
	));
	
	rest_register_route( PCA_API_BASE, '/research/reports', array(
		'methods'  => 'GET',
		'callback' => function() { return get_option( 'pca_research_reports', array() ); },
		'permission_callback' => $auth_callback,
	));
	
	// Merchant Center
	rest_register_route( PCA_API_BASE, '/merchant/feed', array(
		'methods'  => 'GET',
		'callback' => function( $req ) {
			$settings = pca_get_effective_settings();
			$feed = pca_merchant_center_feed( $settings );
			if ( is_wp_error( $feed ) ) { return $feed; }
			if ( $req->get_param( 'format' ) === 'tsv' ) {
				$output = "id\ttitle\tdescription\tlink\timage_link\tavailability\tprice\tgoogle_product_category\tbrand\tcondition\tidentifier_exists\n";
				foreach ( $feed as $item ) { $output .= implode( "\t", array_map( function($v) { return str_replace( "\t", " ", $v ); }, $item ) ) . "\n"; }
				return new WP_REST_Response( $output, 200, array( 'Content-Type' => 'text/tab-separated-values' ) );
			}
			return rest_ensure_response( array( 'products' => $feed, 'count' => count( $feed ) ) );
		},
		'permission_callback' => $auth_callback,
	));
	
	rest_register_route( PCA_API_BASE, '/merchant/sync', array(
		'methods'  => 'POST',
		'callback' => function() {
			if ( function_exists( 'pca_mc_sync_all_products' ) ) {
				return pca_mc_sync_all_products();
			}
			return new WP_Error( 'mc_not_available', 'MC Content API not configured' );
		},
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'merchant' ); },
	));
	
	// Webhooks
	rest_register_route( PCA_API_BASE, '/webhooks', array(
		'methods'  => 'GET',
		'callback' => function() { $webhooks = pca_get_webhooks(); foreach ( $webhooks as $id => $w ) { unset( $webhooks[ $id ]['secret'] ); } return $webhooks; },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'webhooks' ); },
	));
	
	rest_register_route( PCA_API_BASE, '/webhooks', array(
		'methods'  => 'POST',
		'callback' => function( $req ) {
			$data = $req->get_json_params();
			$id = pca_register_webhook( $data['url'], $data['events'], $data['secret'] ?? '' );
			$webhook = pca_get_webhooks()[ $id ];
			unset( $webhook['secret'] );
			return array( 'ok' => true, 'webhook' => $webhook );
		},
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'webhooks' ); },
	));
	
	rest_register_route( PCA_API_BASE, '/webhooks/(?P<id>[a-z0-9-]+)', array(
		'methods'  => 'DELETE',
		'callback' => function( $req ) { pca_unregister_webhook( $req->get_param( 'id' ) ); return array( 'ok' => true ); },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'webhooks' ); },
	));
	
	// API Keys management
	rest_register_route( PCA_API_BASE, '/keys', array(
		'methods'  => 'GET',
		'callback' => function() { $keys = pca_get_api_keys(); foreach ( $keys as $k => $v ) { unset( $keys[ $k ]['key'] ); } return $keys; },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'keys' ); },
	));
	
	rest_register_route( PCA_API_BASE, '/keys', array(
		'methods'  => 'POST',
		'callback' => function( $req ) {
			$data = $req->get_json_params();
			$key = pca_create_api_key( $data['name'], $data['permissions'] ?? array() );
			return array( 'ok' => true, 'key' => $key, 'name' => $data['name'] );
		},
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'keys' ); },
	));
	
	rest_register_route( PCA_API_BASE, '/keys/(?P<key>pca_[a-zA-Z0-9]+)', array(
		'methods'  => 'DELETE',
		'callback' => function( $req ) { pca_revoke_api_key( $req->get_param( 'key' ) ); return array( 'ok' => true ); },
		'permission_callback' => function( $req ) { return pca_api_key_auth( $req ) && pca_validate_api_key( $req->get_param( 'pca_api_key' ), 'keys' ); },
	));
	
	// WC integration
	if ( class_exists( 'WooCommerce' ) ) {
		rest_register_route( PCA_API_BASE, '/wc/products', array(
			'methods'  => 'GET',
			'callback' => function() {
				if ( ! class_exists( 'WooCommerce' ) ) { return new WP_Error( 'wc_not_active', 'WooCommerce not active', array( 'status' => 503 ) ); }
				$products = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'return' => 'objects' ) );
				$out = array();
				foreach ( $products as $p ) { if ( $p->get_sku() ) { $out[ $p->get_sku() ] = array( 'name' => $p->get_name(), 'price' => $p->get_price(), 'sku' => $p->get_sku(), 'stock' => $p->get_stock_status() ); } }
				return $out;
			},
			'permission_callback' => $auth_callback,
		);
	}
} );

/**
 * Admin: API Keys & Webhooks UI
 */
add_action( 'admin_footer', function () {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'posmana-agent' ) ) { return; }
	?>
	<script>
	jQuery(document).ready(function($) {
		// API Key creation
		$('#pca-create-key').on('click', function() {
			var name = $('#pca-key-name').val();
			var perms = [];
			$('#pca-key-perms input:checked').each(function() { perms.push($(this).val()); });
			$.post(ajaxurl, { action: 'pca_create_api_key', nonce: pcaNonce, name: name, permissions: perms }, function(resp) {
				if ( resp.success ) { alert('Key created: ' + resp.data.key); location.reload(); } else { alert('Failed: ' + resp.data); }
			});
		});
		
		// Webhook creation
		$('#pca-create-webhook').on('click', function() {
			var url = $('#pca-webhook-url').val();
			var events = [];
			$('#pca-webhook-events input:checked').each(function() { events.push($(this).val()); });
			$.post(ajaxurl, { action: 'pca_create_webhook', nonce: pcaNonce, url: url, events: events }, function(resp) {
				if ( resp.success ) { alert('Webhook created'); location.reload(); } else { alert('Failed: ' + resp.data); }
			});
		});
	});
	</script>
	<?php
} );

add_action( 'wp_ajax_pca_create_api_key', function () {
	check_ajax_referer( 'pca_settings', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
	$key = pca_create_api_key( $_POST['name'], $_POST['permissions'] ?? array() );
	wp_send_json_success( array( 'key' => $key ) );
} );

add_action( 'wp_ajax_pca_create_webhook', function () {
	check_ajax_referer( 'pca_settings', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
	$id = pca_register_webhook( $_POST['url'], $_POST['events'] );
	wp_send_json_success( array( 'id' => $id ) );
} );
