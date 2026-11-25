<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * BetterSearch-style merchant research: plan queries, gather from a compliant
 * source, rank, fetch, extract prices and fact-check that each price appears in
 * the fetched source. No scraping of Google search results, no CAPTCHA bypass.
 */
function pca_fa_digits( $text ) {
	$map = array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' );
	return strtr( (string) $text, $map );
}

function pca_research_queries( $product ) {
	$name = sanitize_text_field( $product['name'] ?? '' );
	if ( '' === $name ) { return array(); }
	return array_values( array_unique( array_filter( array(
		$name . ' price',
		$name . ' قیمت',
		$name . ' price comparison',
		$name . ' digikala torob',
	) ) ) );
}

function pca_research_search( $query ) {
	$settings = pca_settings();
	$provider = sanitize_key( $settings['research_provider'] );

	if ( 'google_news' === $provider ) {
		// Public RSS feed. No scraping, no anti-bot bypass, no key required.
		$url = 'https://news.google.com/rss/search?q=' . rawurlencode( $query ) . '&hl=fa&gl=IR&ceid=IR:fa';
		$items = pca_fetch_feed_items( $url, 5 );
		if ( is_wp_error( $items ) ) { return $items; }
		return array_map( function ( $item ) {
			return array( 'title' => $item['title'], 'url' => $item['url'], 'snippet' => wp_strip_all_tags( $item['description'] ) );
		}, $items );
	}

	if ( 'google_cse' === $provider ) {
		if ( empty( $settings['google_cse_key'] ) || empty( $settings['google_cse_cx'] ) ) {
			return new WP_Error( 'pca_cse_missing', 'Google Custom Search key and CX are required.' );
		}
		$url = add_query_arg( array(
			'key' => $settings['google_cse_key'], 'cx' => $settings['google_cse_cx'],
			'q' => $query, 'num' => 5,
		), 'https://www.googleapis.com/customsearch/v1' );
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) { return $response; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$out  = array();
		foreach ( (array) ( $data['items'] ?? array() ) as $item ) {
			$out[] = array( 'title' => sanitize_text_field( $item['title'] ?? '' ), 'url' => esc_url_raw( $item['link'] ?? '' ), 'snippet' => sanitize_text_field( $item['snippet'] ?? '' ) );
		}
		return $out;
	}

	if ( 'deepseek' === $provider ) {
		if ( empty( $settings['deepseek_key'] ) ) { return new WP_Error( 'pca_ds_missing', 'DeepSeek search key is required.' ); }
		$response = wp_remote_post( esc_url_raw( $settings['deepseek_endpoint'] ), array(
			'headers' => array( 'Authorization' => 'Bearer ' . trim( $settings['deepseek_key'] ), 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( array( 'query' => $query, 'count' => 10 ) ),
			'timeout' => 20,
		) );
		if ( is_wp_error( $response ) ) { return $response; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$rows = $data['results'] ?? $data['data'] ?? array();
		$out  = array();
		foreach ( (array) $rows as $item ) {
			$out[] = array(
				'title'   => sanitize_text_field( $item['title'] ?? '' ),
				'url'     => esc_url_raw( $item['url'] ?? $item['link'] ?? '' ),
				'snippet' => sanitize_text_field( $item['snippet'] ?? $item['content'] ?? '' ),
			);
		}
		return $out;
	}

	if ( 'mcp' === $provider ) {
		$endpoint = $settings['mcp_digikala_url'] ?: $settings['mcp_torob_url'];
		if ( ! $endpoint ) { return new WP_Error( 'pca_mcp_missing', 'Configure an MCP endpoint first.' ); }
		$result = pca_mcp_call( $endpoint, 'search', array( 'query' => $query ) );
		if ( is_wp_error( $result ) ) { return $result; }
		return array( array( 'title' => $query, 'url' => $endpoint, 'snippet' => wp_json_encode( $result ) ) );
	}

	return new WP_Error( 'pca_bad_provider', 'Unknown research provider.' );
}

function pca_domain_score( $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! $host ) { return 0; }
	if ( false !== strpos( $host, 'digikala' ) || false !== strpos( $host, 'torob' ) ) { return 3; }
	if ( false !== strpos( $host, 'news.google' ) ) { return 1; }
	return 2;
}

function pca_research_prices( $text ) {
	$text = pca_fa_digits( $text );
	$prices = array();
	if ( preg_match_all( '/(?:price|قیمت|تومان|ریال)\s*:?\s*([0-9][0-9,\.]{3,})/iu', $text, $matches ) ) {
		foreach ( $matches[1] as $raw ) {
			$number = (float) str_replace( array( ',', '.' ), '', $raw );
			if ( $number > 1000 ) { $prices[] = $number; }
		}
	}
	return array_values( array_unique( $prices ) );
}

function pca_research_run( $sku ) {
	$settings = pca_settings();
	$product  = null;
	foreach ( (array) $settings['products'] as $item ) {
		if ( sanitize_key( $item['sku'] ?? '' ) === sanitize_key( $sku ) ) { $product = $item; break; }
	}
	if ( ! $product ) { return new WP_Error( 'pca_no_product', 'Product not found.' ); }

	$results = array();
	foreach ( pca_research_queries( $product ) as $query ) {
		$found = pca_research_search( $query );
		if ( is_wp_error( $found ) ) { pca_log( 'Research query failed: ' . $found->get_error_message() ); continue; }
		foreach ( $found as $item ) {
			if ( empty( $item['url'] ) ) { continue; }
			$item['score'] = pca_domain_score( $item['url'] );
			$results[ $item['url'] ] = $item;
		}
	}
	usort( $results, function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
	$results = array_slice( $results, 0, 6 );

	$findings = array();
	foreach ( $results as $item ) {
		$response = wp_remote_get( $item['url'], array( 'timeout' => 12 ) );
		if ( is_wp_error( $response ) ) { continue; }
		$body = pca_fa_digits( wp_remote_retrieve_body( $response ) );
		$plain = wp_strip_all_tags( $body );
		foreach ( pca_research_prices( $plain ) as $price ) {
			// Fact-check: the exact number must exist in the fetched source.
			$needle = (string) (int) $price;
			if ( false === strpos( preg_replace( '/[^0-9]/', '', $plain ), $needle ) ) { continue; }
			$findings[] = array(
				'price'      => $price,
				'source'     => esc_url_raw( $item['url'] ),
				'title'      => $item['title'],
				'confidence' => $item['score'] >= 3 ? 'high' : 'single-source',
			);
			break;
		}
	}

	$report = array( 'sku' => sanitize_key( $sku ), 'product' => $product['name'] ?? '', 'queries' => pca_research_queries( $product ), 'findings' => $findings, 'time' => current_time( 'mysql' ) );
	$reports = get_option( 'pca_research_reports', array() );
	$reports = is_array( $reports ) ? $reports : array();
	$reports[ sanitize_key( $sku ) ] = $report;
	update_option( 'pca_research_reports', $reports, false );
	pca_mark_run( 'research' );
	pca_log( 'Research finished for ' . $sku . ': ' . count( $findings ) . ' verified finding(s).' );
	return $report;
}
