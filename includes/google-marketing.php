<?php
/**
 * Google Marketing Integrations (Free/Optional Services)
 * 
 * No Google Ads API, no Cloud billing required.
 * Uses: Merchant Center (free), Custom Search (free tier), 
 * Business Profile (free), GA4/GTM (free).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Register Google marketing settings fields
 */
add_filter( 'pca_settings_fields', function ( $fields ) {
	$fields['google_merchant'] = array(
		'label'       => 'Google Merchant Center',
		'description' => 'Free product listings & Shopping ads. Requires Merchant Center account.',
		'fields'      => array(
			'merchant_enabled'        => array( 'type' => 'checkbox', 'label' => 'Enable Merchant Center sync' ),
			'merchant_id'             => array( 'type' => 'text', 'label' => 'Merchant ID', 'placeholder' => '123456789' ),
			'merchant_feed_url'       => array( 'type' => 'url', 'label' => 'Feed fetch URL (auto-generated)', 'readonly' => true ),
			'merchant_feed_schedule'  => array( 'type' => 'select', 'label' => 'Feed fetch schedule', 'options' => array(
				'daily'     => 'Daily',
				'weekly'    => 'Weekly',
				'monthly'   => 'Monthly',
				'manual'    => 'Manual only',
			) ),
		),
	);

	$fields['google_cse'] = array(
		'label'       => 'Google Custom Search (Free tier: 100 queries/day)',
		'description' => 'Research source for merchant price discovery. Optional.',
		'fields'      => array(
			'cse_enabled'   => array( 'type' => 'checkbox', 'label' => 'Use Google CSE for research' ),
			'cse_api_key'   => array( 'type' => 'text', 'label' => 'Custom Search API Key', 'placeholder' => 'AIzaSy...' ),
			'cse_cx'        => array( 'type' => 'text', 'label' => 'Search Engine ID (cx)', 'placeholder' => '1234567890:abcdefghij' ),
		),
	);

	$fields['google_business'] = array(
		'label'       => 'Google Business Profile (Free)',
		'description' => 'Audit your own business listings, reviews, and local SEO. Optional.',
		'fields'      => array(
			'business_enabled'   => array( 'type' => 'checkbox', 'label' => 'Enable Business Profile audit' ),
			'business_location_ids' => array( 'type' => 'textarea', 'label' => 'Location IDs (one per line)', 'description' => 'From Business Profile Manager URL: accounts/123/locations/456' ),
		),
	);

	$fields['google_analytics'] = array(
		'label'       => 'Google Analytics 4 + Tag Manager (Free)',
		'description' => 'Event tracking, conversion measurement, tag deployment.',
		'fields'      => array(
			'ga4_enabled'       => array( 'type' => 'checkbox', 'label' => 'Enable GA4 event tracking' ),
			'ga4_measurement_id' => array( 'type' => 'text', 'label' => 'GA4 Measurement ID', 'placeholder' => 'G-XXXXXXXXXX' ),
			'gtm_container_id'  => array( 'type' => 'text', 'label' => 'GTM Container ID', 'placeholder' => 'GTM-XXXXXXX' ),
			'gtm_enabled'       => array( 'type' => 'checkbox', 'label' => 'Auto-inject GTM snippet' ),
		),
	);

	return $fields;
} );

/**
 * Google Custom Search - Research Provider
 * Free tier: 100 queries/day, $5/1000 after
 */
function pca_google_cse_search( $query, $settings ) {
	if ( empty( $settings['cse_api_key'] ) || empty( $settings['cse_cx'] ) ) {
		return new WP_Error( 'pca_cse_not_configured', 'CSE API Key and CX required.' );
	}

	$url = add_query_arg( array(
		'key' => $settings['cse_api_key'],
		'cx'  => $settings['cse_cx'],
		'q'   => $query,
		'num' => 10,
	), 'https://www.googleapis.com/customsearch/v1' );

	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( $code === 429 ) {
		return new WP_Error( 'pca_cse_quota', 'Daily quota exceeded (100 free/day).' );
	}
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'pca_cse_http', 'CSE returned HTTP ' . $code );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	$results = array();
	foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
		$results[] = array(
			'title'       => sanitize_text_field( $item['title'] ?? '' ),
			'url'         => esc_url_raw( $item['link'] ?? '' ),
			'snippet'     => sanitize_text_field( $item['snippet'] ?? '' ),
			'source'      => 'google_cse',
		);
	}
	return $results;
}

/**
 * Google Merchant Center - Content API (Free)
 * No Ads API needed. Uses service account or OAuth.
 */
function pca_merchant_center_feed( $settings ) {
	$merchant_id = sanitize_text_field( $settings['merchant_id'] ?? '' );
	if ( empty( $merchant_id ) ) {
		return new WP_Error( 'pca_mc_no_id', 'Merchant ID not configured.' );
	}

	// Generate feed from configured products
	$products = (array) ( $settings['products'] ?? array() );
	$feed = array();

	foreach ( $products as $product ) {
		$sku = sanitize_key( $product['sku'] ?? '' );
		if ( empty( $sku ) ) { continue; }

		$price = get_option( 'pca_price_suggestions', array() );
		$price = isset( $price[ $sku ]['price'] ) ? $price[ $sku ]['price'] : ( $product['cost'] ?? 0 );

		$feed[] = array(
			'id'                  => $sku,
			'title'               => sanitize_text_field( $product['name'] ?? $sku ),
			'description'         => wp_kses_post( $product['description'] ?? '' ),
			'link'                => home_url( '/?pca_sku=' . $sku ),
			'image_link'          => esc_url_raw( $product['image'] ?? '' ),
			'availability'        => 'in stock',
			'price'               => number_format( (float) $price, 2, '.', '' ) . ' IRR',
			'google_product_category' => $product['category'] ?? '',
			'brand'               => pca_brand(),
			'condition'           => 'new',
			'identifier_exists'   => 'false', // No GTIN/MPN required
		);
	}

	return $feed;
}

/**
 * Merchant Center REST endpoint
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'posmana-agent/v1', '/merchant/feed', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true', // Public feed for Google fetch
		'callback'            => function ( $request ) {
			$settings = pca_settings();
			$feed     = pca_merchant_center_feed( $settings );
			if ( is_wp_error( $feed ) ) {
				return new WP_Error( 'pca_feed_error', $feed->get_error_message(), array( 'status' => 500 ) );
			}
			// Return as TSV (Google's preferred format) or JSON
			if ( $request->get_param( 'format' ) === 'tsv' ) {
				$output = "id	title	description	link	image_link	availability	price	google_product_category	brand	condition	identifier_exists
";
				foreach ( $feed as $item ) {
					$output .= implode( "	", array_map( function($v) { return str_replace( "	", " ", $v ); }, $item ) ) . "
";
				}
				return new WP_REST_Response( $output, 200, array( 'Content-Type' => 'text/tab-separated-values' ) );
			}
			return rest_ensure_response( array( 'products' => $feed, 'count' => count( $feed ) ) );
		},
	) );
} );

/**
 * Google Business Profile - Audit own locations
 * Requires OAuth with scope: https://www.googleapis.com/auth/business.manage
 */
function pca_business_profile_audit( $location_ids, $access_token ) {
	$results = array();
	foreach ( (array) $location_ids as $location_id ) {
		$location_id = trim( $location_id );
		if ( empty( $location_id ) ) { continue; }

		$response = wp_remote_get(
			'https://mybusinessbusinessinformation.googleapis.com/v1/' . $location_id,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			$results[ $location_id ] = array( 'error' => $response->get_error_message() );
			continue;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code === 401 ) {
			$results[ $location_id ] = array( 'error' => 'Token expired. Re-authorize.' );
			continue;
		}
		if ( $code === 403 ) {
			$results[ $location_id ] = array( 'error' => 'No access to this location.' );
			continue;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$results[ $location_id ] = array(
			'name'           => $data['name'] ?? '',
			'title'          => $data['title'] ?? '',
			'phone'          => $data['phoneNumbers']['primaryPhone'] ?? '',
			'website'        => $data['websiteUri'] ?? '',
			'address'        => $data['address'] ?? array(),
			'categories'     => array_column( $data['categories'] ?? array(), 'displayName' ),
			'hours'          => $data['regularHours'] ?? array(),
			'profile_state'  => $data['profileState'] ?? 'unknown',
			'verified'       => ! empty( $data['verification'] ),
			'metadata'       => array(
				'last_updated' => $data['metadata']['updateTime'] ?? '',
			),
		);
	}
	return $results;
}

/**
 * GA4 Event Tracking - Server-side (Measurement Protocol)
 * Free, no API key needed, just Measurement ID
 */
function pca_ga4_send_event( $event_name, $params = array() ) {
	$settings = pca_settings();
	$measurement_id = $settings['ga4_measurement_id'] ?? '';
	$api_secret = $settings['ga4_api_secret'] ?? ''; // Optional, for validation

	if ( empty( $measurement_id ) ) { return false; }

	$payload = array(
		'client_id' => pca_get_client_id(),
		'events'    => array( array(
			'name'   => $event_name,
			'params' => $params,
		) ),
	);

	$url = 'https://www.google-analytics.com/mp/collect';
	if ( ! empty( $api_secret ) ) {
		$url = add_query_arg( 'api_secret', $api_secret, $url );
	}
	$url = add_query_arg( 'measurement_id', $measurement_id, $url );

	$response = wp_remote_post( $url, array(
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( $payload ),
		'timeout' => 5,
	) );

	return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 204;
}

function pca_get_client_id() {
	$cookie_name = '_ga';
	if ( isset( $_COOKIE[ $cookie_name ] ) ) {
		// GA4 client ID format: GA1.2.XXXXXX.YYYYYY
		$parts = explode( '.', $_COOKIE[ $cookie_name ] );
		if ( count( $parts ) >= 4 ) {
			return $parts[2] . '.' . $parts[3];
		}
	}
	// Fallback: generate and store
	$client_id = wp_generate_uuid4();
	setcookie( $cookie_name, 'GA1.2.' . $client_id, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, true, true );
	return $client_id;
}

/**
 * Track key events automatically
 */
add_action( 'pca_feed_sync_hook', function () {
	pca_ga4_send_event( 'content_import', array(
		'source' => 'rss_feed',
		'method' => 'scheduled',
	) );
} );

add_action( 'pca_price_sync_hook', function () {
	pca_ga4_send_event( 'price_sync', array(
		'source' => 'merchant_center',
		'method' => 'scheduled',
	) );
} );

add_action( 'pca_merchant_inbound', function ( $post_id ) {
	pca_ga4_send_event( 'merchant_post_created', array(
		'post_id' => $post_id,
		'source'  => 'merchant_api',
	) );
} );

/**
 * GTM Snippet Injection
 */
add_action( 'wp_head', function () {
	$settings = pca_settings();
	if ( empty( $settings['gtm_enabled'] ) || empty( $settings['gtm_container_id'] ) ) { return; }
	$container_id = sanitize_text_field( $settings['gtm_container_id'] );
	?>
	<!-- Google Tag Manager -->
	<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
	new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
	j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
	'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
	})(window,document,'script','dataLayer','<?php echo esc_js( $container_id ); ?>');</script>
	<!-- End Google Tag Manager -->
	<?php
}, 1 );

add_action( 'wp_body_open', function () {
	$settings = pca_settings();
	if ( empty( $settings['gtm_enabled'] ) || empty( $settings['gtm_container_id'] ) ) { return; }
	$container_id = sanitize_text_field( $settings['gtm_container_id'] );
	?>
	<!-- Google Tag Manager (noscript) -->
	<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr( $container_id ); ?>"
	height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
	<!-- End Google Tag Manager (noscript) -->
	<?php
}, 1 );

/**
 * Admin: Test buttons for each integration
 */
add_action( 'admin_footer', function () {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'posmana-agent' ) ) { return; }
	?>
	<script>
	jQuery(document).ready(function($) {
		// Test Google CSE
		$('#pca-test-cse').on('click', function() {
			var btn = $(this), out = $('#pca-cse-result');
			btn.prop('disabled', true).text('Testing...');
			out.text('');
			$.post(ajaxurl, { action: 'pca_test_cse', nonce: pcaNonce }, function(resp) {
				btn.prop('disabled', false).text('Test CSE');
				out.text( resp.success ? 'OK: ' + JSON.stringify(resp.data).slice(0, 200) : 'Failed: ' + resp.data );
			});
		});

		// Test Merchant Center Feed
		$('#pca-test-mc-feed').on('click', function() {
			var btn = $(this), out = $('#pca-mc-result');
			btn.prop('disabled', true).text('Generating...');
			out.text('');
			$.get('/wp-json/posmana-agent/v1/merchant/feed', function(resp) {
				btn.prop('disabled', false).text('Test Feed');
				out.text( 'Products: ' + resp.count + ' | First: ' + (resp.products[0]?.title || 'none') );
			}).fail(function() { btn.prop('disabled', false).text('Test Feed'); out.text('Failed'); });
		});

		// Test GA4 Event
		$('#pca-test-ga4').on('click', function() {
			var btn = $(this), out = $('#pca-ga4-result');
			btn.prop('disabled', true).text('Sending...');
			out.text('');
			$.post(ajaxurl, { action: 'pca_test_ga4', nonce: pcaNonce }, function(resp) {
				btn.prop('disabled', false).text('Test GA4');
				out.text( resp.success ? 'Event sent (204)' : 'Failed: ' + resp.data );
			});
		});
	});
	</script>
	<?php
} );

/**
 * AJAX handlers for test buttons
 */
add_action( 'wp_ajax_pca_test_cse', function () {
	check_ajax_referer( 'pca_settings', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

	$settings = pca_settings();
	$result = pca_google_cse_search( 'test product price', $settings );
	wp_send_json_success( $result );
} );

add_action( 'wp_ajax_pca_test_ga4', function () {
	check_ajax_referer( 'pca_settings', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }

	$sent = pca_ga4_send_event( 'test_event', array( 'test_param' => 'hello' ) );
	wp_send_json_success( array( 'sent' => $sent ) );
} );

/**
 * Defaults for new settings
 */
add_filter( 'pca_defaults', function ( $defaults ) {
	$defaults['merchant_enabled']      = false;
	$defaults['merchant_id']           = '';
	$defaults['merchant_feed_schedule'] = 'daily';
	$defaults['cse_enabled']           = false;
	$defaults['cse_api_key']           = '';
	$defaults['cse_cx']                = '';
	$defaults['business_enabled']      = false;
	$defaults['business_location_ids'] = '';
	$defaults['ga4_enabled']           = false;
	$defaults['ga4_measurement_id']    = '';
	$defaults['ga4_api_secret']        = '';
	$defaults['gtm_enabled']           = false;
	$defaults['gtm_container_id']      = '';
	return $defaults;
} );

/**
 * Settings save handler additions
 */
add_action( 'pca_handle_settings_save', function ( $settings ) {
	// Sanitize new fields
	$settings['merchant_enabled']       = ! empty( $_POST['merchant_enabled'] );
	$settings['merchant_id']            = sanitize_text_field( $_POST['merchant_id'] ?? '' );
	$settings['merchant_feed_schedule'] = in_array( $_POST['merchant_feed_schedule'] ?? 'daily', array( 'daily', 'weekly', 'monthly', 'manual' ) ) ? $_POST['merchant_feed_schedule'] : 'daily';
	$settings['cse_enabled']            = ! empty( $_POST['cse_enabled'] );
	$settings['cse_api_key']            = sanitize_text_field( $_POST['cse_api_key'] ?? '' );
	$settings['cse_cx']                 = sanitize_text_field( $_POST['cse_cx'] ?? '' );
	$settings['business_enabled']       = ! empty( $_POST['business_enabled'] );
	$settings['business_location_ids']  = array_values( array_filter( array_map( 'trim', explode( "
", $_POST['business_location_ids'] ?? '' ) ) ) );
	$settings['ga4_enabled']            = ! empty( $_POST['ga4_enabled'] );
	$settings['ga4_measurement_id']     = sanitize_text_field( $_POST['ga4_measurement_id'] ?? '' );
	$settings['ga4_api_secret']         = sanitize_text_field( $_POST['ga4_api_secret'] ?? '' );
	$settings['gtm_enabled']            = ! empty( $_POST['gtm_enabled'] );
	$settings['gtm_container_id']       = sanitize_text_field( $_POST['gtm_container_id'] ?? '' );

	return $settings;
}, 10, 2 );
