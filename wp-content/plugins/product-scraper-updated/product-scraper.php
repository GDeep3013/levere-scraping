<?php
/**
 * Plugin Name: Product Scraper
 * Description: Scrape product data from Emtek website and import into WooCommerce with ACF fields.
 * Version: 5.1 - Bulk import added
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'PS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'PS_PLACEHOLDER_IMAGE', 'https://ektem-bucket.s3.us-east-1.amazonaws.com/wp-content/uploads/images/2026/06/placeholder.avif' );


// Increase limits
add_action( 'init', function() {
	if ( is_admin() && isset( $_POST['action'] ) && strpos( $_POST['action'], 'ps_' ) === 0 ) {
		set_time_limit( 600 );
		ini_set( 'memory_limit', '1024M' );
	}
});

// Initialize
add_action( 'init', 'ps_init' );
add_action( 'admin_menu', 'ps_add_admin_menu' );
add_action( 'admin_post_ps_scrape_and_import', 'ps_handle_scrape_and_import' );
add_action( 'wp_ajax_ps_bulk_import_urls', 'ps_ajax_bulk_import_urls' );
add_action( 'wp_ajax_nopriv_ps_ajax_bulk_import_urls', 'ps_ajax_bulk_import_urls' );
/**
 * Hide out of stock message when price is null or zero
 */
/**
 * Hide variations that have no price (they won't appear in dropdown)
 */
add_filter( 'woocommerce_variation_is_active', 'ps_hide_variation_without_price', 10, 2 );

function ps_hide_variation_without_price( $active, $variation ) {
    $price = $variation->get_price();
    if ( empty( $price ) || $price == 0 ) {
        return false;
    }
    return $active;
}

// AJAX for configurator image switching
add_action( 'wp_ajax_ps_get_configurator_image', 'ps_ajax_get_configurator_image' );
add_action( 'wp_ajax_nopriv_ps_get_configurator_image', 'ps_ajax_get_configurator_image' );

// WooCommerce hooks
// add_action( 'woocommerce_before_add_to_cart_button', 'ps_add_configurator_swatches' );
add_action( 'woocommerce_product_meta_start', 'ps_add_configurator_swatches' );
add_action( 'wp_enqueue_scripts', 'ps_enqueue_configurator_scripts' );

// Admin configurator tab
add_filter( 'woocommerce_product_data_tabs', 'ps_add_product_data_tab' );
add_action( 'woocommerce_product_data_panels', 'ps_add_product_data_panel' );
add_action( 'save_post_product', 'ps_save_product_configurator_data' );

// REST API routes
add_action( 'rest_api_init', 'ps_register_rest_routes' );

// Add custom fields to product
add_action( 'woocommerce_product_options_general_product_data', 'ps_add_custom_product_fields' );
add_action( 'woocommerce_process_product_meta', 'ps_save_custom_product_fields' );

// Add variation custom fields
add_action( 'woocommerce_product_after_variable_attributes', 'ps_variation_custom_fields', 10, 3 );
add_action( 'woocommerce_save_product_variation', 'ps_save_variation_custom_fields', 10, 2 );

// Display images in admin product list and edit page
add_action( 'admin_head', 'ps_admin_custom_styles' );
add_filter( 'manage_edit-product_columns', 'ps_add_product_thumbnail_column' );
add_action( 'manage_product_posts_custom_column', 'ps_display_product_thumbnail_column', 10, 2 );

/**
 * Initialize plugin
 */
function ps_init() {
	$upload_dir = wp_upload_dir();
	$log_dir = $upload_dir['basedir'] . '/product-scraper-logs';
	if ( ! file_exists( $log_dir ) ) {
		wp_mkdir_p( $log_dir );
	}
	
	$temp_dir = $upload_dir['basedir'] . '/product-scraper-temp';
	if ( ! file_exists( $temp_dir ) ) {
		wp_mkdir_p( $temp_dir );
	}
	
	if ( ! function_exists( 'get_field' ) ) {
		add_action( 'admin_notices', function() {
			echo '<div class="notice notice-warning"><p><strong>Product Scraper:</strong> Advanced Custom Fields (ACF) is not active.</p></div>';
		});
	}
}

/**
 * Register REST API routes
 */
function ps_register_rest_routes() {
	register_rest_route( 'custom/v1', '/product/', array(
		'methods'             => 'GET',
		'callback'            => 'ps_get_single_product_callback',
		'permission_callback' => '__return_true',
	));
	
	register_rest_route( 'custom/v1', '/configurator-image/', array(
		'methods'             => 'GET',
		'callback'            => 'ps_get_configurator_image_callback',
		'permission_callback' => '__return_true',
	));
}

// ==================== ADMIN STYLES & COLUMNS ====================

function ps_admin_custom_styles() {
    echo '<style>
        .ps-s3-image-preview { max-width: 50px; max-height: 50px; border-radius: 4px; margin-right: 10px; vertical-align: middle; }
        .ps-variation-image-preview { max-width: 60px; max-height: 60px; border-radius: 4px; margin-top: 5px; border: 1px solid #ddd; padding: 3px; }
        .ps-bulk-log { background: #f1f1f1; padding: 10px; max-height: 400px; overflow: auto; font-family: monospace; font-size: 12px; margin-top: 10px; display: none; }
    </style>';
}

function ps_add_product_thumbnail_column($columns) {
    $new_columns = array();
    foreach ($columns as $key => $value) {
        if ($key === 'title') {
            $new_columns['ps_thumbnail'] = __('Image URL', 'product-scraper');
        }
        $new_columns[$key] = $value;
    }
    return $new_columns;
}

function ps_display_product_thumbnail_column($column, $post_id) {
    if ($column === 'ps_thumbnail') {
        $image = get_post_meta($post_id, '_ps_product_main_image', true);
        if ($image) {
            echo '<img src="' . esc_url($image) . '" style="max-width: 50px; max-height: 50px; border-radius: 4px;" />';
        } else {
            echo '—';
        }
    }
}

// ==================== NO S3 – JUST RETURN ORIGINAL URL ====================

function ps_upload_to_s3($file_url, $file_type = 'image') {
    return $file_url;
}

// ==================== VARIATION CUSTOM FIELDS ====================

function ps_variation_custom_fields($loop, $variation_data, $variation) {
    $variation_id = $variation->ID;
    $finish_code = get_post_meta($variation_id, '_ps_finish_code', true);
    $finish_image = get_post_meta($variation_id, '_ps_finish_image', true);
    ?>
    <div class="ps-variation-fields">
        <h4 style="margin:0 0 10px; padding:8px; background:#007cba; color:white; border-radius:4px;">Emtek Variation Data</h4>
        <div class="options_group" style="padding:10px;">
            <p class="form-field form-field-wide">
                <label style="display:inline-block; width:120px; font-weight:bold;">Finish Code:</label>
                <input type="text" name="ps_finish_code[<?php echo $loop; ?>]" value="<?php echo esc_attr($finish_code); ?>" style="width:200px;" />
            </p>
            <p class="form-field form-field-wide">
                <label style="display:inline-block; width:120px; font-weight:bold;">Finish Image (URL):</label>
                <input type="url" name="ps_finish_image[<?php echo $loop; ?>]" value="<?php echo esc_url($finish_image); ?>" style="width:400px;" />
                <?php if ($finish_image) : ?>
                    <br><img src="<?php echo esc_url($finish_image); ?>" class="ps-variation-image-preview">
                <?php else: ?>
                    <br><span style="color:red;">No image URL saved</span>
                <?php endif; ?>
            </p>
        </div>
    </div>
    <?php
}

function ps_save_variation_custom_fields($variation_id, $i) {
    if (isset($_POST['ps_finish_code'][$i])) {
        update_post_meta($variation_id, '_ps_finish_code', sanitize_text_field($_POST['ps_finish_code'][$i]));
    }
    if (isset($_POST['ps_finish_image'][$i])) {
        update_post_meta($variation_id, '_ps_finish_image', esc_url_raw($_POST['ps_finish_image'][$i]));
    }
}

// ==================== CUSTOM FIELDS ====================

function ps_add_custom_product_fields() {
    global $post;
    $main_image = get_post_meta($post->ID, '_ps_product_main_image', true);
    $gallery = get_post_meta($post->ID, '_ps_product_gallery_images', true);
    ?>
    <div class="options_group">
        <?php
        woocommerce_wp_text_input(array(
            'id' => '_ps_product_main_image',
            'label' => __('Main Image URL', 'product-scraper'),
            'value' => $main_image,
        ));
        if ($main_image) {
            echo '<p style="margin-left:170px;"><img src="' . esc_url($main_image) . '" style="max-width:100px; border:1px solid #ddd; border-radius:4px; padding:5px;"></p>';
        }
        woocommerce_wp_textarea_input(array(
            'id' => '_ps_product_gallery_images',
            'label' => __('Gallery Image URLs', 'product-scraper'),
            'value' => $gallery,
        ));
        if ($gallery) {
            $images = explode("\n", $gallery);
            echo '<p style="margin-left:170px;">';
            foreach ($images as $img) {
                $img = trim($img);
                if ($img) echo '<img src="' . esc_url($img) . '" style="max-width:60px; margin-right:5px; border:1px solid #ddd; border-radius:4px; padding:2px;">';
            }
            echo '</p>';
        }
        ?>
    </div>
    <?php
}

function ps_save_custom_product_fields($post_id) {
    if (isset($_POST['_ps_product_main_image'])) {
        update_post_meta($post_id, '_ps_product_main_image', esc_url_raw($_POST['_ps_product_main_image']));
    }
    if (isset($_POST['_ps_product_gallery_images'])) {
        update_post_meta($post_id, '_ps_product_gallery_images', sanitize_textarea_field($_POST['_ps_product_gallery_images']));
    }
}

// ==================== SCRAPING FUNCTIONS ====================

function ps_get_single_product_callback($request) {
    set_time_limit(180);
    $product_url = $request->get_param('url');
    if (empty($product_url)) {
        return new WP_Error('missing_url', 'Product URL is missing', array('status' => 400));
    }
    
    if (!function_exists('str_get_html')) {
        $simple_html_path = PS_PLUGIN_PATH . 'simple_html_dom.php';
        if (file_exists($simple_html_path)) {
            require_once $simple_html_path;
        } else {
            return new WP_Error('missing_parser', 'HTML parser not found', array('status' => 500));
        }
    }
    
    $parse_url = parse_url($product_url);
    $home_url = $parse_url["scheme"] . "://" . $parse_url["host"] . "/";
    $product_data = ps_scrape_single_product_direct($product_url, $home_url);
    
    if ($product_data && !empty($product_data['title'])) {
        return array('success' => true, 'product' => $product_data);
    } else {
        return new WP_Error('scrape_failed', 'Failed to scrape product', array('status' => 500));
    }
}

function ps_scrape_single_product_direct($product_url, $home_url) {
    $product_html = ps_get_html_with_headers($product_url);
    if (!$product_html) return null;
    
    $product_data = array(
        'title' => '', 'image' => '', 'gallery_images' => array(), 'description' => '',
        'technical_specs' => '', 'installation_instructions' => array(), 'specifications' => array(),
        'functions' => array(), 'categories' => array(), 'brand' => 'Emtek',
        'configurator_groups' => array(), 'build_id' => '', 'home_url' => $home_url, 'product_slug' => '',
    );
    
    $next_data = $product_html->find("#__NEXT_DATA__", 0);
    if ($next_data) {
        $json_data = json_decode($next_data->innertext, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $product_data['build_id'] = isset($json_data['buildId']) ? $json_data['buildId'] : '';
            $product_data['product_slug'] = ps_extract_product_slug($product_url);
            $extracted = ps_extract_product_data($json_data);
            $product_data = array_merge($product_data, $extracted);
        }
    }
    
    // Categories from URL
    $url_cats = ps_extract_categories_from_url($product_url);
    if (!empty($url_cats)) $product_data['categories'] = $url_cats;
    
    $product_html->clear();
    unset($product_html);
    return $product_data;
}

function ps_extract_product_slug($url) {
    $path = parse_url($url, PHP_URL_PATH);
    $segments = explode('/', trim($path, '/'));
    return end($segments);
}

function ps_extract_product_data($product_data) {
    $result = array();
    $pageProps = isset($product_data['props']['pageProps']) ? $product_data['props']['pageProps'] : array();
    $wagtail = isset($pageProps['wagtail']) ? $pageProps['wagtail'] : array();
    $attribute = isset($wagtail['attribute']) ? $wagtail['attribute'] : array();
    
    $result['title'] = isset($wagtail['title']) ? sanitize_text_field($wagtail['title']) : '';
    $result['image'] = isset($attribute['primary_image']['image']) ? esc_url_raw($attribute['primary_image']['image']) : '';
    $result['description'] = isset($attribute['description']) ? wp_kses_post($attribute['description']) : '';
    $result['technical_specs'] = isset($attribute['technical_specs']) ? wp_kses_post($attribute['technical_specs']) : '';
	if (!empty($result['technical_specs'])) {
		error_log('Technical specs for ' . $result['title'] . ': ' . substr($result['technical_specs'], 0, 500));
	}
	else{
		error_log('Technical specs is empty.');
	}

    $result['brand'] = 'Emtek';
    
    // Gallery images
    $result['gallery_images'] = array();
    if (!empty($result['image'])) $result['gallery_images'][] = $result['image'];
    if (!empty($attribute['finish_images']) && is_array($attribute['finish_images'])) {
        foreach ($attribute['finish_images'] as $image) {
            if (!empty($image) && !in_array($image, $result['gallery_images'])) $result['gallery_images'][] = $image;
        }
    }
    
    // Finish images lookup
    $finish_images = array();
    if (!empty($attribute['finish_images']) && is_array($attribute['finish_images'])) {
        $finish_images = $attribute['finish_images'];
    }
    
    // Process ALL configurator groups – keep all, even without images
    $result['configurator_groups'] = array();
    if (!empty($attribute['configurator']) && is_array($attribute['configurator'])) {
        foreach ($attribute['configurator'] as $group) {
            $group_name = isset($group['display_name']) ? $group['display_name'] : '';
            if (empty($group_name)) continue;
            $options = isset($group['options']) ? $group['options'] : array();
            if (empty($options)) continue;
            
            $processed_options = array();
            foreach ($options as $option) {
                $option_id = isset($option['id']) ? (string)$option['id'] : '';
                $option_name = isset($option['option']) ? $option['option'] : '';
                $option_code = isset($option['code']) ? $option['code'] : '';
                $image_url = '';
                
                // Match finish image using option ID (for Finishes)
                if (!empty($option_id) && isset($finish_images[$option_id])) {
                    $image_url = $finish_images[$option_id];
                } elseif (isset($option['primary_image']['image']) && !empty($option['primary_image']['image'])) {
                    $image_url = $option['primary_image']['image'];
                } elseif (isset($option['image']) && !empty($option['image'])) {
                    $image_url = $option['image'];
                }
                
                if (empty($option_code)) $option_code = sanitize_title($option_name);
                
                $processed_options[] = array(
                    'id' => $option_id,
                    'name' => $option_name,
                    'code' => $option_code,
                    'image' => $image_url,
                );
            }
            if (!empty($processed_options)) {
                $result['configurator_groups'][] = array(
                    'display_name' => $group_name,
                    'options' => $processed_options,
                );
            }
        }
    }
    
    // Installation instructions, specifications, functions
    if (!empty($attribute['installation_instructions']) && is_array($attribute['installation_instructions'])) {
        $result['installation_instructions'] = array();
        foreach ($attribute['installation_instructions'] as $inst) {
            $result['installation_instructions'][] = array(
                'title' => isset($inst['title']) ? $inst['title'] : '',
                'file' => isset($inst['file']) ? $inst['file'] : (isset($inst['url']) ? $inst['url'] : ''),
            );
        }
    }
    if (!empty($attribute['specifications']) && is_array($attribute['specifications'])) {
        $result['specifications'] = array();
        foreach ($attribute['specifications'] as $spec) {
            $result['specifications'][] = array(
                'title' => isset($spec['title']) ? $spec['title'] : '',
                'file' => isset($spec['file']) ? $spec['file'] : (isset($spec['url']) ? $spec['url'] : ''),
            );
        }
    }
    if (!empty($attribute['functions']) && is_array($attribute['functions'])) {
        $result['acf_functions'] = array();
        foreach ($attribute['functions'] as $func) {
            $modal = isset($func['function_modal']) ? $func['function_modal'] : $func;
            $result['acf_functions'][] = array(
                'title' => isset($modal['title']) ? $modal['title'] : '',
                'description' => isset($modal['description']) ? $modal['description'] : '',
                'image' => isset($modal['image']['url']) ? $modal['image']['url'] : (isset($modal['image']) ? $modal['image'] : ''),
            );
        }
    }

    
    return $result;
}

function ps_extract_categories_from_url($url) {
    $categories = array();
    $path = parse_url($url, PHP_URL_PATH);
    if (preg_match('/\/all-products\/(.+?)\/[^\/]+\/?$/', $path, $matches)) {
        $parts = explode('/', $matches[1]);
        $current = '';
        foreach ($parts as $cat) {
            $name = ucwords(str_replace('-', ' ', $cat));
            $current = empty($current) ? $name : $current . ' > ' . $name;
            $categories[] = $current;
        }
    }
    return $categories;
}

function ps_get_html_with_headers($url) {
    $response = wp_remote_get($url, array(
        'timeout' => 30,
        'headers' => array('User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'),
    ));
    if (is_wp_error($response)) return false;
    $body = wp_remote_retrieve_body($response);
    return function_exists('str_get_html') ? str_get_html($body) : false;
}

// ==================== PRODUCT CREATION ====================
function ps_import_product_from_data($product_data, $status = 'draft', $update_existing = true) {
    if (empty($product_data['title'])) {
        return array('success' => false, 'message' => 'Product title is required');
    }
    
    $existing_product_id = ps_find_product_by_title($product_data['title']);
    
    if ($existing_product_id) {
        if ($update_existing) {
            // Update existing product
            return ps_update_existing_product($existing_product_id, $product_data, $status);
        } else {
            // Skip existing product
            return array('success' => false, 'message' => 'Product already exists: ' . $product_data['title'], 'product_id' => $existing_product_id);
        }
    }

    // Create new product
    $config_groups = isset($product_data['configurator_groups']) ? $product_data['configurator_groups'] : array();
    $has_configurator = false;
    $first_group = null;
    $other_groups = array();
    
    if (!empty($config_groups) && count($config_groups) >= 1) {
        $first_group = $config_groups[0];
        if (!empty($first_group['options']) && count($first_group['options']) >= 1) {
            $has_configurator = true;
            $other_groups = array_slice($config_groups, 1);
        }
    }
    
    if ($has_configurator) {
        return ps_create_variable_product($product_data, $first_group, $other_groups, $status);
    } else {
        return ps_create_simple_product($product_data, $status);
    }
}
function ps_import_product_from_data_correctedone($product_data, $status = 'draft') {
    if (empty($product_data['title'])) {
        return array('success' => false, 'message' => 'Product title is required');
    }
    
    $existing = ps_find_product_by_title($product_data['title']);
    if ($existing) {
        return array('success' => false, 'message' => 'Product already exists: ' . $product_data['title'], 'product_id' => $existing);
    }

    $config_groups = isset($product_data['configurator_groups']) ? $product_data['configurator_groups'] : array();
    
    // Check if there are configurator groups with options
    $has_configurator = false;
    $first_group = null;
    $other_groups = array();
    
    if (!empty($config_groups) && count($config_groups) >= 1) {
        $first_group = $config_groups[0];
        // Only create variable product if first group has options
        if (!empty($first_group['options']) && count($first_group['options']) >= 1) {
            $has_configurator = true;
            $other_groups = array_slice($config_groups, 1);
        }
    }
    
    if ($has_configurator) {
        return ps_create_variable_product($product_data, $first_group, $other_groups, $status);
    } else {
        return ps_create_simple_product($product_data, $status);
    }
}

function ps_import_product_from_data_old($product_data, $status = 'draft') {
    if (empty($product_data['title'])) {
        return array('success' => false, 'message' => 'Product title is required');
    }
    $existing = ps_find_product_by_title($product_data['title']);
    if ($existing) {
        return array('success' => false, 'message' => 'Product already exists: ' . $product_data['title'], 'product_id' => $existing);
    }

	$config_groups = isset($product_data['configurator_groups']) ? $product_data['configurator_groups'] : array();
	$first_group = null;
	$other_groups = array();

	if (!empty($config_groups) && count($config_groups) >= 1) {
		$first_group = $config_groups[0];
		$other_groups = array_slice($config_groups, 1);
		// Always create variations if there is at least one option (even a single option)
		if (count($first_group['options']) >= 1) {
			return ps_create_variable_product($product_data, $first_group, $other_groups, $status);
		}
	}
	// No configurator groups → simple product
	return ps_create_simple_product($product_data, $status);
}

function ps_create_single_attribute($attr_name, $options) {
    if (empty($options)) return false;
    $attr_slug = sanitize_title($attr_name);
    $taxonomy_name = wc_attribute_taxonomy_name($attr_slug);
    $attribute_id = wc_attribute_taxonomy_id_by_name($attr_name);
    if (!$attribute_id) {
        wc_create_attribute(array('name' => $attr_name, 'slug' => $attr_slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false));
    }
    if (!taxonomy_exists($taxonomy_name)) register_taxonomy($taxonomy_name, 'product', array());
    $term_names = array();
    foreach ($options as $option) {
        $name = isset($option['name']) ? $option['name'] : '';
        if (!empty($name)) {
            if (!term_exists($name, $taxonomy_name)) wp_insert_term($name, $taxonomy_name);
            $term_names[] = $name;
        }
    }
    $attribute = new WC_Product_Attribute();
    $attribute->set_name($taxonomy_name);
    $attribute->set_options($term_names);
    $attribute->set_position(0);
    $attribute->set_visible(true);
    $attribute->set_variation(true);
    return $attribute;
}

function ps_create_variations($product_id, $first_group, $parent_sku, $status) {
    $count = 0;
    if (!$first_group || empty($first_group['options'])) return 0;
    $attr_slug = sanitize_title($first_group['display_name']);
    $taxonomy_name = wc_attribute_taxonomy_name($attr_slug);
    
    foreach ($first_group['options'] as $option) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($product_id);
        $code = isset($option['code']) ? $option['code'] : sanitize_title($option['name']);
        $variation->set_sku($parent_sku . '-' . $code);
        $variation->set_status($status);
        $variation->set_manage_stock(false);
        $variation->set_stock_status('instock');
        $variation->set_attributes(array('attribute_' . $taxonomy_name => $option['name']));
        $variation->save();
        
        update_post_meta($variation->get_id(), '_ps_finish_code', $code);
        if (!empty($option['image'])) {
            update_post_meta($variation->get_id(), '_ps_finish_image', $option['image']);
        }
        $count++;
    }
    return $count;
}

function ps_add_emtek_brand_to_product($product_id) {
    $brand = 'Emtek';
    if (!taxonomy_exists('product_brand')) {
        register_taxonomy('product_brand', 'product', array(
            'label' => __('Brands'), 'rewrite' => array('slug' => 'brand'), 'hierarchical' => true,
            'show_in_rest' => true, 'show_ui' => true, 'show_admin_column' => true,
        ));
    }
    $term = term_exists($brand, 'product_brand');
    if (!$term) $term = wp_insert_term($brand, 'product_brand', array('slug' => 'emtek'));
    if (!is_wp_error($term)) {
        $term_id = is_array($term) ? $term['term_id'] : $term;
        wp_set_object_terms($product_id, array((int)$term_id), 'product_brand');
    }
}

function ps_create_variable_product($product_data, $first_group, $other_groups, $status) {
    $product = new WC_Product_Variable();
    $product->set_name($product_data['title']);
    $product->set_description($product_data['description']);
    $product->set_short_description(wp_trim_words(wp_strip_all_tags($product_data['description']), 50));
    $product->set_status($status);
    $product->set_catalog_visibility('visible');
    $sku = ps_generate_unique_sku($product_data['title']);
    $product->set_sku($sku);
    
    // Categories
    if (!empty($product_data['categories'])) {
        $cat_ids = array();
        foreach ($product_data['categories'] as $path) {
            $parts = explode(' > ', $path);
            $parent = 0;
            foreach ($parts as $part) {
                $term = term_exists($part, 'product_cat', $parent);
                if (!$term) $term = wp_insert_term($part, 'product_cat', array('parent' => $parent));
                if (!is_wp_error($term)) {
                    $cat_ids[] = (int)$term['term_id'];
                    $parent = (int)$term['term_id'];
                }
            }
        }
        if (!empty($cat_ids)) $product->set_category_ids(array_unique($cat_ids));
    }
    
    $product_id = $product->save();
    if (!$product_id) return array('success' => false, 'message' => 'Failed to create product');
    
    ps_add_emtek_brand_to_product($product_id);
    
    $attr = ps_create_single_attribute($first_group['display_name'], $first_group['options']);
    if ($attr) {
        $product->set_attributes(array($attr));
        $product->save();
    }
    $variation_count = ps_create_variations($product_id, $first_group, $sku, $status);
    
    // Save all configurator groups for frontend
    update_post_meta($product_id, '_ps_configurator_groups', $product_data['configurator_groups']);
    if (!empty($product_data['build_id'])) update_post_meta($product_id, '_ps_build_id', $product_data['build_id']);
    if (!empty($product_data['product_slug'])) update_post_meta($product_id, '_ps_product_slug', $product_data['product_slug']);
    
    // Main image
    if (!empty($product_data['image'])) {
        update_post_meta($product_id, '_ps_product_main_image', $product_data['image']);
    }
    // Gallery images
    if (!empty($product_data['gallery_images']) && is_array($product_data['gallery_images'])) {
        update_post_meta($product_id, '_ps_product_gallery_images', implode("\n", $product_data['gallery_images']));
    }
    
    ps_save_product_acf_fields($product_id, $product_data);
    return array('success' => true, 'message' => sprintf('Product created with %d variations', $variation_count), 'product_id' => $product_id);
}
/**
 * Create simple product (for products without configurator groups)
 */
function ps_create_simple_product($product_data, $status) {
    $product = new WC_Product_Simple();
    $product->set_name($product_data['title']);
    $product->set_description($product_data['description']);
    $product->set_short_description(wp_trim_words(wp_strip_all_tags($product_data['description']), 50));
    $product->set_status($status);
    $product->set_catalog_visibility('visible');
    $product->set_stock_status('instock');
    $product->set_manage_stock(false);
    
    $sku = ps_generate_unique_sku($product_data['title']);
    $product->set_sku($sku);
    
    // Set categories (hierarchical)
    if (!empty($product_data['categories'])) {
        $cat_ids = array();
        foreach ($product_data['categories'] as $path) {
            $parts = explode(' > ', $path);
            $parent = 0;
            foreach ($parts as $part) {
                $term = term_exists($part, 'product_cat', $parent);
                if (!$term) {
                    $term = wp_insert_term($part, 'product_cat', array('parent' => $parent));
                }
                if (!is_wp_error($term)) {
                    $cat_ids[] = (int)$term['term_id'];
                    $parent = (int)$term['term_id'];
                }
            }
        }
        if (!empty($cat_ids)) {
            $product->set_category_ids(array_unique($cat_ids));
        }
    }
    
    $product_id = $product->save();
    
    if (!$product_id) {
        return array('success' => false, 'message' => 'Failed to create product');
    }
    
    // Add Emtek brand
    ps_add_emtek_brand_to_product($product_id);
    
    // Save configurator groups (if any, for frontend display)
    if (!empty($product_data['configurator_groups'])) {
        update_post_meta($product_id, '_ps_configurator_groups', $product_data['configurator_groups']);
    }
    
    // Save metadata
    if (!empty($product_data['build_id'])) {
        update_post_meta($product_id, '_ps_build_id', $product_data['build_id']);
    }
    if (!empty($product_data['product_slug'])) {
        update_post_meta($product_id, '_ps_product_slug', $product_data['product_slug']);
    }
    
    // Save main image
    if (!empty($product_data['image'])) {
        update_post_meta($product_id, '_ps_product_main_image', $product_data['image']);
    }
    
    // Save gallery images
    if (!empty($product_data['gallery_images']) && is_array($product_data['gallery_images'])) {
        update_post_meta($product_id, '_ps_product_gallery_images', implode("\n", $product_data['gallery_images']));
    }
    
    // Save ACF fields
    ps_save_product_acf_fields($product_id, $product_data);
    
    return array('success' => true, 'message' => 'Simple product created', 'product_id' => $product_id);
}
function ps_save_product_acf_fields($product_id, $product_data) {
    if (!function_exists('update_field')) return;
    if (!empty($product_data['technical_specs'])) update_field('technical_specs', wp_kses_post($product_data['technical_specs']), $product_id);
    
    if (!empty($product_data['installation_instructions'])) {
        $rows = array();
        foreach ($product_data['installation_instructions'] as $inst) {
            $rows[] = array('title' => sanitize_text_field($inst['title']), 'file' => $inst['file']);
        }
        update_field('installation_instructions', $rows, $product_id);
    }
    if (!empty($product_data['specifications'])) {
        $rows = array();
        foreach ($product_data['specifications'] as $spec) {
            $rows[] = array('title' => sanitize_text_field($spec['title']), 'file' => $spec['file']);
        }
        update_field('specifications', $rows, $product_id);
    }

    // ACF Functions - Skip empty titles
    if (!empty($product_data['acf_functions'])) {
        $rows = array();
        foreach ($product_data['acf_functions'] as $func) {
            // Skip if title is empty
            if (empty($func['title'])) {
                continue;
            }
            
            $rows[] = array(
                'title' => sanitize_text_field($func['title']),
                'description' => wp_kses_post($func['description']),
                'image' => $func['image'],
            );
        }
        // Only update if there are valid rows
        if (!empty($rows)) {
            update_field('functions', $rows, $product_id);
        }
    }

}

function ps_generate_unique_sku($title) {
    $base = sanitize_title($title);
    $base = preg_replace('/[^a-z0-9-]/', '', $base);
    $base = trim($base, '-');
    if (empty($base)) $base = 'product';
    $sku = $base;
    $counter = 1;
    while (wc_get_product_id_by_sku($sku)) {
        $sku = $base . '-' . $counter;
        $counter++;
    }
    return $sku;
}

function ps_find_product_by_title($title) {
    $products = wc_get_products(array('title' => $title, 'limit' => 1, 'return' => 'ids'));
    return !empty($products) ? $products[0] : false;
}


/**
 * Update existing product with new data (preserves existing categories)
 */
function ps_update_existing_product($product_id, $product_data, $status = 'draft') {
    $product = wc_get_product($product_id);
    if (!$product) {
        return array('success' => false, 'message' => 'Product not found');
    }
    
    // Update basic info
    $product->set_name($product_data['title']);
    $product->set_description($product_data['description']);
    $product->set_short_description(wp_trim_words(wp_strip_all_tags($product_data['description']), 50));
    $product->set_status($status);
    
    // ==================== UPDATE CATEGORIES (MERGE, NOT REPLACE) ====================
    // Get existing category IDs
    $existing_cat_ids = $product->get_category_ids();
    
    // Get new category IDs from the scraped data
    $new_cat_ids = array();
    if (!empty($product_data['categories'])) {
        foreach ($product_data['categories'] as $path) {
            $parts = explode(' > ', $path);
            $parent = 0;
            foreach ($parts as $part) {
                $term = term_exists($part, 'product_cat', $parent);
                if (!$term) {
                    $term = wp_insert_term($part, 'product_cat', array('parent' => $parent));
                }
                if (!is_wp_error($term)) {
                    $new_cat_ids[] = (int)$term['term_id'];
                    $parent = (int)$term['term_id'];
                }
            }
        }
    }
    
    // Merge existing and new categories (remove duplicates)
    $merged_cat_ids = array_unique(array_merge($existing_cat_ids, $new_cat_ids));
    
    // Set the merged categories back to the product
    $product->set_category_ids($merged_cat_ids);
    
    $product->save();
    
    // Update configurator groups meta
    if (!empty($product_data['configurator_groups'])) {
        update_post_meta($product_id, '_ps_configurator_groups', $product_data['configurator_groups']);
    }
    
    // Update build ID and slug
    if (!empty($product_data['build_id'])) {
        update_post_meta($product_id, '_ps_build_id', $product_data['build_id']);
    }
    if (!empty($product_data['product_slug'])) {
        update_post_meta($product_id, '_ps_product_slug', $product_data['product_slug']);
    }
    
    // Update main image (only if not already set)
    if (!empty($product_data['image'])) {
        $existing_image = get_post_meta($product_id, '_ps_product_main_image', true);
        if (empty($existing_image)) {
            update_post_meta($product_id, '_ps_product_main_image', $product_data['image']);
        }
    }
    
    // Update gallery images (only if not already set)
    if (!empty($product_data['gallery_images']) && is_array($product_data['gallery_images'])) {
        $existing_gallery = get_post_meta($product_id, '_ps_product_gallery_images', true);
        if (empty($existing_gallery)) {
            update_post_meta($product_id, '_ps_product_gallery_images', implode("\n", $product_data['gallery_images']));
        }
    }
    
    // Update ACF fields
    ps_save_product_acf_fields($product_id, $product_data);
    
    // Update variations if configurator groups exist
    $config_groups = $product_data['configurator_groups'];
    if (!empty($config_groups) && count($config_groups) >= 1) {
        $first_group = $config_groups[0];
        if (!empty($first_group['options'])) {
            ps_update_variations($product_id, $first_group);
        }
    }
    
    return array('success' => true, 'message' => 'Product updated successfully (categories merged)', 'product_id' => $product_id);
}

/**
 * Update variations for existing product (add missing, update existing, don't delete)
 */
function ps_update_variations($product_id, $first_group) {
    $product = wc_get_product($product_id);
    if (!$product) return;
    
    $existing_variations = $product->get_children();
    $existing_sku_map = array();
    
    // Get existing variations by SKU
    foreach ($existing_variations as $var_id) {
        $var = wc_get_product($var_id);
        if ($var) {
            $sku = $var->get_sku();
            if ($sku) {
                $existing_sku_map[$sku] = $var_id;
            }
        }
    }
    
    $attr_slug = sanitize_title($first_group['display_name']);
    $taxonomy_name = wc_attribute_taxonomy_name($attr_slug);
    
    foreach ($first_group['options'] as $option) {
        $code = isset($option['code']) ? $option['code'] : sanitize_title($option['name']);
        $var_sku = $product->get_sku() . '-' . $code;
        
        if (isset($existing_sku_map[$var_sku])) {
            // Update existing variation (don't delete)
            $variation = wc_get_product($existing_sku_map[$var_sku]);
            if ($variation) {
                // Update finish code
                update_post_meta($variation->get_id(), '_ps_finish_code', $code);
                
                // Update image if not already set
                if (!empty($option['image'])) {
                    $existing_image = get_post_meta($variation->get_id(), '_ps_finish_image', true);
                    if (empty($existing_image)) {
                        update_post_meta($variation->get_id(), '_ps_finish_image', $option['image']);
                    }
                }
            }
        } else {
            // Create new variation (don't delete existing ones)
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_sku($var_sku);
            $variation->set_status('publish');
            $variation->set_manage_stock(false);
            $variation->set_stock_status('instock');
            $variation->set_attributes(array('attribute_' . $taxonomy_name => $option['name']));
            $variation->save();
            
            update_post_meta($variation->get_id(), '_ps_finish_code', $code);
            if (!empty($option['image'])) {
                update_post_meta($variation->get_id(), '_ps_finish_image', $option['image']);
            }
        }
    }
}
// ==================== ADMIN CONFIGURATOR TAB ====================

function ps_add_product_data_tab($tabs) {
    $tabs['emtek_configurator'] = array(
        'label'    => __('Emtek Configurator', 'product-scraper'),
        'target'   => 'emtek_configurator_data',
        'class'    => array(),
        'priority' => 60,
    );
    return $tabs;
}

function ps_add_product_data_panel() {
    global $post;
    $groups = get_post_meta($post->ID, '_ps_configurator_groups', true);
    $build_id = get_post_meta($post->ID, '_ps_build_id', true);
    $product_slug = get_post_meta($post->ID, '_ps_product_slug', true);
    ?>
    <div id="emtek_configurator_data" class="panel woocommerce_options_panel">
        <div class="options_group">
            <h3>Product Configuration</h3>
            <div class="form-field"><label for="ps_build_id">Next.js Build ID</label><input type="text" id="ps_build_id" name="ps_build_id" value="<?php echo esc_attr($build_id); ?>" class="short" /></div>
            <div class="form-field"><label for="ps_product_slug">Product Slug</label><input type="text" id="ps_product_slug" name="ps_product_slug" value="<?php echo esc_attr($product_slug); ?>" class="short" /></div>
        </div>
        <div class="options_group">
            <h3>Configurator Groups (Frontend Swatches)</h3>
            <p class="description">Only the FIRST group creates variations. All groups appear as swatches.</p>
            <div id="ps_configurator_groups_container">
                <?php if (is_array($groups) && !empty($groups)) : ?>
                    <?php foreach ($groups as $idx => $group) : 
                        $is_first = ($idx === 0);
                        $bg = $is_first ? '#e8f4e8' : '#f9f9f9';
                        ?>
                        <div class="ps-config-group" style="border:1px solid #ccc; padding:15px; margin-bottom:20px; background:<?php echo $bg; ?>;">
                            <h4>
                                <input type="text" name="ps_config_group_names[]" value="<?php echo esc_attr($group['display_name']); ?>" style="width:30%;" />
                                <?php if ($is_first) : ?><span style="background:#4CAF50; color:white; padding:2px 8px; border-radius:3px; margin-left:10px;">Creates Variations</span><?php endif; ?>
                                <button type="button" class="button remove-group" style="float:right;">Remove Group</button>
                            </h4>
                            <div class="ps-group-options">
                                <?php foreach ($group['options'] as $opt) : ?>
                                    <div class="ps-option-item" style="margin-bottom:10px; padding:10px; background:white; border:1px solid #eee;">
                                        <input type="text" name="ps_option_labels[<?php echo $idx; ?>][]" value="<?php echo esc_attr($opt['name']); ?>" placeholder="Label" style="width:25%; margin-right:10px;" />
                                        <input type="text" name="ps_option_codes[<?php echo $idx; ?>][]" value="<?php echo esc_attr($opt['code']); ?>" placeholder="Code" style="width:20%; margin-right:10px;" />
                                        <input type="url" name="ps_option_images[<?php echo $idx; ?>][]" value="<?php echo esc_url($opt['image']); ?>" placeholder="Image URL" style="width:40%; margin-right:10px;" />
                                        <button type="button" class="button remove-option">Remove</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button add-option">+ Add Option</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" id="ps-add-config-group" class="button button-primary">+ Add Configurator Group</button>
        </div>
    </div>
    <script>
    jQuery(document).ready(function($){
        var groupIndex = <?php echo is_array($groups) ? count($groups) : 0; ?>;
        $('#ps-add-config-group').click(function(){
            var isFirst = $('.ps-config-group').length === 0;
            var bg = isFirst ? '#e8f4e8' : '#f9f9f9';
            var badge = isFirst ? '<span style="background:#4CAF50; color:white; padding:2px 8px; border-radius:3px; margin-left:10px;">Creates Variations</span>' : '';
            var newGroup = $('<div class="ps-config-group" style="border:1px solid #ccc; padding:15px; margin-bottom:20px; background:'+bg+';">'+
                '<h4><input type="text" name="ps_config_group_names[]" placeholder="Group Name" style="width:30%;" />'+badge+
                '<button type="button" class="button remove-group" style="float:right;">Remove Group</button></h4>'+
                '<div class="ps-group-options"></div><button type="button" class="button add-option">+ Add Option</button></div>');
            $('#ps_configurator_groups_container').append(newGroup);
        });
        $(document).on('click', '.add-option', function(){
            var group = $(this).closest('.ps-config-group');
            var idx = $('.ps-config-group').index(group);
            var html = '<div class="ps-option-item" style="margin-bottom:10px; padding:10px; background:white; border:1px solid #eee;">'+
                '<input type="text" name="ps_option_labels['+idx+'][]" placeholder="Label" style="width:25%; margin-right:10px;" />'+
                '<input type="text" name="ps_option_codes['+idx+'][]" placeholder="Code" style="width:20%; margin-right:10px;" />'+
                '<input type="url" name="ps_option_images['+idx+'][]" placeholder="Image URL" style="width:40%; margin-right:10px;" />'+
                '<button type="button" class="button remove-option">Remove</button></div>';
            group.find('.ps-group-options').append(html);
        });
        $(document).on('click', '.remove-group', function(){ if(confirm('Remove group?')){ $(this).closest('.ps-config-group').remove(); reindex(); } });
        $(document).on('click', '.remove-option', function(){ $(this).closest('.ps-option-item').remove(); });
        function reindex(){
            $('.ps-config-group').each(function(newIdx, g){
                $(g).find('.ps-option-item').each(function(){
                    $(this).find('input[name^="ps_option_labels"]').attr('name','ps_option_labels['+newIdx+'][]');
                    $(this).find('input[name^="ps_option_codes"]').attr('name','ps_option_codes['+newIdx+'][]');
                    $(this).find('input[name^="ps_option_images"]').attr('name','ps_option_images['+newIdx+'][]');
                });
            });
        }
    });
    </script>
    <style>.ps-config-group{margin-bottom:20px;}.ps-option-item{margin-bottom:10px;}.remove-group,.remove-option{margin-left:10px;}</style>
    <?php
}

function ps_save_product_configurator_data($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (isset($_POST['ps_build_id'])) update_post_meta($post_id, '_ps_build_id', sanitize_text_field($_POST['ps_build_id']));
    if (isset($_POST['ps_product_slug'])) update_post_meta($post_id, '_ps_product_slug', sanitize_text_field($_POST['ps_product_slug']));
    if (isset($_POST['ps_config_group_names'])) {
        $groups = array();
        $names = $_POST['ps_config_group_names'];
        $labels = isset($_POST['ps_option_labels']) ? $_POST['ps_option_labels'] : array();
        $codes = isset($_POST['ps_option_codes']) ? $_POST['ps_option_codes'] : array();
        $images = isset($_POST['ps_option_images']) ? $_POST['ps_option_images'] : array();
        foreach ($names as $idx => $name) {
            if (empty($name)) continue;
            $opts = array();
            $lbls = isset($labels[$idx]) ? $labels[$idx] : array();
            $cds = isset($codes[$idx]) ? $codes[$idx] : array();
            $imgs = isset($images[$idx]) ? $images[$idx] : array();
            for ($i=0; $i<count($lbls); $i++) {
                if (!empty($lbls[$i])) {
                    $opts[] = array(
                        'name' => sanitize_text_field($lbls[$i]),
                        'code' => sanitize_text_field($cds[$i]),
                        'image' => esc_url_raw($imgs[$i]),
                    );
                }
            }
            if (!empty($opts)) $groups[] = array('display_name' => sanitize_text_field($name), 'options' => $opts);
        }
        update_post_meta($post_id, '_ps_configurator_groups', $groups);
    }
}

// ==================== FRONTEND CONFIGURATOR ====================

function ps_enqueue_configurator_scripts() {
    if (!is_product()) return;
     wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
    wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);

    wp_enqueue_script('ps-configurator', PS_PLUGIN_URL . 'js/configurator.js', array('jquery', 'wc-add-to-cart-variation'), '1.0', true);
    $css_file = PS_PLUGIN_PATH . 'css/style.css';
    if ( file_exists( $css_file ) ) {
        wp_enqueue_style( 'ps-configurator', PS_PLUGIN_URL . 'css/style.css', array(), filemtime( $css_file ) );
    }
    wp_localize_script('ps-configurator', 'ps_ajax', array('ajax_url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('ps_configurator_nonce')));
}

function ps_add_configurator_swatches() {
    global $product;

    $product_id = $product->get_id();
     // Get gallery images from meta
    $gallery_images = get_post_meta($product_id, '_ps_product_gallery_images', true);
    $image_urls = array();
    $is_gallery_images_group = false;
    $image_count = 0;

    if (!empty($gallery_images)) {
        $image_urls = array_filter(explode("\n", $gallery_images));
        $image_urls = array_map('trim', $image_urls);
        $image_count = count($image_urls);
        
        // Show as select dropdown only if more than 1 image
        if ($image_count > 1) {
            $is_gallery_images_group = true;
        }
    }

    $groups = get_post_meta($product->get_id(), '_ps_configurator_groups', true);
    $build_id = get_post_meta($product->get_id(), '_ps_build_id', true);
    $slug = get_post_meta($product->get_id(), '_ps_product_slug', true);
    
    if (empty($groups)) {
        return;
    }
    ?>
    <div class="ps-configurator-wrapper">
        <div class="ps-configurator-swatches" 
             data-product-id="<?php echo esc_attr($product->get_id()); ?>" 
             data-build-id="<?php echo esc_attr($build_id); ?>" 
             data-product-slug="<?php echo esc_attr($slug); ?>">
            
            <?php foreach ($groups as $idx => $group) : 
                $is_first_group = ($idx === 0);
                $group_name = $group['display_name'];
                $is_finish_group = ($group_name === 'Finishes' || stripos($group_name, 'Finish') !== false);
            ?>
                <div class="ps-configurator-group" style="margin-bottom: 20px;" data-group-index="<?php echo $idx; ?>" data-group-name="<?php echo esc_attr($group_name); ?>" data-is-first="<?php echo $is_first_group ? 'yes' : 'no'; ?>">
                    <label style="display: block; font-weight: 600; margin-bottom: 10px;">
                        <?php echo esc_html($group_name); ?>:
                    </label>
                    
                    <select class="ps-config-select <?php echo ($is_first_group && $image_count > 1)? 'ps-first-group-select' : ''; ?>" name="ps_config_<?php echo $idx; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value=""><?php _e('Select an option...', 'product-scraper'); ?></option>
                        <?php foreach ($group['options'] as $opt) : 
                            $option_name = isset($opt['name']) ? $opt['name'] : '';
                            $option_code = isset($opt['code']) ? $opt['code'] : '';
                            $option_id = isset($opt['id']) ? $opt['id'] : '';
                            
                            // Get thumbnail URL
                            $thumbnail_url = '';
                            if ($is_finish_group) {
                                $thumbnail_url = ps_get_finish_thumbnail_url($option_name);
                                if (empty($thumbnail_url) && isset($opt['image'])) {
                                    $thumbnail_url = $opt['image'];
                                }
                            } else {
                                $thumbnail_url = (isset($opt['image']) && !empty($opt['image'])) ? $opt['image'] : PS_PLACEHOLDER_IMAGE;
                            }
                            
                            // IMPORTANT: For first group, store the product image URL in data-image
                            $product_image = '';
                            if ($is_first_group && isset($opt['image']) && !empty($opt['image'])) {
                                $product_image = $opt['image'];
                            }
                            
                            // Also check for large_image or other image fields
                            if (empty($product_image) && $is_first_group && isset($opt['large_image'])) {
                                $product_image = $opt['large_image'];
                            }
                            if (empty($product_image) && $is_first_group && isset($opt['product_image'])) {
                                $product_image = $opt['product_image'];
                            }
                        ?>
                            <option value="<?php echo esc_attr($option_code); ?>" 
                                    data-name="<?php echo esc_attr($option_name); ?>"
                                    data-image="<?php echo esc_url($product_image); ?>"
                                    data-id="<?php echo esc_attr($option_id); ?>"
                                    data-thumbnail="<?php echo esc_url($thumbnail_url); ?>">
                                <?php echo esc_html($option_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            
            <input type="hidden" name="ps_selected_options" id="ps_selected_options" value="">
        </div>
        
        <div id="ps-selected-summary" style="margin-top: 15px; padding: 10px; background: #e9ecef; border-radius: 6px; display: none;">
            <strong><?php _e('Selected:', 'product-scraper'); ?></strong> <span id="ps-summary-text"></span>
        </div>
    </div>
    
    <script>
    // Initialize configurator image switching
    jQuery(document).ready(function($) {
        // Handle first group selection - updates featured image
        $('.ps-config-select.ps-first-group-select').on('change', function() {
            var selectedOption = $(this).find('option:selected');
            var imageUrl = selectedOption.data('image');
            var optionName = selectedOption.data('name');
            
            console.log('Configurator selected: ' + optionName);
            console.log('Image URL: ' + imageUrl);
            
            if (imageUrl && imageUrl !== '') {
                // Update featured image
                var mainImage = $('.woocommerce-product-gallery__wrapper img:first');
                if (mainImage.length) {
                    mainImage.attr('src', imageUrl);
                    mainImage.attr('srcset', imageUrl);
                    mainImage.attr('data-large-image', imageUrl);
                    
                    // Update gallery
                    $('.woocommerce-product-gallery').trigger('refresh');
                }
            }
        });
        
        // Trigger change on page load if an option is pre-selected
        $('.ps-config-select.ps-first-group-select').each(function() {
            if ($(this).val() !== '') {
                $(this).trigger('change');
            }
        });
    });
    </script>
    <?php
}

function ps_add_configurator_swatches_worked() {
    global $product;
    $groups = get_post_meta($product->get_id(), '_ps_configurator_groups', true);
    $build_id = get_post_meta($product->get_id(), '_ps_build_id', true);
    $slug = get_post_meta($product->get_id(), '_ps_product_slug', true);
    
    if (empty($groups)) {
        return;
    }
    ?>
    <div class="ps-configurator-wrapper">
        <!-- <h3 style="margin-top: 0; margin-bottom: 20px;"><?php _e('Product Configurator', 'product-scraper'); ?></h3> -->
        
        <div class="ps-configurator-swatches" 
             data-product-id="<?php echo esc_attr($product->get_id()); ?>" 
             data-build-id="<?php echo esc_attr($build_id); ?>" 
             data-product-slug="<?php echo esc_attr($slug); ?>">
            
            <?php foreach ($groups as $idx => $group) : 
                $is_first_group = ($idx === 0);
                $group_name = $group['display_name'];
                $is_finish_group = ($group_name === 'Finishes' || stripos($group_name, 'Finish') !== false);
            ?>
                <div class="ps-configurator-group" style="margin-bottom: 20px;" data-group-index="<?php echo $idx; ?>" data-group-name="<?php echo esc_attr($group_name); ?>" data-is-first="<?php echo $is_first_group ? 'yes' : 'no'; ?>">
                    <label style="display: block; font-weight: 600; margin-bottom: 10px;">
                        <?php echo esc_html($group_name); ?>:
                    </label>
                    
                    <select class="ps-config-select <?php echo $is_first_group ? 'ps-first-group-select' : ''; ?>" name="ps_config_<?php echo $idx; ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value=""><?php _e('Select an option...', 'product-scraper'); ?></option>
                        <?php foreach ($group['options'] as $opt) : 
                            $option_name = isset($opt['name']) ? $opt['name'] : '';
                            $option_code = isset($opt['code']) ? $opt['code'] : '';
                            $option_id = isset($opt['id']) ? $opt['id'] : '';
                            
                            // Get thumbnail URL
                            $thumbnail_url = '';
                            if ($is_finish_group) {
                                $thumbnail_url = ps_get_finish_thumbnail_url($option_name);
                                if (empty($thumbnail_url) && isset($opt['image'])) {
                                    $thumbnail_url = $opt['image'];
                                }
                            } else {
                                // $thumbnail_url = isset($opt['image']) ? $opt['image'] : '';
                                $thumbnail_url = (isset($opt['image']) && !empty($opt['image'])) ? $opt['image'] : PS_PLACEHOLDER_IMAGE;

                            }
                            
                            $product_image = ($is_first_group && isset($opt['image'])) ? $opt['image'] : '';
                        ?>
                            <option value="<?php echo esc_attr($option_code); ?>" 
                                    data-name="<?php echo esc_attr($option_name); ?>"
                                    data-image="<?php echo esc_url($product_image); ?>"
                                    data-id="<?php echo esc_attr($option_id); ?>"
                                    data-thumbnail="<?php echo esc_url($thumbnail_url); ?>">
                                <?php echo esc_html($option_name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            
            <input type="hidden" name="ps_selected_options" id="ps_selected_options" value="">
        </div>
        
        <div id="ps-selected-summary" style="margin-top: 15px; padding: 10px; background: #e9ecef; border-radius: 6px; display: none;">
            <strong><?php _e('Selected:', 'product-scraper'); ?></strong> <span id="ps-summary-text"></span>
        </div>
    </div>
    <?php
}
function ps_add_configurator_swatches_old() {
    global $product;
    $groups = get_post_meta($product->get_id(), '_ps_configurator_groups', true);
    $build_id = get_post_meta($product->get_id(), '_ps_build_id', true);
    $slug = get_post_meta($product->get_id(), '_ps_product_slug', true);
    if (empty($groups)) return;
    ?>
    <div class="ps-configurator-swatches"  data-product-id="<?php echo esc_attr($product->get_id()); ?>" data-build-id="<?php echo esc_attr($build_id); ?>" data-product-slug="<?php echo esc_attr($slug); ?>">
        <?php foreach ($groups as $idx => $group) : ?>
            <div class="ps-configurator-group" data-group-index="<?php echo $idx; ?>" data-group-name="<?php echo esc_attr($group['display_name']); ?>">
                <span class="ps-group-label"><?php echo esc_html($group['display_name']); ?>:</span>
                <div class="ps-swatch-list">
                    <?php foreach ($group['options'] as $opt) : ?>
                        <div class="ps-swatch" data-option-id="<?php echo esc_attr($opt['code']); ?>" data-option-name="<?php echo esc_attr($opt['name']); ?>" data-image-url="<?php echo esc_url($opt['image']); ?>">
                            <?php if (!empty($opt['image'])) : ?>
                                <img src="<?php echo esc_url($opt['image']); ?>" class="ps-swatch-image">
                            <?php endif; ?>
                            <span class="ps-swatch-label"><?php echo esc_html($opt['name']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <input type="hidden" name="ps_selected_options" id="ps_selected_options" value="">
    </div>
    <?php
}

function ps_ajax_get_configurator_image() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ps_configurator_nonce')) wp_send_json_error('Invalid nonce');
    $product_id = intval($_POST['product_id']);
    $selected = json_decode(stripslashes($_POST['selected_options']), true);
    if (!$product_id) wp_send_json_error('Invalid product ID');
    $build_id = get_post_meta($product_id, '_ps_build_id', true);
    $slug = get_post_meta($product_id, '_ps_product_slug', true);
    if (empty($build_id) || empty($slug)) wp_send_json_error('Missing build ID or slug');
    $data_url = "https://www.emtek.com/_next/data/{$build_id}/en/all-products/{$slug}.json";
    $response = wp_remote_get($data_url, array('timeout' => 30, 'headers' => array('User-Agent' => 'Mozilla/5.0')));
    if (is_wp_error($response)) wp_send_json_error($response->get_error_message());
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $image_url = '';
    if (isset($data['props']['pageProps']['wagtail']['attribute'])) {
        $attr = $data['props']['pageProps']['wagtail']['attribute'];
        if (!empty($attr['finish_images']) && is_array($attr['finish_images'])) {
            foreach ($selected as $val) {
                foreach ($attr['finish_images'] as $id => $url) {
                    if (strpos(strtolower($id), strtolower($val)) !== false) { $image_url = $url; break 2; }
                }
            }
        }
        if (empty($image_url) && !empty($attr['configurator'])) {
            foreach ($attr['configurator'] as $g) {
                if (!empty($g['options'])) {
                    foreach ($g['options'] as $opt) {
                        if (in_array($opt['option'], $selected) && !empty($opt['primary_image']['image'])) {
                            $image_url = $opt['primary_image']['image']; break 2;
                        }
                    }
                }
            }
        }
        if (empty($image_url) && !empty($attr['primary_image']['image'])) $image_url = $attr['primary_image']['image'];
    }
    if ($image_url) {
        wp_send_json_success(array('image_url' => $image_url));
    } else {
        wp_send_json_success(array('image_url' => ''));
    }
}

function ps_get_configurator_image_callback($request) {
    $slug = $request->get_param('slug');
    $build_id = $request->get_param('build_id');
    if (empty($slug) || empty($build_id)) return new WP_Error('missing_params', 'Missing params', array('status' => 400));
    $data_url = "https://www.emtek.com/_next/data/{$build_id}/en/all-products/{$slug}.json";
    $response = wp_remote_get($data_url, array('timeout' => 30, 'headers' => array('User-Agent' => 'Mozilla/5.0')));
    if (is_wp_error($response)) return new WP_Error('fetch_failed', $response->get_error_message(), array('status' => 500));
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $attr = $data['props']['pageProps']['wagtail']['attribute'] ?? array();
    $image = $attr['primary_image']['image'] ?? '';
    return array('success' => true, 'image_url' => $image);
}

// ==================== BULK IMPORT AJAX ====================
function ps_ajax_bulk_import_urls() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ps_bulk_import')) {
        wp_send_json_error('Invalid nonce');
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $urls_raw = isset($_POST['urls']) ? $_POST['urls'] : '';
    $urls = array_filter(array_map('trim', explode("\n", $urls_raw)));
    $urls = array_slice($urls, 0, 10);
    $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'draft';
    
    $results = array();
    foreach ($urls as $url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $results[] = array('url' => $url, 'success' => false, 'message' => 'Invalid URL');
            continue;
        }
        
        $api_url = home_url('/wp-json/custom/v1/product/?url=' . urlencode($url));
        $resp = wp_remote_get($api_url, array('timeout' => 120));
        
        if (is_wp_error($resp)) {
            $results[] = array('url' => $url, 'success' => false, 'message' => $resp->get_error_message());
            continue;
        }
        
        $body = wp_remote_retrieve_body($resp);
        
        // Remove any unexpected characters (like BOM) that break JSON
        if (substr($body, 0, 3) == "\xEF\xBB\xBF") {
            $body = substr($body, 3);
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JSON decode error for URL ' . $url . ': ' . json_last_error_msg());
            error_log('Response preview: ' . substr($body, 0, 500));
            $results[] = array('url' => $url, 'success' => false, 'message' => 'Invalid JSON response: ' . json_last_error_msg());
            continue;
        }
        
        if (!isset($data['success']) || $data['success'] !== true) {
            $results[] = array('url' => $url, 'success' => false, 'message' => 'API returned error');
            continue;
        }
        
        if (empty($data['product']) || empty($data['product']['title'])) {
            $results[] = array('url' => $url, 'success' => false, 'message' => 'Not a valid product URL');
            continue;
        }
        
        // $import = ps_import_product_from_data($data['product'], $status);
        $import = ps_import_product_from_data($data['product'], $status, true); // true = update existing

        $results[] = array(
            'url' => $url,
            'success' => $import['success'],
            'message' => $import['message'],
            'product_id' => $import['success'] ? $import['product_id'] : null
        );
        
        usleep(500000);
    }
    
    wp_send_json_success(array('results' => $results));
}

// ==================== ADMIN MENU ====================

function ps_add_admin_menu() {
    add_menu_page('Product Scraper', 'Product Scraper', 'manage_options', 'product-scraper', 'ps_admin_page', 'dashicons-download', 30);
}

function ps_admin_page() {
    if (isset($_GET['imported']) && isset($_GET['product_id'])) {
        $edit = get_edit_post_link(intval($_GET['product_id']));
        echo '<div class="notice notice-success"><p>Product imported! <a href="' . esc_url($edit) . '">Edit Product</a></p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Product Scraper v5.1</h1>
        <div class="notice notice-info"><p><strong>How it works:</strong> Only the FIRST configurator group creates variations. Original image URLs are stored (no S3 upload).</p></div>
        
        <div class="card" style="max-width:1000px;">
            <h2>Import Single Product</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ps_scrape_action', 'ps_nonce'); ?>
                <input type="hidden" name="action" value="ps_scrape_and_import">
                <table class="form-table">
                    <tr><th><label for="product-url">Product URL</label></th><td><input type="url" id="product-url" name="product_url" class="regular-text" style="width:100%;" required></td></tr>
                    <tr><th><label for="product-status">Product Status</label></th><td><select id="product-status" name="product_status"><option value="publish">Published</option><option value="draft" selected>Draft</option></select></td></tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary">Scrape & Import</button></p>
            </form>
        </div>

        <div class="card" style="max-width:1000px; margin-top:20px;">
            <h2>Bulk Import (up to 10 URLs)</h2>
            <p>Paste one product URL per line.</p>
            <textarea id="bulk-urls" rows="10" style="width:100%;" placeholder="https://www.emtek.com/all-products/...&#10;https://www.emtek.com/all-products/..."></textarea>
            <select id="bulk-status" style="margin-top:10px;">
                <option value="draft">Draft</option>
                <option value="publish">Published</option>
            </select>
            <button id="bulk-import-btn" class="button button-primary" style="margin-left:10px;">Start Bulk Import</button>
            <div id="bulk-log" class="ps-bulk-log"></div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($){
        $('#bulk-import-btn').click(function(){
            var urls = $('#bulk-urls').val();
            if (!urls.trim()) {
                alert('Please enter at least one URL.');
                return;
            }
            var status = $('#bulk-status').val();
            $('#bulk-import-btn').prop('disabled', true).text('Importing...');
            $('#bulk-log').show().html('<div>Starting bulk import...</div>');
            
            $.ajax({
                // url: ajaxurl,
                 url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'ps_bulk_import_urls',
                    nonce: '<?php echo wp_create_nonce('ps_bulk_import'); ?>',
                    urls: urls,
                    status: status
                },
                dataType: 'json',
                success: function(response){
                    if (response.success && response.data.results) {
                        var html = '<div><strong>Import completed</strong></div>';
                        $.each(response.data.results, function(i, r){
                            var color = r.success ? 'green' : 'red';
                            html += '<div style="color:'+color+'; margin-top:5px;">';
                            html += '<strong>' + (i+1) + '.</strong> ' + r.url + '<br>';
                            html += '&nbsp;&nbsp;→ ' + r.message;
                            if (r.product_id) html += ' (ID: ' + r.product_id + ')';
                            html += '</div>';
                        });
                        $('#bulk-log').html(html);
                    } else {
                        $('#bulk-log').html('<div style="color:red;">Error: ' + (response.data.message || 'Unknown error') + '</div>');
                    }
                },
                error: function(xhr) { 
                    $('#bulk-log').html('<div style="color:red;">AJAX error – please check the console.</div>');
                    console.log('Error:', xhr.status, xhr.responseText); 
                },
                complete: function(){
                    $('#bulk-import-btn').prop('disabled', false).text('Start Bulk Import');
                }
            });
        });
    });
    </script>
    <?php
}

function ps_handle_scrape_and_import() {
    check_admin_referer('ps_scrape_action', 'ps_nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $url = isset($_POST['product_url']) ? esc_url_raw($_POST['product_url']) : '';
    $status = isset($_POST['product_status']) ? sanitize_text_field($_POST['product_status']) : 'draft';
    if (empty($url)) wp_die('Product URL required');
    $api = home_url('/wp-json/custom/v1/product/?url=' . urlencode($url));
    $resp = wp_remote_get($api, array('timeout' => 120));
    if (is_wp_error($resp)) wp_die('Error: ' . $resp->get_error_message());
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (!isset($data['success']) || !$data['success']) wp_die('Failed to fetch product');
    $result = ps_import_product_from_data($data['product'], $status);
    if ($result['success']) {
        wp_redirect(add_query_arg(array('page' => 'product-scraper', 'imported' => '1', 'product_id' => $result['product_id']), admin_url('admin.php')));
        exit;
    } else {
        wp_die('Import failed: ' . $result['message']);
    }
}

// Ensure variation custom fields are visible
add_action('admin_footer', function() {
    global $post_type;
    if ($post_type !== 'product') return;
    ?>
    <script>
    jQuery(document).ready(function($){
        setTimeout(function(){ $('.ps-variation-fields').show(); }, 500);
        $(document).on('woocommerce_variations_loaded', function(){ $('.ps-variation-fields').show(); });
        $(document).on('woocommerce_variation_added', function(){ setTimeout(function(){ $('.ps-variation-fields').show(); }, 200); });
    });
    </script>
    <?php
});


// ==================== FINISH THUMBNAILS MANAGER ====================

/**
 * Get finish thumbnail URL from saved options
 */
function ps_get_finish_thumbnail_url($finish_name) {
    $saved_thumbnails = get_option('ps_custom_finish_thumbnails', array());
    
    if (isset($saved_thumbnails[$finish_name]) && !empty($saved_thumbnails[$finish_name])) {
        return $saved_thumbnails[$finish_name];
    }
    
    $extra_finishes = get_option('ps_extra_finishes', array());
    foreach ($extra_finishes as $extra) {
        if ($extra['name'] === $finish_name) {
            return $extra['url'];
        }
    }
    
    return '';
}

/**
 * Add submenu page for Finish Thumbnails Manager
 */
add_action('admin_menu', 'ps_add_finish_thumbnails_submenu');

function ps_add_finish_thumbnails_submenu() {
    add_submenu_page(
        'product-scraper',
        __('Finish Thumbnails', 'product-scraper'),
        __('Finish Thumbnails', 'product-scraper'),
        'manage_options',
        'ps-finish-thumbnails',
        'ps_render_finish_thumbnails_page'
    );
}

/**
 * Render Finish Thumbnails Manager Page
 */
function ps_render_finish_thumbnails_page() {
    if (isset($_POST['ps_save_thumbnails']) && check_admin_referer('ps_save_thumbnails')) {
        $thumbnails = array();
        $finish_names = $_POST['finish_name'];
        $image_urls = $_POST['image_url'];
        
        for ($i = 0; $i < count($finish_names); $i++) {
            if (!empty($finish_names[$i]) && !empty($image_urls[$i])) {
                $thumbnails[$finish_names[$i]] = esc_url_raw($image_urls[$i]);
            }
        }
        update_option('ps_custom_finish_thumbnails', $thumbnails);
        
        if (isset($_POST['extra_finish_name'])) {
            $extra_finishes = array();
            $extra_names = $_POST['extra_finish_name'];
            $extra_urls = $_POST['extra_finish_url'];
            
            for ($i = 0; $i < count($extra_names); $i++) {
                if (!empty($extra_names[$i]) && !empty($extra_urls[$i])) {
                    $extra_finishes[] = array(
                        'name' => sanitize_text_field($extra_names[$i]),
                        'url' => esc_url_raw($extra_urls[$i]),
                    );
                }
            }
            update_option('ps_extra_finishes', $extra_finishes);
        }
        
        echo '<div class="notice notice-success"><p>' . __('Thumbnails saved successfully!', 'product-scraper') . '</p></div>';
    }
    
    $saved_thumbnails = get_option('ps_custom_finish_thumbnails', array());
    $extra_finishes = get_option('ps_extra_finishes', array());
    
    $default_finishes = array(
        'Satin Nickel', 'French Antique', 'Polished Chrome', 'Polished Nickel',
        'Tumbled White Bronze', 'Satin Brass', 'Medium Bronze', 'Pewter',
        'Polished Brass', 'Oil Rubbed Bronze', 'Unlacquered Brass', 'Flat Black',
    );
    ?>
    <div class="wrap">
        <h1><?php _e('Finish Thumbnails Manager', 'product-scraper'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('ps_save_thumbnails'); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr><th width="30%">Finish Name</th><th width="50%">Thumbnail Image URL</th><th width="20%">Preview</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($default_finishes as $finish) : 
                        $image_url = isset($saved_thumbnails[$finish]) ? $saved_thumbnails[$finish] : '';
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($finish); ?></strong><input type="hidden" name="finish_name[]" value="<?php echo esc_attr($finish); ?>"></td>
                            <td><input type="url" name="image_url[]" value="<?php echo esc_url($image_url); ?>" style="width:100%; padding:8px;"></td>
                            <td><?php if ($image_url) : ?><img src="<?php echo esc_url($image_url); ?>" style="max-width:50px; max-height:50px; border-radius:4px;"><?php else: ?>—<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="margin-top:20px;">
                <h3><?php _e('Additional Finishes', 'product-scraper'); ?></h3>
                <div id="ps-extra-finishes-container">
                    <?php foreach ($extra_finishes as $extra) : ?>
                        <div class="ps-extra-finish-row" style="margin-bottom:10px; display:flex; gap:10px;">
                            <input type="text" name="extra_finish_name[]" value="<?php echo esc_attr($extra['name']); ?>" placeholder="Finish Name" style="flex:1; padding:8px;">
                            <input type="url" name="extra_finish_url[]" value="<?php echo esc_url($extra['url']); ?>" placeholder="Image URL" style="flex:2; padding:8px;">
                            <button type="button" class="button remove-extra-finish">Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="ps-add-extra-finish" class="button">+ Add Another Finish</button>
            </div>
            
            <p class="submit"><input type="submit" name="ps_save_thumbnails" class="button button-primary" value="<?php _e('Save Thumbnails', 'product-scraper'); ?>"></p>
        </form>
    </div>
    <script>
    jQuery(document).ready(function($){
        $('#ps-add-extra-finish').click(function(){
            $('#ps-extra-finishes-container').append('<div class="ps-extra-finish-row" style="margin-bottom:10px; display:flex; gap:10px;"><input type="text" name="extra_finish_name[]" placeholder="Finish Name" style="flex:1; padding:8px;"><input type="url" name="extra_finish_url[]" placeholder="Image URL" style="flex:2; padding:8px;"><button type="button" class="button remove-extra-finish">Remove</button></div>');
        });
        $(document).on('click', '.remove-extra-finish', function(){ $(this).closest('.ps-extra-finish-row').remove(); });
    });
    </script>
    <?php
}


add_action('wp_footer', function() {
    if (!is_product()) return;
    global $product;
    $groups = get_post_meta($product->get_id(), '_ps_configurator_groups', true);
    if (empty($groups)) return;
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        var selectedOptions = {};
        
        // Initialize Select2 for each configurator select with image support
        $('.ps-config-select').each(function() {
            var $select = $(this);
            
            $select.select2({
                placeholder: "Select an option...",
                width: '100%',
                theme: 'classic',
                templateResult: formatOptionWithThumbnail,
                templateSelection: formatSelectionWithThumbnail
            });
        });
        
        // Format dropdown options with thumbnail on left
        function formatOptionWithThumbnail(option) {
            if (!option.id || option.id === '') {
                return option.text;
            }
            
            var $option = $(option.element);
            var thumbnailUrl = $option.data('thumbnail');
            var optionText = option.text;
            
            if (thumbnailUrl && thumbnailUrl !== '') {
                var $container = $('<div class="ps-option-with-image">');
                var $img = $('<img>', {
                    src: thumbnailUrl,
                    class: 'ps-option-thumb',
                    onerror: function() { 
                        // $(this).hide(); 
                    }
                });
                var $text = $('<span class="ps-option-text">').text(optionText);
                
                $container.append($img);
                $container.append($text);
                
                return $container;
            }
            
            return option.text;
        }
        
        // Format selected value with thumbnail
        function formatSelectionWithThumbnail(option) {
            if (!option.id || option.id === '') {
                return option.text;
            }
            
            var $option = $(option.element);
            var thumbnailUrl = $option.data('thumbnail');
            var optionText = option.text;
            
            if (thumbnailUrl && thumbnailUrl !== '') {
                return $('<span><img src="' + thumbnailUrl + '" class="ps-selected-image"> ' + optionText + '</span>');
            }
            
            return optionText;
        }
        
        // Handle ONLY the first configurator group select change (image update)
        $(document).on('change', '.ps-first-group-select', function() {
            var $select = $(this);
            var selectedOption = $select.find('option:selected');
            var optionName = selectedOption.data('name');
            var optionCode = selectedOption.val();
            var optionImage = selectedOption.data('image');
            var optionId = selectedOption.data('id');
            
            if (optionCode && optionCode !== '') {
                // Update selected options for summary
                selectedOptions = {};
                $('.ps-config-select').each(function() {
                    var $thisSelect = $(this);
                    var $thisGroup = $thisSelect.closest('.ps-configurator-group');
                    var thisGroupName = $thisGroup.data('group-name');
                    var thisSelected = $thisSelect.find('option:selected');
                    var thisOptionName = thisSelected.data('name');
                    if (thisOptionName) {
                        selectedOptions[thisGroupName] = thisOptionName;
                    }
                });
                
                // Update hidden field and summary
                $('#ps_selected_options').val(JSON.stringify(selectedOptions));
                updateSelectedSummary(selectedOptions);
                
                // Update product image based on first group selection
                if (optionImage && optionImage !== '') {
                    console.log('Updating product image for:', optionName, optionImage);
                    updateProductImage(optionImage);
                }
            }
        });
        
        // Handle other groups (just update summary, not image)
        $(document).on('change', '.ps-config-select:not(.ps-first-group-select)', function() {
            selectedOptions = {};
            $('.ps-config-select').each(function() {
                var $thisSelect = $(this);
                var $thisGroup = $thisSelect.closest('.ps-configurator-group');
                var thisGroupName = $thisGroup.data('group-name');
                var thisSelected = $thisSelect.find('option:selected');
                var thisOptionName = thisSelected.data('name');
                if (thisOptionName) {
                    selectedOptions[thisGroupName] = thisOptionName;
                }
            });
            
            $('#ps_selected_options').val(JSON.stringify(selectedOptions));
            updateSelectedSummary(selectedOptions);
        });
        
        function updateSelectedSummary(selected) {
            var summaryText = '';
            var count = 0;
            for (var key in selected) {
                if (selected.hasOwnProperty(key) && selected[key]) {
                    if (count > 0) summaryText += ' | ';
                    summaryText += key + ': ' + selected[key];
                    count++;
                }
            }
            
            if (count > 0) {
                // $('#ps-selected-summary').fadeIn();
                // $('#ps-summary-text').html(summaryText);
            } else {
                // $('#ps-selected-summary').fadeOut();
            }
        }
        
        function updateProductImage(imageUrl) {
            if (!imageUrl) return;
            
            console.log('Updating product image to:', imageUrl);
            
            var $mainImage = $('.woocommerce-product-gallery__image:first');
            var $mainImg = $mainImage.find('img');
            
            if ($mainImg.length) {
                $mainImg.css('opacity', '0.3');
                setTimeout(function() {
                    $mainImg.attr('src', imageUrl);
                    $mainImg.attr('srcset', imageUrl + ' 800w');
                    $mainImg.css('opacity', '1');
                    
                    if ($mainImage.find('a').length) {
                        $mainImage.find('a').attr('href', imageUrl);
                        $mainImage.find('a').attr('data-large-image', imageUrl);
                    }
                    
                    $(document.body).trigger('wc_product_gallery_reset');
                }, 200);
            }
            
            var $firstThumb = $('.flex-control-nav li:first img');
            if ($firstThumb.length) {
                setTimeout(function() {
                    $firstThumb.attr('src', imageUrl);
                }, 250);
            }
            
            $('.woocommerce-product-gallery').addClass('ps-image-flash');
            setTimeout(function() {
                $('.woocommerce-product-gallery').removeClass('ps-image-flash');
            }, 500);
        }
        
        console.log('Configurator initialized - Thumbnails shown in dropdown');
    });
    </script>
    <?php
});