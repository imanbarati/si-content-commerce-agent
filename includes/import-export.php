<?php
/**
 * Import/Export Settings
 * 
 * One-click JSON export/import of all plugin settings.
 * Sensitive fields (keys, tokens, secrets) are redacted on export.
 * On import, existing keys are preserved unless explicitly overwritten.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Fields that should never be exported (or redacted)
 */
function pca_sensitive_fields() {
	return array(
		'ai_key',
		'x_bearer',
		'deepseek_key',
		'google_cse_key',
		'google_service_account_json',
		'merchant_token',
		'merchant_to_url',
		'mcp_digikala_url',
		'mcp_torob_url',
		'ga4_api_secret',
		'pca_oauth_tokens',
	);
}

/**
 * Export all settings to JSON
 */
function pca_export_settings( $include_sensitive = false ) {
	$settings = pca_settings();
	$sensitive = $include_sensitive ? array() : pca_sensitive_fields();
	
	$export = array(
		'version'     => PCA_VERSION,
		'exported_at' => current_time( 'mysql' ),
		'site_url'    => home_url(),
		'settings'    => array(),
	);
	
	foreach ( $settings as $key => $value ) {
		if ( in_array( $key, $sensitive, true ) ) {
			if ( is_string( $value ) && ! empty( $value ) ) {
				$export['settings'][ $key ] = '***REDACTED***';
			} else {
				$export['settings'][ $key ] = $value;
			}
		} else {
			$export['settings'][ $key ] = $value;
		}
	}
	
	$export['plugin_version'] = PCA_VERSION;
	$export['wp_version']     = get_bloginfo( 'version' );
	$export['php_version']    = PHP_VERSION;
	$export['wc_active']      = class_exists( 'WooCommerce' );
	
	return $export;
}

/**
 * Import settings from JSON
 */
function pca_import_settings( $data, $overwrite_keys = false ) {
	if ( ! is_array( $data ) || empty( $data['settings'] ) ) {
		return new WP_Error( 'pca_import_invalid', 'Invalid import data: missing settings array.' );
	}
	
	if ( ! empty( $data['plugin_version'] ) && version_compare( $data['plugin_version'], PCA_VERSION, '>' ) ) {
		return new WP_Error( 'pca_import_version', 'Import data is from newer plugin version (' . $data['plugin_version'] . '). Current: ' . PCA_VERSION );
	}
	
	$current = pca_settings();
	$imported = $data['settings'];
	$sensitive = pca_sensitive_fields();
	$updated = array();
	$skipped = array();
	$preserved_keys = array();
	
	foreach ( $imported as $key => $value ) {
		if ( in_array( $key, $sensitive, true ) && $value === '***REDACTED***' ) {
			if ( $overwrite_keys ) {
				$skipped[] = $key . ' (redacted, not overwritten)';
			} else {
				$preserved_keys[] = $key;
			}
			continue;
		}
		
		if ( ! array_key_exists( $key, $current ) ) {
			$skipped[] = $key . ' (unknown setting)';
			continue;
		}
		
		$current_type = gettype( $current[ $key ] );
		$import_type  = gettype( $value );
		
		if ( $current_type !== $import_type && $import_type !== 'NULL' ) {
			settype( $value, $current_type );
		}
		
		$current[ $key ] = $value;
		$updated[] = $key;
	}
	
	pca_update_settings( $current );
	
	pca_log( 'Settings imported: ' . count( $updated ) . ' updated, ' . count( $preserved_keys ) . ' keys preserved, ' . count( $skipped ) . ' skipped' );
	
	return array(
		'updated'         => $updated,
		'preserved_keys'  => $preserved_keys,
		'skipped'         => $skipped,
		'imported_at'     => current_time( 'mysql' ),
	);
}

/**
 * Export settings as downloadable file
 */
add_action( 'admin_post_nopriv_pca_export_settings', 'pca_export_settings_download' );
add_action( 'admin_post_pca_export_settings', 'pca_export_settings_download' );

function pca_export_settings_download() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions', 403 );
	}
	
	check_admin_referer( 'pca_export_settings' );
	
	$include_sensitive = isset( $_POST['include_sensitive'] );
	$export = pca_export_settings( $include_sensitive );
	
	$filename = 'si-agent-settings-' . date( 'Y-m-d-H-i-s' ) . '.json';
	
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	header( 'Content-Length: ' . strlen( $json ) );
	
	echo $json;
	exit;
}

/**
 * Import settings from uploaded file
 */
add_action( 'admin_post_pca_import_settings', 'pca_import_settings_upload' );

function pca_import_settings_upload() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions', 403 );
	}
	
	check_admin_referer( 'pca_import_settings' );
	
	if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
		wp_redirect( add_query_arg( 'pca_import_error', 'no_file', wp_get_referer() ) );
		exit;
	}
	
	$file = $_FILES['import_file'];
	if ( $file['error'] !== UPLOAD_ERR_OK ) {
		wp_redirect( add_query_arg( 'pca_import_error', 'upload_failed', wp_get_referer() ) );
		exit;
	}
	
	$content = file_get_contents( $file['tmp_name'] );
	$data = json_decode( $content, true );
	
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		wp_redirect( add_query_arg( 'pca_import_error', 'invalid_json', wp_get_referer() ) );
		exit;
	}
	
	$overwrite_keys = ! empty( $_POST['overwrite_keys'] );
	$result = pca_import_settings( $data, $overwrite_keys );
	
	if ( is_wp_error( $result ) ) {
		wp_redirect( add_query_arg( 'pca_import_error', urlencode( $result->get_error_message() ), wp_get_referer() ) );
		exit;
	}
	
	$msg = 'Settings imported: ' . count( $result['updated'] ) . ' updated';
	if ( ! empty( $result['preserved_keys'] ) ) {
		$msg .= ', ' . count( $result['preserved_keys'] ) . ' keys preserved';
	}
	if ( ! empty( $result['skipped'] ) ) {
		$msg .= ', ' . count( $result['skipped'] ) . ' skipped';
	}
	
	wp_redirect( add_query_arg( array( 'pca_import_success' => 1, 'pca_import_msg' => urlencode( $msg ) ), wp_get_referer() ) );
	exit;
}

/**
 * Admin UI: Import/Export section
 */
add_action( 'admin_footer', function () {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'posmana-agent' ) ) { return; }
	?>
	<div class="pca-import-export" style="margin-top: 32px; padding: 20px; background: #f6f7f7; border: 1px solid #ddd; border-radius: 4px;">
		<h2 style="margin-top:0">Import / Export Settings</h2>
		<p class="description">Backup or migrate all plugin settings. Sensitive keys are redacted by default.</p>
		
		<div style="display: flex; gap: 16px; flex-wrap: wrap; align-items: center;">
			<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display: inline;">
				<input type="hidden" name="action" value="pca_export_settings">
				<?php wp_nonce_field( 'pca_export_settings' ); ?>
				<label style="display: inline-flex; align-items: center; gap: 8px; margin-right: 16px;">
					<input type="checkbox" name="include_sensitive" value="1"> Include sensitive keys (not recommended)
				</label>
				<button type="submit" class="button button-primary">Export Settings (JSON)</button>
			</form>
			
			<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" enctype="multipart/form-data" style="display: inline;">
				<input type="hidden" name="action" value="pca_import_settings">
				<?php wp_nonce_field( 'pca_import_settings' ); ?>
				<input type="file" name="import_file" accept=".json" style="display: inline-block; width: auto; margin-right: 8px;" required>
				<label style="display: inline-flex; align-items: center; gap: 8px; margin-right: 16px;">
					<input type="checkbox" name="overwrite_keys" value="1"> Overwrite redacted keys
				</label>
				<button type="submit" class="button">Import Settings</button>
			</form>
		</div>
		
		<?php if ( isset( $_GET['pca_import_success'] ) ) : ?>
			<div class="notice notice-success is-dismissible" style="margin-top: 16px;">
				<p><?php echo esc_html( urldecode( $_GET['pca_import_msg'] ) ); ?></p>
			</div>
		<?php endif; ?>
		
		<?php if ( isset( $_GET['pca_import_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible" style="margin-top: 16px;">
				<p>Import failed: <?php echo esc_html( urldecode( $_GET['pca_import_error'] ) ); ?></p>
			</div>
		<?php endif; ?>
	</div>
	<?php
} );
