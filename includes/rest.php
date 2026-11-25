<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'rest_api_init', function () {
	register_rest_route( 'posmana-agent/v1', '/status', array(
		'methods'             => 'GET',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function () {
			$settings = pca_settings();
			$prices   = get_option( 'pca_price_suggestions', array() );
			return array(
				'version'          => PCA_VERSION,
				'yoast_active'     => pca_yoast_active(),
				'feed_count'       => count( (array) $settings['feeds'] ),
				'x_handles'        => count( (array) $settings['x_handles'] ),
				'x_configured'     => ! empty( $settings['x_bearer'] ) || ! empty( $settings['x_rss_bridge'] ),
				'mcp_configured'   => array(
					'digikala' => ! empty( $settings['mcp_digikala_url'] ),
					'torob'    => ! empty( $settings['mcp_torob_url'] ),
				),
				'price_auto_apply' => ! empty( $settings['price_auto_apply'] ),
				'last_feed_run'    => pca_last_run( 'feeds' ),
				'last_price_run'   => pca_last_run( 'prices' ),
				'price_suggestions'=> is_array( $prices ) ? count( $prices ) : 0,
			);
		},
	) );

	register_rest_route( 'posmana-agent/v1', '/sync/(?P<what>feeds|prices)', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function ( $request ) {
			$what = sanitize_key( $request['what'] );
			if ( 'feeds' === $what ) {
				return array( 'ok' => true, 'created' => pca_import_all_feeds() );
			}
			return array( 'ok' => true, 'applied' => pca_sync_prices() );
		},
	) );

	register_rest_route( 'posmana-agent/v1', '/research/(?P<sku>[a-z0-9-]+)', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function ( $request ) {
			$report = pca_research_run( sanitize_key( $request['sku'] ) );
			if ( is_wp_error( $report ) ) { return $report; }
			return $report;
		},
	) );

	register_rest_route( 'posmana-agent/v1', '/mcp-test', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function ( $request ) {
			$endpoint = esc_url_raw( $request->get_param( 'endpoint' ) );
			$tool     = sanitize_key( $request->get_param( 'tool' ) ?: pca_settings()['mcp_tool'] );
			$query    = sanitize_text_field( $request->get_param( 'query' ) );
			$result   = pca_mcp_call( $endpoint, $tool, array( 'query' => $query ) );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'pca_test_failed', $result->get_error_message(), array( 'status' => 400 ) );
			}
			return array( 'ok' => true, 'price' => pca_extract_price( $result ), 'response' => wp_json_encode( $result ) );
		},
	) );
} );
