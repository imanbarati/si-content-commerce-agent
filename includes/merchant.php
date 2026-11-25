<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function pca_merchant_mode_allows( $direction ) {
	$mode = sanitize_key( pca_settings()['merchant_mode'] );
	return 'both' === $mode || $mode === $direction;
}

function pca_merchant_token_ok( $token ) {
	$expected = trim( pca_settings()['merchant_token'] );
	return '' !== $expected && hash_equals( $expected, (string) $token );
}

/** Inbound merchant feed: creates a draft post. Requires the shared token. */
function pca_merchant_inbound( $request ) {
	if ( ! pca_merchant_mode_allows( 'from' ) ) {
		return new WP_Error( 'pca_merchant_disabled', 'Inbound merchant mode is off.', array( 'status' => 403 ) );
	}
	$token = $request->get_header( 'X-Merchant-Token' );
	if ( ! pca_merchant_token_ok( $token ) ) {
		return new WP_Error( 'pca_merchant_token', 'Invalid merchant token.', array( 'status' => 401 ) );
	}
	$title = sanitize_text_field( $request->get_param( 'title' ) );
	$body  = wp_kses_post( $request->get_param( 'content' ) );
	$sku   = sanitize_key( $request->get_param( 'sku' ) );
	if ( '' === $title || '' === $body ) {
		return new WP_Error( 'pca_merchant_fields', 'title and content are required.', array( 'status' => 400 ) );
	}
	$post_id = wp_insert_post( array(
		'post_title'   => $title,
		'post_content' => $body,
		'post_status'  => 'draft',
	), true );
	if ( is_wp_error( $post_id ) ) { return $post_id; }
	update_post_meta( $post_id, '_pca_source', 'merchant' );
	update_post_meta( $post_id, '_pca_sku', $sku );
	pca_ai_enrich_post( $post_id, $title, $body );
	pca_log( 'Merchant inbound post ' . $post_id );
	return array( 'ok' => true, 'post_id' => $post_id );
}

/** Outbound merchant webhook: sends suggestions/research summaries. */
function pca_merchant_send( $payload ) {
	$settings = pca_settings();
	if ( ! pca_merchant_mode_allows( 'to' ) || empty( $settings['merchant_to_url'] ) ) { return false; }
	$response = wp_remote_post( esc_url_raw( $settings['merchant_to_url'] ), array(
		'headers' => array(
			'Content-Type'    => 'application/json',
			'X-Merchant-Token'=> trim( $settings['merchant_token'] ),
		),
		'body'    => wp_json_encode( $payload ),
		'timeout' => 15,
	) );
	if ( is_wp_error( $response ) ) {
		pca_log( 'Merchant outbound failed: ' . $response->get_error_message() );
		return false;
	}
	pca_log( 'Merchant outbound sent.' );
	return true;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'posmana-agent/v1', '/merchant/inbound', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'pca_merchant_inbound',
		'args'                => array(
			'title'   => array( 'required' => true ),
			'content' => array( 'required' => true ),
		),
	) );
} );
