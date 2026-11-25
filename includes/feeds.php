<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function pca_fetch_feed_items( $url, $limit = 5 ) {
	if ( ! pca_valid_url( $url ) ) {
		return new WP_Error( 'pca_bad_url', 'Invalid feed URL.' );
	}
	if ( ! function_exists( 'fetch_feed' ) ) {
		require_once ABSPATH . WPINC . '/feed.php';
	}
	$feed = fetch_feed( $url );
	if ( is_wp_error( $feed ) ) {
		return $feed;
	}
	$items = array();
	foreach ( $feed->get_items( 0, $limit ) as $item ) {
		$items[] = array(
			'guid'        => (string) $item->get_guid(),
			'title'       => wp_strip_all_tags( $item->get_title() ),
			'url'         => esc_url_raw( $item->get_permalink() ),
			'description' => wp_kses_post( $item->get_description() ),
			'date'        => $item->get_date( 'Y-m-d H:i:s' ),
		);
	}
	return $items;
}

function pca_create_post_from_item( $item, $source ) {
	$guid = sanitize_text_field( $item['guid'] );
	$found = get_posts( array(
		'post_type'   => 'post',
		'post_status' => 'any',
		'meta_key'    => '_pca_guid',
		'meta_value'  => $guid,
		'numberposts' => 1,
		'fields'      => 'ids',
	) );
	if ( $found ) { return false; }

	$settings = pca_settings();
	$post_id  = wp_insert_post( array(
		'post_type'    => 'post',
		'post_title'   => wp_strip_all_tags( $item['title'] ),
		'post_content' => wp_kses_post( $item['description'] ) . '<p><a href="' . esc_url( $item['url'] ) . '" rel="nofollow noopener">' . esc_html__( 'Original source', 'posmana-content-agent' ) . '</a></p>',
		'post_status'  => in_array( $settings['feed_status'], array( 'draft', 'publish', 'pending' ), true ) ? $settings['feed_status'] : 'draft',
		'post_category'=> absint( $settings['feed_category'] ) ? array( absint( $settings['feed_category'] ) ) : array(),
	), true );
	if ( is_wp_error( $post_id ) ) { return $post_id; }

	update_post_meta( $post_id, '_pca_guid', $guid );
	update_post_meta( $post_id, '_pca_source', sanitize_key( $source ) );
	update_post_meta( $post_id, '_pca_source_url', esc_url_raw( $item['url'] ) );
	pca_ai_enrich_post( $post_id, $item['title'], $item['description'] );
	if ( pca_apply_seo( $post_id, $item['title'], wp_strip_all_tags( $item['description'] ) ) ) {
		pca_log( 'Imported ' . $item['title'] . ' with Yoast metadata.' );
	} else {
		pca_log( 'Imported ' . $item['title'] . '.' );
	}
	return $post_id;
}

function pca_import_all_feeds() {
	$settings = pca_settings();
	$created  = 0;
	foreach ( (array) $settings['feeds'] as $feed_url ) {
		$items = pca_fetch_feed_items( $feed_url, absint( $settings['feed_limit'] ) );
		if ( is_wp_error( $items ) ) {
			pca_log( 'Feed failed: ' . $items->get_error_message() );
			continue;
		}
		foreach ( $items as $item ) {
			$result = pca_create_post_from_item( $item, 'rss' );
			if ( is_numeric( $result ) && $result > 0 ) { $created++; }
		}
	}
	foreach ( (array) $settings['x_handles'] as $handle ) {
		$items = pca_fetch_x_items( $handle );
		if ( is_wp_error( $items ) ) {
			pca_log( 'X feed failed for ' . $handle . ': ' . $items->get_error_message() );
			continue;
		}
		foreach ( $items as $item ) {
			$result = pca_create_post_from_item( $item, 'x' );
			if ( is_numeric( $result ) && $result > 0 ) { $created++; }
		}
	}
	pca_mark_run( 'feeds' );
	pca_log( 'Feed sync finished: ' . $created . ' new post(s).' );
	return $created;
}
