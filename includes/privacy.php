<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Privacy hygiene for content you own: remove invisible control characters and
 * exotic spaces that can alter display or carry hidden formatting.
 * This does NOT remove C2PA/EXIF provenance or statistical text watermarks.
 */
function pca_privacy_clean_text( $text ) {
	$text = (string) $text;
	$zero = array( "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}", "\u{2060}", "\u{180E}", "\u{00AD}" );
	$text = str_replace( $zero, '', $text );
	$text = preg_replace( '/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{E0000}-\x{E007F}]/u', '', $text );
	$text = preg_replace( '/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $text );
	return preg_replace( '/[ \t]{2,}/u', ' ', $text );
}

function pca_privacy_clean_post( $post_id ) {
	$post = get_post( absint( $post_id ) );
	if ( ! $post ) { return new WP_Error( 'pca_no_post', 'Post not found.' ); }
	$fields = array(
		'post_title'   => pca_privacy_clean_text( $post->post_title ),
		'post_content' => pca_privacy_clean_text( $post->post_content ),
		'post_excerpt' => pca_privacy_clean_text( $post->post_excerpt ),
	);
	wp_update_post( array_merge( array( 'ID' => absint( $post_id ) ), $fields ) );
	pca_log( 'Privacy cleanup applied to post ' . absint( $post_id ) );
	return true;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'posmana-agent/v1', '/privacy/clean', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function ( $request ) {
			$post_id = absint( $request->get_param( 'post_id' ) );
			$result  = pca_privacy_clean_post( $post_id );
			if ( is_wp_error( $result ) ) { return $result; }
			return array( 'ok' => true, 'post_id' => $post_id );
		},
	) );
} );
