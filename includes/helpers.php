<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function pca_defaults() {
	return array(
		'feeds'              => array(),
		'feed_status'        => 'draft',
		'feed_category'      => 0,
		'feed_limit'         => 5,
		'x_handles'          => array(),
		'x_bearer'           => '',
		'x_rss_bridge'       => '',
		'mcp_digikala_url'   => '',
		'mcp_torob_url'      => '',
		'mcp_tool'           => 'search_product',
		'price_sync_enabled' => false,
		'price_auto_apply'   => false,
		'undercut'           => 1000,
		'min_margin'         => 5,
		'products'           => array(),
		'seo_enabled'        => true,
		'interval'           => 'hourly',
	);
}

function pca_settings() {
	$saved = get_option( 'pca_settings', array() );
	if ( ! is_array( $saved ) ) { $saved = array(); }
	return array_merge( pca_defaults(), $saved );
}

function pca_update_settings( $settings ) {
	return update_option( 'pca_settings', $settings, false );
}

function pca_log( $message ) {
	$log   = get_option( 'pca_log', array() );
	$log   = is_array( $log ) ? $log : array();
	array_unshift( $log, array( 'time' => current_time( 'mysql' ), 'message' => sanitize_text_field( $message ) ) );
	update_option( 'pca_log', array_slice( $log, 0, 100 ), false );
}

function pca_valid_url( $url ) {
	$parts = wp_parse_url( esc_url_raw( $url ) );
	return ! empty( $parts['scheme'] ) && in_array( $parts['scheme'], array( 'http', 'https' ), true ) && ! empty( $parts['host'] );
}

function pca_last_run( $key ) {
	$times = get_option( 'pca_last_run', array() );
	return isset( $times[ $key ] ) ? $times[ $key ] : __( 'never', 'posmana-content-agent' );
}

function pca_mark_run( $key ) {
	$times        = get_option( 'pca_last_run', array() );
	$times        = is_array( $times ) ? $times : array();
	$times[ $key ] = current_time( 'mysql' );
	update_option( 'pca_last_run', $times, false );
}

function pca_money( $value ) {
	$number = (float) $value;
	return number_format( $number, 0, '.', ',' ) . ' تومان';
}
