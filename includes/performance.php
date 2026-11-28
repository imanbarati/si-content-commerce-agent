<?php
/**
 * Performance Optimization
 * 
 * - Object caching (Redis/Memcached/APCu/Array fallback)
 * - Background job processing via WP-Cron offload
 * - Feed/price processing in chunks
 * - Rate limiting & circuit breaker for external APIs
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Cache backend detection
 */
function pca_cache_backend() {
	if ( extension_loaded( 'redis' ) && class_exists( 'Redis' ) ) {
		$redis = new Redis();
		$connected = @$redis->connect( '127.0.0.1', 6379, 1 );
		if ( $connected ) { return 'redis'; }
	}
	
	if ( extension_loaded( 'memcached' ) && class_exists( 'Memcached' ) ) {
		$mc = new Memcached();
		$mc->addServer( '127.0.0.1', 11211 );
		return 'memcached';
	}
	
	if ( extension_loaded( 'apcu' ) && function_exists( 'apcu_fetch' ) ) {
		return 'apcu';
	}
	
	return 'wp_cache';
}

/**
 * Get cache instance
 */
function pca_cache() {
	static $cache = null;
	
	if ( $cache !== null ) { return $cache; }
	
	$backend = pca_cache_backend();
	
	$cache = array(
		'backend' => $backend,
		'get'     => function( $key ) use ( $backend ) {
			switch ( $backend ) {
			case 'redis':
				$redis = new Redis();
				$redis->connect( '127.0.0.1', 6379 );
				$val = $redis->get( 'pca:' . $key );
				return $val ? json_decode( $val, true ) : false;
			case 'memcached':
				$mc = new Memcached();
				$mc->addServer( '127.0.0.1', 11211 );
				return $mc->get( 'pca:' . $key );
			case 'apcu':
				return apcu_fetch( 'pca:' . $key );
			default:
				return wp_cache_get( $key, 'pca' );
		}
		},
		'set'     => function( $key, $value, $ttl = 3600 ) use ( $backend ) {
			switch ( $backend ) {
			case 'redis':
				$redis = new Redis();
				$redis->connect( '127.0.0.1', 6379 );
				return $redis->setex( 'pca:' . $key, $ttl, wp_json_encode( $value ) );
			case 'memcached':
				$mc = new Memcached();
				$mc->addServer( '127.0.0.1', 11211 );
				return $mc->set( 'pca:' . $key, $value, $ttl );
			case 'apcu':
				return apcu_store( 'pca:' . $key, $value, $ttl );
			default:
				return wp_cache_set( $key, $value, 'pca', $ttl );
		}
		},
		'delete'  => function( $key ) use ( $backend ) {
			switch ( $backend ) {
			case 'redis':
				$redis = new Redis();
				$redis->connect( '127.0.0.1', 6379 );
				return $redis->del( 'pca:' . $key );
			case 'memcached':
				$mc = new Memcached();
				$mc->addServer( '127.0.0.1', 11211 );
				return $mc->delete( 'pca:' . $key );
			case 'apcu':
				return apcu_delete( 'pca:' . $key );
			default:
				return wp_cache_delete( $key, 'pca' );
		}
		},
		'flush'   => function() use ( $backend ) {
			switch ( $backend ) {
			case 'redis':
				$redis = new Redis();
				$redis->connect( '127.0.0.1', 6379 );
				$keys = $redis->keys( 'pca:*' );
				if ( $keys ) { $redis->del( $keys ); }
				return true;
			case 'memcached':
				$mc = new Memcached();
				$mc->addServer( '127.0.0.1', 11211 );
				return $mc->flush();
			case 'apcu':
				return apcu_clear_cache();
			default:
				return wp_cache_flush();
		}
		},
	);
	
	return $cache;
}

function pca_cache_get( $key, $default = false ) {
	$cache = pca_cache();
	$result = $cache['get']( $key );
	return $result !== false ? $result : $default;
}

function pca_cache_set( $key, $value, $ttl = 3600 ) {
	$cache = pca_cache();
	return $cache['set']( $key, $value, $ttl );
}

function pca_cache_delete( $key ) {
	$cache = pca_cache();
	return $cache['delete']( $key );
}

/**
 * Circuit Breaker for external APIs
 */
class PCA_Circuit_Breaker {
	private $failure_threshold;
	private $timeout;
	private $cache_key;
	
	public function __construct( $key, $threshold = 5, $timeout = 60 ) {
		$this->cache_key = 'circuit_' . $key;
		$this->failure_threshold = $threshold;
		$this->timeout = $timeout;
	}
	
	public function is_open() {
		$state = pca_cache_get( $this->cache_key, array( 'failures' => 0, 'opened_at' => 0 ) );
		if ( $state['failures'] >= $this->failure_threshold ) {
			if ( time() - $state['opened_at'] > $this->timeout ) {
				return 'half-open';
			}
			return true;
		}
		return false;
	}
	
	public function record_success() {
		pca_cache_delete( $this->cache_key );
	}
	
	public function record_failure() {
		$state = pca_cache_get( $this->cache_key, array( 'failures' => 0, 'opened_at' => 0 ) );
		$state['failures']++;
		if ( $state['failures'] >= $this->failure_threshold ) {
			$state['opened_at'] = time();
		}
		pca_cache_set( $this->cache_key, $state, 3600 );
	}
}

/**
 * Rate Limiter (token bucket)
 */
class PCA_Rate_Limiter {
	private $key;
	private $rate;
	private $burst;
	
	public function __construct( $key, $rate = 10, $burst = 20 ) {
		$this->key = 'ratelimit_' . $key;
		$this->rate = $rate;
		$this->burst = $burst;
	}
	
	public function acquire( $tokens = 1 ) {
		$state = pca_cache_get( $this->key, array( 'tokens' => $this->burst, 'updated' => time() ) );
		$now = time();
		
		$elapsed = $now - $state['updated'];
		$state['tokens'] = min( $this->burst, $state['tokens'] + ( $elapsed * $this->rate ) );
		
		if ( $state['tokens'] >= $tokens ) {
			$state['tokens'] -= $tokens;
			$state['updated'] = $now;
			pca_cache_set( $this->key, $state, 3600 );
			return true;
		}
		
		$state['updated'] = $now;
		pca_cache_set( $this->key, $state, 3600 );
		return false;
	}
	
	public function wait_for_token( $tokens = 1, $max_wait = 5 ) {
		$start = time();
		while ( time() - $start < $max_wait ) {
			if ( $this->acquire( $tokens ) ) { return true; }
			usleep( 100000 );
		}
		return false;
	}
}

/**
 * Background Job Queue
 */
function pca_queue_job( $job_type, $data, $priority = 10 ) {
	$queue = get_option( 'pca_job_queue', array() );
	
	$job = array(
		'id'        => wp_generate_uuid4(),
		'type'      => $job_type,
		'data'      => $data,
		'priority'  => $priority,
		'created'   => current_time( 'mysql' ),
		'attempts'  => 0,
		'max_tries' => 3,
	);
	
	$queue[] = $job;
	usort( $queue, function( $a, $b ) { return $a['priority'] - $b['priority']; } );
	
	if ( count( $queue ) > 1000 ) {
		$queue = array_slice( $queue, 0, 1000 );
	}
	
	update_option( 'pca_job_queue', $queue, false );
	
	if ( ! wp_next_scheduled( 'pca_process_job_queue' ) ) {
		wp_schedule_single_event( time() + 5, 'pca_process_job_queue' );
	}
	
	return $job['id'];
}

add_action( 'pca_process_job_queue', function () {
	$queue = get_option( 'pca_job_queue', array() );
	if ( empty( $queue ) ) { return; }
	
	$job = array_shift( $queue );
	update_option( 'pca_job_queue', $queue, false );
	
	$job['attempts']++;
	
	try {
		$result = pca_execute_job( $job );
		
		if ( is_wp_error( $result ) ) {
			throw new Exception( $result->get_error_message() );
		}
		
		pca_log( 'Job completed: ' . $job['type'] . ' (' . $job['id'] . ')' );
		
	} catch ( Exception $e ) {
		pca_log( 'Job failed: ' . $job['type'] . ' - ' . $e->getMessage() );
		
		if ( $job['attempts'] < $job['max_tries'] ) {
			$delay = min( 300, pow( 2, $job['attempts'] ) * 30 );
			wp_schedule_single_event( time() + $delay, 'pca_retry_job', array( $job ) );
		} else {
			pca_log( 'Job permanently failed after ' . $job['max_tries'] . ' attempts: ' . $job['id'] );
		}
	}
	
	if ( ! empty( $queue ) ) {
		wp_schedule_single_event( time() + 2, 'pca_process_job_queue' );
	}
} );

add_action( 'pca_retry_job', function ( $job ) {
	$queue = get_option( 'pca_job_queue', array() );
	$queue[] = $job;
	usort( $queue, function( $a, $b ) { return $a['priority'] - $b['priority']; } );
	update_option( 'pca_job_queue', $queue, false );
	
	if ( ! wp_next_scheduled( 'pca_process_job_queue' ) ) {
		wp_schedule_single_event( time() + 5, 'pca_process_job_queue' );
	}
} );

function pca_execute_job( $job ) {
	switch ( $job['type'] ) {
		case 'feed_sync':
			return pca_import_all_feeds();
		case 'price_sync':
			return pca_sync_prices();
		case 'research':
			$sku = $job['data']['sku'] ?? '';
			if ( empty( $sku ) ) { return new WP_Error( 'no_sku', 'SKU required' ); }
			return pca_research_run( $sku );
		case 'mc_api_sync':
			if ( function_exists( 'pca_mc_sync_all_products' ) ) {
				return pca_mc_sync_all_products();
			}
			return new WP_Error( 'mc_not_available', 'MC API not available' );
		case 'wc_price_sync':
			if ( function_exists( 'pca_wc_sync_prices' ) ) {
				return pca_wc_sync_prices();
			}
			return new WP_Error( 'wc_not_active', 'WC not active' );
		case 'clean_post':
			$post_id = $job['data']['post_id'] ?? 0;
			if ( ! $post_id ) { return new WP_Error( 'no_post_id', 'Post ID required' ); }
			return pca_privacy_clean_post( $post_id );
		case 'batch_feed_import':
			$feed_url = $job['data']['feed_url'] ?? '';
			$limit = $job['data']['limit'] ?? 5;
			if ( empty( $feed_url ) ) { return new WP_Error( 'no_feed_url', 'Feed URL required' ); }
			$items = pca_fetch_feed_items( $feed_url, $limit );
			if ( is_wp_error( $items ) ) { return $items; }
			$created = 0;
			foreach ( $items as $item ) {
				if ( pca_create_post_from_item( $item, 'rss' ) ) { $created++; }
			}
			return $created;
		default:
			return new WP_Error( 'unknown_job', 'Unknown job type: ' . $job['type'] );
	}
}

function pca_process_feed_chunked( $feed_url, $chunk_size = 50, $delay = 2 ) {
	$items = pca_fetch_feed_items( $feed_url, 1000 );
	if ( is_wp_error( $items ) ) { return $items; }
	
	$chunks = array_chunk( $items, $chunk_size );
	$total_created = 0;
	
	foreach ( $chunks as $index => $chunk ) {
		foreach ( $chunk as $item ) {
			$result = pca_create_post_from_item( $item, 'rss' );
			if ( is_numeric( $result ) && $result > 0 ) { $total_created++; }
		}
		
		if ( $index < count( $chunks ) - 1 ) {
			wp_schedule_single_event( time() + $delay, 'pca_feed_chunk', array( $feed_url, $index + 1, $chunk_size, $delay ) );
			pca_log( 'Feed chunk ' . ( $index + 1 ) . '/' . count( $chunks ) . ' processed, ' . $total_created . ' created so far' );
			return array( 'status' => 'processing', 'created' => $total_created, 'chunk' => $index + 1, 'total_chunks' => count( $chunks ) );
		}
	}
	
	pca_log( 'Feed processing complete: ' . $total_created . ' posts created' );
	return array( 'status' => 'complete', 'created' => $total_created );
}

add_action( 'pca_feed_chunk', function( $feed_url, $chunk_index, $chunk_size, $delay ) {
	$items = pca_fetch_feed_items( $feed_url, 1000 );
	if ( is_wp_error( $items ) ) { return; }
	
	$chunks = array_chunk( $items, $chunk_size );
	if ( $chunk_index >= count( $chunks ) ) { return; }
	
	$chunk = $chunks[ $chunk_index ];
	$created = 0;
	foreach ( $chunk as $item ) {
		if ( pca_create_post_from_item( $item, 'rss' ) ) { $created++; }
	}
	
	if ( $chunk_index < count( $chunks ) - 1 ) {
		wp_schedule_single_event( time() + $delay, 'pca_feed_chunk', array( $feed_url, $chunk_index + 1, $chunk_size, $delay ) );
	}
}, 10, 4 );

add_action( 'admin_footer', function () {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'posmana-agent' ) ) { return; }
	?>
	<script>
	jQuery(document).ready(function($) {
		$('#pca-cache-flush').on('click', function() {
			var btn = $(this), out = $('#pca-cache-status');
			btn.prop('disabled', true).text('Flushing...');
			$.post(ajaxurl, { action: 'pca_flush_cache', nonce: pcaNonce }, function(resp) {
				btn.prop('disabled', false).text('Flush Cache');
				out.text( resp.success ? 'Cache flushed' : 'Failed' );
			});
		});
		
		$('#pca-queue-status').on('click', function() {
			var btn = $(this), out = $('#pca-queue-status-out');
			btn.prop('disabled', true).text('Checking...');
			$.post(ajaxurl, { action: 'pca_queue_status', nonce: pcaNonce }, function(resp) {
				btn.prop('disabled', false).text('Queue Status');
				out.text( JSON.stringify( resp.data ) );
			});
		});
	});
	</script>
	<?php
} );

add_action( 'wp_ajax_pca_flush_cache', function () {
	check_ajax_referer( 'pca_settings', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
	
	$cache = pca_cache();
	$result = $cache['flush']();
	wp_send_json_success( $result );
} );

add_action( 'wp_ajax_pca_queue_status', function () {
	check_ajax_referer( 'pca_settings', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
	
	$queue = get_option( 'pca_job_queue', array() );
	wp_send_json_success( array(
		'pending' => count( $queue ),
		'jobs'    => array_slice( $queue, 0, 10 ),
	) );
} );

add_filter( 'pca_defaults', function ( $defaults ) {
	$defaults['cache_enabled']     = true;
	$defaults['background_jobs']   = true;
	$defaults['chunked_feeds']     = false;
	return $defaults;
} );