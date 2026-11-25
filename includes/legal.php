<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Audit-only automation for domains the site owner explicitly declares. */
function pca_owned_domains() {
	$settings = pca_settings();
	return array_values( array_filter( array_map( 'esc_url_raw', (array) ( $settings['owned_domains'] ?? array() ) ) ) );
}

function pca_robots_allows( $robots, $url, $agent = 'SI-Agent' ) {
	$parts = wp_parse_url( $url );
	$path  = $parts['path'] ?? '/';
	$active = false;
	$matched = false;
	foreach ( preg_split( '/\r?\n/', (string) $robots ) as $raw ) {
		$line = trim( preg_replace( '/#.*$/', '', $raw ) );
		if ( '' === $line ) { continue; }
		$pieces = explode( ':', $line, 2 );
		$key = strtolower( trim( $pieces[0] ) );
		$value = isset( $pieces[1] ) ? trim( $pieces[1] ) : '';
		if ( 'user-agent' === $key ) {
			$active = ( '*' === $value ) || ( false !== stripos( $agent, $value ) );
			continue;
		}
		if ( ! $active ) { continue; }
		if ( 'disallow' === $key && '' !== $value && 0 === strpos( $path, $value ) ) { $matched = true; }
		if ( 'allow' === $key && '' !== $value && 0 === strpos( $path, $value ) ) { $matched = false; }
	}
	return ! $matched;
}

function pca_audit_html( $html, $url ) {
	$html = (string) $html;
	return array(
		'url'    => esc_url_raw( $url ),
		'checks' => array(
			array( 'label' => 'title', 'ok' => (bool) preg_match( '/<title[^>]*>[^<]{5,}<\/title>/i', $html ) ),
			array( 'label' => 'meta description', 'ok' => (bool) preg_match( '/<meta[^>]+name=[\x27"]description[\x27"][^>]+content=[\x27"][^\x27"]{20,}/i', $html ) ),
			array( 'label' => 'canonical', 'ok' => (bool) preg_match( '/rel=[\x27"]canonical[\x27"]/i', $html ) ),
			array( 'label' => 'json-ld', 'ok' => false !== stripos( $html, 'application/ld+json' ) ),
			array( 'label' => 'h1', 'ok' => (bool) preg_match( '/<h1[\s>]/i', $html ) ),
		),
	);
}

function pca_legal_audit( $domains ) {
	$results = array();
	foreach ( array_slice( (array) $domains, 0, 5 ) as $domain ) {
		$robots = wp_remote_get( trailingslashit( $domain ) . 'robots.txt', array( 'timeout' => 8 ) );
		$robots_text = is_wp_error( $robots ) ? '' : wp_remote_retrieve_body( $robots );
		$url = trailingslashit( $domain );
		if ( ! pca_robots_allows( $robots_text, $url ) ) {
			$results[] = array( 'url' => $url, 'skipped' => 'robots.txt' );
			continue;
		}
		$response = wp_remote_get( $url, array( 'timeout' => 8, 'headers' => array( 'User-Agent' => 'SI-Agent' ) ) );
		$results[] = pca_audit_html( is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response ), $url );
		sleep( 1 );
	}
	return $results;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'posmana-agent/v1', '/legal/audit', array(
		'methods'             => 'POST',
		'permission_callback' => function () { return current_user_can( 'manage_options' ); },
		'callback'            => function () {
			$domains = pca_owned_domains();
			if ( ! $domains ) {
				return new WP_Error( 'pca_no_domains', 'Configure owned domains first.', array( 'status' => 403 ) );
			}
			return array( 'ok' => true, 'results' => pca_legal_audit( $domains ) );
		},
	) );
} );
