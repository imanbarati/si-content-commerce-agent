<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function pca_yoast_active() {
	return defined( 'WPSEO_VERSION' ) || class_exists( 'Yoast\WP\SEO\Main' );
}

function pca_apply_seo( $post_id, $focus, $description ) {
	$settings = pca_settings();
	if ( empty( $settings['seo_enabled'] ) ) { return false; }

	$focus      = wp_strip_all_tags( $focus );
	$description = wp_trim_words( wp_strip_all_tags( $description ), 25, '' );
	update_post_meta( $post_id, '_yoast_wpseo_focuskw', $focus );
	update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
	update_post_meta( $post_id, '_pca_seo_focus', $focus );
	return pca_yoast_active();
}

function pca_seo_checklist() {
	$items   = array();
	$active  = pca_yoast_active();
	$items[] = array(
		'label' => __( 'Yoast SEO active', 'posmana-content-agent' ),
		'ok'    => $active,
		'hint'  => $active ? __( 'Focus keyphrase and meta description are written to Yoast meta fields.', 'posmana-content-agent' ) : __( 'Install Yoast SEO. Meta fields are still written as standard post meta.', 'posmana-content-agent' ),
	);
	$items[] = array(
		'label' => __( 'Feed posts use drafts by default', 'posmana-content-agent' ),
		'ok'    => 'draft' === pca_settings()['feed_status'],
		'hint'  => __( 'Review imported posts before publishing to avoid duplicate-content issues.', 'posmana-content-agent' ),
	);
	$items[] = array(
		'label' => __( 'Canonical source links included', 'posmana-content-agent' ),
		'ok'    => true,
		'hint'  => __( 'Each imported post links back to the original item with nofollow.', 'posmana-content-agent' ),
	);
	$items[] = array(
		'label' => __( 'Price sync is dry-run by default', 'posmana-content-agent' ),
		'ok'    => empty( pca_settings()['price_auto_apply'] ),
		'hint'  => __( 'Enable auto-apply only after reviewing suggestions.', 'posmana-content-agent' ),
	);
	return $items;
}
