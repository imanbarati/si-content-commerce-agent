<?php
/**
 * AI Content Generation
 * 
 * - Auto-generate product descriptions from feed/research data
 * - Blog post generation from imported content
 * - Category/landing page content
 * - Meta titles/descriptions for SEO
 * - FAQ schema generation
 * - Content rewriting/optimization
 * - Batch processing with queue
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * AI Content Types
 */
function pca_ai_content_types() {
	return array(
		'product_description' => array(
			'label'       => 'Product Description',
			'prompt_file' => 'product-description',
			'max_tokens'  => 800,
			'temperature' => 0.3,
		),
		'product_short_desc' => array(
			'label'       => 'Short Description',
			'prompt_file' => 'product-short-desc',
			'max_tokens'  => 200,
			'temperature' => 0.3,
		),
		'blog_post' => array(
			'label'       => 'Blog Post',
			'prompt_file' => 'blog-post',
			'max_tokens'  => 1500,
			'temperature' => 0.5,
		),
		'category_description' => array(
			'label'       => 'Category Description',
			'prompt_file' => 'category-desc',
			'max_tokens'  => 500,
			'temperature' => 0.3,
		),
		'meta_title' => array(
			'label'       => 'Meta Title',
			'prompt_file' => 'meta-title',
			'max_tokens'  => 60,
			'temperature' => 0.2,
		),
		'meta_description' => array(
			'label'       => 'Meta Description',
			'prompt_file' => 'meta-desc',
			'max_tokens'  => 155,
			'temperature' => 0.2,
		),
		'faq_schema' => array(
			'label'       => 'FAQ Schema',
			'prompt_file' => 'faq-schema',
			'max_tokens'  => 1000,
			'temperature' => 0.2,
		),
		'rewrite' => array(
			'label'       => 'Rewrite / Optimize',
			'prompt_file' => 'rewrite',
			'max_tokens'  => 1000,
			'temperature' => 0.3,
		),
	);
}

/**
 * Load prompt template
 */
function pca_ai_load_prompt( $type, $variables = array() ) {
	$types = pca_ai_content_types();
	if ( ! isset( $types[ $type ] ) ) {
		return new WP_Error( 'pca_ai_bad_type', 'Unknown content type: ' . $type );
	}
	
	$prompt_file = PCA_DIR . 'prompts/' . $types[ $type ]['prompt_file'] . '.txt';
	if ( ! file_exists( $prompt_file ) ) {
		return new WP_Error( 'pca_ai_no_prompt', 'Prompt file not found: ' . $prompt_file );
	}
	
	$prompt = file_get_contents( $prompt_file );
	
	// Replace variables
	foreach ( $variables as $key => $value ) {
		$prompt = str_replace( '{' . $key . '}', $value, $prompt );
	}
	
	return $prompt;
}

/**
 * Call AI endpoint
 */
function pca_ai_call( $system_prompt, $user_prompt, $max_tokens = 600, $temperature = 0.3 ) {
	$settings = pca_get_effective_settings();
	
	if ( empty( $settings['ai_endpoint'] ) || empty( $settings['ai_key'] ) ) {
		return new WP_Error( 'pca_ai_not_configured', 'AI endpoint or key not configured.' );
	}
	
	$model = $settings['ai_model'] ?? 'gpt-4o-mini';
	
	$response = wp_remote_post( esc_url_raw( $settings['ai_endpoint'] ), array(
		'headers' => array(
			'Authorization' => 'Bearer ' . trim( $settings['ai_key'] ),
			'Content-Type'  => 'application/json',
		),
		'body' => wp_json_encode( array(
			'model'       => sanitize_text_field( $model ),
			'temperature' => $temperature,
			'max_tokens'  => absint( $max_tokens ),
			'messages'    => array(
				array( 'role' => 'system', 'content' => $system_prompt ),
				array( 'role' => 'user', 'content' => $user_prompt ),
			),
		) ),
		'timeout' => 60,
	) );
	
	if ( is_wp_error( $response ) ) { return $response; }
	
	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'pca_ai_http', 'AI endpoint returned HTTP ' . $code . ': ' . ( $data['error']['message'] ?? 'unknown' ) );
	}
	
	$content = $data['choices'][0]['message']['content'] ?? '';
	return is_string( $content ) ? trim( $content ) : new WP_Error( 'pca_ai_empty', 'Empty AI response.' );
}

/**
 * Generate content by type
 */
function pca_ai_generate( $type, $variables = array() ) {
	$types = pca_ai_content_types();
	if ( ! isset( $types[ $type ] ) ) {
		return new WP_Error( 'pca_ai_bad_type', 'Unknown content type: ' . $type );
	}
	
	$system = file_exists( PCA_DIR . 'prompts/system.txt' ) 
		? file_get_contents( PCA_DIR . 'prompts/system.txt' )
		: 'You are an expert Persian/English e-commerce content writer. Write persuasive, accurate, SEO-optimized content. Return only the requested output, no explanations.';
	
	$user = pca_ai_load_prompt( $type, $variables );
	if ( is_wp_error( $user ) ) { return $user; }
	
	$max_tokens = $types[ $type ]['max_tokens'];
	$temperature = $types[ $type ]['temperature'];
	
	return pca_ai_call( $system, $user, $max_tokens, $temperature );
}

/**
 * Generate product description from feed item + research
 */
function pca_ai_generate_product_description( $product_data, $research_data = array() ) {
	$variables = array(
		'product_name'  => $product_data['name'] ?? '',
		'product_sku'   => $product_data['sku'] ?? '',
		'product_query' => $product_data['query'] ?? '',
		'feed_title'    => $product_data['feed_title'] ?? '',
		'feed_desc'     => $product_data['feed_description'] ?? '',
		'feed_source'   => $product_data['feed_source'] ?? '',
		'price_min'     => ! empty( $research_data ) ? min( array_column( $research_data, 'price' ) ) : 'N/A',
		'price_max'     => ! empty( $research_data ) ? max( array_column( $research_data, 'price' ) ) : 'N/A',
		'price_avg'     => ! empty( $research_data ) ? round( array_sum( array_column( $research_data, 'price' ) ) / count( $research_data ) ) : 'N/A',
		'sources_count' => count( $research_data ),
		'top_sources'   => implode( ', ', array_slice( array_column( $research_data, 'source' ), 0, 3 ) ),
	);
	
	return pca_ai_generate( 'product_description', $variables );
}

/**
 * Generate blog post from imported feed item
 */
function pca_ai_generate_blog_post( $item, $research_data = array() ) {
	$variables = array(
		'title'       => $item['title'] ?? '',
		'source_url'  => $item['url'] ?? '',
		'description' => $item['description'] ?? '',
		'source_name' => $item['source'] ?? 'feed',
		'research'    => ! empty( $research_data ) ? wp_json_encode( $research_data ) : 'No research data',
	);
	
	return pca_ai_generate( 'blog_post', $variables );
}

/**
 * Generate meta title/description for SEO
 */
function pca_ai_generate_seo_meta( $content, $focus_keyword = '' ) {
	$variables = array(
		'content'        => $content,
		'focus_keyword'  => $focus_keyword,
	);
	
	$title = pca_ai_generate( 'meta_title', $variables );
	$desc  = pca_ai_generate( 'meta_description', $variables );
	
	if ( is_wp_error( $title ) || is_wp_error( $desc ) ) {
		return new WP_Error( 'pca_ai_seo_failed', 'Failed to generate SEO meta' );
	}
	
	return array( 'title' => $title, 'description' => $desc );
}

/**
 * Generate FAQ schema
 */
function pca_ai_generate_faq( $topic, $product_data = array() ) {
	$variables = array(
		'topic'         => $topic,
		'product_name'  => $product_data['name'] ?? '',
		'product_query' => $product_data['query'] ?? '',
	);
	
	$result = pca_ai_generate( 'faq_schema', $variables );
	if ( is_wp_error( $result ) ) { return $result; }
	
	// Parse JSON from response
	$json = pca_ai_parse_json( $result );
	return $json ? $json : new WP_Error( 'pca_ai_faq_parse', 'Failed to parse FAQ JSON' );
}

/**
 * Parse JSON from AI response
 */
function pca_ai_parse_json( $text ) {
	$match = array();
	if ( preg_match( '/\{.*\}/s', $text, $match ) ) {
		$data = json_decode( $match[0], true );
		if ( is_array( $data ) ) { return $data; }
	}
	return false;
}

/**
 * Batch generate for multiple products
 */
function pca_ai_batch_generate( $type, $items, $progress_callback = null ) {
	$results = array();
	$settings = pca_get_effective_settings();
	
	if ( empty( $settings['ai_enabled'] ) ) {
		return new WP_Error( 'pca_ai_disabled', 'AI enrichment is disabled in settings.' );
	}
	
	foreach ( $items as $index => $item ) {
		$result = pca_ai_generate( $type, $item );
		$results[] = array(
			'input'  => $item,
			'output' => is_wp_error( $result ) ? $result->get_error_message() : $result,
			'error'  => is_wp_error( $result ),
		);
		
		if ( $progress_callback && is_callable( $progress_callback ) ) {
			$progress_callback( $index + 1, count( $items ), $item );
		}
		
		// Rate limiting
		usleep( 500000 ); // 0.5s between calls
	}
	
	return $results;
}

/**
 * Queue AI generation job
 */
function pca_queue_ai_generation( $type, $data, $post_id = 0 ) {
	return pca_queue_job( 'ai_generate', array(
		'type'     => $type,
		'data'     => $data,
		'post_id'  => $post_id,
	), 5 );
}

/**
 * Execute AI generation job
 */
add_action( 'pca_process_job_queue', function() {
	// Handled in performance.php, but we add our job type here
}, 10 );

// Add to pca_execute_job in performance.php via filter
add_filter( 'pca_execute_job_types', function( $types ) {
	$types['ai_generate'] = 'pca_execute_ai_generate_job';
	return $types;
} );

function pca_execute_ai_generate_job( $job ) {
	$type = $job['data']['type'];
	$data = $job['data']['data'];
	$post_id = $job['data']['post_id'];
	
	$result = pca_ai_generate( $type, $data );
	
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	
	// If post_id provided, save to post meta
	if ( $post_id ) {
		$meta_key = '_pca_ai_' . $type;
		update_post_meta( $post_id, $meta_key, $result );
		
		// For product description, update post content
		if ( $type === 'product_description' ) {
			wp_update_post( array( 'ID' => $post_id, 'post_content' => $result ) );
		}
		
		// For SEO meta, update Yoast fields
		if ( in_array( $type, array( 'meta_title', 'meta_description' ) ) ) {
			if ( function_exists( 'pca_apply_seo' ) ) {
				$focus = get_post_meta( $post_id, '_pca_ai_meta_title', true );
				$desc  = get_post_meta( $post_id, '_pca_ai_meta_description', true );
				if ( $focus && $desc ) {
					pca_apply_seo( $post_id, $focus, $desc );
				}
			}
		}
	}
	
	return array( 'ok' => true, 'type' => $type, 'post_id' => $post_id );
}

/**
 * Admin: AI Generation UI
 */
add_action( 'admin_footer', function () {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'posmana-agent' ) ) { return; }
	?>
	<script>
	jQuery(document).ready(function($) {
		$('#pca-ai-test').on('click', function() {
			var type = $('#pca-ai-type').val();
			var btn = $(this), out = $('#pca-ai-result');
			btn.prop('disabled', true).text('Generating...');
			out.text('');
			
			var data = {};
			$('.pca-ai-field').each(function() {
				data[ $(this).attr('name') ] = $(this).val();
			});
			
			$.post(ajaxurl, { action: 'pca_ai_test', nonce: pcaNonce, type: type, data: data }, function(resp) {
				btn.prop('disabled', false).text('Test Generate');
				out.val( resp.success ? resp.data : 'Failed: ' + resp.data );
			});
		});
		
		// Show/hide fields based on type
		$('#pca-ai-type').on('change', function() {
			var type = $(this).val();
			$('.pca-ai-field-group').hide();
			$('#pca-ai-fields-' + type).show();
		}).trigger('change');
	});
	</script>
	<?php
} );

add_action( 'wp_ajax_pca_ai_test', function () {
	check_ajax_referer( 'pca_settings', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
	
	$type = sanitize_key( $_POST['type'] );
	$data = $_POST['data'] ?? array();
	
	$result = pca_ai_generate( $type, $data );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}
	wp_send_json_success( $result );
} );

/**
 * Settings defaults
 */
add_filter( 'pca_defaults', function( $defaults ) {
	$defaults['ai_enabled'] = false;
	return $defaults;
} );
