<?php
/**
 * Google Merchant Center Content API v2.1 Integration
 * 
 * Push products, prices, and inventory directly to Merchant Center.
 * Requires: Service account with Content API access in Merchant Center.
 * Free tier: 10,000 operations/day.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const PCA_MC_API_BASE = 'https://www.googleapis.com/content/v2.1';

/**
 * Get OAuth2 access token from service account
 */
function pca_mc_get_access_token() {
	$settings = pca_settings();
	$sa_json = $settings['google_service_account_json'] ?? '';
	
	if ( empty( $sa_json ) ) {
		return new WP_Error( 'pca_mc_no_sa', 'Service account JSON not configured.' );
	}
	
	$sa = json_decode( $sa_json, true );
	if ( ! $sa || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
		return new WP_Error( 'pca_mc_bad_sa', 'Invalid service account JSON.' );
	}
	
	$cache_key = 'pca_mc_access_token';
	$cached = get_transient( $cache_key );
	if ( $cached ) { return $cached; }
	
	// Build JWT
	$header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
	$now = time();
	$claim = array(
		'iss'   => $sa['client_email'],
		'scope' => 'https://www.googleapis.com/auth/content',
		'aud'   => 'https://oauth2.googleapis.com/token',
		'exp'   => $now + 3600,
		'iat'   => $now,
	);
	
	$token = pca_mc_create_jwt( $header, $claim, $sa['private_key'] );
	if ( is_wp_error( $token ) ) { return $token; }
	
	// Exchange for access token
	$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
		'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
		'body'    => array(
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion'  => $token,
		),
		'timeout' => 15,
	) );
	
	if ( is_wp_error( $response ) ) { return $response; }
	
	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	
	if ( $code !== 200 || empty( $data['access_token'] ) ) {
		return new WP_Error( 'pca_mc_token_failed', 'Token exchange failed: ' . ( $data['error_description'] ?? 'unknown' ) );
	}
	
	// Cache for 50 minutes (token lasts 60)
	set_transient( $cache_key, $data['access_token'], 50 * MINUTE_IN_SECONDS );
	return $data['access_token'];
}

/**
 * Create RS256 JWT (simplified - uses OpenSSL)
 */
function pca_mc_create_jwt( $header, $claim, $private_key ) {
	$header_b64 = pca_mc_base64url_encode( wp_json_encode( $header ) );
	$claim_b64  = pca_mc_base64url_encode( wp_json_encode( $claim ) );
	$signing_input = $header_b64 . '.' . $claim_b64;
	
	$pkey = openssl_pkey_get_private( $private_key );
	if ( ! $pkey ) {
		return new WP_Error( 'pca_mc_key_error', 'Failed to load private key.' );
	}
	
	$signature = '';
	$ok = openssl_sign( $signing_input, $signature, $pkey, OPENSSL_ALGO_SHA256 );
	openssl_free_key( $pkey );
	
	if ( ! $ok ) {
		return new WP_Error( 'pca_mc_sign_failed', 'Failed to sign JWT.' );
	}
	
	return $signing_input . '.' . pca_mc_base64url_encode( $signature );
}

function pca_mc_base64url_encode( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * Make authenticated API call
 */
function pca_mc_api_call( $method, $endpoint, $body = null ) {
	$token = pca_mc_get_access_token();
	if ( is_wp_error( $token ) ) { return $token; }
	
	$merchant_id = sanitize_text_field( pca_settings()['merchant_id'] ?? '' );
	if ( empty( $merchant_id ) ) {
		return new WP_Error( 'pca_mc_no_id', 'Merchant ID not configured.' );
	}
	
	$url = PCA_MC_API_BASE . '/' . $merchant_id . $endpoint;
	
	$args = array(
		'method'  => $method,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
		'timeout' => 30,
	);
	
	if ( $body !== null ) {
		$args['body'] = wp_json_encode( $body );
	}
	
	$response = wp_remote_request( $url, $args );
	
	if ( is_wp_error( $response ) ) { return $response; }
	
	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	
	if ( $code === 401 ) {
		// Token expired, clear cache and retry once
		delete_transient( 'pca_mc_access_token' );
		return new WP_Error( 'pca_mc_auth', 'Authentication failed. Check service account permissions.' );
	}
	
	if ( $code >= 400 ) {
		return new WP_Error( 'pca_mc_http_' . $code, 'API error: ' . ( $data['error']['message'] ?? 'unknown' ) );
	}
	
	return $data;
}

/**
 * Insert or update product
 */
function pca_mc_upsert_product( $product ) {
	$offer_id = sanitize_key( $product['id'] ?? '' );
	if ( empty( $offer_id ) ) {
		return new WP_Error( 'pca_mc_no_offer_id', 'Product ID (offerId) required.' );
	}
	
	$mc_product = array(
		'offerId'           => $offer_id,
		'title'             => sanitize_text_field( $product['title'] ?? '' ),
		'description'       => wp_kses_post( $product['description'] ?? '' ),
		'link'              => esc_url_raw( $product['link'] ?? '' ),
		'imageLink'         => esc_url_raw( $product['image_link'] ?? '' ),
		'availability'      => in_array( $product['availability'] ?? '', array( 'in stock', 'out of stock', 'preorder' ) ) ? $product['availability'] : 'in stock',
		'price'             => array(
			'value'    => (float) ( $product['price'] ?? 0 ),
			'currency' => 'IRR',
		),
		'condition'         => 'new',
		'brand'             => sanitize_text_field( $product['brand'] ?? pca_brand() ),
		'googleProductCategory' => sanitize_text_field( $product['google_product_category'] ?? '' ),
		'gtin'              => sanitize_text_field( $product['gtin'] ?? '' ),
		'mpn'               => sanitize_text_field( $product['mpn'] ?? $offer_id ),
		'identifierExists'  => false,
	);
	
	// Optional fields
	if ( ! empty( $product['sale_price'] ) ) {
		$mc_product['salePrice'] = array(
			'value'    => (float) $product['sale_price'],
			'currency' => 'IRR',
		);
		$mc_product['salePriceEffectiveDate'] = $product['sale_price_effective_date'] ?? '';
	}
	
	if ( ! empty( $product['item_group_id'] ) ) {
		$mc_product['itemGroupId'] = sanitize_key( $product['item_group_id'] );
	}
	
	if ( ! empty( $product['color'] ) ) {
		$mc_product['color'] = sanitize_text_field( $product['color'] );
	}
	if ( ! empty( $product['size'] ) ) {
		$mc_product['size'] = sanitize_text_field( $product['size'] );
	}
	if ( ! empty( $product['gender'] ) ) {
		$mc_product['gender'] = sanitize_text_field( $product['gender'] );
	}
	if ( ! empty( $product['age_group'] ) ) {
		$mc_product['ageGroup'] = sanitize_text_field( $product['age_group'] );
	}
	if ( ! empty( $product['shipping_weight'] ) ) {
		$mc_product['shippingWeight'] = array(
			'value' => (float) $product['shipping_weight'],
			'unit'  => 'kg',
		);
	}
	
	$endpoint = '/products/' . $offer_id;
	return pca_mc_api_call( 'PUT', $endpoint, $mc_product );
}

/**
 * Delete product
 */
function pca_mc_delete_product( $offer_id ) {
	return pca_mc_api_call( 'DELETE', '/products/' . sanitize_key( $offer_id ) );
}

/**
 * Batch upsert products (more efficient)
 */
function pca_mc_batch_upsert( $products ) {
	$entries = array();
	foreach ( $products as $product ) {
		$offer_id = sanitize_key( $product['id'] ?? '' );
		if ( empty( $offer_id ) ) { continue; }
		
		$entries[] = array(
			'batchId'   => count( $entries ),
			'merchantId' => sanitize_text_field( pca_settings()['merchant_id'] ),
			'method'    => 'insert',
			'product'   => array(
				'offerId'           => $offer_id,
				'title'             => sanitize_text_field( $product['title'] ?? '' ),
				'description'       => wp_kses_post( $product['description'] ?? '' ),
				'link'              => esc_url_raw( $product['link'] ?? '' ),
				'imageLink'         => esc_url_raw( $product['image_link'] ?? '' ),
				'availability'      => $product['availability'] ?? 'in stock',
				'price'             => array( 'value' => (float) ( $product['price'] ?? 0 ), 'currency' => 'IRR' ),
				'condition'         => 'new',
				'brand'             => sanitize_text_field( $product['brand'] ?? pca_brand() ),
				'googleProductCategory' => sanitize_text_field( $product['google_product_category'] ?? '' ),
				'gtin'              => sanitize_text_field( $product['gtin'] ?? '' ),
				'mpn'               => sanitize_text_field( $product['mpn'] ?? $offer_id ),
				'identifierExists'  => false,
			),
		);
	}
	
	if ( empty( $entries ) ) {
		return array( 'success' => 0, 'errors' => array( 'No valid products' ) );
	}
	
	return pca_mc_api_call( 'POST', '/products/batch', array( 'entries' => $entries ) );
}

/**
 * Sync all products to Merchant Center
 */
function pca_mc_sync_all_products() {
	$settings = pca_settings();
	if ( empty( $settings['merchant_id'] ) || empty( $settings['google_service_account_json'] ) ) {
		return new WP_Error( 'pca_mc_not_configured', 'Merchant ID or Service Account not configured.' );
	}
	
	// Get products from WC or plugin settings
	if ( function_exists( 'pca_wc_get_products_for_feed' ) && pca_wc_active() ) {
		$products = pca_wc_get_products_for_feed();
	} else {
		$products = pca_merchant_center_feed( $settings );
		if ( is_wp_error( $products ) ) { return $products; }
	}
	
	if ( empty( $products ) ) {
		return array( 'success' => 0, 'message' => 'No products to sync' );
	}
	
	// Batch in chunks of 100 (API limit)
	$chunks = array_chunk( $products, 100 );
	$total_success = 0;
	$total_errors = array();
	
	foreach ( $chunks as $chunk ) {
		$result = pca_mc_batch_upsert( $chunk );
		if ( is_wp_error( $result ) ) {
			$total_errors[] = $result->get_error_message();
			continue;
		}
		
		if ( isset( $result['entries'] ) ) {
			foreach ( $result['entries'] as $entry ) {
				if ( isset( $entry['errors'] ) ) {
					foreach ( $entry['errors']['errors'] ?? array() as $err ) {
						$total_errors[] = $entry['batchId'] . ': ' . $err['message'];
					}
				} else {
					$total_success++;
				}
			}
		}
		
		// Rate limiting: 100ms between batches
		usleep( 100000 );
	}
	
	pca_log( 'Merchant Center Content API sync: ' . $total_success . ' product(s) updated, ' . count( $total_errors ) . ' error(s)' );
	
	return array(
		'success' => $total_success,
		'errors'  => $total_errors,
	);
}

/**
 * Admin: Sync button
 */
add_action( 'admin_footer', function () {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'posmana-agent' ) ) { return; }
	?>
	<script>
	jQuery(document).ready(function($) {
		$('#pca-mc-sync-all').on('click', function() {
			var btn = $(this), out = $('#pca-mc-sync-result');
			btn.prop('disabled', true).text('Syncing...');
			out.text('');
			$.post(ajaxurl, { action: 'pca_mc_sync_all', nonce: pcaNonce }, function(resp) {
				btn.prop('disabled', false).text('Sync All to Merchant Center');
				if ( resp.success ) {
					out.text( 'Success: ' + resp.data.success + ' products, Errors: ' + resp.data.errors.length );
				} else {
					out.text( 'Failed: ' + resp.data );
				}
			});
		});
	});
	</script>
	<?php
} );

add_action( 'wp_ajax_pca_mc_sync_all', function () {
	check_ajax_referer( 'pca_settings', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
	
	$result = pca_mc_sync_all_products();
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}
	wp_send_json_success( $result );
} );

/**
 * Scheduled sync via cron
 */
add_action( 'pca_mc_feed_sync_hook', function () {
	$settings = pca_settings();
	if ( empty( $settings['merchant_id'] ) || empty( $settings['google_service_account_json'] ) ) { return; }
	if ( empty( $settings['mc_content_api_sync'] ) ) { return; }
	
	$result = pca_mc_sync_all_products();
	if ( is_wp_error( $result ) ) {
		pca_log( 'MC Content API sync failed: ' . $result->get_error_message() );
	}
} );

// Schedule daily
if ( ! wp_next_scheduled( 'pca_mc_feed_sync_hook' ) ) {
	wp_schedule_event( time() + 3600, 'daily', 'pca_mc_feed_sync_hook' );
}

/**
 * Settings defaults
 */
add_filter( 'pca_defaults', function ( $defaults ) {
	$defaults['google_service_account_json'] = '';
	$defaults['mc_content_api_sync']         = false;
	return $defaults;
} );

/**
 * Settings save
 */
add_action( 'pca_handle_settings_save', function ( $settings ) {
	$settings['google_service_account_json'] = isset( $_POST['google_service_account_json'] ) ? sanitize_textarea_field( $_POST['google_service_account_json'] ) : '';
	$settings['mc_content_api_sync']         = ! empty( $_POST['mc_content_api_sync'] );
	return $settings;
}, 10, 2 );
