<?php
/**
 * Plugin Name: Viefe Product Scraper
 * Plugin URI: https://www.viefe.com
 * Description: Scrape products from Viefe.com and import directly into WooCommerce with batch processing
 * Version: 1.2.0
 * Author: Your Name
 * License: GPL-2.0+
 * Text Domain: viefe-scraper
 * WC requires at least: 5.0
 */

// view json data: https://levere.wpenginepowered.com/wp-json/custom/v1/products?url=https://www.viefe.com/en/door-stoppers

if (!defined('ABSPATH')) {
    exit;
}

define('VIEFE_SCRAPER_PATH', plugin_dir_path(__FILE__));

// Include Simple HTML DOM
if (file_exists(VIEFE_SCRAPER_PATH . 'simple_html_dom.php')) {
    require_once VIEFE_SCRAPER_PATH . 'simple_html_dom.php';
}

class Viefe_Product_Scraper {

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_api_routes'));
        add_action('admin_menu', array($this, 'add_admin_menu'), 99);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_filter('woocommerce_product_tabs', array($this, 'add_custom_product_tabs'), 20);
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'viefe-scraper') === false) return;
        
        wp_enqueue_script('viefe-scraper-js', plugin_dir_url(__FILE__) . 'scraper.js', array('jquery'), '1.2', true);
        wp_localize_script('viefe-scraper-js', 'viefe_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('viefe_scrape_ajax'),
        ));
    }

    public function register_api_routes() {
        register_rest_route(
            'custom/v1',
            '/products',
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_products_callback'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function get_products_callback($request) {
        $url = $request->get_param('url');
        
        if (empty($url)) {
            return new WP_Error('missing_url', 'Product URL is missing', array('status' => 400));
        }

        $parsed_url = parse_url($url);
        $domain = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        $html = file_get_html($url);
        
        if (!$html) {
            return new WP_Error('fetch_failed', 'Failed to retrieve webpage', array('status' => 500));
        }

        $product_items = $html->find(".grid-items .product-list form.buyForm");

        if ($product_items) {
            $products = array();
            $category_title = $html->find('.category-main-title', 0);
            $category_name = $category_title ? trim($category_title->plaintext) : '';
            
            foreach ($product_items as $item) {
                $products[] = $this->extract_product_data_basic($item, $domain, $category_name);
            }
            
            $html->clear();
            unset($html);
            return rest_ensure_response($products);
        }
        
        return new WP_Error('no_product', 'No product data found', array('status' => 404));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Product Scraper',
            'Product Scraper',
            'manage_woocommerce',
            'viefe-scraper',
            array($this, 'admin_page_callback')
        );
    }

    public function admin_page_callback() {
        ?>
        <div class="wrap">
            <h1>Viefe Product Scraper</h1>
            <p>Enter a Viefe.com category URL to scrape products and import them directly into WooCommerce.</p>
            <p><strong>Tip:</strong> Check "Skip detail pages" for faster import (basic data only, no descriptions).</p>
            
            <?php
            if (isset($_GET['scrape_message'])) {
                $type = isset($_GET['scrape_type']) ? $_GET['scrape_type'] : 'success';
                echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($_GET['scrape_message']) . '</p></div>';
            }
            
            $last = get_option('viefe_last_import', array());
            if ($last) {
                echo '<div class="notice notice-info"><p>';
                echo '<strong>Last Import:</strong> ' . esc_html($last['date'] ?? '') . '<br>';
                echo '<strong>URL:</strong> ' . esc_html($last['url'] ?? '') . '<br>';
                echo '<strong>Imported:</strong> ' . intval($last['imported'] ?? 0) . ' | ';
                echo '<strong>Updated:</strong> ' . intval($last['updated'] ?? 0) . ' | ';
                echo '<strong>Failed:</strong> ' . intval($last['failed'] ?? 0);
                echo '</p></div>';
            }
            ?>
            
            <form id="viefe-scrape-form" style="max-width:800px; margin-top:20px;" onsubmit="return false;">
                <?php wp_nonce_field('viefe_scrape_action', 'viefe_scrape_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="viefe_url">Category URL</label></th>
                        <td>
                            <input type="url" name="viefe_url" id="viefe_url" class="regular-text" 
                                   placeholder="https://www.viefe.com/en/door-stoppers" required 
                                   style="width:100%; max-width:600px;">
                            <p class="description">Example: https://www.viefe.com/en/door-stoppers</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Options</th>
                        <td>
                            <label>
                                <input type="checkbox" name="viefe_update_existing" id="viefe_update_existing" value="1" checked>
                                Update existing products if SKU matches
                            </label><br>
                            <label>
                                <input type="checkbox" name="viefe_import_images" id="viefe_import_images" value="1" checked>
                                Import product images
                            </label><br>
                            <label>
                                <input type="checkbox" name="viefe_publish" id="viefe_publish" value="1" checked>
                                Publish products immediately
                            </label><br>
                            <label>
                                <input type="checkbox" name="viefe_skip_details" id="viefe_skip_details" value="1">
                                <strong>Skip detail pages</strong> (faster - basic data only)
                            </label>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="button" id="viefe-start-scrape" class="button button-primary">
                        Scrape & Import Products
                    </button>
                    <span class="spinner" style="float:none; margin:0 0 0 10px;"></span>
                </p>
            </form>
            
            <div id="viefe-progress-container" style="display:none; margin-top:20px; max-width:800px;">
                <h3>Import Progress</h3>
                <div style="background:#ddd; height:30px; border-radius:4px; overflow:hidden;">
                    <div id="viefe-progress-bar" style="background:#2271b1; height:100%; width:0%; transition:width 0.3s;"></div>
                </div>
                <p id="viefe-progress-text" style="margin-top:10px;"></p>
                <div id="viefe-progress-log" style="max-height:300px; overflow-y:auto; background:#f9f9f9; padding:10px; border:1px solid #ddd; margin-top:10px; font-family:monospace; font-size:12px;"></div>
                <p style="margin-top:10px;">
                    <a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="button button-secondary">View All Products</a>
                </p>
            </div>
            
            <div style="margin-top:30px; padding:15px; background:#f0f0f1; border-left:4px solid #2271b1; max-width:800px;">
                <h3>Supported Categories</h3>
                <ul style="list-style-type:disc; padding-left:20px;">
                    <li>Door Stoppers - <code>/en/door-stoppers</code></li>
                    <li>Wall Hooks - <code>/en/wall-hooks</code></li>
                    <li>Handles - <code>/en/knobs-and-handles</code></li>
                    <li>Pull Handles - <code>/en/pull-handles</code></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Extract basic product data from listing item
     */
    public function extract_product_data_basic($item, $domain, $category_name) {
        $product = array();

        $title_element = $item->find('.product-list-title', 0);
        $product['title'] = $title_element ? trim($title_element->plaintext) : '';

        $link_element = $item->find('.product-list-img-link', 0);
        $relative_url = $link_element ? $link_element->getAttribute('href') : '';
        $product['url'] = $relative_url ? $domain . $relative_url : '';
        
        $image_element = $item->find('.product-list-img', 0);
        $product['image'] = $image_element ? $image_element->getAttribute('src') : '';
        
        $hover_element = $item->find('.product-list-img-hover', 0);
        if ($hover_element) {
            $style = $hover_element->getAttribute('style');
            if (preg_match('/url\([\'"]?([^\'"()]+)[\'"]?\)/', $style, $matches)) {
                $product['hover_image'] = $matches[1];
            }
        }
        
        $data_product = $item->getAttribute('data-product');
        if ($data_product) {
            $product_data = json_decode(html_entity_decode($data_product), true);
            
            if ($product_data) {
                $product['product_id'] = $product_data['id'] ?? '';
                $product['sku'] = $product_data['sku'] ?? '';
                $product['brand'] = $product_data['brandName'] ?? '';
                
                $product['price'] = 0;
                if (isset($product_data['definition']['alternativeBasePrice'])) {
                    $product['price'] = (float) $product_data['definition']['alternativeBasePrice'];
                }
                
                $product['stock'] = 0;
                if (isset($product_data['combinationData']['stock']['units'])) {
                    $product['stock'] = (int) $product_data['combinationData']['stock']['units'];
                }
                
                $product['variations'] = array();
                
                if (isset($product_data['options'])) {
                    foreach ($product_data['options'] as $option) {
                        if (isset($option['values'])) {
                            foreach ($option['values'] as $value_data) {
                                $option_value_id = $value_data['id'];
                                $combo_key = 'PC_' . $option_value_id;
                                $combo_sku = '';
                                $combo_ean = '';
                                $combo_id = '';
                                
                                if (isset($product_data['combinations'][$combo_key])) {
                                    $combo = $product_data['combinations'][$combo_key];
                                    $combo_sku = $combo['sku'] ?? '';
                                    $combo_ean = $combo['ean'] ?? '';
                                    $combo_id = $combo['id'] ?? '';
                                }
                                
                                $stock_key = 'WH1_' . $option_value_id;
                                $var_stock = 0;
                                if (isset($product_data['stocks'][$stock_key])) {
                                    $var_stock = (int) $product_data['stocks'][$stock_key];
                                }
                                
                                $product['variations'][] = array(
                                    'variation_id' => $combo_id,
                                    'option_value_id' => $option_value_id,
                                    'sku' => $combo_sku,
                                    'ean' => $combo_ean,
                                    'alternative_price' => (float) ($value_data['alternativeBasePrice'] ?? 0),
                                    'base_price' => (float) ($value_data['basePrice'] ?? 0),
                                    'small_image' => $value_data['smallImage'] ?? '',
                                    'large_image' => $value_data['largeImage'] ?? '',
                                    'stock' => $var_stock,
                                );
                            }
                        }
                    }
                }
            }
        }
        
        if (!isset($product['product_id'])) $product['product_id'] = '';
        if (!isset($product['price'])) $product['price'] = 0;
        if (!isset($product['stock'])) $product['stock'] = 0;
        
        $product['finishes'] = array();
        foreach ($item->find('.bullet-option .bullet') as $bullet) {
            $img = $bullet->find('img', 0);
            $product['finishes'][] = array(
                'title' => $bullet->getAttribute('title'),
                'image' => $img ? $img->getAttribute('src') : ''
            );
        }
        
        $product['category'] = $category_name;
        return $product;
    }

    /**
     * Scrape detail page for a single product
     */
    public function scrape_product_detail($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'headers' => array('Accept' => 'text/html,application/xhtml+xml')
        ));
        
        if (is_wp_error($response)) return false;
        if (wp_remote_retrieve_response_code($response) !== 200) return false;
        
        $html_body = wp_remote_retrieve_body($response);
        $detail_html = str_get_html($html_body);
        if (!$detail_html) return false;
        
        $product_data = array(
            'description' => '',
            'specifications' => array(),
            'gallery' => array(),
            'finishes' => array(),
            'sizes' => array(),
            'drawing' => ''
        );
        
        // Title
        $title_element = $detail_html->find('.product-main-title', 0);
        if ($title_element) $product_data['title'] = trim($title_element->plaintext);
        
        // SKU
        $sku_element = $detail_html->find('[data-product-sku]', 0);
        if ($sku_element) {
            $product_data['sku'] = $sku_element->getAttribute('data-product-sku');
        }
        
        // Gallery
        $gallery_container = $detail_html->find('.swiper-main-gallery', 0);
        if ($gallery_container) {
            foreach ($gallery_container->find('img.zoom-gallery-img') as $img) {
                $src = $img->getAttribute('src');
                $alt = $img->getAttribute('alt');
                if ($src && strpos($src, 'not-found.png') === false) {
                    $product_data['gallery'][] = array('url' => $src, 'alt' => $alt);
                }
            }
        }
        
        // Finishes
        foreach ($detail_html->find('.finish-value input[name="finishSelector"]') as $finish_input) {
            $finish_id = $finish_input->getAttribute('id');
            $finish_value = $finish_input->getAttribute('value');
            $label = $detail_html->find("label[for='$finish_id']", 0);
            if ($label) {
                $finish_image = $label->find('img', 0);
                $product_data['finishes'][] = array(
                    'value' => $finish_value,
                    'name' => $label->getAttribute('data-name'),
                    'title' => $label->getAttribute('title'),
                    'image' => $finish_image ? $finish_image->getAttribute('src') : ''
                );
            }
        }
        
        // Sizes
        $sizes_select = $detail_html->find('#sizesSelector', 0);
        if ($sizes_select) {
            foreach ($sizes_select->find('option') as $option) {
                $value = $option->getAttribute('value');
                if (!empty($value)) {
                    $product_data['sizes'][] = array('value' => $value, 'label' => trim($option->plaintext));
                }
            }
        }
        
        // Description
        $description_element = $detail_html->find('.html-output', 0);
        if ($description_element) $product_data['description'] = trim($description_element->plaintext);



         // DEBUG: Log what we found
        error_log('=== SCRAPE DEBUG for ' . $url . ' ===');
        error_log('Description: ' . (empty($product_data['description']) ? 'EMPTY' : substr($product_data['description'], 0, 50)));
        error_log('Drawing: ' . (empty($product_data['drawing']) ? 'NOT FOUND' : $product_data['drawing']));
        error_log('Specifications: ' . json_encode($product_data['specifications']));
        error_log('Finishes: ' . count($product_data['finishes']));
        error_log('Sizes: ' . count($product_data['sizes']));
        error_log('Gallery: ' . count($product_data['gallery']));

        
        // Specifications
        foreach ($detail_html->find('#collapse-specifications .tag-wrapper') as $tag) {
            $lbl = $tag->find('.lbl', 0);
            $val = $tag->find('.val', 0);
            if ($lbl && $val) {
                $key = strtolower(trim(str_replace(':', '', $lbl->plaintext)));
                $product_data['specifications'][$key] = trim($val->plaintext);
            }
        }

        

        
        // Drawing - try multiple selectors
        $drawing_img = $detail_html->find('.tag-wrapper.drawing img', 0);
        if (!$drawing_img) {
            $drawing_img = $detail_html->find('#collapse-drawing img', 0);
        }
        if (!$drawing_img) {
            foreach ($detail_html->find('img') as $img) {
                $alt = $img->getAttribute('alt') ?? '';
                $src = $img->getAttribute('src') ?? '';
                if (stripos($alt, 'drawing') !== false || stripos($src, '_D1') !== false || stripos($src, '_D1_') !== false) {
                    $drawing_img = $img;
                    break;
                }
            }
        }
        if ($drawing_img) {
            $product_data['drawing'] = $drawing_img->getAttribute('src');
        }
        
        $detail_html->clear();
        unset($detail_html);
        return $product_data;
    }

    /**
     * Create or update a single WooCommerce product
     */
    public function create_or_update_product($product_data, $update_existing, $import_images, $publish) {
        $sku = $product_data['sku'] ?? '';
        if (empty($sku)) {
            $sku = 'VIEFE-' . ($product_data['product_id'] ?? 'unknown');
        }
        
        $product_id = wc_get_product_id_by_sku($sku);
        $is_update = false;
        
        if ($product_id && $update_existing) {
            $product = wc_get_product($product_id);
            $is_update = true;
        } elseif ($product_id && !$update_existing) {
            return 'skipped';
        } else {
            $has_variations = !empty($product_data['variations']);
            
            if ($has_variations) {
                $product = new WC_Product_Variable();
            } else {
                $product = new WC_Product_Simple();
            }
            
            $product->set_name($product_data['title'] ?? 'Product');
            $product->set_sku($sku);
            $product->set_status($publish ? 'publish' : 'draft');
            $product->set_catalog_visibility('visible');
            $product->set_description($product_data['description'] ?? '');
            
            if (!empty($product_data['specifications'])) {
                $specs_parts = array();
                foreach ($product_data['specifications'] as $key => $val) {
                    $specs_parts[] = ucfirst($key) . ': ' . $val;
                }
                $product->set_short_description(implode(' | ', $specs_parts));
            }
            
            $category_name = $product_data['category'] ?? '';
            if (!empty($category_name)) {
                $term = term_exists($category_name, 'product_cat');
                if (!$term) {
                    $term = wp_insert_term($category_name, 'product_cat');
                }
                if (!is_wp_error($term)) {
                    $product->set_category_ids(array($term['term_id']));
                }
            }
            
            $product->save();
            $product_id = $product->get_id();
            
            $brand = $product_data['brand'] ?? '';
            if (!empty($brand)) {
                wp_set_object_terms($product_id, $brand, 'product_brand', true);
            }
        }
        
        if (!$product_id) return 'failed';
        
        // Save drawing for BOTH new and updated products
        if (!empty($product_data['drawing'])) {
            update_post_meta($product_id, '_viefe_drawing', $product_data['drawing']);
        }
        
        // Save specifications for BOTH new and updated products
        if (!empty($product_data['specifications'])) {
            update_post_meta($product_id, '_viefe_specifications', $product_data['specifications']);
        }
        
        // Handle variations
        if (!empty($product_data['variations']) && $product->is_type('variable')) {
            $this->create_variations($product_id, $product_data, $publish, $import_images);
        }
        
        // Import images (only for new products)
        if ($import_images && !$is_update) {
            $this->import_images($product_id, $product_data);
        }
        
        return $is_update ? 'updated' : 'imported';
    }

    /**
     * Create variations for a variable product
     */
    private function create_variations($parent_id, $product_data, $publish, $import_images) {
        $finishes = !empty($product_data['finishes_detail']) ? $product_data['finishes_detail'] : $product_data['finishes'];
        $sizes = $product_data['sizes'] ?? array();
        
        $attributes = array();
        
        if (!empty($finishes)) {
            $finish_names = array();
            foreach ($finishes as $finish) {
                $name = $finish['name'] ?? $finish['title'] ?? '';
                if ($name) $finish_names[] = $name;
            }
            if (!empty($finish_names)) {
                $attribute = new WC_Product_Attribute();
                $attribute->set_name('Finish');
                $attribute->set_options($finish_names);
                $attribute->set_visible(true);
                $attribute->set_variation(true);
                $attributes[] = $attribute;
            }
        }
        
        if (!empty($sizes)) {
            $size_labels = array();
            foreach ($sizes as $size) {
                $label = $size['label'] ?? $size['value'] ?? '';
                if ($label) $size_labels[] = $label;
            }
            if (!empty($size_labels)) {
                $attribute = new WC_Product_Attribute();
                $attribute->set_name('Size');
                $attribute->set_options($size_labels);
                $attribute->set_visible(true);
                $attribute->set_variation(true);
                $attributes[] = $attribute;
            }
        }
        
        if (!empty($attributes)) {
            $product = wc_get_product($parent_id);
            $product->set_attributes($attributes);
            $product->save();
        }
        
        foreach ($product_data['variations'] as $variation) {
            $var_sku = $variation['sku'] ?? '';
            if (empty($var_sku)) continue;
            
            $existing_id = wc_get_product_id_by_sku($var_sku);
            if ($existing_id) {
                $variation_product = wc_get_product($existing_id);
            } else {
                $variation_product = new WC_Product_Variation();
                $variation_product->set_parent_id($parent_id);
            }
            
            $variation_product->set_sku($var_sku);
            $variation_product->set_regular_price($variation['alternative_price'] ?? $variation['base_price'] ?? 0);
            
            // Only manage stock if there's actual stock quantity
            $var_stock = (int)($variation['stock'] ?? 0);
            if ($var_stock > 0) {
                $variation_product->set_manage_stock(true);
                $variation_product->set_stock_quantity($var_stock);
                $variation_product->set_stock_status('instock');
            }
            
            $variation_product->set_status($publish ? 'publish' : 'private');
            
            $var_attributes = array();
            
            foreach ($finishes as $finish) {
                $finish_val = $finish['value'] ?? '';
                if ($finish_val && stripos($var_sku, $finish_val) !== false) {
                    $var_attributes['attribute_finish'] = $finish['name'] ?? $finish['title'] ?? $finish_val;
                    break;
                }
            }
            
            foreach ($sizes as $size) {
                $size_val = $size['value'] ?? '';
                if ($size_val && stripos($var_sku, $size_val) !== false) {
                    $var_attributes['attribute_size'] = $size['label'] ?? $size_val;
                    break;
                }
            }
            
            $variation_product->set_attributes($var_attributes);
            
            if ($import_images && !empty($variation['large_image'])) {
                $image_id = $this->upload_image($variation['large_image']);
                if ($image_id) $variation_product->set_image_id($image_id);
            }
            
            $variation_product->save();
        }
    }

    /**
     * Import product images
     */
    private function import_images($product_id, $product_data) {
        $product = wc_get_product($product_id);
        
        if (!empty($product_data['image'])) {
            $image_id = $this->upload_image($product_data['image']);
            if ($image_id) $product->set_image_id($image_id);
        }
        
        if (!empty($product_data['gallery'])) {
            $gallery_ids = array();
            foreach ($product_data['gallery'] as $img) {
                $img_url = is_array($img) ? ($img['url'] ?? '') : $img;
                if ($img_url) {
                    $image_id = $this->upload_image($img_url);
                    if ($image_id) $gallery_ids[] = $image_id;
                }
            }
            if (!empty($gallery_ids)) $product->set_gallery_image_ids($gallery_ids);
        }
        
        $product->save();
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
        
        $tmp = download_url($image_url, 10);
        if (is_wp_error($tmp)) return false;
        
        $file_array = array('name' => basename($image_url), 'tmp_name' => $tmp);
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return false;
        }
        
        return $attachment_id;
    }

    /**
     * Add custom WooCommerce tabs (Drawing & Specifications)
     */
    public function add_custom_product_tabs($tabs) {
        global $product;
        
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
        
        // Specifications tab
        $specifications = get_post_meta($product->get_id(), '_viefe_specifications', true);
        if (!empty($specifications)) {
            $tabs['specifications'] = array(
                'title'    => __('Specifications', 'viefe-scraper'),
                'priority' => 30,
                'callback' => array($this, 'specifications_tab_content'),
            );
        }
        
        return $tabs;
    }
    
    /**
     * Description tab content
     */
    public function description_tab_content() {
        global $product;
        
        $description = $product->get_description();
        if ($description) {
            echo '<div class="viefe-description">';
            echo wpautop($description);
            echo '</div>';
        }
    }
    
    /**
     * Drawing tab content
     */
    public function drawing_tab_content() {
        global $product;
        
        $drawing = get_post_meta($product->get_id(), '_viefe_drawing', true);
        if ($drawing) {
            echo '<div class="viefe-drawing">';
            echo '<img src="' . esc_url($drawing) . '" alt="' . esc_attr__('Product Drawing', 'viefe-scraper') . '" style="max-width:100%; height:auto;" />';
            echo '<p><a href="' . esc_url($drawing) . '" download class="button">' . __('Download Drawing', 'viefe-scraper') . '</a></p>';
            echo '</div>';
        }
    }
    
    /**
     * Specifications tab content
     */
    public function specifications_tab_content() {
        global $product;
        
        $specifications = get_post_meta($product->get_id(), '_viefe_specifications', true);
        if (!empty($specifications) && is_array($specifications)) {
            echo '<div class="viefe-specifications">';
            echo '<table class="shop_attributes">';
            echo '<tbody>';
            foreach ($specifications as $key => $value) {
                echo '<tr>';
                echo '<th>' . esc_html(ucfirst(str_replace('_', ' ', $key))) . '</th>';
                echo '<td>' . esc_html($value) . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
        }
    }


}

// Initialize the plugin
$viefe_scraper = new Viefe_Product_Scraper();

// =============================================
// AJAX HANDLERS FOR BATCH PROCESSING
// =============================================

add_action('wp_ajax_viefe_init_scrape', 'viefe_ajax_init_scrape');

function viefe_ajax_init_scrape() {
    check_ajax_referer('viefe_scrape_ajax', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
    }
    
    $url = esc_url_raw($_POST['url'] ?? '');
    $skip_details = !empty($_POST['skip_details']);
    
    if (empty($url)) {
        wp_send_json_error('URL is required');
    }
    
    set_time_limit(60);
    
    $parsed_url = parse_url($url);
    $domain = $parsed_url['scheme'] . '://' . $parsed_url['host'];
    $html = file_get_html($url);
    
    if (!$html) {
        wp_send_json_error('Failed to fetch webpage. Check the URL.');
    }
    
    $product_items = $html->find(".grid-items .product-list form.buyForm");
    
    if (!$product_items || count($product_items) === 0) {
        $html->clear();
        unset($html);
        wp_send_json_error('No products found on this page.');
    }
    
    $products = array();
    $category_title = $html->find('.category-main-title', 0);
    $category_name = $category_title ? trim($category_title->plaintext) : '';
    
    global $viefe_scraper;
    
    foreach ($product_items as $item) {
        $product = $viefe_scraper->extract_product_data_basic($item, $domain, $category_name);
        
        if ($skip_details) {
            $product['description'] = '';
            $product['specifications'] = array();
            $product['gallery'] = array();
            $product['finishes_detail'] = array();
            $product['sizes'] = array();
            $product['drawing'] = '';
        }
        
        $products[] = $product;
    }
    
    $html->clear();
    unset($html);
    
    $batch_id = 'viefe_batch_' . time();
    set_transient($batch_id, array(
        'products' => $products,
        'url' => $url,
        'skip_details' => $skip_details,
        'total' => count($products),
        'processed' => 0,
        'imported' => 0,
        'updated' => 0,
        'failed' => 0,
    ), HOUR_IN_SECONDS);
    
    wp_send_json_success(array(
        'batch_id' => $batch_id,
        'total' => count($products),
        'message' => 'Found ' . count($products) . ' products. Starting import...',
    ));
}

add_action('wp_ajax_viefe_process_batch', 'viefe_ajax_process_batch');

function viefe_ajax_process_batch() {
    check_ajax_referer('viefe_scrape_ajax', 'nonce');
    
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied');
    }
    
    set_time_limit(30);
    
    $batch_id = sanitize_text_field($_POST['batch_id'] ?? '');
    $batch_index = intval($_POST['batch_index'] ?? 0);
    $update_existing = !empty($_POST['update_existing']);
    $import_images = !empty($_POST['import_images']);
    $publish = !empty($_POST['publish']);
    $skip_details = !empty($_POST['skip_details']);
    
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
            'message' => 'Import complete! ' . ($batch['imported'] ?? 0) . ' imported, ' . ($batch['updated'] ?? 0) . ' updated, ' . ($batch['failed'] ?? 0) . ' failed.',
            'imported' => $batch['imported'] ?? 0,
            'updated' => $batch['updated'] ?? 0,
            'failed' => $batch['failed'] ?? 0,
        ));
    }
    
    $product = $products[$batch_index];
    global $viefe_scraper;
    
    // Scrape detail page if not skipping
    if (!$skip_details && !empty($product['url'])) {
        $detail_url = $product['url'];
        
        $cache_key = 'viefe_product_' . md5($detail_url);
        $product_detail = get_transient($cache_key);
        
        if (false === $product_detail) {
            $product_detail = $viefe_scraper->scrape_product_detail($detail_url);
            if ($product_detail && !empty($product_detail['description'])) {
                set_transient($cache_key, $product_detail, 6 * HOUR_IN_SECONDS);
            }
        }
        
        if ($product_detail) {
            $saved_variations = $product['variations'] ?? array();
            
            // Only update fields that have actual data
            if (!empty($product_detail['description'])) {
                $product['description'] = $product_detail['description'];
            }
            if (!empty($product_detail['specifications'])) {
                $product['specifications'] = $product_detail['specifications'];
            }
            if (!empty($product_detail['gallery'])) {
                $product['gallery'] = $product_detail['gallery'];
            }
            if (!empty($product_detail['drawing'])) {
                $product['drawing'] = $product_detail['drawing'];
            }
            
            $product['variations'] = $saved_variations;
            
            if (empty($product['finishes_detail']) && !empty($product_detail['finishes'])) {
                $product['finishes_detail'] = $product_detail['finishes'];
            }
            if (empty($product['sizes']) && !empty($product_detail['sizes'])) {
                $product['sizes'] = $product_detail['sizes'];
            }
        }
        
        usleep(500000);
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
        'message' => 'Processing: ' . ($product['title'] ?? 'Product') . ' (' . $batch['processed'] . '/' . $total . ') - ' . ucfirst($result),
        'product_title' => $product['title'] ?? 'Unknown',
        'product_result' => ucfirst($result),
        'next_index' => $batch_index + 1,
        'imported' => $batch['imported'],
        'updated' => $batch['updated'],
        'failed' => $batch['failed'],
    ));
}