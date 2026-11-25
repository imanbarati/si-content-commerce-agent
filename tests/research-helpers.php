<?php
define( 'ABSPATH', __DIR__ . '/' );

function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value ) { return (string) $value; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function pca_settings() { return array( 'research_provider' => 'google_news' ); }
function pca_log( $message ) {}

require_once dirname( __DIR__ ) . '/includes/research.php';

$checks = array(
	'Persian digits'        => pca_fa_digits( '۱۲۳۴' ) === '1234',
	'Query planning'        => count( pca_research_queries( array( 'name' => 'پوزمانا مینی' ) ) ) === 4,
	'Price extraction'      => pca_research_prices( 'قیمت: ۱۲,۹۰۰,۰۰۰ تومان' ) === array( 12900000.0 ),
	'Domain ranking'        => pca_domain_score( 'https://www.digikala.com/product/1' ) === 3,
	'News domain ranking'   => pca_domain_score( 'https://news.google.com/rss' ) === 1,
);

$failed = 0;
foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
	if ( ! $ok ) { $failed++; }
}
exit( $failed ? 1 : 0 );
