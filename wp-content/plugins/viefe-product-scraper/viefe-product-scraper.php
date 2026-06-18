<?php
/**
 * Plugin Name: Viefe Product Scraper
 * Plugin URI: https://www.viefe.com
 * Description: Scrape products from Viefe.com via external API and import directly into WooCommerce with batch processing
 * Version: 2.5.0
 * Author: Your Name
 * License: GPL-2.0+
 * Text Domain: viefe-scraper
 * WC requires at least: 5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VIEFE_SCRAPER_PATH', plugin_dir_path(__FILE__));
define('VIEFE_API_BASE', 'https://610weblab.in/scraper/viefe-products.php');


// Include Simple HTML DOM
if (file_exists(VIEFE_SCRAPER_PATH . 'fetch-finishes.php')) {
    require_once VIEFE_SCRAPER_PATH . 'fetch-finishes.php';
}


class Viefe_Product_Scraper {

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_api_routes'));
        add_action('admin_menu', array($this, 'add_admin_menu'), 99);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_filter('woocommerce_product_tabs', array($this, 'add_custom_product_tabs'), 20);

        // add_action('woocommerce_before_add_to_cart_button', array($this, 'display_finish_swatches'), 5);


    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'viefe-scraper') === false) return;

        wp_enqueue_script('viefe-scraper-js', plugin_dir_url(__FILE__) . 'scraper.js', array('jquery'), '2.5', true);
        wp_localize_script('viefe-scraper-js', 'viefe_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('viefe_scrape_ajax'),
        ));
    }


    /**
     * Debug a single product to see why category isn't saving
     */
    public function debug_product_category($product_id) {
        $product = wc_get_product($product_id);
        $sku = $product->get_sku();
        $current_categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
        $all_terms = wp_get_post_terms($product_id, 'product_cat');
        
        echo '<div style="background: #f0f0f1; padding: 15px; margin: 10px 0;">';
        echo '<h4>Debug Product: ' . $product->get_name() . ' (SKU: ' . $sku . ')</h4>';
        echo '<p>Current Categories: ' . (empty($current_categories) ? 'EMPTY - Uncategorized' : implode(', ', $current_categories)) . '</p>';
        echo '<p>All Terms: <pre>' . print_r($all_terms, true) . '</pre></p>';
        
        // Check if "Handles" category exists in system
        $handles_term = term_exists('Handles', 'product_cat');
        echo '<p>"Handles" category exists: ' . ($handles_term ? 'Yes (ID: ' . $handles_term['term_id'] . ')' : 'No') . '</p>';
        
        echo '</div>';
    }


    /**
     * Debug method to check API response structure
     */
    public function debug_api_response($url) {
        $result = $this->fetch_products($url);
        
        echo '<pre>';
        echo '=== API DEBUG INFO ===' . "\n\n";
        
        echo '1. RESPONSE STRUCTURE:' . "\n";
        echo 'Has error: ' . (isset($result['error']) ? $result['error'] : 'No') . "\n";
        echo 'Products count: ' . (isset($result['products']) ? count($result['products']) : 0) . "\n";
        echo 'Category name: ' . ($result['category_name'] ?? 'NOT SET') . "\n";
        echo 'Total products: ' . ($result['total_products'] ?? 0) . "\n\n";
        
        if (!empty($result['products'])) {
            echo '2. FIRST PRODUCT DATA:' . "\n";
            $first_product = $result['products'][0];
            echo 'Title: ' . ($first_product['title'] ?? 'NOT SET') . "\n";
            echo 'SKU: ' . ($first_product['sku'] ?? 'NOT SET') . "\n";
            echo 'Category: ' . ($first_product['category'] ?? 'NOT SET') . "\n";
            echo 'Product ID: ' . ($first_product['product_id'] ?? 'NOT SET') . "\n";
            echo 'Has variations: ' . (count($first_product['variations'] ?? []) > 0 ? 'Yes' : 'No') . "\n";
            echo 'Finishes count: ' . count($first_product['finishes'] ?? []) . "\n\n";
            
            echo '3. ALL PRODUCT KEYS:' . "\n";
            echo print_r(array_keys($first_product), true) . "\n\n";
            
            echo '4. CATEGORY VALUES FROM FIRST 5 PRODUCTS:' . "\n";
            for ($i = 0; $i < min(5, count($result['products'])); $i++) {
                echo 'Product ' . ($i+1) . ': ' . ($result['products'][$i]['title'] ?? 'Unknown') . ' - Category: ' . ($result['products'][$i]['category'] ?? 'NOT SET') . "\n";
            }
        } else {
            echo 'No products found in response!' . "\n";
            echo 'Raw result: ' . print_r($result, true) . "\n";
        }
        
        echo '</pre>';
    }


    /**
     * Fetch data from your API endpoint
     */
    private function fetch_from_api($params = array()) {
        $url = add_query_arg($params, VIEFE_API_BASE);
        
        error_log('VIEFE API Request: ' . $url);
        
        $response = wp_remote_get($url, array(
            'timeout' => 45,
            'headers' => array(
                'Accept' => 'application/json',
            )
        ));

        if (is_wp_error($response)) {
            error_log('VIEFE API Error: ' . $response->get_error_message());
            return array('error' => $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('VIEFE JSON Error: ' . json_last_error_msg());
            error_log('Raw response: ' . substr($body, 0, 500));
            return array('error' => 'Invalid JSON response from API');
        }

        return $data;
    }

    /**
     * Main method to fetch products from category URL or single product
     */
    public function fetch_products($url) {
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '';
        
        // Check if it's a single product URL
        $is_single_product = preg_match('/\/([a-z]+-[a-z0-9-]+)$/i', $path) && 
                             !preg_match('/(knobs-and-handles|door-stoppers|wall-hooks|pull-handles|handles)/', $path);
        
        if ($is_single_product) {
            // Fetch single product detail
            $data = $this->fetch_from_api(array('product_url' => $url));
            
            if (isset($data['error'])) {
                return array('error' => $data['error']);
            }
            
            return $this->process_single_product_response($data);
        } else {
            // Fetch category products
            $api_params = array('url' => $url);
            
            // Extract page number from URL if present
            if (preg_match('/[?&]page=(\d+)/', $url, $matches)) {
                $api_params['page'] = intval($matches[1]);
            }
            
            $data = $this->fetch_from_api($api_params);
            
            if (isset($data['error'])) {
                return array('error' => $data['error']);
            }
            
            if (empty($data) || !is_array($data)) {
                return array('error' => 'API returned empty response');
            }
            
            return $this->process_category_response($data);
        }
    }

    /**
     * Process category API response - UPDATED to handle your API structure
     */
    private function process_category_response($data) {
        $products = array();
        $category_name = '';
        
        // Debug: Log what we received
        error_log('=== VIEFE CATEGORY DEBUG ===');
        error_log('Data type: ' . gettype($data));
        error_log('Data keys: ' . print_r(array_keys($data), true));
        
        // YOUR API RETURNS: {"products": [...]}
        if (isset($data['products']) && is_array($data['products'])) {
            $products_data = $data['products'];
            error_log('Products array count: ' . count($products_data));
            
            // Get category from the first product (since all products in same category)
            if (!empty($products_data) && isset($products_data[0]['category'])) {
                $category_name = $products_data[0]['category'];
                error_log('Category found in first product: ' . $category_name);
            } else {
                error_log('No category found in first product');
                if (!empty($products_data)) {
                    error_log('First product keys: ' . print_r(array_keys($products_data[0]), true));
                }
            }
            
            // Process each product
            foreach ($products_data as $product_data) {
                $products[] = $this->normalize_product_from_category($product_data, $category_name);
            }
        }
        // Fallback: If API returns direct array (no wrapper)
        elseif (isset($data[0]) && is_array($data[0])) {
            error_log('Direct array format detected');
            if (!empty($data) && isset($data[0]['category'])) {
                $category_name = $data[0]['category'];
            }
            foreach ($data as $product_data) {
                $products[] = $this->normalize_product_from_category($product_data, $category_name);
            }
        }
        else {
            error_log('Unexpected API response format');
            error_log('Response: ' . substr(print_r($data, true), 0, 500));
        }
        
        error_log('Final category name: ' . $category_name);
        error_log('Final products count: ' . count($products));
        error_log('=== END VIEFE CATEGORY DEBUG ===');
        
        return array(
            'products' => $products,
            'category_name' => $category_name,
            'total_products' => count($products)
        );
    }

    /**
     * Process single product API response
     * Your API returns: {title, sku, description, specifications, gallery, finishes, sizes, drawing}
     */
    private function process_single_product_response($data) {
        $products = array();
        
        if (!empty($data) && isset($data['title'])) {
            $products[] = $this->normalize_product_from_detail($data);
        }
        
        return array(
            'products' => $products,
            'category_name' => '',
            'total_products' => count($products)
        );
    }

    /**
     * Normalize product data from category API response
     */
    private function normalize_product_from_category($item, $category_name = '') {
        // FIX: Ensure SKU is properly handled
        $sku = $item['sku'] ?? '';
        $product_id = $item['product_id'] ?? '';
        
        // If SKU is empty, create from product_id (for DINO: product_id=222 -> VIEFE-222)
        if (empty($sku) && !empty($product_id)) {
            $sku = 'VIEFE-' . $product_id;
            error_log('VIEFE: Generated SKU for ' . ($item['title'] ?? 'Unknown') . ': ' . $sku . ' (from product_id: ' . $product_id . ')');
        } elseif (empty($sku)) {
            // Last resort: generate from title
            $title = $item['title'] ?? 'Product';
            $sku = 'VIEFE-' . sanitize_title($title);
            error_log('VIEFE: Generated SKU from title: ' . $sku);
        }
        
        // Process variations
        $variations = array();
        if (isset($item['variations']) && is_array($item['variations'])) {
            foreach ($item['variations'] as $variation) {
                $variations[] = array(
                    'sku' => $variation['sku'] ?? '',
                    'price' => (float)($variation['alternative_price'] ?? $variation['price'] ?? 0),
                    'stock' => (int)($variation['stock'] ?? 0),
                    'large_image' => $variation['large_image'] ?? '',  // Keep this!
                    'small_image' => $variation['small_image'] ?? '',  // Keep this!
                    'image' => $variation['large_image'] ?? $variation['small_image'] ?? '',  // For compatibility
                    'variation_id' => $variation['variation_id'] ?? '',
                    'option_value_id' => $variation['option_value_id'] ?? '',
                    'ean' => $variation['ean'] ?? '',
                );
            }
        }
        
        // Process finishes
        $finishes = array();
        if (isset($item['finishes']) && is_array($item['finishes'])) {
            foreach ($item['finishes'] as $finish) {
                $finishes[] = array(
                    'name' => $finish['title'] ?? '',
                    'image_url' => $finish['image'] ?? '',
                );
            }
        }

        
        
        // Use category from item if available
        $product_category = !empty($item['category']) ? $item['category'] : $category_name;
        
        return array(
            'title' => $item['title'] ?? '',
            'url' => $item['url'] ?? '',
            'image' => $item['image'] ?? '',
            'product_id' => $product_id,
            'sku' => $sku,  // ← Now properly set
            'brand' => $item['brand'] ?? 'Viefe Studio',
            'price' => (float)($item['price'] ?? 0),
            'stock' => (int)($item['stock'] ?? 0),
            'description' => '',
            'specifications' => array(),
            'gallery' => array(),
            'finishes' => $finishes,
            'sizes' => array(),
            'drawing' => '',
            'variations' => $variations,
            'category' => $product_category,
        );
    }

    /**
     * Normalize product data from detail API response
     */
    private function normalize_product_from_detail($data) {
        // Process finishes
        $finishes = array();
        if (isset($data['finishes']) && is_array($data['finishes'])) {
            foreach ($data['finishes'] as $finish) {
                $finishes[] = array(
                    'name' => $finish['name'] ?? $finish['title'] ?? '',
                    'value' => $finish['value'] ?? '',
                    'image_url' => $finish['image'] ?? '',
                );
            }
        }
        
        // Process sizes
        $sizes = array();
        if (isset($data['sizes']) && is_array($data['sizes'])) {
            foreach ($data['sizes'] as $size) {
                $sizes[] = array(
                    'value' => $size['value'] ?? '',
                    'label' => $size['label'] ?? '',
                );
            }
        }
        
        // Process gallery
        $gallery = array();
        if (isset($data['gallery']) && is_array($data['gallery'])) {
            foreach ($data['gallery'] as $image) {
                if (is_array($image) && isset($image['url'])) {
                    $gallery[] = $image['url'];
                } elseif (is_string($image)) {
                    $gallery[] = $image;
                }
            }
        }
        
        return array(
            'title' => $data['title'] ?? '',
            'url' => $data['url'] ?? '',
            'image' => $data['image'] ?? ($gallery[0] ?? ''),
            'sku' => $data['sku'] ?? '',
            'brand' => $data['brand'] ?? 'Viefe Studio',
            'price' => (float)($data['price'] ?? 0),
            'stock' => (int)($data['stock'] ?? 0),
            'description' => $data['description'] ?? '',
            'specifications' => $data['specifications'] ?? array(),
            'gallery' => $gallery,
            'finishes' => $finishes,
            'sizes' => $sizes,
            'drawing' => $data['drawing'] ?? '',
            'variations' => array(),
            'category' => '',
        );
    }

    /**
     * Create or update a single WooCommerce product
     */
    public function create_or_update_product($product_data, $update_existing, $import_images, $publish) {
        // Handle SKU
        $original_sku = $product_data['sku'] ?? '';
        $product_id_from_api = $product_data['product_id'] ?? '';
        $category_name = $product_data['category'] ?? 'Handles';
        
        // Generate possible SKUs for matching
        $possible_skus = array();
        
        // Add original SKU if exists
        if (!empty($original_sku)) {
            $possible_skus[] = $original_sku;
        }
        
        // Add generated SKU from product_id
        if (!empty($product_id_from_api)) {
            $possible_skus[] = 'VIEFE-' . $product_id_from_api;
        }
        
        // Add the product_id as string (if numeric, e.g., "6005" for FONDA)
        if (!empty($product_id_from_api) && is_numeric($product_id_from_api)) {
            $possible_skus[] = (string) $product_id_from_api;
        }
        
        // Generate SKU from title as last resort
        $title_sku = 'VIEFE-' . sanitize_title($product_data['title'] ?? 'Product');
        $possible_skus[] = $title_sku;
        
        // Remove duplicates
        $possible_skus = array_unique($possible_skus);
        
        error_log('VIEFE: Possible SKUs for matching: ' . print_r($possible_skus, true));
        
        // Check if product exists with ANY of the possible SKUs
        $existing_product_id = null;
        $matched_sku = null;
        
        foreach ($possible_skus as $check_sku) {
            if (!empty($check_sku)) {
                $found_id = wc_get_product_id_by_sku($check_sku);
                if ($found_id) {
                    $existing_product_id = $found_id;
                    $matched_sku = $check_sku;
                    error_log('VIEFE: Found existing product with SKU: ' . $check_sku);
                    break;
                }
            }
        }
        
        // Determine the final SKU to use for this product
        $final_sku = $original_sku;
        if (empty($final_sku) && !empty($product_id_from_api)) {
            $final_sku = 'VIEFE-' . $product_id_from_api;
        } elseif (empty($final_sku)) {
            $final_sku = $title_sku;
        }
        
        error_log('VIEFE: Final SKU for product: ' . $final_sku);
        
        // If product exists AND we are NOT updating existing products, skip it
        if ($existing_product_id && !$update_existing) {
            error_log('VIEFE: SKIPPING product - already exists with SKU: ' . $matched_sku . ' (update_existing is disabled)');
            return 'skipped';
        }
        
        $product_id = $existing_product_id;
        $is_update = false;
        
        // Get or create category
        $category_slug = sanitize_title($category_name);
        $category_term = get_term_by('slug', $category_slug, 'product_cat');
        
        if (!$category_term) {
            $category_id = wp_insert_term($category_name, 'product_cat', array(
                'slug' => $category_slug
            ));
            
            if (!is_wp_error($category_id)) {
                $category_term = get_term_by('id', $category_id['term_id'], 'product_cat');
            }
        }
        
        // Create or update product
        if ($product_id && $update_existing) {
            $product = wc_get_product($product_id);
            $is_update = true;
            
            $product->set_name($product_data['title'] ?? $product->get_name());
            if (!empty($product_data['description'])) {
                $product->set_description($product_data['description']);
            }
            $product->save();
            error_log('VIEFE: UPDATED existing product: ' . $final_sku . ' (matched by: ' . $matched_sku . ')');
            
        } else {
            // Create new product
            $has_variations = !empty($product_data['variations']) || !empty($product_data['finishes']) || !empty($product_data['sizes']);
            
            if ($has_variations) {
                $product = new WC_Product_Variable();
            } else {
                $product = new WC_Product_Simple();
            }

            $product->set_name($product_data['title'] ?? 'Product');
            $product->set_sku($final_sku);
            $product->set_status($publish ? 'publish' : 'draft');
            $product->set_catalog_visibility('visible');
            $product->set_description($product_data['description'] ?? '');
            
            // Build short description
            $short_description = '';

            if (!empty($product_data['specifications']) && is_array($product_data['specifications'])) {
                $specs_parts = array();
                foreach ($product_data['specifications'] as $key => $value) {
                    if (!is_array($value) && !empty($value)) {
                        $specs_parts[] = '<strong>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . ':</strong> ' . esc_html($value);
                    }
                }
                if (!empty($specs_parts)) {
                    $short_description .= '<div class="product-specifications">' . implode(' | ', $specs_parts) . '</div>';
                }
            }
            
            $product->set_short_description($short_description);
            
            if (!$has_variations && isset($product_data['price']) && $product_data['price'] > 0) {
                $product->set_regular_price($product_data['price']);
            }
            
            $product->save();
            $product_id = $product->get_id();

            if (!empty($product_data['brand'])) {
                wp_set_object_terms($product_id, $product_data['brand'], 'product_brand', true);
            }
            
            error_log('VIEFE: CREATED new product: ' . $final_sku);
        }

        if (!$product_id) return 'failed';

        // Assign category
        if ($category_term) {
            wp_set_object_terms($product_id, $category_name, 'product_cat');
            error_log('VIEFE: Assigned category "' . $category_name . '" to product: ' . $final_sku);
        } else {
            wp_set_object_terms($product_id, 'Handles', 'product_cat');
            error_log('VIEFE: Assigned default "Handles" category to product: ' . $final_sku);
        }

        // Save meta data
        if (!empty($product_data['drawing'])) {
            update_post_meta($product_id, 'drawing_image', $product_data['drawing']);
        }
        
        if (!empty($product_data['finishes'])) {
            update_post_meta($product_id, '_viefe_finish_images', $product_data['finishes']);
        }
        
        if (!empty($product_data['url'])) {
            update_post_meta($product_id, '_viefe_original_url', $product_data['url']);
        }
        
        // Save the original SKU from API for reference
        if (!empty($original_sku)) {
            update_post_meta($product_id, '_viefe_api_sku', $original_sku);
        }
        
        // Save the product_id from API
        if (!empty($product_id_from_api)) {
            update_post_meta($product_id, '_viefe_api_product_id', $product_id_from_api);
        }

        // Handle variations
        $has_variations = !empty($product_data['variations']) || !empty($product_data['finishes']) || !empty($product_data['sizes']);
        
        if ($has_variations && $product->is_type('variable')) {
            $this->create_variations($product_id, $product_data, $publish, $import_images);
        }

        // Import images
        if ($import_images) {
            $this->import_images($product_id, $product_data);
        }

        return $is_update ? 'updated' : 'imported';
    }

    /**
     * Create variations for a variable product - STORE IMAGE URLs DIRECTLY (no media upload)
     */

    private function create_variations($parent_id, $product_data, $publish, $import_images) {
        $finishes = $product_data['finishes'] ?? array();
        $sizes = $product_data['sizes'] ?? array();
        $variations = $product_data['variations'] ?? array();
        
        $attributes = array();
        $finish_options = array();
        $size_options = array();
        
        // Collect finish options
        if (!empty($finishes)) {
            foreach ($finishes as $finish) {
                $name = '';
                if (is_array($finish)) {
                    $name = $finish['name'] ?? $finish['title'] ?? '';
                } else {
                    $name = $finish;
                }
                if ($name && !in_array($name, $finish_options)) {
                    $finish_options[] = $name;
                }
            }
            
            if (!empty($finish_options)) {
                $attribute = new WC_Product_Attribute();
                $attribute->set_name('Finish');
                $attribute->set_options($finish_options);
                $attribute->set_visible(true);
                $attribute->set_variation(true);
                $attributes[] = $attribute;
            }
        }
        
        // Collect size options
        if (!empty($sizes)) {
            foreach ($sizes as $size) {
                $label = '';
                if (is_array($size)) {
                    $label = $size['label'] ?? $size['value'] ?? '';
                } else {
                    $label = $size;
                }
                if ($label && !in_array($label, $size_options)) {
                    $size_options[] = $label;
                }
            }
            
            if (!empty($size_options)) {
                $attribute = new WC_Product_Attribute();
                $attribute->set_name('Size');
                $attribute->set_options($size_options);
                $attribute->set_visible(true);
                $attribute->set_variation(true);
                $attributes[] = $attribute;
            }
        }
        
        // Save attributes to parent product
        if (!empty($attributes)) {
            $product = wc_get_product($parent_id);
            $product->set_attributes($attributes);
            $product->save();
        }
        
        // Create variations from explicit variations data
        if (!empty($variations)) {
            foreach ($variations as $index => $variation_data) {
                $var_sku = $variation_data['sku'] ?? '';
                if (empty($var_sku)) continue;
                
                $existing_id = wc_get_product_id_by_sku($var_sku);
                if ($existing_id) {
                    $variation_product = wc_get_product($existing_id);
                    error_log('VIEFE: Using existing variation ID: ' . $existing_id . ' for SKU: ' . $var_sku);
                } else {
                    $variation_product = new WC_Product_Variation();
                    $variation_product->set_parent_id($parent_id);
                    error_log('VIEFE: Creating new variation for SKU: ' . $var_sku);
                }
                
                $variation_product->set_sku($var_sku);
                $variation_product->set_regular_price($variation_data['price'] ?? $product_data['price'] ?? 0);
                
                $var_stock = (int)($variation_data['stock'] ?? 10);
                $variation_product->set_manage_stock(true);
                $variation_product->set_stock_quantity($var_stock);
                $variation_product->set_stock_status($var_stock > 0 ? 'instock' : 'outofstock');
                $variation_product->set_status($publish ? 'publish' : 'private');
                
                // Map variation to finish and size
                $var_attributes = array();
                if (!empty($finish_options)) {
                    $finish_index = $index % count($finish_options);
                    $var_attributes['attribute_finish'] = $finish_options[$finish_index];
                }
                if (!empty($size_options) && count($finish_options) > 0) {
                    $size_index = floor($index / count($finish_options)) % count($size_options);
                    $var_attributes['attribute_size'] = $size_options[$size_index];
                } elseif (!empty($size_options)) {
                    $var_attributes['attribute_size'] = $size_options[$index % count($size_options)];
                }
                
                if (!empty($var_attributes)) {
                    $variation_product->set_attributes($var_attributes);
                }
                
                // ========== CRITICAL: SAVE FIRST to get variation ID ==========
                $variation_product->save();
                $variation_id = $variation_product->get_id();
                error_log('VIEFE: Variation saved with ID: ' . $variation_id . ' for SKU: ' . $var_sku);
                
                // ========== NOW store the image URL meta ==========
                $variation_image = '';
                if (!empty($variation_data['large_image'])) {
                    $variation_image = $variation_data['large_image'];
                } elseif (!empty($variation_data['image'])) {
                    $variation_image = $variation_data['image'];
                } elseif (!empty($variation_data['small_image'])) {
                    $variation_image = $variation_data['small_image'];
                }
                
                if (!empty($variation_image)) {
                    update_post_meta($variation_id, '_ps_finish_image', $variation_image);
                    error_log('VIEFE: ✓ Stored _ps_finish_image for variation ID ' . $variation_id . ': ' . $variation_image);
                    
                    if (!empty($var_attributes['attribute_finish'])) {
                        update_post_meta($variation_id, '_finish_name', $var_attributes['attribute_finish']);
                    }
                } else {
                    error_log('VIEFE: ✗ No image found for variation SKU: ' . $var_sku);
                }
            }
        } else {
            // Generate variations from finishes and sizes (cartesian product)
            // ... existing code for generating variations ...
            if (!empty($finish_options) && !empty($size_options)) {
                foreach ($finish_options as $finish) {
                    foreach ($size_options as $size) {
                        $var_sku = $product_data['sku'] . '-' . sanitize_title($finish) . '-' . sanitize_title($size);
                        $variation_product = new WC_Product_Variation();
                        $variation_product->set_parent_id($parent_id);
                        $variation_product->set_sku($var_sku);
                        $variation_product->set_regular_price($product_data['price'] ?? 0);
                        $variation_product->set_manage_stock(true);
                        $variation_product->set_stock_quantity(10);
                        $variation_product->set_stock_status('instock');
                        $variation_product->set_status($publish ? 'publish' : 'private');
                        $variation_product->set_attributes(array(
                            'attribute_finish' => $finish,
                            'attribute_size' => $size,
                        ));
                        $variation_product->save();
                    }
                }
            } elseif (!empty($finish_options)) {
                foreach ($finish_options as $finish) {
                    $var_sku = $product_data['sku'] . '-' . sanitize_title($finish);
                    $variation_product = new WC_Product_Variation();
                    $variation_product->set_parent_id($parent_id);
                    $variation_product->set_sku($var_sku);
                    $variation_product->set_regular_price($product_data['price'] ?? 0);
                    $variation_product->set_manage_stock(true);
                    $variation_product->set_stock_quantity(10);
                    $variation_product->set_stock_status('instock');
                    $variation_product->set_status($publish ? 'publish' : 'private');
                    $variation_product->set_attributes(array(
                        'attribute_finish' => $finish,
                    ));
                    $variation_product->save();
                }
            } elseif (!empty($size_options)) {
                foreach ($size_options as $size) {
                    $var_sku = $product_data['sku'] . '-' . sanitize_title($size);
                    $variation_product = new WC_Product_Variation();
                    $variation_product->set_parent_id($parent_id);
                    $variation_product->set_sku($var_sku);
                    $variation_product->set_regular_price($product_data['price'] ?? 0);
                    $variation_product->set_manage_stock(true);
                    $variation_product->set_stock_quantity(10);
                    $variation_product->set_stock_status('instock');
                    $variation_product->set_status($publish ? 'publish' : 'private');
                    $variation_product->set_attributes(array(
                        'attribute_size' => $size,
                    ));
                    $variation_product->save();
                }
            }
        }
    }
    /**
     * Import product images - STORE URLS DIRECTLY (no media upload)
     */
    private function import_images($product_id, $product_data) {
        $product = wc_get_product($product_id);
        
        // Main featured image - store URL directly
        if (!empty($product_data['image'])) {
            update_post_meta($product_id, '_ps_product_main_image', $product_data['image']);
            
            // Also set as WooCommerce product image URL meta
            update_post_meta($product_id, '_product_image_url', $product_data['image']);
        }
        
        // Gallery images - store URLs directly
        if (!empty($product_data['gallery']) && is_array($product_data['gallery'])) {
            $gallery_urls = array();
            foreach ($product_data['gallery'] as $img_url) {
                if ($img_url) {
                    $gallery_urls[] = $img_url;
                }
            }
            if (!empty($gallery_urls)) {
                update_post_meta($product_id, '_ps_product_gallery_images', implode("\n", $gallery_urls));
            }
        }
        
        // Note: Don't call $product->save() here since we're not setting image IDs
    }

    /**
     * Upload image from URL
     */
    private function upload_image($image_url) {
        if (empty($image_url)) return false;
        
        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id) return $attachment_id;
        
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $tmp = download_url($image_url, 30);
        if (is_wp_error($tmp)) return false;
        
        $file_array = array(
            'name' => sanitize_file_name(basename($image_url)),
            'tmp_name' => $tmp
        );
        
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return false;
        }
        
        return $attachment_id;
    }

    // REST API endpoints
    public function register_api_routes() {
        register_rest_route('viefe/v1', '/scrape', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_scrape_callback'),
            'permission_callback' => '__return_true',
        ));
    }
    
    public function api_scrape_callback($request) {
        $url = $request->get_param('url');
        if (empty($url)) {
            return new WP_Error('missing_url', 'URL parameter is required', array('status' => 400));
        }
        
        $result = $this->fetch_products($url);
        
        if (isset($result['error'])) {
            return new WP_Error('scrape_failed', $result['error'], array('status' => 500));
        }
        
        return rest_ensure_response($result);
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Viefe Product Scraper', 'viefe-scraper'),
            __('Viefe Scraper', 'viefe-scraper'),
            'manage_woocommerce',
            'viefe-scraper',
            array($this, 'admin_page_callback')
        );
    }

    public function admin_page_callback() {

        ?>

        <!-- Add this debug button after your form -->
        <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #dc3232;">
            <h3>Debug Tools</h3>
            <form method="get" action="">
                <input type="hidden" name="page" value="viefe-scraper">
                <input type="hidden" name="debug" value="1">
                <input type="url" name="debug_url" value="<?php echo isset($_GET['debug_url']) ? esc_url($_GET['debug_url']) : 'https://www.viefe.com/en/knobs-and-handles?page=5'; ?>" style="width: 400px;">
                <button type="submit" class="button">Debug API Response</button>
            </form>
            <?php
            // Show debug output if debug parameter is set
            if (isset($_GET['debug']) && isset($_GET['debug_url'])) {
                $debug_url = esc_url_raw($_GET['debug_url']);
                $this->debug_api_response($debug_url);
            }
            ?>
        </div>

        <div class="wrap">
            <h1><?php _e('Viefe Product Scraper', 'viefe-scraper'); ?></h1>
            <p><?php _e('Enter a Viefe.com URL to scrape products and import them directly into WooCommerce.', 'viefe-scraper'); ?></p>
            
            <?php
            $last_import = get_option('viefe_last_import', array());
            if (!empty($last_import)) {
                echo '<div class="notice notice-info is-dismissible"><p>';
                echo '<strong>' . __('Last Import:', 'viefe-scraper') . '</strong> ' . esc_html($last_import['date'] ?? '') . '<br>';
                echo '<strong>' . __('URL:', 'viefe-scraper') . '</strong> ' . esc_html($last_import['url'] ?? '') . '<br>';
                echo '<strong>' . __('Imported:', 'viefe-scraper') . '</strong> ' . intval($last_import['imported'] ?? 0) . ' | ';
                echo '<strong>' . __('Updated:', 'viefe-scraper') . '</strong> ' . intval($last_import['updated'] ?? 0) . ' | ';
                echo '<strong>' . __('Failed:', 'viefe-scraper') . '</strong> ' . intval($last_import['failed'] ?? 0);
                echo '</p></div>';
            }
            ?>
            
            <form id="viefe-scrape-form" style="max-width:800px; margin-top:20px;" onsubmit="return false;">
                <?php wp_nonce_field('viefe_scrape_action', 'viefe_scrape_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="viefe_url"><?php _e('URL', 'viefe-scraper'); ?></label></th>
                        <td>
                            <input type="url" name="viefe_url" id="viefe_url" class="regular-text"
                                   placeholder="https://www.viefe.com/en/knobs-and-handles" required
                                   style="width:100%; max-width:600px;">
                            <p class="description">
                                <?php _e('Examples:', 'viefe-scraper'); ?><br>
                                <code>https://www.viefe.com/en/knobs-and-handles</code><br>
                                <code>https://www.viefe.com/en/knobs-and-handles?page=5</code><br>
                                <code>https://www.viefe.com/en/handle-jey2-0163</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Options', 'viefe-scraper'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="viefe_update_existing" id="viefe_update_existing" value="1" checked>
                                <?php _e('Update existing products if SKU matches', 'viefe-scraper'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="viefe_import_images" id="viefe_import_images" value="1" checked>
                                <?php _e('Import product images', 'viefe-scraper'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="viefe_publish" id="viefe_publish" value="1" checked>
                                <?php _e('Publish products immediately', 'viefe-scraper'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="button" id="viefe-start-scrape" class="button button-primary">
                        <?php _e('Scrape & Import Products', 'viefe-scraper'); ?>
                    </button>
                    <span class="spinner" style="float:none; margin:0 0 0 10px;"></span>
                </p>
            </form>
            
            <div id="viefe-progress-container" style="display:none; margin-top:20px; max-width:800px;">
                <h3><?php _e('Import Progress', 'viefe-scraper'); ?></h3>
                <div style="background:#ddd; height:30px; border-radius:4px; overflow:hidden;">
                    <div id="viefe-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>
                </div>
                <p id="viefe-progress-text" style="margin-top:10px;"></p>
                <div id="viefe-progress-log" style="max-height:300px; overflow-y:auto; background:#f9f9f9; padding:10px; border:1px solid #ddd; margin-top:10px; font-family:monospace; font-size:12px;"></div>
                <p style="margin-top:10px;">
                    <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="button button-secondary"><?php _e('View All Products', 'viefe-scraper'); ?></a>
                </p>
            </div>
        </div>
        <?php
    }


    /**
 * Add custom WooCommerce tabs (only for Viefe brand products)
 */
public function add_custom_product_tabs($tabs) {
    global $product;
    
    if (!$product) return $tabs;
    
    // Check if product is Viefe brand
    $is_viefe = $this->is_viefe_brand_product($product->get_id());
    
    // Only add custom tabs for Viefe products
    if ($is_viefe) {

    // Custom Description tab (from Viefe)
        $description = $product->get_description();
        if (!empty($description)) {
            $tabs['viefe_description'] = array(
                'title'    => __('Description', 'viefe-scraper'),
                'priority' => 20,
                'callback' => array($this, 'description_tab_content'),
            );
            // Remove default description tab to avoid duplicate
            unset($tabs['description']);
        }
        

        // Drawing tab
        $drawing = get_post_meta($product->get_id(), '_viefe_drawing', true);
        if (!empty($drawing)) {
            $tabs['drawing'] = array(
                'title'    => __('Drawing', 'viefe-scraper'),
                'priority' => 25,
                'callback' => array($this, 'drawing_tab_content'),
            );
        }

        // Specifications tab - check both meta AND short description
        $specifications = get_post_meta($product->get_id(), '_viefe_specifications', true);
        $short_description = $product->get_short_description();
        
        $has_specifications = (!empty($specifications) && is_array($specifications)) || !empty($short_description);
        
        if ($has_specifications) {
            $tabs['specifications'] = array(
                'title'    => __('Specifications', 'viefe-scraper'),
                'priority' => 30,
                'callback' => array($this, 'specifications_tab_content'),
            );
        }
    }

    else{
        // Custom Description tab (from Viefe)
        $description = $product->get_description();
        if (!empty($description)) {
            $tabs['viefe_description'] = array(
                'title'    => __('Description', 'viefe-scraper'),
                'priority' => 20,
                'callback' => array($this, 'description_tab_content'),
            );
            // Remove default description tab to avoid duplicate
            unset($tabs['description']);
        }
    }
    
    return $tabs;
}

/**
 * Check if product is Viefe brand
 */
private function is_viefe_brand_product($product_id) {
    // Check by brand taxonomy
    $brands = wp_get_post_terms($product_id, 'product_brand', array('fields' => 'names'));
    foreach ($brands as $brand) {
        if (stripos($brand, 'viefe') !== false) {
            return true;
        }
    }
    
    // Check by SKU pattern
    $product = wc_get_product($product_id);
    if ($product) {
        $sku = $product->get_sku();
        if (!empty($sku)) {
            // Check for VIEFE- prefix or numeric SKUs (like 0163, 6005, etc.)
            if (strpos($sku, 'VIEFE-') === 0 || preg_match('/^\d+$/', $sku)) {
                return true;
            }
        }
    }
    
    // Check by meta field
    $is_viefe = get_post_meta($product_id, '_is_viefe_product', true);
    if ($is_viefe === 'yes') {
        return true;
    }
    
    // Check by original URL meta
    $original_url = get_post_meta($product_id, '_viefe_original_url', true);
    if (!empty($original_url) && strpos($original_url, 'viefe.com') !== false) {
        return true;
    }
    
    return false;
}


    // Custom WooCommerce tabs
    public function add_custom_product_tabs_____($tabs) {
        global $product;
        
        if (!$product) return $tabs;
        
        $description = $product->get_description();
        if (!empty($description)) {
            $tabs['viefe_description'] = array(
                'title' => __('Description', 'viefe-scraper'),
                'priority' => 20,
                'callback' => array($this, 'description_tab_content'),
            );
            unset($tabs['description']);
        }
        
        $drawing = get_post_meta($product->get_id(), '_viefe_drawing', true);
        if (!empty($drawing)) {
            $tabs['drawing'] = array(
                'title' => __('Technical Drawing', 'viefe-scraper'),
                'priority' => 30,
                'callback' => array($this, 'drawing_tab_content'),
            );
        }

        // Specifications tab
         $short_description = $product->get_short_description();
        $specifications = get_post_meta($product->get_id(), '_viefe_specifications', true);
        if (!empty($specifications) || !empty($short_description)) {
            $tabs['specifications'] = array(
                'title'    => __('Specifications', 'viefe-scraper'),
                'priority' => 30,
                'callback' => array($this, 'specifications_tab_content'),
            );
        }

        
        return $tabs;
    }
    
    public function description_tab_content() {
        global $product;
        $description = $product->get_description();
        if ($description) {
            echo '<div class="viefe-description">' . wpautop($description) . '</div>';
        }
    }
    
    public function drawing_tab_content() {
        global $product;
        $drawing = get_post_meta($product->get_id(), '_viefe_drawing', true);
        if ($drawing) {
            echo '<div class="viefe-drawing">';
            echo '<img src="' . esc_url($drawing) . '" alt="' . esc_attr__('Technical Drawing', 'viefe-scraper') . '" style="max-width:100%; height:auto;" />';
            echo '<p><a href="' . esc_url($drawing) . '" download class="button">' . __('Download Drawing', 'viefe-scraper') . '</a></p>';
            echo '</div>';
        }
    }

    /**
     * Specifications tab content
     */


    public function specifications_tab_content() {
        global $product;

        $short_description = $product->get_short_description();
        $specifications = get_post_meta($product->get_id(), '_viefe_specifications', true);
       if(!empty($short_description)){
            echo '<div class="viefe-specifications">';
            echo wpautop($short_description);
            echo '</div>';
        }
        
    }

    


}

// Initialize plugin
$viefe_scraper = new Viefe_Product_Scraper();

// ================= AJAX HANDLERS =================

add_action('wp_ajax_viefe_init_scrape', 'viefe_ajax_init_scrape');
function viefe_ajax_init_scrape() {
    check_ajax_referer('viefe_scrape_ajax', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
    }
    
    $url = esc_url_raw($_POST['url'] ?? '');
    if (empty($url)) {
        wp_send_json_error('URL is required');
    }
    
    global $viefe_scraper;
    $result = $viefe_scraper->fetch_products($url);
    
    if (isset($result['error'])) {
        wp_send_json_error('Failed to fetch products: ' . $result['error']);
    }
    
    $products = $result['products'] ?? array();
    
    if (empty($products)) {
        wp_send_json_error('No products found for this URL. Please check the URL and try again.');
    }
    
    $batch_id = 'viefe_batch_' . time();
    set_transient($batch_id, array(
        'products' => $products,
        'url' => $url,
        'total' => count($products),
        'processed' => 0,
        'imported' => 0,
        'updated' => 0,
        'failed' => 0,
    ), HOUR_IN_SECONDS);
    
    wp_send_json_success(array(
        'batch_id' => $batch_id,
        'total' => count($products),
        'message' => sprintf(__('Found %d products. Starting import...', 'viefe-scraper'), count($products)),
    ));
}

add_action('wp_ajax_viefe_process_batch', 'viefe_ajax_process_batch');
function viefe_ajax_process_batch() {
    check_ajax_referer('viefe_scrape_ajax', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
    }
    
    $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
    $batch_index = intval($_POST['batch_index'] ?? 0);
    $update_existing = !empty($_POST['update_existing']);
    $import_images = !empty($_POST['import_images']);
    $publish = !empty($_POST['publish']);
    
    $batch = get_transient($batch_id);
    if (!$batch) {
        wp_send_json_error('Batch expired. Please start again.');
    }
    
    $products = $batch['products'];
    $total = count($products);
    
    if ($batch_index >= $total) {
        delete_transient($batch_id);
        
        update_option('viefe_last_import', array(
            'date' => current_time('mysql'),
            'url' => $batch['url'],
            'imported' => $batch['imported'] ?? 0,
            'updated' => $batch['updated'] ?? 0,
            'failed' => $batch['failed'] ?? 0,
        ));
        
        wp_send_json_success(array(
            'complete' => true,
            'progress' => 100,
            'message' => sprintf(
                __('Import complete! %d imported, %d updated, %d failed.', 'viefe-scraper'),
                $batch['imported'] ?? 0,
                $batch['updated'] ?? 0,
                $batch['failed'] ?? 0
            ),
        ));
    }
    
    $product = $products[$batch_index];
    global $viefe_scraper;
    
    // Fetch detailed data for product if needed (single product or detail page)
    if (!empty($product['url']) && empty($product['description'])) {
        $cache_key = 'viefe_detail_' . md5($product['url']);
        $detail = get_transient($cache_key);
        
        if (false === $detail) {
            $detail_result = $viefe_scraper->fetch_products($product['url']);
            if (!isset($detail_result['error']) && !empty($detail_result['products'])) {
                $detail = $detail_result['products'][0];
                set_transient($cache_key, $detail, 6 * HOUR_IN_SECONDS);
            }
            usleep(200000);
        }
        
        if ($detail && is_array($detail)) {
            // Keep variations from category data
            $saved_variations = $product['variations'] ?? array();
            $product = array_merge($product, $detail);
            if (empty($product['variations']) && !empty($saved_variations)) {
                $product['variations'] = $saved_variations;
            }
        }
    }
    
    $result = $viefe_scraper->create_or_update_product($product, $update_existing, $import_images, $publish);
    
    if ($result === 'imported') $batch['imported']++;
    elseif ($result === 'updated') $batch['updated']++;
    else $batch['failed']++;
    
    $batch['processed'] = $batch_index + 1;
    set_transient($batch_id, $batch, HOUR_IN_SECONDS);
    
    $progress = round(($batch['processed'] / $total) * 100);
    
    wp_send_json_success(array(
        'complete' => false,
        'progress' => $progress,
        'processed' => $batch['processed'],
        'total' => $total,
        'message' => sprintf(
            __('Processing: %s (%d/%d) - %s', 'viefe-scraper'),
            $product['title'] ?? 'Product',
            $batch['processed'],
            $total,
            ucfirst($result)
        ),
        'next_index' => $batch_index + 1,
    ));
}