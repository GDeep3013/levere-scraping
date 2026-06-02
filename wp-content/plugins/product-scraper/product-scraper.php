<?php
/**
 * Plugin Name: Product Scraper
 * Description: Scrape product data from Emtek website and import into WooCommerce with ACF fields and native variations.
 * Version: 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'PS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
require_once PS_PLUGIN_PATH . 'scraper-alt.php';

// Increase limits for this plugin only
add_action( 'init', function() {
	if ( is_admin() && isset( $_POST['action'] ) && strpos( $_POST['action'], 'ps_' ) === 0 ) {
		set_time_limit( 300 );
		ini_set( 'memory_limit', '512M' );
	}
});

// Initialize plugin
add_action( 'init', 'ps_init' );
add_action( 'admin_menu', 'ps_add_admin_menu' );
add_action( 'admin_post_ps_import_product', 'ps_handle_import_product' );
add_action( 'admin_post_ps_scrape_and_import', 'ps_handle_scrape_and_import' );
add_action( 'admin_post_ps_bulk_import', 'ps_handle_bulk_import' );
add_action( 'admin_post_ps_download_import_log', 'ps_download_import_log' );
add_action( 'admin_post_ps_delete_import_log', 'ps_delete_import_log' );
add_action( 'wp_ajax_ps_get_product_data', 'ps_ajax_get_product_data' );
add_action( 'wp_ajax_ps_import_single_product', 'ps_ajax_import_single_product' );

// REST API endpoints
add_action( 'rest_api_init', 'ps_register_rest_routes' );

// WooCommerce hooks
add_filter( 'woocommerce_csv_product_import_mapping_options', 'ps_add_column_mapping' );
add_filter( 'woocommerce_csv_product_import_mapping_default_columns', 'ps_add_default_columns_mapping' );
add_action( 'woocommerce_product_import_inserted_product_object', 'ps_save_acf_fields', 10, 2 );

// Admin configurator tabs
add_filter( 'woocommerce_product_data_tabs', 'ps_add_product_data_tab' );
add_action( 'woocommerce_product_data_panels', 'ps_add_product_data_panel' );
add_action( 'save_post_product', 'ps_save_product_configurator_data' );

/**
 * Initialize plugin
 */
function ps_init() {
	$upload_dir = wp_upload_dir();
	$log_dir = $upload_dir['basedir'] . '/product-scraper-logs';
	if ( ! file_exists( $log_dir ) ) {
		wp_mkdir_p( $log_dir );
	}
	
	if ( ! function_exists( 'get_field' ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-warning"><p><strong>Product Scraper:</strong> Advanced Custom Fields (ACF) is not active. Please install and activate ACF to use all features.</p></div>';
		});
	}
}

/**
 * Register REST API routes
 */
function ps_register_rest_routes() {
	register_rest_route(
		'custom/v1',
		'/product/',
		array(
			'methods'             => 'GET',
			'callback'            => 'ps_get_single_product_callback',
			'permission_callback' => '__return_true',
		)
	);
	
	register_rest_route(
		'custom/v1',
		'/products/',
		array(
			'methods'             => 'GET',
			'callback'            => 'ps_get_products_callback',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Get single product callback
 */
function ps_get_single_product_callback( $request ) {
	set_time_limit(180);
	
	$product_url = $request->get_param( 'url' );
	
	if ( empty( $product_url ) ) {
		return new WP_Error( 'missing_url', 'Product URL is missing', array( 'status' => 400 ) );
	}
	
	if ( ! function_exists( 'str_get_html' ) ) {
		if ( file_exists( PS_PLUGIN_PATH . 'simple_html_dom.php' ) ) {
			require_once PS_PLUGIN_PATH . 'simple_html_dom.php';
		} else {
			return new WP_Error( 'missing_parser', 'HTML parser not found', array( 'status' => 500 ) );
		}
	}
	
	$parse_url = parse_url( $product_url );
	$home_url = $parse_url["scheme"] . "://" . $parse_url["host"] . "/";
	
	$product_data = ps_scrape_single_product_direct( $product_url, $home_url );
	
	if ( $product_data && ! empty( $product_data['title'] ) ) {
		return array(
			'success' => true,
			'product' => $product_data
		);
	} else {
		return new WP_Error( 'scrape_failed', 'Failed to scrape product', array( 'status' => 500 ) );
	}
}

/**
 * Get multiple products callback
 */
function ps_get_products_callback( $request ) {
	set_time_limit(300);
	
	$url = $request->get_param( 'url' );
	$n = min( intval( $request->get_param( 'n' ) ), 50 );
	
	if ( empty( $url ) ) {
		return new WP_Error( 'missing_url', 'URL is missing', array( 'status' => 400 ) );
	}
	
	if ( ! function_exists( 'str_get_html' ) ) {
		if ( file_exists( PS_PLUGIN_PATH . 'simple_html_dom.php' ) ) {
			require_once PS_PLUGIN_PATH . 'simple_html_dom.php';
		} else {
			return new WP_Error( 'missing_parser', 'HTML parser not found', array( 'status' => 500 ) );
		}
	}
	
	$parse_url = parse_url( $url );
	$home_url = $parse_url["scheme"] . "://" . $parse_url["host"] . "/";
	
	$html = ps_get_html_with_headers( $url );
	if ( ! $html ) {
		return new WP_Error( 'scrape_failed', 'Failed to retrieve webpage', array( 'status' => 500 ) );
	}
	
	$product_grid = $html->find( ".product-grid_gridList__q6ju2", 0 );
	if ( ! $product_grid ) {
		return new WP_Error( 'no_products', 'Product grid not found', array( 'status' => 404 ) );
	}
	
	$products = [];
	$product_count = 0;
	$links = $product_grid->find( "a" );
	
	foreach ( $links as $a ) {
		if ( $product_count >= $n ) break;
		
		$product_url = $a->getAttribute( "href" );
		if ( strpos( $product_url, 'http' ) !== 0 ) {
			$product_url = $home_url . ltrim( $product_url, '/' );
		}
		
		$product_data = ps_scrape_single_product_direct( $product_url, $home_url );
		if ( $product_data && ! empty( $product_data['title'] ) ) {
			$products[] = $product_data;
			$product_count++;
		}
		
		usleep( 500000 );
	}
	
	$html->clear();
	unset( $html );
	
	return array(
		'success' => true,
		'total_products' => count( $products ),
		'products' => $products
	);
}

/**
 * Scrape single product directly
 */
function ps_scrape_single_product_direct( $product_url, $home_url ) {
	$product_html = ps_get_html_with_headers( $product_url );
	if ( ! $product_html ) {
		return null;
	}
	
	$product_data = array(
		'title' => '',
		'image' => '',
		'description' => '',
		'technical_specs' => '',
		'installation_instructions' => array(),
		'specifications' => array(),
		'functions' => array(),
		'categories' => array(),
		'finishes' => array(),
		'knobs' => array(),
		'build_id' => '',
		'home_url' => $home_url
	);
	
	$main = $product_html->find( "main", 0 );
	if ( $main ) {
		foreach ( $main->find( ".breadcrumbs_link__Asi34" ) as $breadcrumbs_link ) {
			$breadcrumb_text = trim( $breadcrumbs_link->innertext );
			if ( $breadcrumb_text !== "All Products" && ! empty( $breadcrumb_text ) ) {
				$product_data['categories'][] = $breadcrumb_text;
			}
		}
	}
	
	$next_data = $product_html->find( "#__NEXT_DATA__", 0 );
	if ( $next_data ) {
		$json_data = json_decode( $next_data->innertext, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			$product_data['build_id'] = isset( $json_data['buildId'] ) ? $json_data['buildId'] : '';
			$extracted = ps_extract_product_data( $json_data );
			$product_data = array_merge( $product_data, $extracted );
		}
	}
	
	$product_html->clear();
	unset( $product_html );
	
	return $product_data;
}

/**
 * Extract product data from JSON - CLEAN & DYNAMIC
 */
function ps_extract_product_data( $product_data ) {
	$result = array();
	
	$pageProps = isset( $product_data['props']['pageProps'] ) ? $product_data['props']['pageProps'] : array();
	$wagtail = isset( $pageProps['wagtail'] ) ? $pageProps['wagtail'] : array();
	$attribute = isset( $wagtail['attribute'] ) ? $wagtail['attribute'] : array();
	
	$result['title'] = isset( $wagtail['title'] ) ? sanitize_text_field( $wagtail['title'] ) : '';
	$result['image'] = isset( $attribute['primary_image']['image'] ) ? esc_url_raw( $attribute['primary_image']['image'] ) : '';
	$result['description'] = isset( $attribute['description'] ) ? wp_kses_post( $attribute['description'] ) : '';
	$result['technical_specs'] = isset( $attribute['technical_specs'] ) ? wp_kses_post( $attribute['technical_specs'] ) : '';
	
	// Store finish images for lookup
	$finish_images = array();
	if ( ! empty( $attribute['finish_images'] ) && is_array( $attribute['finish_images'] ) ) {
		$finish_images = $attribute['finish_images'];
	}
	
	// Process configurator groups - COMPLETELY DYNAMIC
	$result['configurator_groups'] = array();
	
	if ( ! empty( $attribute['configurator'] ) && is_array( $attribute['configurator'] ) ) {
		foreach ( $attribute['configurator'] as $group ) {
			$group_name = isset( $group['display_name'] ) ? $group['display_name'] : '';
			if ( empty( $group_name ) ) continue;
			
			$options = isset( $group['options'] ) ? $group['options'] : array();
			if ( empty( $options ) ) continue;
			
			$processed_options = array();
			foreach ( $options as $option ) {
				$option_id = isset( $option['id'] ) ? $option['id'] : '';
				$image_url = '';
				
				if ( ! empty( $finish_images[ $option_id ] ) ) {
					$image_url = $finish_images[ $option_id ];
				} elseif ( isset( $option['primary_image']['image'] ) ) {
					$image_url = $option['primary_image']['image'];
				}
				
				// Auto-generate code if empty
				$code = isset( $option['code'] ) ? $option['code'] : '';
				if ( empty( $code ) ) {
					$code = sanitize_title( isset( $option['option'] ) ? $option['option'] : '' );
				}
				
				$processed_options[] = array(
					'id'    => $option_id,
					'name'  => isset( $option['option'] ) ? $option['option'] : '',
					'code'  => $code,
					'image' => $image_url,
				);
			}
			
			$result['configurator_groups'][] = array(
				'display_name' => $group_name,
				'options'      => $processed_options,
			);
		}
	}
	
	// Installation instructions
	if ( ! empty( $attribute['installation_instructions'] ) && is_array( $attribute['installation_instructions'] ) ) {
		$result['installation_instructions'] = array();
		foreach ( $attribute['installation_instructions'] as $instruction ) {
			$result['installation_instructions'][] = array(
				'title' => isset( $instruction['title'] ) ? $instruction['title'] : '',
				'file'  => isset( $instruction['file'] ) ? $instruction['file'] : ( isset( $instruction['url'] ) ? $instruction['url'] : '' )
			);
		}
	}
	
	// Specifications
	if ( ! empty( $attribute['specifications'] ) && is_array( $attribute['specifications'] ) ) {
		$result['specifications'] = array();
		foreach ( $attribute['specifications'] as $spec ) {
			$result['specifications'][] = array(
				'title' => isset( $spec['title'] ) ? $spec['title'] : '',
				'file'  => isset( $spec['file'] ) ? $spec['file'] : ( isset( $spec['url'] ) ? $spec['url'] : '' )
			);
		}
	}
	
	// ACF Functions (descriptions)
	if ( ! empty( $attribute['functions'] ) && is_array( $attribute['functions'] ) ) {
		$result['acf_functions'] = array();
		foreach ( $attribute['functions'] as $function ) {
			$modal = isset( $function['function_modal'] ) ? $function['function_modal'] : $function;
			$result['acf_functions'][] = array(
				'title'       => isset( $modal['title'] ) ? $modal['title'] : '',
				'description' => isset( $modal['description'] ) ? $modal['description'] : '',
				'image'       => isset( $modal['image']['url'] ) ? $modal['image']['url'] : ( isset( $modal['image'] ) ? $modal['image'] : '' )
			);
		}
	}
	
	return $result;
}



/**
 * Import product - creates variable/simple based on configurator
 */
function ps_import_product_from_data( $product_data, $status = 'draft', $download_files = true, $regular_price = 0 ) {
	if ( empty( $product_data['title'] ) ) {
		return array( 'success' => false, 'message' => 'Product title is required' );
	}
	
	$existing_product_id = ps_find_product_by_title( $product_data['title'] );
	
	if ( $existing_product_id ) {
		return array( 
			'success' => false, 
			'message' => 'Product already exists: ' . $product_data['title'],
			'product_id' => $existing_product_id 
		);
	}
	
	$config_groups = isset( $product_data['configurator_groups'] ) ? $product_data['configurator_groups'] : array();
	
	if ( ! empty( $config_groups ) ) {
		return ps_create_variable_product( $product_data, $config_groups, $status, $download_files, $regular_price );
	} else {
		return ps_create_simple_product( $product_data, $status, $download_files, $regular_price );
	}
}

/**
 * Get HTML with headers
 */
function ps_get_html_with_headers( $url ) {
	$args = array(
		'headers' => array(
			'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
			'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
		),
		'timeout' => 30,
	);
	
	$response = wp_remote_get( $url, $args );
	
	if ( is_wp_error( $response ) ) {
		return false;
	}
	
	$body = wp_remote_retrieve_body( $response );
	return str_get_html( $body );
}

/**
 * Add admin menu page with logs
 */
function ps_add_admin_menu() {
	add_menu_page(
		'Product Scraper',
		'Product Scraper',
		'manage_options',
		'product-scraper',
		'ps_admin_page',
		'dashicons-download',
		30
	);
	
	add_submenu_page(
		'product-scraper',
		'Import Product',
		'Import Product',
		'manage_options',
		'product-scraper-import',
		'ps_import_page'
	);
	
	add_submenu_page(
		'product-scraper',
		'Bulk Import',
		'Bulk Import',
		'manage_options',
		'product-scraper-bulk',
		'ps_bulk_import_page'
	);
	
	add_submenu_page(
		'product-scraper',
		'Import Logs',
		'Import Logs',
		'manage_options',
		'product-scraper-logs',
		'ps_logs_page'
	);
	
	add_submenu_page(
		'product-scraper',
		'ACF Setup',
		'ACF Setup',
		'manage_options',
		'product-scraper-acf',
		'ps_acf_setup_page'
	);
}

/**
 * Logs page
 */
function ps_logs_page() {
	$log_dir = WP_CONTENT_DIR . '/uploads/product-scraper-logs/';
	$log_files = glob( $log_dir . 'import-log-*.txt' );
	?>
	<div class="wrap">
		<h1>Import Logs</h1>
		
		<div class="card" style="max-width: 800px;">
			<h2>Previous Import Logs</h2>
			<?php if ( empty( $log_files ) ) : ?>
				<p>No logs found.</p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>Log File</th>
							<th>Date</th>
							<th>Size</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log_files as $log_file ) : ?>
							<?php
							$file_name = basename( $log_file );
							$file_date = date( 'Y-m-d H:i:s', filemtime( $log_file ) );
							$file_size = size_format( filesize( $log_file ) );
							?>
							<tr>
								<td><?php echo esc_html( $file_name ); ?></td>
								<td><?php echo esc_html( $file_date ); ?></td>
								<td><?php echo esc_html( $file_size ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=ps_download_import_log&file=' . urlencode( $file_name ) . '&path=uploads' ) ); ?>" class="button button-small">Download</a>
									<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=ps_delete_import_log&file=' . urlencode( $file_name ) . '&path=uploads' ) ); ?>" class="button button-small" onclick="return confirm('Are you sure?')">Delete</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * Download import log file
 */
function ps_download_import_log() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	
	$file_name = isset( $_GET['file'] ) ? sanitize_file_name( $_GET['file'] ) : '';
	$path = isset( $_GET['path'] ) && $_GET['path'] === 'uploads' ? WP_CONTENT_DIR . '/uploads/product-scraper-logs/' : PS_PLUGIN_PATH;
	$file_path = $path . $file_name;
	
	if ( ! file_exists( $file_path ) ) {
		wp_die( 'Log file not found' );
	}
	
	header( 'Content-Type: text/plain' );
	header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
	header( 'Content-Length: ' . filesize( $file_path ) );
	readfile( $file_path );
	exit;
}

/**
 * Delete import log file
 */
function ps_delete_import_log() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	
	$file_name = isset( $_GET['file'] ) ? sanitize_file_name( $_GET['file'] ) : '';
	$path = isset( $_GET['path'] ) && $_GET['path'] === 'uploads' ? WP_CONTENT_DIR . '/uploads/product-scraper-logs/' : PS_PLUGIN_PATH;
	$file_path = $path . $file_name;
	
	if ( file_exists( $file_path ) ) {
		unlink( $file_path );
	}
	
	wp_redirect( admin_url( 'admin.php?page=product-scraper-logs' ) );
	exit;
}

/**
 * ACF Setup Instructions Page
 */
function ps_acf_setup_page() {
	?>
	<div class="wrap">
		<h1>ACF Field Setup</h1>
		
		<div class="card" style="max-width: 800px;">
			<h2>Required ACF Field Groups</h2>
			<p>Please create the following ACF Field Groups for your products:</p>
			
			<h3>1. Technical Specs Field</h3>
			<ul>
				<li><strong>Field Name:</strong> technical_specs</li>
				<li><strong>Field Label:</strong> Technical Specifications</li>
				<li><strong>Field Type:</strong> WYSIWYG or Text Area</li>
				<li><strong>Location:</strong> Product → Product Type is equal to → Simple Product</li>
			</ul>
			
			<h3>2. Installation Instructions (Repeater Field)</h3>
			<ul>
				<li><strong>Field Name:</strong> installation_instructions</li>
				<li><strong>Field Label:</strong> Installation Instructions</li>
				<li><strong>Field Type:</strong> Repeater</li>
				<li><strong>Sub Fields:</strong>
					<ul>
						<li>title (Text) - Label: Title</li>
						<li>file (File) - Label: PDF File, Return Format: File ID</li>
					</ul>
				</li>
			</ul>
			
			<h3>3. Specifications (Repeater Field)</h3>
			<ul>
				<li><strong>Field Name:</strong> specifications</li>
				<li><strong>Field Label:</strong> Specifications</li>
				<li><strong>Field Type:</strong> Repeater</li>
				<li><strong>Sub Fields:</strong>
					<ul>
						<li>title (Text) - Label: Title</li>
						<li>file (File) - Label: PDF File, Return Format: File ID</li>
					</ul>
				</li>
			</ul>
			
			<h3>4. Functions (Repeater Field)</h3>
			<ul>
				<li><strong>Field Name:</strong> functions</li>
				<li><strong>Field Label:</strong> Functions</li>
				<li><strong>Field Type:</strong> Repeater</li>
				<li><strong>Sub Fields:</strong>
					<ul>
						<li>title (Text) - Label: Title</li>
						<li>description (WYSIWYG) - Label: Description</li>
						<li>image (Image) - Label: Image, Return Format: Image ID</li>
					</ul>
				</li>
			</ul>
			
			<p><a href="<?php echo admin_url('edit.php?post_type=acf-field-group'); ?>" class="button button-primary">Go to ACF Field Groups</a></p>
		</div>
	</div>
	<?php
}

/**
 * Main admin page
 */
function ps_admin_page() {
	if ( isset( $_GET['imported'] ) && isset( $_GET['product_id'] ) ) {
		$product_id = intval( $_GET['product_id'] );
		$edit_link = get_edit_post_link( $product_id );
		echo '<div class="notice notice-success is-dismissible"><p>Product imported successfully! <a href="' . esc_url( $edit_link ) . '">Edit Product</a></p></div>';
	}
	
	if ( isset( $_GET['bulk_imported'] ) ) {
		$imported = intval( $_GET['bulk_imported'] );
		$failed = isset( $_GET['bulk_failed'] ) ? intval( $_GET['bulk_failed'] ) : 0;
		
		if ( $imported > 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>Bulk import completed! ' . $imported . ' products imported successfully.';
			if ( $failed > 0 ) {
				echo ' ' . $failed . ' products failed.';
			}
			echo '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>Bulk import failed. No products were imported.</p></div>';
		}
	}
		
	?>
	<div class="wrap">
		<h1>Product Scraper</h1>
		
		<div class="card" style="max-width: 800px; margin-top: 20px;">
			<h2>Quick Import from URL</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="quick-import-form">
				<?php wp_nonce_field( 'ps_scrape_action', 'ps_nonce' ); ?>
				<input type="hidden" name="action" value="ps_scrape_and_import">
				
				<table class="form-table">
					<tr>
						<th scope="row"><label for="product-url">Product URL</label></th>
						<td>
							<input type="url" id="product-url" name="product_url" class="regular-text" style="width: 100%;" required>
							<p class="description">Enter the Emtek product URL to scrape and import</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="product-status">Product Status</label></th>
						<td>
							<select id="product-status" name="product_status">
								<option value="publish">Published</option>
								<option value="draft" selected>Draft</option>
								<option value="pending">Pending Review</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="regular-price">Regular Price ($)</label></th>
						<td>
							<input type="number" id="regular-price" name="regular_price" value="0" step="0.01" min="0">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="download-files">Download Files</label></th>
						<td>
							<input type="checkbox" id="download-files" name="download_files" value="1" checked>
							<label for="download-files">Download PDFs and images to media library</label>
						</td>
					</tr>
				</table>
				
				<p class="submit">
					<button type="submit" class="button button-primary" id="import-btn">Scrape & Import Product</button>
					<span id="import-status" style="margin-left: 10px;"></span>
				</p>
			</form>
		</div>
		
		<div class="card" style="max-width: 800px; margin-top: 20px;">
			<h2>Import from JSON Data</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ps_import_action', 'ps_import_nonce' ); ?>
				<input type="hidden" name="action" value="ps_import_product">
				<textarea name="json_data" rows="10" class="large-text code" style="font-family: monospace;" placeholder='{"success":true,"product":{...}}'></textarea>
				<p class="submit"><button type="submit" class="button button-secondary">Import from JSON</button></p>
			</form>
		</div>
	</div>
	
	<script>
	jQuery(document).ready(function($) {
		$('#quick-import-form').on('submit', function() {
			$('#import-btn').prop('disabled', true).text('Importing...');
			$('#import-status').html('<span style="color: orange;">Processing...</span>');
		});
	});
	</script>
	<?php
}

/**
 * Import product page
 */
function ps_import_page() {
	?>
	<div class="wrap">
		<h1>Import Single Product</h1>
		
		<div class="card" style="max-width: 800px;">
			<h2>Fetch Product from Emtek</h2>
			<form id="fetch-product-form">
				<?php wp_nonce_field( 'ps_fetch_product', 'fetch_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="fetch-url">Product URL</label></th>
						<td>
							<input type="url" id="fetch-url" name="url" class="regular-text" style="width: 100%;" required>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="button" id="fetch-product-btn" class="button">Fetch Product Data</button>
					<span id="fetch-status" style="margin-left: 10px;"></span>
				</p>
			</form>
			
			<div id="product-preview" style="display: none; margin-top: 20px; border-top: 1px solid #ccc; padding-top: 20px;">
				<h3>Product Preview</h3>
				<div id="preview-content"></div>
				<form id="import-preview-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ps_import_action', 'ps_import_nonce' ); ?>
					<input type="hidden" name="action" value="ps_import_product">
					<input type="hidden" name="json_data" id="import-json-data">
					<p><button type="submit" class="button button-primary">Import This Product</button></p>
				</form>
			</div>
		</div>
	</div>
	
	<script>
	jQuery(document).ready(function($) {
		$('#fetch-product-btn').on('click', function() {
			var url = $('#fetch-url').val();
			var nonce = $('#fetch_nonce').val();
			
			if (!url) {
				alert('Please enter a product URL');
				return;
			}
			
			$('#fetch-product-btn').prop('disabled', true).text('Fetching...');
			$('#fetch-status').html('<span style="color: orange;">Fetching...</span>');
			
			$.ajax({
				url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
				type: 'POST',
				data: {
					action: 'ps_get_product_data',
					url: url,
					nonce: nonce
				},
				success: function(response) {
					if (response.success) {
						$('#import-json-data').val(JSON.stringify(response.data));
						
						var product = response.data.product;
						var html = '<div style="background: #f9f9f9; padding: 15px; border-radius: 5px;">';
						html += '<h4>' + (product.title || 'N/A') + '</h4>';
						html += '<p><strong>Categories:</strong> ' + (product.categories ? product.categories.join(' > ') : 'N/A') + '</p>';
						html += '<p><strong>Finishes:</strong> ' + (product.finishes ? product.finishes.length : '0') + ' options</p>';
						html += '<p><strong>Knobs:</strong> ' + (product.knobs ? product.knobs.length : '0') + ' options</p>';
						html += '<p><strong>Variations to create:</strong> ' + ((product.finishes?.length || 0) * (product.knobs?.length || 0)) + '</p>';
						html += '<p><strong>Functions:</strong> ' + (product.functions ? product.functions.length : '0') + '</p>';
						html += '<p><strong>Installation Instructions:</strong> ' + (product.installation_instructions ? product.installation_instructions.length : '0') + ' files</p>';
						html += '<p><strong>Specifications:</strong> ' + (product.specifications ? product.specifications.length : '0') + ' files</p>';
						html += '</div>';
						
						$('#preview-content').html(html);
						$('#product-preview').show();
						$('#fetch-status').html('<span style="color: green;">Success!</span>');
					} else {
						$('#fetch-status').html('<span style="color: red;">Error: ' + (response.data?.message || 'Unknown error') + '</span>');
					}
				},
				error: function(xhr, status, error) {
					$('#fetch-status').html('<span style="color: red;">Error: ' + error + '</span>');
				},
				complete: function() {
					$('#fetch-product-btn').prop('disabled', false).text('Fetch Product Data');
				}
			});
		});
	});
	</script>
	<?php
}

/**
 * Bulk import page
 */
function ps_bulk_import_page() {
	?>
	<div class="wrap">
		<h1>Bulk Import Products</h1>
		
		<div class="card" style="max-width: 800px;">
			<p><strong>Note:</strong> Bulk import may take a few minutes. Maximum 20 products per batch recommended.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ps_bulk_action', 'ps_bulk_nonce' ); ?>
				<input type="hidden" name="action" value="ps_bulk_import">
				
				<table class="form-table">
					<tr>
						<th scope="row"><label for="category-url">Category URL</label></th>
						<td><input type="url" id="category-url" name="category_url" class="regular-text" value="https://www.emtek.com/all-products/" style="width: 100%;" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="product-count">Number of Products</label></th>
						<td>
							<input type="number" id="product-count" name="product_count" value="10" min="1" max="50">
							<p class="description">Maximum 50 products per batch</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bulk-status">Product Status</label></th>
						<td>
							<select id="bulk-status" name="product_status">
								<option value="draft" selected>Draft</option>
								<option value="publish">Published</option>
								<option value="pending">Pending Review</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bulk-price">Regular Price ($)</label></th>
						<td><input type="number" id="bulk-price" name="regular_price" value="0" step="0.01" min="0"></td>
					</tr>
					<tr>
						<th scope="row"><label for="update-existing">Update Existing Products</label></th>
						<td>
							<input type="checkbox" id="update-existing" name="update_existing" value="1">
							<label for="update-existing">Update product data if product already exists</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bulk-download">Download Files</label></th>
						<td>
							<input type="checkbox" id="bulk-download" name="download_files" value="1" checked>
							<label for="bulk-download">Download PDFs and images to media library</label>
						</td>
					</tr>
				</table>
				
				<p class="submit"><button type="submit" class="button button-primary">Start Bulk Import</button></p>
			</form>
		</div>
	</div>
	<?php
}

/**
 * AJAX handler to get product data
 */
function ps_ajax_get_product_data() {
	check_ajax_referer( 'ps_fetch_product', 'nonce' );
	
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}
	
	$url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';
	
	if ( empty( $url ) ) {
		wp_send_json_error( array( 'message' => 'URL is required' ) );
	}
	
	$api_url = home_url( '/wp-json/custom/v1/product/?url=' . urlencode( $url ) );
	$response = wp_remote_get( $api_url, array( 'timeout' => 60 ) );
	
	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array( 'message' => $response->get_error_message() ) );
	}
	
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	
	if ( isset( $data['success'] ) && $data['success'] ) {
		wp_send_json_success( $data );
	} else {
		wp_send_json_error( array( 'message' => isset( $data['message'] ) ? $data['message'] : 'Failed to fetch product' ) );
	}
}

/**
 * AJAX handler for single product import
 */
function ps_ajax_import_single_product() {
	check_ajax_referer( 'ps_import_action', 'nonce' );
	
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized' ) );
	}
	
	$json_data = isset( $_POST['json_data'] ) ? stripslashes( $_POST['json_data'] ) : '';
	$product_status = isset( $_POST['product_status'] ) ? sanitize_text_field( $_POST['product_status'] ) : 'draft';
	$regular_price = isset( $_POST['regular_price'] ) ? floatval( $_POST['regular_price'] ) : 0;
	$download_files = isset( $_POST['download_files'] ) ? true : false;
	
	if ( empty( $json_data ) ) {
		wp_send_json_error( array( 'message' => 'JSON data is required' ) );
	}
	
	$data = json_decode( $json_data, true );
	
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		wp_send_json_error( array( 'message' => 'Invalid JSON data: ' . json_last_error_msg() ) );
	}
	
	if ( isset( $data['success'] ) && isset( $data['product'] ) ) {
		$product_data = $data['product'];
	} elseif ( isset( $data['product'] ) ) {
		$product_data = $data['product'];
	} else {
		$product_data = $data;
	}
	
	$result = ps_import_product_from_data( $product_data, $product_status, $download_files, $regular_price );
	
	if ( $result['success'] ) {
		wp_send_json_success( array(
			'product_id' => $result['product_id'],
			'message' => $result['message']
		) );
	} else {
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}
}

/**
 * Handle scrape and import from URL
 */
function ps_handle_scrape_and_import() {
	check_admin_referer( 'ps_scrape_action', 'ps_nonce' );
	
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	
	$product_url = isset( $_POST['product_url'] ) ? esc_url_raw( $_POST['product_url'] ) : '';
	$product_status = isset( $_POST['product_status'] ) ? sanitize_text_field( $_POST['product_status'] ) : 'draft';
	$regular_price = isset( $_POST['regular_price'] ) ? floatval( $_POST['regular_price'] ) : 0;
	$download_files = isset( $_POST['download_files'] ) ? true : false;
	
	if ( empty( $product_url ) ) {
		wp_die( 'Product URL is required' );
	}
	
	$api_url = home_url( '/wp-json/custom/v1/product/?url=' . urlencode( $product_url ) );
	$response = wp_remote_get( $api_url, array( 'timeout' => 60 ) );
	
	if ( is_wp_error( $response ) ) {
		wp_die( 'Error fetching product: ' . $response->get_error_message() );
	}
	
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	
	if ( ! isset( $data['success'] ) || ! $data['success'] ) {
		wp_die( 'Failed to fetch product data: ' . ( isset( $data['message'] ) ? $data['message'] : 'Unknown error' ) );
	}
	
	$result = ps_import_product_from_data( $data['product'], $product_status, $download_files, $regular_price );
	
	if ( $result['success'] ) {
		wp_redirect( add_query_arg( array(
			'page' => 'product-scraper',
			'imported' => '1',
			'product_id' => $result['product_id']
		), admin_url( 'admin.php' ) ) );
		exit;
	} else {
		wp_die( 'Import failed: ' . $result['message'] );
	}
}

/**
 * Handle bulk import from category URL
 */
function ps_handle_bulk_import() {
	check_admin_referer( 'ps_bulk_action', 'ps_bulk_nonce' );
	
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	
	$category_url = isset( $_POST['category_url'] ) ? esc_url_raw( $_POST['category_url'] ) : '';
	$product_count = isset( $_POST['product_count'] ) ? min( intval( $_POST['product_count'] ), 50 ) : 20;
	$product_status = isset( $_POST['product_status'] ) ? sanitize_text_field( $_POST['product_status'] ) : 'draft';
	$regular_price = isset( $_POST['regular_price'] ) ? floatval( $_POST['regular_price'] ) : 0;
	$download_files = isset( $_POST['download_files'] ) ? true : false;
	$update_existing = isset( $_POST['update_existing'] ) ? true : false;
	
	if ( empty( $category_url ) ) {
		wp_die( 'Category URL is required' );
	}
	
	$upload_dir = wp_upload_dir();
	$log_dir = $upload_dir['basedir'] . '/product-scraper-logs/';
	if ( ! file_exists( $log_dir ) ) {
		wp_mkdir_p( $log_dir );
	}
	$log_file = $log_dir . 'import-log-' . date('Y-m-d-H-i-s') . '.txt';
	$log_handle = fopen( $log_file, 'w' );
	fwrite( $log_handle, "=== Product Import Log - " . date('Y-m-d H:i:s') . " ===\n");
	fwrite( $log_handle, "Source URL: $category_url\n");
	fwrite( $log_handle, "Max Products: $product_count\n");
	fwrite( $log_handle, "Update Existing: " . ($update_existing ? 'Yes' : 'No') . "\n");
	fwrite( $log_handle, "===================================\n\n");
	
	set_time_limit(0);
	ini_set('memory_limit', '1024M');
	
	$api_url = home_url( '/wp-json/custom/v1/products/?url=' . urlencode( $category_url ) . '&n=' . $product_count );
	$response = wp_remote_get( $api_url, array( 'timeout' => 300 ) );
	
	if ( is_wp_error( $response ) ) {
		fwrite( $log_handle, "ERROR: Failed to fetch products - " . $response->get_error_message() . "\n");
		fclose( $log_handle );
		wp_die( 'Error fetching products: ' . $response->get_error_message() );
	}
	
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	
	if ( ! isset( $data['success'] ) || ! $data['success'] ) {
		fwrite( $log_handle, "ERROR: API returned error\n");
		fclose( $log_handle );
		wp_die( 'Failed to fetch products' );
	}
	
	$products = isset( $data['products'] ) ? $data['products'] : array();
	$total_products = count( $products );
	
	fwrite( $log_handle, "Found $total_products products to process\n\n");
	
	$imported = 0;
	$updated = 0;
	$failed = 0;
	$skipped = 0;
	
	foreach ( $products as $index => $product_data ) {
		$product_title = isset( $product_data['title'] ) ? $product_data['title'] : 'Unknown';
		fwrite( $log_handle, "\n[" . ($index + 1) . "/$total_products] Processing: $product_title\n");
		
		$existing_product_id = ps_find_product_by_title( $product_data['title'] );
		
		if ( $existing_product_id && ! $update_existing ) {
			fwrite( $log_handle, "  → SKIPPED: Product already exists (ID: $existing_product_id)\n");
			$skipped++;
			continue;
		}
		
		if ( $existing_product_id && $update_existing ) {
			fwrite( $log_handle, "  → Updating existing product (ID: $existing_product_id)\n");
			$result = ps_update_existing_product( $existing_product_id, $product_data, $download_files, $regular_price );
			if ( $result['success'] ) {
				$updated++;
				fwrite( $log_handle, "  ✓ UPDATED successfully\n");
			} else {
				$failed++;
				fwrite( $log_handle, "  ✗ UPDATE FAILED: " . $result['message'] . "\n");
			}
		} else {
			fwrite( $log_handle, "  → Importing new product\n");
			$result = ps_import_product_from_data( $product_data, $product_status, $download_files, $regular_price );
			if ( $result['success'] ) {
				$imported++;
				fwrite( $log_handle, "  ✓ IMPORTED successfully (ID: {$result['product_id']})\n");
			} else {
				$failed++;
				fwrite( $log_handle, "  ✗ IMPORT FAILED: " . $result['message'] . "\n");
			}
		}
		
		fflush( $log_handle );
		usleep( 500000 );
	}
	
	fwrite( $log_handle, "\n\n=== SUMMARY ===\n");
	fwrite( $log_handle, "Total: $total_products | Imported: $imported | Updated: $updated | Skipped: $skipped | Failed: $failed\n");
	fwrite( $log_handle, "Completed at: " . date('Y-m-d H:i:s') . "\n");
	fclose( $log_handle );
	
	wp_redirect( add_query_arg( array(
		'page' => 'product-scraper',
		'bulk_imported' => $imported,
		'bulk_failed' => $failed,
	), admin_url( 'admin.php' ) ) );
	exit;
}

/**
 * Handle import from JSON
 */
function ps_handle_import_product() {
	check_admin_referer( 'ps_import_action', 'ps_import_nonce' );
	
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}
	
	$json_data = isset( $_POST['json_data'] ) ? stripslashes( $_POST['json_data'] ) : '';
	$regular_price = isset( $_POST['regular_price'] ) ? floatval( $_POST['regular_price'] ) : 0;
	
	if ( empty( $json_data ) ) {
		wp_die( 'JSON data is required' );
	}
	
	$data = json_decode( $json_data, true );
	
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		wp_die( 'Invalid JSON data: ' . json_last_error_msg() );
	}
	
	if ( isset( $data['success'] ) && isset( $data['product'] ) ) {
		$product_data = $data['product'];
	} elseif ( isset( $data['product'] ) ) {
		$product_data = $data['product'];
	} else {
		$product_data = $data;
	}
	
	$result = ps_import_product_from_data( $product_data, 'publish', true, $regular_price );
	
	if ( $result['success'] ) {
		wp_redirect( add_query_arg( array(
			'page' => 'product-scraper',
			'imported' => '1',
			'product_id' => $result['product_id']
		), admin_url( 'admin.php' ) ) );
		exit;
	} else {
		wp_die( 'Import failed: ' . $result['message'] );
	}
}

// ==================== CORE IMPORT FUNCTIONS ====================

/**
 * Create variable product with attributes & variations
 */
function ps_create_variable_product( $product_data, $config_groups, $status, $download_files, $regular_price ) {
	$product = new WC_Product_Variable();
	$product->set_name( $product_data['title'] );
	$product->set_description( $product_data['description'] );
	$product->set_short_description( wp_trim_words( wp_strip_all_tags( $product_data['description'] ), 50 ) );
	$product->set_status( $status );
	$product->set_catalog_visibility( 'visible' );
	
	$parent_sku = ps_generate_unique_sku( $product_data['title'] );
	$product->set_sku( $parent_sku );
	
	ps_set_categories( $product, $product_data );
	
	if ( $download_files && ! empty( $product_data['image'] ) ) {
		$image_id = ps_download_image_with_timeout( $product_data['image'] );
		if ( $image_id ) $product->set_image_id( $image_id );
	}
	
	// Create attributes from configurator groups
	$attributes = array();
	foreach ( $config_groups as $group ) {
		$attr = ps_ensure_attribute( $group['display_name'], $group['options'] );
		if ( $attr ) $attributes[] = $attr;
	}
	
	if ( ! empty( $attributes ) ) {
		$product->set_attributes( $attributes );
	}
	
	$product_id = $product->save();
	
	if ( ! $product_id ) {
		return array( 'success' => false, 'message' => 'Failed to create product' );
	}
	
	// Create variations
	$variation_count = ps_create_variations( $product_id, $config_groups, $parent_sku, $regular_price, $download_files, $status );
	
	// Save ACF fields
	$acf_data = $product_data;
	if ( ! empty( $product_data['acf_functions'] ) ) {
		$acf_data['functions'] = $product_data['acf_functions'];
	}
	ps_save_product_acf_fields( $product_id, $acf_data, $download_files );
	
	$group_names = array_map( function( $g ) { return $g['display_name']; }, $config_groups );
	$total_options = 1;
	foreach ( $config_groups as $g ) { $total_options *= count( $g['options'] ); }
	
	return array( 
		'success' => true, 
		'message' => sprintf( 
			'Product imported with %d attributes (%s) and %d variations', 
			count( $config_groups ), 
			implode( ', ', $group_names ), 
			$variation_count 
		),
		'product_id' => $product_id,
	);
}

/**
 * Create all variation combinations
 */
function ps_create_variations( $product_id, $config_groups, $parent_sku, $regular_price, $download_files, $status ) {
	// Get options arrays for cartesian product
	$option_arrays = array();
	foreach ( $config_groups as $group ) {
		$option_arrays[] = $group['options'];
	}
	
	$combinations = ps_cartesian_product( $option_arrays );
	$count = 0;
	
	foreach ( $combinations as $combo ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		
		// Build SKU: PARENT-CODE1-CODE2-...
		$sku_parts = array( $parent_sku );
		$attributes = array();
		$variation_image = '';
		
		foreach ( $combo as $index => $option ) {
			$group = $config_groups[ $index ];
			$attr_slug = sanitize_title( $group['display_name'] );
			$code = $option['code'];
			$name = $option['name'];
			
			$sku_parts[] = $code;
			$attributes[ 'attribute_' . $attr_slug ] = $name;
			
			// Use first image as variation image
			if ( empty( $variation_image ) && ! empty( $option['image'] ) ) {
				$variation_image = $option['image'];
			}
		}
		
		// Generate unique variation SKU
		$var_sku = implode( '-', $sku_parts );
		$var_sku = ps_generate_unique_variation_sku( $var_sku );
		
		$variation->set_sku( $var_sku );
		$variation->set_regular_price( $regular_price );
		$variation->set_status( $status );
		$variation->set_manage_stock( false );
		$variation->set_stock_status( 'instock' );
		$variation->set_attributes( $attributes );
		
		if ( $download_files && ! empty( $variation_image ) ) {
			$image_id = ps_download_image_with_timeout( $variation_image );
			if ( $image_id ) $variation->set_image_id( $image_id );
		}
		
		$variation->save();
		$count++;
	}
	
	return $count;
}

/**
 * Create variable product from ANY configurator groups
 */
function ps_create_variable_product_dynamic( $product_data, $variation_groups, $status, $download_files, $regular_price ) {
	$product = new WC_Product_Variable();
	$product->set_name( $product_data['title'] );
	$product->set_description( $product_data['description'] );
	$product->set_short_description( wp_trim_words( wp_strip_all_tags( $product_data['description'] ), 50 ) );
	$product->set_status( $status );
	$product->set_catalog_visibility( 'visible' );
	
	// Generate unique SKU
	$base_sku = sanitize_title( $product_data['title'] );
	$unique_sku = $base_sku;
	$counter = 1;
	while ( wc_get_product_id_by_sku( $unique_sku ) ) {
		$unique_sku = $base_sku . '-' . $counter;
		$counter++;
	}
	$product->set_sku( $unique_sku );
	
	ps_set_categories( $product, $product_data );
	
	// Set main product image
	if ( $download_files && ! empty( $product_data['image'] ) ) {
		$image_id = ps_download_image_with_timeout( $product_data['image'] );
		if ( $image_id ) $product->set_image_id( $image_id );
	}
	
	// Create attributes dynamically from all configurator groups
	$attributes = array();
	
	foreach ( $variation_groups as $group ) {
		$attr_name = $group['display_name'];
		$attr = ps_ensure_attribute( $attr_name, $group['options'] );
		if ( $attr ) {
			$attributes[] = $attr;
		}
	}
	
	if ( ! empty( $attributes ) ) {
		$product->set_attributes( $attributes );
	}
	
	$product_id = $product->save();
	
	if ( ! $product_id ) {
		return array( 'success' => false, 'message' => 'Failed to create product' );
	}
	
	// Create all variation combinations
	$variation_count = ps_create_dynamic_variations( $product_id, $variation_groups, $regular_price, $download_files, $status, $unique_sku );
	
	// Save ACF fields
	$acf_data = $product_data;
	if ( ! empty( $product_data['acf_functions'] ) ) {
		$acf_data['functions'] = $product_data['acf_functions'];
	}
	ps_save_product_acf_fields( $product_id, $acf_data, $download_files );
	
	$group_names = array_map( function( $g ) { return $g['display_name']; }, $variation_groups );
	
	return array( 
		'success' => true, 
		'message' => "Product imported with " . count( $variation_groups ) . " attributes (" . implode( ', ', $group_names ) . ") and $variation_count variations",
		'product_id' => $product_id,
	);
}

/**
 * Create all variation combinations dynamically
 */
function ps_create_dynamic_variations( $product_id, $variation_groups, $regular_price, $download_files, $status, $parent_sku ) {
	// Build arrays of options for each group
	$option_arrays = array();
	
	foreach ( $variation_groups as $group ) {
		$option_arrays[] = $group['options'];
	}
	
	// Generate all combinations using cartesian product
	$combinations = ps_cartesian_product( $option_arrays );
	
	$count = 0;
	foreach ( $combinations as $combination ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		
		// Build SKU from option codes
		$sku_parts = array( $parent_sku );
		$attributes = array();
		$variation_image = '';
		
		foreach ( $combination as $index => $option ) {
			$group = $variation_groups[ $index ];
			$attr_name = $group['display_name'];
			$attr_slug = sanitize_title( $attr_name );
			$code = ! empty( $option['code'] ) ? $option['code'] : sanitize_title( $option['name'] );
			$name = $option['name'];
			
			$sku_parts[] = $code;
			$attributes[ 'attribute_' . $attr_slug ] = $name;
			
			// Use first available image as variation image
			if ( empty( $variation_image ) && ! empty( $option['image'] ) ) {
				$variation_image = $option['image'];
			}
		}
		
		$variation->set_sku( implode( '-', $sku_parts ) );
		$variation->set_regular_price( $regular_price );
		$variation->set_status( $status );
		$variation->set_manage_stock( false );
		$variation->set_stock_status( 'instock' );
		$variation->set_attributes( $attributes );
		
		// Set variation image
		if ( $download_files && ! empty( $variation_image ) ) {
			$image_id = ps_download_image_with_timeout( $variation_image );
			if ( $image_id ) $variation->set_image_id( $image_id );
		}
		
		$variation->save();
		$count++;
	}
	
	return $count;
}


/**
 * Cartesian product for combinations
 */
function ps_cartesian_product( $arrays ) {
	$result = array( array() );
	
	foreach ( $arrays as $array ) {
		$new_result = array();
		foreach ( $result as $partial ) {
			foreach ( $array as $item ) {
				$new_result[] = array_merge( $partial, array( $item ) );
			}
		}
		$result = $new_result;
	}
	
	return $result;
}
/**
 * Create simple product
 */
function ps_create_simple_product( $product_data, $status, $download_files, $regular_price ) {
	$product = new WC_Product_Simple();
	$product->set_name( $product_data['title'] );
	$product->set_description( $product_data['description'] );
	$product->set_short_description( wp_trim_words( wp_strip_all_tags( $product_data['description'] ), 50 ) );
	$product->set_status( $status );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( $regular_price );
	
	$sku = ps_generate_unique_sku( $product_data['title'] );
	$product->set_sku( $sku );
	
	ps_set_categories( $product, $product_data );
	
	if ( $download_files && ! empty( $product_data['image'] ) ) {
		$image_id = ps_download_image_with_timeout( $product_data['image'] );
		if ( $image_id ) $product->set_image_id( $image_id );
	}
	
	$product_id = $product->save();
	
	if ( ! $product_id ) {
		return array( 'success' => false, 'message' => 'Failed to create product' );
	}
	
	ps_save_product_acf_fields( $product_id, $product_data, $download_files );
	
	return array( 'success' => true, 'message' => 'Simple product imported', 'product_id' => $product_id );
}
/**
 * Create variable product with attributes and variations
 */
function ps_create_variable_product_with_attributes( $product_data, $status, $download_files, $regular_price, $has_knobs ) {
	$product = new WC_Product_Variable();
	$product->set_name( $product_data['title'] );
	$product->set_description( $product_data['description'] );
	$product->set_short_description( wp_trim_words( wp_strip_all_tags( $product_data['description'] ), 50 ) );
	$product->set_status( $status );
	$product->set_catalog_visibility( 'visible' );
	
	$base_sku = sanitize_title( $product_data['title'] );
	$unique_sku = $base_sku;
	$counter = 1;
	while ( wc_get_product_id_by_sku( $unique_sku ) ) {
		$unique_sku = $base_sku . '-' . $counter;
		$counter++;
	}
	$product->set_sku( $unique_sku );
	
	ps_set_categories( $product, $product_data );
	
	if ( $download_files && ! empty( $product_data['image'] ) ) {
		$image_id = ps_download_image_with_timeout( $product_data['image'] );
		if ( $image_id ) $product->set_image_id( $image_id );
	}
	
	// Create attributes
	$attributes = array();
	
	$finish_attribute = ps_ensure_attribute( 'Finish', $product_data['finishes'] );
	if ( $finish_attribute ) {
		$attributes[] = $finish_attribute;
	}
	
	if ( $has_knobs ) {
		$knob_attribute = ps_ensure_attribute( 'Knob/Lever', $product_data['knobs'] );
		if ( $knob_attribute ) {
			$attributes[] = $knob_attribute;
		}
	}
	
	if ( ! empty( $attributes ) ) {
		$product->set_attributes( $attributes );
	}
	
	$product_id = $product->save();
	
	if ( ! $product_id ) {
		return array( 'success' => false, 'message' => 'Failed to create product' );
	}
	
	// Create variations
	$variation_count = 0;
	
	if ( $has_knobs ) {
		$variation_count = ps_create_all_variations( $product_id, $product_data['finishes'], $product_data['knobs'], $regular_price, $download_files, $status, $unique_sku );
	} else {
		$variation_count = ps_create_finish_variations( $product_id, $product_data['finishes'], $regular_price, $download_files, $status, $unique_sku );
	}
	
	ps_save_product_acf_fields( $product_id, $product_data, $download_files );
	
	return array( 
		'success' => true, 
		'message' => "Product imported with $variation_count variations",
		'product_id' => $product_id,
	);
}
/**
 * Generate unique SKU for parent product
 */
function ps_generate_unique_sku( $title ) {
	$base_sku = sanitize_title( $title );
	$base_sku = preg_replace( '/[^a-z0-9-]/', '', $base_sku );
	$base_sku = trim( $base_sku, '-' );
	
	if ( empty( $base_sku ) ) {
		$base_sku = 'product';
	}
	
	$sku = $base_sku;
	$counter = 1;
	while ( wc_get_product_id_by_sku( $sku ) ) {
		$sku = $base_sku . '-' . $counter;
		$counter++;
	}
	
	return $sku;
}

/**
 * Generate unique SKU for variation
 */
function ps_generate_unique_variation_sku( $sku ) {
	$original = $sku;
	$counter = 1;
	
	while ( wc_get_product_id_by_sku( $sku ) ) {
		$sku = $original . '-' . $counter;
		$counter++;
	}
	
	return $sku;
}

/**
 * Ensure attribute exists - create if needed
 */
/**
 * Ensure attribute exists - create if needed
 */
function ps_ensure_attribute( $attr_name, $options ) {
	if ( empty( $options ) || ! is_array( $options ) ) {
		return false;
	}
	
	$attr_slug = sanitize_title( $attr_name );
	$taxonomy_name = wc_attribute_taxonomy_name( $attr_slug );
	
	// Create attribute if doesn't exist
	$attribute_id = wc_attribute_taxonomy_id_by_name( $attr_name );
	if ( ! $attribute_id ) {
		$attribute_id = wc_create_attribute( array(
			'name'         => $attr_name,
			'slug'         => $attr_slug,
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		) );
	}
	
	// Register taxonomy
	if ( ! taxonomy_exists( $taxonomy_name ) ) {
		register_taxonomy( $taxonomy_name, 'product', array() );
	}
	
	// Add terms
	$term_names = array();
	foreach ( $options as $option ) {
		$name = $option['name'];
		if ( ! empty( $name ) ) {
			if ( ! term_exists( $name, $taxonomy_name ) ) {
				wp_insert_term( $name, $taxonomy_name );
			}
			$term_names[] = $name;
		}
	}
	
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( $taxonomy_name );
	$attribute->set_options( $term_names );
	$attribute->set_position( 0 );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	
	return $attribute;
}
/**
 * Create variations for each finish
 */
function ps_create_finish_variations( $product_id, $finishes, $regular_price, $download_files, $status, $parent_sku ) {
	$count = 0;
	
	foreach ( $finishes as $finish ) {
		$finish_name = isset( $finish['name'] ) ? $finish['name'] : ( isset( $finish['option'] ) ? $finish['option'] : '' );
		$finish_code = isset( $finish['code'] ) ? $finish['code'] : sanitize_title( $finish_name );
		
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_sku( $parent_sku . '-' . $finish_code );
		$variation->set_regular_price( $regular_price );
		$variation->set_status( $status );
		$variation->set_manage_stock( false );
		$variation->set_stock_status( 'instock' );
		$variation->set_attributes( array( 'attribute_finish' => $finish_name ) );
		
		if ( $download_files && ! empty( $finish['image'] ) ) {
			$image_id = ps_download_image_with_timeout( $finish['image'] );
			if ( $image_id ) $variation->set_image_id( $image_id );
		}
		
		$variation->save();
		$count++;
	}
	
	return $count;
}

/**
 * Create all variations (Finish × Knob)
 */
function ps_create_all_variations( $product_id, $finishes, $knobs, $regular_price, $download_files, $status, $parent_sku ) {
	$count = 0;
	
	foreach ( $finishes as $finish ) {
		$finish_name = isset( $finish['name'] ) ? $finish['name'] : ( isset( $finish['option'] ) ? $finish['option'] : '' );
		$finish_code = isset( $finish['code'] ) ? $finish['code'] : sanitize_title( $finish_name );
		$finish_image = isset( $finish['image'] ) ? $finish['image'] : '';
		
		foreach ( $knobs as $knob ) {
			$knob_name = isset( $knob['name'] ) ? $knob['name'] : ( isset( $knob['option'] ) ? $knob['option'] : '' );
			$knob_code = isset( $knob['code'] ) ? $knob['code'] : sanitize_title( $knob_name );
			
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$variation->set_sku( $parent_sku . '-' . $finish_code . '-' . $knob_code );
			$variation->set_regular_price( $regular_price );
			$variation->set_status( $status );
			$variation->set_manage_stock( false );
			$variation->set_stock_status( 'instock' );
			$variation->set_attributes( array(
				'attribute_finish' => $finish_name,
				'attribute_knob-lever' => $knob_name,
			) );
			
			if ( $download_files && ! empty( $finish_image ) ) {
				$image_id = ps_download_image_with_timeout( $finish_image );
				if ( $image_id ) $variation->set_image_id( $image_id );
			} elseif ( $download_files && ! empty( $knob['image'] ) ) {
				$image_id = ps_download_image_with_timeout( $knob['image'] );
				if ( $image_id ) $variation->set_image_id( $image_id );
			}
			
			$variation->save();
			$count++;
		}
	}
	
	return $count;
}

/**
 * Set product categories
 */
function ps_set_categories( $product, $product_data ) {
	if ( ! empty( $product_data['categories'] ) && is_array( $product_data['categories'] ) ) {
		$category_ids = array();
		$parent_id = 0;
		
		foreach ( $product_data['categories'] as $category_name ) {
			$term = term_exists( $category_name, 'product_cat' );
			if ( ! $term ) {
				$term = wp_insert_term( $category_name, 'product_cat', array( 'parent' => $parent_id ) );
				if ( ! is_wp_error( $term ) ) {
					$category_ids[] = (int) $term['term_id'];
					$parent_id = (int) $term['term_id'];
				}
			} else {
				$category_ids[] = (int) $term['term_id'];
				$parent_id = (int) $term['term_id'];
			}
		}
		
		if ( ! empty( $category_ids ) ) {
			$product->set_category_ids( $category_ids );
		}
	}
}

/**
 * Update existing product with new configurator data
 */
function ps_update_existing_product( $product_id, $product_data, $download_files = true, $regular_price = 0 ) {
	if ( empty( $product_data['title'] ) ) {
		return array( 'success' => false, 'message' => 'Product title is required' );
	}
	
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return array( 'success' => false, 'message' => 'Product not found' );
	}
	
	$product->set_name( $product_data['title'] );
	$product->set_description( $product_data['description'] );
	$product->set_short_description( wp_trim_words( wp_strip_all_tags( $product_data['description'] ), 50 ) );
	
	ps_set_categories( $product, $product_data );
	
	if ( $download_files && ! empty( $product_data['image'] ) ) {
		$image_id = ps_download_image_with_timeout( $product_data['image'] );
		if ( $image_id && ! $product->get_image_id() ) {
			$product->set_image_id( $image_id );
		}
	}
	
	$config_groups = isset( $product_data['configurator_groups'] ) ? $product_data['configurator_groups'] : array();
	
	if ( ! empty( $config_groups ) ) {
		// Delete old variations
		foreach ( $product->get_children() as $var_id ) {
			wp_delete_post( $var_id, true );
		}
		
		// Recreate attributes
		$attributes = array();
		foreach ( $config_groups as $group ) {
			$attr = ps_ensure_attribute( $group['display_name'], $group['options'] );
			if ( $attr ) $attributes[] = $attr;
		}
		
		if ( ! empty( $attributes ) ) {
			$product->set_attributes( $attributes );
		}
		$product->save();
		
		// Recreate variations
		ps_create_variations( $product_id, $config_groups, $product->get_sku(), $regular_price, $download_files, $product->get_status() );
	}
	
	$acf_data = $product_data;
	if ( ! empty( $product_data['acf_functions'] ) ) {
		$acf_data['functions'] = $product_data['acf_functions'];
	}
	ps_save_product_acf_fields( $product_id, $acf_data, $download_files );
	
	return array( 'success' => true, 'message' => 'Product updated', 'product_id' => $product_id );
}

/**
 * Save ACF fields for product
 */
function ps_save_product_acf_fields( $product_id, $product_data, $download_files = true ) {
	if ( ! function_exists( 'update_field' ) ) {
		return array( 'success' => false, 'message' => 'ACF not active' );
	}
	
	$saved_fields = array();
	
	if ( ! empty( $product_data['technical_specs'] ) ) {
		$saved_fields['technical_specs'] = update_field( 'technical_specs', wp_kses_post( $product_data['technical_specs'] ), $product_id );
	}
	
	if ( ! empty( $product_data['installation_instructions'] ) && is_array( $product_data['installation_instructions'] ) ) {
		$rows = array();
		foreach ( $product_data['installation_instructions'] as $instruction ) {
			$file_id = null;
			if ( $download_files && ! empty( $instruction['file'] ) ) {
				$file_id = ps_download_pdf_with_timeout( $instruction['file'] );
			}
			$rows[] = array(
				'title' => sanitize_text_field( $instruction['title'] ),
				'file' => $file_id ? $file_id : $instruction['file']
			);
		}
		if ( ! empty( $rows ) ) {
			$saved_fields['installation_instructions'] = update_field( 'installation_instructions', $rows, $product_id );
		}
	}
	
	if ( ! empty( $product_data['specifications'] ) && is_array( $product_data['specifications'] ) ) {
		$rows = array();
		foreach ( $product_data['specifications'] as $spec ) {
			$file_id = null;
			if ( $download_files && ! empty( $spec['file'] ) ) {
				$file_id = ps_download_pdf_with_timeout( $spec['file'] );
			}
			$rows[] = array(
				'title' => sanitize_text_field( $spec['title'] ),
				'file' => $file_id ? $file_id : $spec['file']
			);
		}
		if ( ! empty( $rows ) ) {
			$saved_fields['specifications'] = update_field( 'specifications', $rows, $product_id );
		}
	}
	
	if ( ! empty( $product_data['functions'] ) && is_array( $product_data['functions'] ) ) {
		$rows = array();
		foreach ( $product_data['functions'] as $function ) {
			$image_id = null;
			if ( $download_files && ! empty( $function['image'] ) ) {
				$image_id = ps_download_image_with_timeout( $function['image'] );
			}
			$rows[] = array(
				'title' => sanitize_text_field( $function['title'] ),
				'description' => wp_kses_post( $function['description'] ),
				'image' => $image_id ? $image_id : $function['image']
			);
		}
		if ( ! empty( $rows ) ) {
			$saved_fields['functions'] = update_field( 'functions', $rows, $product_id );
		}
	}
	
	return array( 'success' => true, 'saved_fields' => $saved_fields );
}

/**
 * Download image with timeout
 */
function ps_download_image_with_timeout( $url, $timeout = 30 ) {
	if ( empty( $url ) ) return false;
	
	$attachment_id = ps_attachment_exists_by_url( $url );
	if ( $attachment_id ) return $attachment_id;
	
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	
	add_filter( 'http_request_timeout', function() use ( $timeout ) { return $timeout; } );
	$tmp = download_url( $url, $timeout );
	remove_filter( 'http_request_timeout', function() use ( $timeout ) { return $timeout; } );
	
	if ( is_wp_error( $tmp ) ) return false;
	
	$file_array = array(
			'name' => sanitize_file_name( basename( $url ) ),
			'tmp_name' => $tmp,
	);
	
	$attachment_id = media_handle_sideload( $file_array, 0 );
	
	if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return false;
	}
	
	return $attachment_id;
}

/**
 * Download PDF with timeout
 */
function ps_download_pdf_with_timeout( $url, $timeout = 20 ) {
	if ( empty( $url ) ) return false;
	
	$attachment_id = ps_attachment_exists_by_url( $url );
	if ( $attachment_id ) return $attachment_id;
	
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	
	add_filter( 'http_request_timeout', function() use ( $timeout ) { return $timeout; } );
	$tmp = download_url( $url, $timeout );
	remove_filter( 'http_request_timeout', function() use ( $timeout ) { return $timeout; } );
	
	if ( is_wp_error( $tmp ) ) return false;
	
	$file_array = array(
			'name' => sanitize_file_name( basename( $url ) ),
			'tmp_name' => $tmp,
	);
	
	$attachment_id = media_handle_sideload( $file_array, 0 );
	
	if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return false;
	}
	
	return $attachment_id;
}

/**
 * Check if attachment exists by URL
 */
function ps_attachment_exists_by_url( $url ) {
	global $wpdb;
	
	$attachment_id = $wpdb->get_var( $wpdb->prepare( "
		SELECT ID FROM {$wpdb->posts} 
		WHERE post_type = 'attachment' 
		AND guid = %s
	", $url ) );
	
	return $attachment_id ? intval( $attachment_id ) : false;
}

/**
 * Find product by title
 */
function ps_find_product_by_title( $title ) {
	$products = wc_get_products( array(
		'title' => $title,
		'limit' => 1,
		'return' => 'ids'
	) );
	
	return ! empty( $products ) ? $products[0] : false;
}

// ==================== WOOCOMMERCE CSV IMPORT HOOKS ====================

function ps_add_column_mapping( $columns ) {
	$columns['Technical specs'] = 'Technical Specs';
	$columns['Installation instructions'] = 'Installation Instructions';
	$columns['Specifications'] = 'Specifications';
	$columns['Functions'] = 'Functions';
	return $columns;
}

function ps_add_default_columns_mapping( $columns ) {
	$columns['Technical specs'] = 'technical_specs';
	$columns['Installation instructions'] = 'installation_instructions';
	$columns['Specifications'] = 'specifications';
	$columns['Functions'] = 'functions';
	return $columns;
}

function ps_save_acf_fields( $product, $data ) {
	$product_id = $product->get_id();
	if ( ! function_exists( 'update_field' ) ) return;
	
	if ( ! empty( $data['technical_specs'] ) ) {
		update_field( 'technical_specs', sanitize_textarea_field( $data['technical_specs'] ), $product_id );
	}
}

// ==================== ADMIN CONFIGURATOR TABS ====================

function ps_add_product_data_tab( $tabs ) {
	$tabs['emtek_configurator'] = array(
		'label'    => __( 'Emtek Configurator', 'product-scraper' ),
		'target'   => 'emtek_configurator_data',
		'class'    => array(),
		'priority' => 60,
	);
	return $tabs;
}

function ps_add_product_data_panel() {
	global $post;
	$finishes = get_post_meta( $post->ID, '_ps_finishes', true );
	$knobs = get_post_meta( $post->ID, '_ps_knobs', true );
	?>
	<div id="emtek_configurator_data" class="panel woocommerce_options_panel">
		<div class="options_group">
			<h3>Finish Options</h3>
			<div id="ps_finish_options">
				<?php if ( is_array( $finishes ) ) : ?>
					<?php foreach ( $finishes as $finish ) : ?>
						<div class="ps-finish-item" style="margin-bottom: 10px;">
							<input type="text" name="ps_finishes[]" value="<?php echo esc_attr( $finish['name'] ); ?>" placeholder="Finish Name" style="width: 25%; margin-right: 10px;">
							<input type="text" name="ps_finish_codes[]" value="<?php echo esc_attr( $finish['code'] ); ?>" placeholder="Code" style="width: 15%; margin-right: 10px;">
							<input type="url" name="ps_finish_images[]" value="<?php echo esc_url( $finish['image'] ); ?>" placeholder="Image URL" style="width: 40%; margin-right: 10px;">
							<button type="button" class="button remove-finish">Remove</button>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<button type="button" id="add-finish" class="button" style="margin-bottom: 20px;">+ Add Finish</button>
			
			<h3>Knob and Lever Options</h3>
			<div id="ps_knob_options">
				<?php if ( is_array( $knobs ) ) : ?>
					<?php foreach ( $knobs as $knob ) : ?>
						<div class="ps-knob-item" style="margin-bottom: 10px;">
							<input type="text" name="ps_knobs[]" value="<?php echo esc_attr( $knob['name'] ); ?>" placeholder="Knob Name" style="width: 30%; margin-right: 10px;">
							<input type="text" name="ps_knob_codes[]" value="<?php echo esc_attr( $knob['code'] ); ?>" placeholder="Code" style="width: 15%; margin-right: 10px;">
							<input type="url" name="ps_knob_images[]" value="<?php echo esc_url( $knob['image'] ); ?>" placeholder="Image URL" style="width: 40%; margin-right: 10px;">
							<button type="button" class="button remove-knob">Remove</button>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<button type="button" id="add-knob" class="button">+ Add Knob</button>
		</div>
	</div>
	
	<script>
	jQuery(document).ready(function($) {
		$('#add-finish').click(function() {
			$('#ps_finish_options').append(
				'<div class="ps-finish-item" style="margin-bottom: 10px;">' +
				'<input type="text" name="ps_finishes[]" placeholder="Finish Name" style="width: 25%; margin-right: 10px;">' +
				'<input type="text" name="ps_finish_codes[]" placeholder="Code" style="width: 15%; margin-right: 10px;">' +
				'<input type="url" name="ps_finish_images[]" placeholder="Image URL" style="width: 40%; margin-right: 10px;">' +
				'<button type="button" class="button remove-finish">Remove</button>' +
				'</div>'
			);
		});
		
		$('#add-knob').click(function() {
			$('#ps_knob_options').append(
				'<div class="ps-knob-item" style="margin-bottom: 10px;">' +
				'<input type="text" name="ps_knobs[]" placeholder="Knob Name" style="width: 30%; margin-right: 10px;">' +
				'<input type="text" name="ps_knob_codes[]" placeholder="Code" style="width: 15%; margin-right: 10px;">' +
				'<input type="url" name="ps_knob_images[]" placeholder="Image URL" style="width: 40%; margin-right: 10px;">' +
				'<button type="button" class="button remove-knob">Remove</button>' +
				'</div>'
			);
		});
		
		$(document).on('click', '.remove-finish', function() {
			$(this).closest('.ps-finish-item').remove();
		});
		
		$(document).on('click', '.remove-knob', function() {
			$(this).closest('.ps-knob-item').remove();
		});
	});
	</script>
	<?php
}

function ps_save_product_configurator_data( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	
	if ( isset( $_POST['ps_finishes'] ) ) {
		$finishes = array();
		$names = $_POST['ps_finishes'];
		$codes = $_POST['ps_finish_codes'];
		$images = $_POST['ps_finish_images'];
		
		for ( $i = 0; $i < count( $names ); $i++ ) {
			if ( ! empty( $names[$i] ) ) {
				$finishes[] = array(
					'name' => sanitize_text_field( $names[$i] ),
					'code' => sanitize_text_field( $codes[$i] ),
					'image' => esc_url_raw( $images[$i] ),
				);
			}
		}
		update_post_meta( $post_id, '_ps_finishes', $finishes );
	}
	
	if ( isset( $_POST['ps_knobs'] ) ) {
		$knobs = array();
		$names = $_POST['ps_knobs'];
		$codes = $_POST['ps_knob_codes'];
		$images = $_POST['ps_knob_images'];
		
		for ( $i = 0; $i < count( $names ); $i++ ) {
			if ( ! empty( $names[$i] ) ) {
				$knobs[] = array(
					'name' => sanitize_text_field( $names[$i] ),
					'code' => sanitize_text_field( $codes[$i] ),
					'image' => esc_url_raw( $images[$i] ),
				);
			}
		}
		update_post_meta( $post_id, '_ps_knobs', $knobs );
	}
}