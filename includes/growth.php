<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Compliant growth tooling: keyword variants, schema.org JSON-LD, affiliate links. */

function pca_keyword_variants( $seed ) {
	$seed = trim( wp_strip_all_tags( (string) $seed ) );
	if ( '' === $seed ) { return array(); }
	$patterns = array(
		'%s price', '%s قیمت', 'buy %s', 'خرید %s', '%s review', 'بررسی %s',
		'%s comparison', 'مقایسه %s', '%s near me', '%s alternatives',
	);
	$out = array();
	foreach ( $patterns as $pattern ) {
		$out[] = sprintf( $pattern, $seed );
	}
	return array_values( array_unique( $out ) );
}

function pca_schema_product( $args ) {
	return array(
		'@context' => 'https://schema.org',
		'@type'    => 'Product',
		'@id'      => '#pca-product-' . sanitize_key( $args['sku'] ?? 'product' ),
		'name'     => wp_strip_all_tags( $args['name'] ?? '' ),
		'sku'      => sanitize_key( $args['sku'] ?? '' ),
		'description' => wp_strip_all_tags( $args['description'] ?? '' ),
		'offers'   => array(
			'@type'         => 'Offer',
			'priceCurrency' => 'IRR',
			'price'         => (float) ( $args['price'] ?? 0 ),
			'availability'  => 'https://schema.org/InStock',
			'url'           => esc_url_raw( $args['url'] ?? '' ),
		),
	);
}

function pca_schema_article( $post ) {
	return array(
		'@context' => 'https://schema.org',
		'@type'    => 'Article',
		'@id'      => '#pca-article-' . absint( $post->ID ),
		'headline' => wp_strip_all_tags( $post->post_title ),
		'datePublished' => get_the_date( 'c', $post ),
		'dateModified'  => get_the_modified_date( 'c', $post ),
		'author'   => array( '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', $post->post_author ) ),
	);
}

function pca_affiliate_link( $platform, $url, $tag ) {
	$allowed = array( 'shopify', 'woocommerce', 'aliexpress', 'cj', 'spocket', 'zendrop', 'printify' );
	$platform = sanitize_key( $platform );
	if ( ! in_array( $platform, $allowed, true ) || ! pca_valid_url( $url ) ) { return ''; }
	$parts = wp_parse_url( $url );
	$query = array();
	if ( ! empty( $parts['query'] ) ) { parse_str( $parts['query'], $query ); }
	$query['utm_source']   = 'si-content-agent';
	$query['utm_medium']   = 'affiliate';
	$query['utm_campaign'] = $platform;
	$key = 'aliexpress' === $platform ? 'aff_id' : ( 'cj' === $platform ? 'cid' : 'ref' );
	if ( '' !== (string) $tag ) { $query[ $key ] = sanitize_text_field( $tag ); }
	$port = ! empty( $parts['port'] ) ? ':' . $parts['port'] : '';
	return $parts['scheme'] . '://' . $parts['host'] . $port . ( $parts['path'] ?? '' ) . '?' . http_build_query( $query );
}

function pca_campaign_checklist() {
	$settings = pca_settings();
	return array(
		array( 'label' => 'No automated bot swarms or fake traffic', 'ok' => true, 'hint' => 'Traffic and engagement must come from real users.' ),
		array( 'label' => 'Ads automation uses official Google Ads API only', 'ok' => true, 'hint' => 'No SERP scraping, click fraud or policy evasion.' ),
		array( 'label' => 'Draft review before publishing', 'ok' => 'draft' === $settings['feed_status'], 'hint' => 'Human review prevents thin or duplicate pages.' ),
		array( 'label' => 'Schema markup enabled', 'ok' => ! empty( $settings['schema_enabled'] ), 'hint' => 'Product/Article JSON-LD works alongside Yoast SEO.' ),
	);
}

add_action( 'wp_head', function () {
	$settings = pca_settings();
	if ( empty( $settings['schema_enabled'] ) || ! is_singular() ) { return; }
	$post = get_queried_object();
	if ( ! $post || empty( $post->ID ) ) { return; }
	$data = pca_schema_article( $post );
	$products = (array) $settings['products'];
	$sku = get_post_meta( $post->ID, '_pca_sku', true );
	foreach ( $products as $product ) {
		if ( sanitize_key( $product['sku'] ?? '' ) === sanitize_key( $sku ) ) {
			$data = array( '@context' => 'https://schema.org', '@graph' => array( $data, pca_schema_product( $product ) ) );
			break;
		}
	}
	echo '<script type="application/ld+json">' . wp_json_encode( $data ) . '</script>';
} );

add_shortcode( 'pca_affiliate', function ( $atts ) {
	$atts = shortcode_atts( array( 'platform' => '', 'url' => '', 'tag' => '' ), $atts, 'pca_affiliate' );
	$link = pca_affiliate_link( $atts['platform'], $atts['url'], $atts['tag'] );
	return $link ? '<a href="' . esc_url( $link ) . '" rel="nofollow sponsored noopener">' . esc_html( ucfirst( $atts['platform'] ) ) . '</a>' : '';
} );

add_action( 'rest_api_init', function () {
	register_rest_route( 'posmana-agent/v1', '/growth/keywords', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function ( $request ) {
			return array( 'ok' => true, 'variants' => pca_keyword_variants( $request->get_param( 'seed' ) ) );
		},
	) );
	register_rest_route( 'posmana-agent/v1', '/growth/affiliate', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function ( $request ) {
			$link = pca_affiliate_link( $request->get_param( 'platform' ), $request->get_param( 'url' ), $request->get_param( 'tag' ) );
			return $link ? array( 'ok' => true, 'url' => $link ) : new WP_Error( 'pca_bad_link', 'Invalid platform or URL.', array( 'status' => 400 ) );
		},
	) );
	register_rest_route( 'posmana-agent/v1', '/growth/campaign', array(
		'methods'             => 'GET',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function () { return array( 'ok' => true, 'checklist' => pca_campaign_checklist() ); },
	) );
} );
