<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

register_activation_hook( PCA_FILE, function () {
	add_option( 'pca_settings', pca_defaults(), '', false );
	if ( ! wp_next_scheduled( 'pca_feed_sync_hook' ) ) {
		wp_schedule_event( time(), pca_settings()['interval'], 'pca_feed_sync_hook' );
	}
	if ( ! wp_next_scheduled( 'pca_price_sync_hook' ) ) {
		wp_schedule_event( time() + 600, 'hourly', 'pca_price_sync_hook' );
	}
	pca_log( 'Plugin activated.' );
} );

register_deactivation_hook( PCA_FILE, function () {
	wp_clear_scheduled_hook( 'pca_feed_sync_hook' );
	wp_clear_scheduled_hook( 'pca_price_sync_hook' );
} );

add_action( 'pca_feed_sync_hook', 'pca_import_all_feeds' );
add_action( 'pca_price_sync_hook', 'pca_sync_prices' );

add_shortcode( 'pca_price', function ( $atts ) {
	$atts  = shortcode_atts( array( 'sku' => '' ), $atts, 'pca_price' );
	$price = get_option( 'pca_prices', array() );
	$sku   = sanitize_key( $atts['sku'] );
	if ( ! isset( $price[ $sku ] ) ) { return ''; }
	return esc_html( pca_money( $price[ $sku ] ) );
} );

add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['pca_five_minutes'] = array( 'interval' => 300, 'display' => 'Every five minutes' );
	return $schedules;
} );
