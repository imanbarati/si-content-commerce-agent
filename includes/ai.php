<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * OpenAI-compatible chat adapter. The endpoint and key are configured by the
 * site owner; no provider is implied and no secret is stored in the repo.
 */
function pca_ai_call( $system, $user, $max_tokens = 600 ) {
	$settings = pca_settings();
	if ( empty( $settings['ai_endpoint'] ) || empty( $settings['ai_key'] ) ) {
		return new WP_Error( 'pca_ai_not_configured', 'SI endpoint or key not configured.' );
	}
	$response = wp_remote_post( esc_url_raw( $settings['ai_endpoint'] ), array(
		'headers' => array(
			'Authorization' => 'Bearer ' . trim( $settings['ai_key'] ),
			'Content-Type'  => 'application/json',
		),
		'body' => wp_json_encode( array(
			'model'       => sanitize_text_field( $settings['ai_model'] ),
			'temperature' => 0.2,
			'max_tokens'  => absint( $max_tokens ),
			'messages'    => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
		) ),
		'timeout' => 30,
	) );
	if ( is_wp_error( $response ) ) { return $response; }
	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 ) { return new WP_Error( 'pca_ai_http', 'SI endpoint returned HTTP ' . $code ); }
	$content = $data['choices'][0]['message']['content'] ?? '';
	return is_string( $content ) ? trim( $content ) : new WP_Error( 'pca_ai_empty', 'Empty SI response.' );
}

function pca_ai_parse_json( $text ) {
	$match = array();
	if ( preg_match( '/\{[\s\S]*\}/', $text, $match ) ) {
		$data = json_decode( $match[0], true );
		if ( is_array( $data ) ) { return $data; }
	}
	return array();
}

function pca_ai_enrich_post( $post_id, $title, $body ) {
	$settings = pca_settings();
	if ( empty( $settings['ai_enabled'] ) ) { return false; }

	$system = 'You are a careful Persian/English content editor. Return strict JSON only: {"title":"...","summary":"...","focus":"...","category":"..."}. Do not invent facts. Keep the original language.';
	$user   = wp_json_encode( array( 'title' => $title, 'body' => wp_strip_all_tags( $body ) ) );
	$result = pca_ai_call( $system, $user, 500 );
	if ( is_wp_error( $result ) ) {
		pca_log( 'SI enrichment failed: ' . $result->get_error_message() );
		return false;
	}
	$data = pca_ai_parse_json( $result );
	if ( ! $data ) { return false; }

	if ( ! empty( $data['title'] ) ) {
		wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $data['title'] ), 'post_excerpt' => sanitize_text_field( $data['summary'] ?? '' ) ) );
	}
	if ( ! empty( $data['summary'] ) ) {
		update_post_meta( $post_id, '_pca_ai_summary', sanitize_text_field( $data['summary'] ) );
	}
	if ( ! empty( $data['focus'] ) ) {
		pca_apply_seo( $post_id, sanitize_text_field( $data['focus'] ), sanitize_text_field( $data['summary'] ?? '' ) );
	}
	pca_log( 'SI enrichment applied to post ' . $post_id );
	return true;
}
