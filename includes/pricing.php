<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Generic JSON-RPC MCP client. Endpoints are configured by the site owner and
 * may be real MCP bridges or any HTTP tool endpoint returning JSON.
 */
function pca_mcp_call( $endpoint, $tool, $arguments ) {
	if ( ! pca_valid_url( $endpoint ) ) {
		return new WP_Error( 'pca_bad_endpoint', 'Invalid MCP endpoint URL.' );
	}
	$body = array(
		'jsonrpc' => '2.0',
		'id'      => wp_generate_uuid4(),
		'method'  => 'tools/call',
		'params'  => array( 'name' => sanitize_key( $tool ), 'arguments' => $arguments ),
	);
	$response = wp_remote_post( $endpoint, array(
		'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
		'body'    => wp_json_encode( $body ),
		'timeout' => 15,
	) );
	if ( is_wp_error( $response ) ) { return $response; }
	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'pca_mcp_http', 'MCP endpoint returned HTTP ' . $code );
	}
	return is_array( $data ) ? $data : array();
}

function pca_extract_price( $payload ) {
	$text = $payload['result']['content'][0]['text'] ?? '';
	if ( ! is_string( $text ) || '' === $text ) { $text = wp_json_encode( $payload ); }
	$data = json_decode( $text, true );
	if ( ! is_array( $data ) ) { $data = $payload; }

	foreach ( array( 'price', 'min_price', 'lowest_price', 'final_price' ) as $key ) {
		if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) { return (float) $data[ $key ]; }
	}
	$found = null;
	array_walk_recursive( $data, function ( $value, $key ) use ( &$found ) {
		if ( null === $found && in_array( $key, array( 'price', 'min_price', 'lowest_price', 'final_price' ), true ) && is_numeric( $value ) ) {
			$found = (float) $value;
		}
	} );
	return $found;
}

function pca_sync_prices() {
	$settings = pca_settings();
	if ( empty( $settings['price_sync_enabled'] ) ) { return 0; }

	$suggestions = array();
	$applied     = 0;
	foreach ( (array) $settings['products'] as $product ) {
		$sku   = sanitize_key( $product['sku'] ?? '' );
		$query = sanitize_text_field( $product['query'] ?? $product['name'] ?? '' );
		if ( ! $sku || '' === $query ) { continue; }

		$prices = array();
		foreach ( array( 'mcp_digikala_url', 'mcp_torob_url' ) as $field ) {
			if ( empty( $settings[ $field ] ) ) { continue; }
			$response = pca_mcp_call( $settings[ $field ], $settings['mcp_tool'], array( 'query' => $query ) );
			if ( is_wp_error( $response ) ) {
				pca_log( $field . ' failed: ' . $response->get_error_message() );
				continue;
			}
			$price = pca_extract_price( $response );
			if ( null !== $price && $price > 0 ) { $prices[] = $price; }
		}
		if ( ! $prices ) { continue; }

		$market    = min( $prices );
		$undercut  = max( 0, (float) ( $settings['undercut'] ?? 0 ) );
		$cost      = max( 0, (float) ( $product['cost'] ?? 0 ) );
		$margin    = max( 0, (float) ( $settings['min_margin'] ?? 0 ) );
		$new_price = max( $market - $undercut, $cost * ( 1 + $margin / 100 ) );
		$suggestions[ $sku ] = array(
			'market' => $market,
			'price'  => round( $new_price ),
			'name'   => sanitize_text_field( $product['name'] ?? $sku ),
		);

		if ( ! empty( $settings['price_auto_apply'] ) ) {
			if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
				$product_id = wc_get_product_id_by_sku( $sku );
				if ( $product_id ) {
					$woo = wc_get_product( $product_id );
					if ( $woo ) { $woo->set_price( round( $new_price ) ); $woo->save(); $applied++; }
				}
			} else {
				$current = get_option( 'pca_prices', array() );
				$current = is_array( $current ) ? $current : array();
				$current[ $sku ] = round( $new_price );
				update_option( 'pca_prices', $current, false );
				$applied++;
			}
		}
	}

	update_option( 'pca_price_suggestions', $suggestions, false );
	pca_merchant_send( array( 'type' => 'price_suggestions', 'data' => $suggestions ) );
	pca_mark_run( 'prices' );
	pca_log( 'Price sync finished: ' . count( $suggestions ) . ' suggestion(s), ' . $applied . ' applied.' );
	return $applied;
}
