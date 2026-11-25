<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * X.com adapter. Neither bearer tokens nor bridge URLs are bundled. The site
 * owner supplies them, and X API access may require a paid plan. No attempt is
 * made to bypass authentication or platform restrictions.
 */
function pca_fetch_x_items( $handle ) {
	$settings = pca_settings();
	$handle   = ltrim( sanitize_text_field( $handle ), '@' );
	if ( '' === $handle ) { return new WP_Error( 'pca_bad_handle', 'Empty handle.' ); }

	$items = array();
	if ( ! empty( $settings['x_bearer'] ) ) {
		$user = pca_x_api( '/2/users/by/username/' . rawurlencode( $handle ) );
		if ( is_wp_error( $user ) ) { return $user; }
		$user_id = $user['data']['id'] ?? '';
		if ( ! $user_id ) { return new WP_Error( 'pca_x_user', 'User id not found.' ); }
		$tweets = pca_x_api( '/2/users/' . rawurlencode( $user_id ) . '/tweets?max_results=5&tweet.fields=created_at,text' );
		if ( is_wp_error( $tweets ) ) { return $tweets; }
		foreach ( (array) ( $tweets['data'] ?? array() ) as $tweet ) {
			$id = sanitize_text_field( $tweet['id'] ?? '' );
			$items[] = array(
				'guid'        => 'x-' . $id,
				'title'       => wp_trim_words( wp_strip_all_tags( $tweet['text'] ?? '' ), 8, '…' ),
				'url'         => 'https://x.com/' . $handle . '/status/' . rawurlencode( $id ),
				'description' => '<p>' . esc_html( $tweet['text'] ?? '' ) . '</p>',
				'date'        => isset( $tweet['created_at'] ) ? sanitize_text_field( $tweet['created_at'] ) : current_time( 'mysql' ),
			);
		}
		return $items;
	}

	if ( ! empty( $settings['x_rss_bridge'] ) ) {
		$url = str_replace( '{handle}', rawurlencode( $handle ), $settings['x_rss_bridge'] );
		if ( ! pca_valid_url( $url ) ) { return new WP_Error( 'pca_bad_bridge', 'Invalid RSS bridge URL.' ); }
		return pca_fetch_feed_items( $url, absint( $settings['feed_limit'] ) );
	}

	return new WP_Error( 'pca_x_not_configured', 'Set an X bearer token or an RSS bridge URL in settings.' );
}

function pca_x_api( $path ) {
	$settings = pca_settings();
	$response = wp_remote_get( 'https://api.x.com' . $path, array(
		'headers' => array( 'Authorization' => 'Bearer ' . trim( $settings['x_bearer'] ), 'Accept' => 'application/json' ),
		'timeout' => 15,
	) );
	if ( is_wp_error( $response ) ) { return $response; }
	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'pca_x_http', 'X API returned HTTP ' . $code );
	}
	return is_array( $data ) ? $data : array();
}
