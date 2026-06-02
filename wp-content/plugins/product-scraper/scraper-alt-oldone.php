<?php
/**
 * Alternative Scraper - Extracts JSON from <pre><code> tags
 * Handles both nested (Next.js) and flat (Wagtail API) JSON structures
 * 
 * Usage: 
 * http://localhost/viefe/wp-json/custom/v1/alt-product/?url=YOUR_URL
 */

add_action('rest_api_init', function() {
    register_rest_route('custom/v1', '/alt-product/', array(
        'methods'             => 'GET',
        'callback'            => 'ps_alt_scrape_product',
        'permission_callback' => '__return_true',
    ));
});

function ps_alt_scrape_product($request) {
    set_time_limit(60);
    
    $url = $request->get_param('url');
    
    if (empty($url)) {
        return new WP_Error('missing_url', 'URL is required', array('status' => 400));
    }
    
    $response = wp_remote_get($url, array(
        'timeout' => 30,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'text/html,application/xhtml+xml',
        ),
    ));
    
    if (is_wp_error($response)) {
        return new WP_Error('fetch_failed', $response->get_error_message(), array('status' => 500));
    }
    
    $html = wp_remote_retrieve_body($response);
    
    // Method 1: Extract from <pre><code> tags
    $product_data = ps_extract_json_from_pre_code($html);
    
    // Method 2: Extract from __NEXT_DATA__
    if (empty($product_data)) {
        $product_data = ps_extract_json_from_next_data($html);
    }
    
    if (empty($product_data)) {
        return new WP_Error('no_data', 'No product data found', array('status' => 404));
    }
    
    return array(
        'success' => true,
        'source_url' => $url,
        'extraction_method' => $product_data['_method'] ?? 'unknown',
        'product' => $product_data,
    );
}

/**
 * Extract JSON from <pre><code> tags
 */
function ps_extract_json_from_pre_code($html) {
    preg_match_all('/<pre[^>]*>\s*<code[^>]*>(.*?)<\/code>\s*<\/pre>/si', $html, $matches);
    
    if (empty($matches[1])) {
        return null;
    }
    
    foreach ($matches[1] as $code_content) {
        $code_content = html_entity_decode($code_content, ENT_QUOTES, 'UTF-8');
        $code_content = trim($code_content);
        
        $data = json_decode($code_content, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            $product = ps_parse_flat_product_json($data);
            if (!empty($product['title'])) {
                $product['_method'] = 'pre_code_flat';
                return $product;
            }
            
            // Try nested Next.js structure
            $product = ps_parse_nested_product_json($data);
            if (!empty($product['title'])) {
                $product['_method'] = 'pre_code_nested';
                return $product;
            }
        }
    }
    
    return null;
}

/**
 * Extract from Next.js __NEXT_DATA__ script
 */
function ps_extract_json_from_next_data($html) {
    preg_match('/<script[^>]*id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/si', $html, $matches);
    
    if (empty($matches[1])) {
        return null;
    }
    
    $data = json_decode(trim($matches[1]), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    
    $product = ps_parse_nested_product_json($data);
    if (!empty($product['title'])) {
        $product['_method'] = 'next_data';
        return $product;
    }
    
    return null;
}

/**
 * Parse FLAT product JSON (direct from Wagtail API in <pre><code>)
 */
function ps_parse_flat_product_json($data) {
    // Check if this is a flat product structure (has 'option', 'configurator', etc. at root)
    if (empty($data['option']) && empty($data['title']) && empty($data['configurator'])) {
        return null;
    }
    
    $product = array(
        'title' => '',
        'image' => '',
        'description' => '',
        'technical_specs' => '',
        'configurator_groups' => array(),
        'installation_instructions' => array(),
        'specifications' => array(),
        'acf_functions' => array(),
        'categories' => array(),
    );
    
    // Title - check multiple fields
    $product['title'] = $data['option'] ?? $data['title'] ?? '';
    
    // Primary image
    if (!empty($data['primary_image']['image'])) {
        $product['image'] = esc_url_raw($data['primary_image']['image']);
    } elseif (!empty($data['images'][0]['image'])) {
        $product['image'] = esc_url_raw($data['images'][0]['image']);
    }
    
    // Description - decode HTML entities
    if (!empty($data['description'])) {
        $product['description'] = html_entity_decode($data['description'], ENT_QUOTES, 'UTF-8');
    }
    
    // Technical specs - decode HTML entities
    if (!empty($data['technical_specs'])) {
        $product['technical_specs'] = html_entity_decode($data['technical_specs'], ENT_QUOTES, 'UTF-8');
    }
    
    // Finish images mapping
    $finish_images = array();
    if (!empty($data['finish_images']) && is_array($data['finish_images'])) {
        $finish_images = $data['finish_images'];
    }
    
    // Configurator groups - FLAT STRUCTURE
    if (!empty($data['configurator']) && is_array($data['configurator'])) {
        foreach ($data['configurator'] as $group) {
            $group_name = $group['display_name'] ?? '';
            if (empty($group_name)) continue;
            
            $options = $group['options'] ?? array();
            if (empty($options)) continue;
            
            $processed = array();
            foreach ($options as $option) {
                $option_id = $option['id'] ?? '';
                $image_url = '';
                
                // Check multiple image sources
                if (!empty($finish_images[$option_id])) {
                    $image_url = $finish_images[$option_id];
                } elseif (!empty($option['primary_image']['image'])) {
                    $image_url = $option['primary_image']['image'];
                }
                
                // Get code
                $code = $option['code'] ?? '';
                if (empty($code)) {
                    $code = sanitize_title($option['option'] ?? '');
                }
                
                $processed[] = array(
                    'id'    => $option_id,
                    'name'  => $option['option'] ?? '',
                    'code'  => $code,
                    'image' => $image_url,
                );
            }
            
            if (!empty($processed)) {
                $product['configurator_groups'][] = array(
                    'display_name' => $group_name,
                    'options'      => $processed,
                );
            }
        }
    }
    
    // Installation instructions
    if (!empty($data['installation_instructions']) && is_array($data['installation_instructions'])) {
        foreach ($data['installation_instructions'] as $inst) {
            $product['installation_instructions'][] = array(
                'title' => $inst['title'] ?? '',
                'file'  => $inst['file'] ?? $inst['url'] ?? '',
            );
        }
    }
    
    // Specifications
    if (!empty($data['specifications']) && is_array($data['specifications'])) {
        foreach ($data['specifications'] as $spec) {
            $product['specifications'][] = array(
                'title' => $spec['title'] ?? '',
                'file'  => $spec['file'] ?? $spec['url'] ?? '',
            );
        }
    }
    
    // Functions (ACF descriptions)
    if (!empty($data['functions']) && is_array($data['functions'])) {
        foreach ($data['functions'] as $func) {
            $modal = $func['function_modal'] ?? $func;
            $desc = $modal['description'] ?? '';
            if (!empty($desc)) {
                $desc = html_entity_decode($desc, ENT_QUOTES, 'UTF-8');
            }
            $product['acf_functions'][] = array(
                'title'       => $modal['title'] ?? '',
                'description' => $desc,
                'image'       => $modal['image']['url'] ?? $modal['image'] ?? '',
            );
        }
    }
    
    // Themes as categories
    // if (!empty($data['themes']) && is_array($data['themes'])) {
    //     foreach ($data['themes'] as $theme) {
    //         if (!empty($theme['name'])) {
    //             $product['categories'][] = $theme['name'];
    //         }
    //     }
    // }
    
    return $product;
}

/**
 * Parse NESTED product JSON (Next.js page props structure)
 */
function ps_parse_nested_product_json($data) {
    $pageProps = $data['props']['pageProps'] ?? array();
    $wagtail = $pageProps['wagtail'] ?? array();
    $attribute = $wagtail['attribute'] ?? array();
    
    if (empty($attribute)) {
        $attribute = $pageProps['attribute'] ?? array();
    }
    
    if (empty($attribute) && !empty($pageProps)) {
        // Maybe the pageProps itself is the attribute
        if (!empty($pageProps['configurator'])) {
            $attribute = $pageProps;
        }
    }
    
    if (empty($attribute['configurator']) && empty($attribute['title'])) {
        return null;
    }
    
    $product = array(
        'title' => '',
        'image' => '',
        'description' => '',
        'technical_specs' => '',
        'configurator_groups' => array(),
        'installation_instructions' => array(),
        'specifications' => array(),
        'acf_functions' => array(),
        'categories' => array(),
    );
    
    $product['title'] = sanitize_text_field($wagtail['title'] ?? $attribute['title'] ?? '');
    $product['image'] = esc_url_raw($attribute['primary_image']['image'] ?? '');
    $product['description'] = wp_kses_post($attribute['description'] ?? '');
    $product['technical_specs'] = wp_kses_post($attribute['technical_specs'] ?? '');
    
    // Finish images
    $finish_images = $attribute['finish_images'] ?? array();
    if (!is_array($finish_images)) $finish_images = array();
    
    // Configurator
    if (!empty($attribute['configurator']) && is_array($attribute['configurator'])) {
        foreach ($attribute['configurator'] as $group) {
            $group_name = $group['display_name'] ?? '';
            if (empty($group_name)) continue;
            
            $options = $group['options'] ?? array();
            if (empty($options)) continue;
            
            $processed = array();
            foreach ($options as $option) {
                $option_id = $option['id'] ?? '';
                $image_url = '';
                
                if (!empty($finish_images[$option_id])) {
                    $image_url = $finish_images[$option_id];
                } elseif (!empty($option['primary_image']['image'])) {
                    $image_url = $option['primary_image']['image'];
                }
                
                $code = $option['code'] ?? '';
                if (empty($code)) {
                    $code = sanitize_title($option['option'] ?? '');
                }
                
                $processed[] = array(
                    'id'    => $option_id,
                    'name'  => $option['option'] ?? '',
                    'code'  => $code,
                    'image' => $image_url,
                );
            }
            
            $product['configurator_groups'][] = array(
                'display_name' => $group_name,
                'options'      => $processed,
            );
        }
    }
    
    // Installation instructions
    if (!empty($attribute['installation_instructions']) && is_array($attribute['installation_instructions'])) {
        foreach ($attribute['installation_instructions'] as $inst) {
            $product['installation_instructions'][] = array(
                'title' => $inst['title'] ?? '',
                'file'  => $inst['file'] ?? $inst['url'] ?? '',
            );
        }
    }
    
    // Specifications
    if (!empty($attribute['specifications']) && is_array($attribute['specifications'])) {
        foreach ($attribute['specifications'] as $spec) {
            $product['specifications'][] = array(
                'title' => $spec['title'] ?? '',
                'file'  => $spec['file'] ?? $spec['url'] ?? '',
            );
        }
    }
    
    // ACF Functions
    if (!empty($attribute['functions']) && is_array($attribute['functions'])) {
        foreach ($attribute['functions'] as $func) {
            $modal = $func['function_modal'] ?? $func;
            $product['acf_functions'][] = array(
                'title'       => $modal['title'] ?? '',
                'description' => $modal['description'] ?? '',
                'image'       => $modal['image']['url'] ?? $modal['image'] ?? '',
            );
        }
    }
    
    return $product;
}


/**
 * CSV Export Endpoint
 * Usage: /wp-json/custom/v1/alt-product-csv/?url=YOUR_URL
 */
add_action('rest_api_init', function() {
    register_rest_route('custom/v1', '/alt-product-csv/', array(
        'methods'             => 'GET',
        'callback'            => 'ps_alt_export_csv',
        'permission_callback' => '__return_true',
    ));
});

function ps_alt_export_csv($request) {
    set_time_limit(120);
    
    $url = $request->get_param('url');
    
    if (empty($url)) {
        return new WP_Error('missing_url', 'URL is required', array('status' => 400));
    }
    
    // Get product data using the same scraper
    $response = wp_remote_get($url, array(
        'timeout' => 30,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ),
    ));
    
    if (is_wp_error($response)) {
        return new WP_Error('fetch_failed', $response->get_error_message(), array('status' => 500));
    }
    
    $html = wp_remote_retrieve_body($response);
    
    // Extract product data
    $product_data = ps_extract_json_from_pre_code($html);
    if (empty($product_data)) {
        $product_data = ps_extract_json_from_next_data($html);
    }
    
    if (empty($product_data)) {
        return new WP_Error('no_data', 'No product data found', array('status' => 404));
    }
    
    // Generate CSV
    ps_generate_woocommerce_csv($product_data);
    exit;
}

/**
 * Generate all combinations from configurator groups
 */
function ps_generate_combinations($groups) {
    $result = array(array());
    
    foreach ($groups as $group) {
        $new_result = array();
        foreach ($result as $partial) {
            foreach ($group['options'] as $option) {
                $new_result[] = array_merge($partial, array($option));
            }
        }
        $result = $new_result;
    }
    
    return $result;
}


function ps_generate_woocommerce_csv($product_data) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $filename = 'emtek-product-' . sanitize_title($product_data['title']) . '-' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // === GENERATE PARENT SKU ===
    $parent_sku = sanitize_title($product_data['title']);
    $parent_sku = preg_replace('/[^a-z0-9-]/', '', $parent_sku);
    $parent_sku = trim($parent_sku, '-');
    
    $has_variations = !empty($product_data['configurator_groups']);
    
    // Count configurator groups
    $config_groups = $product_data['configurator_groups'] ?? array();
    $group_count = count($config_groups);
    
    // === HEADERS ===
    $headers = array(
        'ID',
        'Type',
        'SKU',
        'Name',
        'Published',
        'Is featured?',
        'Visibility in catalog',
        'Short description',
        'Description',
        'Date sale price starts',
        'Date sale price ends',
        'Tax status',
        'Tax class',
        'In stock?',
        'Stock',
        'Low stock amount',
        'Backorders allowed?',
        'Sold individually?',
        'Weight (kg)',
        'Length (cm)',
        'Width (cm)',
        'Height (cm)',
        'Allow customer reviews?',
        'Purchase note',
        'Sale price',
        'Regular price',
        'Categories',
        'Tags',
        'Shipping class',
        'Images',
        'Download limit',
        'Download expiry days',
        'Parent',
        'Grouped products',
        'Upsells',
        'Cross-sells',
        'External URL',
        'Button text',
        'Position',
    );
    
    // Add attribute columns for each configurator group
    for ($i = 1; $i <= $group_count; $i++) {
        $headers[] = "Attribute {$i} name";
        $headers[] = "Attribute {$i} value(s)";
        $headers[] = "Attribute {$i} visible";
        $headers[] = "Attribute {$i} global";
    }
    
    // === ACF REPEATER FIELDS ===
    $install_count = count($product_data['installation_instructions'] ?? array());
    $headers[] = 'Meta: installation_instructions';
    for ($i = 0; $i < max($install_count, 1); $i++) {
        $headers[] = "Meta: installation_instructions_{$i}_title";
        $headers[] = "Meta: installation_instructions_{$i}_file";
    }
    
    $spec_count = count($product_data['specifications'] ?? array());
    $headers[] = 'Meta: specifications';
    for ($i = 0; $i < max($spec_count, 1); $i++) {
        $headers[] = "Meta: specifications_{$i}_title";
        $headers[] = "Meta: specifications_{$i}_file";
    }
    
    $func_count = count($product_data['acf_functions'] ?? array());
    $headers[] = 'Meta: functions';
    for ($i = 0; $i < max($func_count, 1); $i++) {
        $headers[] = "Meta: functions_{$i}_title";
        $headers[] = "Meta: functions_{$i}_description";
        $headers[] = "Meta: functions_{$i}_image";
    }
    
    $headers[] = 'Meta: technical_specs';
    
    fputcsv($output, $headers);
    
    // === BUILD PARENT ROW ===
    $short_desc = '';
    if (!empty($product_data['technical_specs'])) {
        $short_desc = wp_trim_words(strip_tags(html_entity_decode($product_data['technical_specs'])), 50);
    }
    
    $categories = !empty($product_data['categories']) ? implode(' > ', $product_data['categories']) : '';
    
    $images = array();
    if (!empty($product_data['image'])) {
        $images[] = $product_data['image'];
    }
    $images_str = implode(', ', $images);
    
    // Parent row starts
    $parent_row = array(
        '',                              // ID (empty = new product)
        $has_variations ? 'variable' : 'simple', // Type
        $parent_sku,                     // SKU
        $product_data['title'],          // Name
        1,                               // Published
        0,                               // Is featured?
        'visible',                       // Visibility in catalog
        $short_desc,                     // Short description
        $product_data['description'],    // Description
        '',                              // Date sale price starts
        '',                              // Date sale price ends
        'taxable',                       // Tax status
        '',                              // Tax class
        1,                               // In stock?
        '',                              // Stock (empty for variable parent)
        '',                              // Low stock amount
        '',                              // Backorders allowed?
        0,                               // Sold individually?
        '', '', '', '',                  // Weight, Length, Width, Height
        1,                               // Allow customer reviews?
        '',                              // Purchase note
        '',                              // Sale price
        '',                              // Regular price (empty for variable)
        $categories,                     // Categories
        '',                              // Tags
        '',                              // Shipping class
        $images_str,                     // Images
        '', '',                          // Download limit, Download expiry
        '',                              // PARENT = empty for parent row
        '',                              // Grouped products
        '',                              // Upsells
        '',                              // Cross-sells
        '',                              // External URL
        '',                              // Button text
        0,                               // Position
    );
    
    // Add attribute columns for parent - ALL values pipe-separated
    for ($i = 0; $i < $group_count; $i++) {
        $group = $config_groups[$i];
        $option_names = array();
        foreach ($group['options'] as $opt) {
            $option_names[] = $opt['name'];
        }
        $parent_row[] = $group['display_name'];              // Attribute name
        $parent_row[] = implode(' , ', $option_names);       // ALL values
        $parent_row[] = 1;                                   // Visible
        $parent_row[] = 1;                                   // Global
    }
    
    // Add ACF data for parent
    $parent_row = array_merge($parent_row, ps_build_acf_row($product_data, $install_count, $spec_count, $func_count));
    
    fputcsv($output, $parent_row);
    
    // === VARIATION ROWS ===
    if ($has_variations) {
        $combinations = ps_generate_combinations($config_groups);
        
        foreach ($combinations as $combo) {
            $var_sku_parts = array($parent_sku);
            $var_values = array();
            $var_image = '';  // Variation image
            
            foreach ($combo as $group_index => $option) {
                $var_sku_parts[] = $option['code'];
                $var_values[] = $option['name'];
                
                // Use the first available image as variation image
                if (empty($var_image) && !empty($option['image'])) {
                    $var_image = $option['image'];
                }
            }
            
            // Also check for finish_images in the product data
            if (empty($var_image) && !empty($product_data['finish_images'])) {
                foreach ($combo as $option) {
                    if (!empty($product_data['finish_images'][$option['id']])) {
                        $var_image = $product_data['finish_images'][$option['id']];
                        break;
                    }
                }
            }
            
            $var_sku = implode('-', $var_sku_parts);
            
            // Variation row
            $variation_row = array(
                '',                              // ID (empty = new)
                'variation',                     // Type
                $var_sku,                        // SKU
                '',                              // Name (inherits from parent)
                1,                               // Published
                0,                               // Is featured?
                'visible',                       // Visibility
                '',                              // Short description
                '',                              // Description
                '', '',                          // Sale dates
                'taxable',                       // Tax status
                '',                              // Tax class
                1,                               // In stock?
                '',                              // Stock
                '',                              // Low stock amount
                '',                              // Backorders
                0,                               // Sold individually?
                '', '', '', '',                  // Dimensions
                1,                               // Allow reviews?
                '',                              // Purchase note
                '',                              // Sale price
                '',                              // Regular price (empty = no price)
                '',                              // Categories
                '',                              // Tags
                '',                              // Shipping class
                $var_image,                      // IMAGES - variation image!
                '', '',                          // Downloads
                $parent_sku,                     // PARENT = parent SKU (CRITICAL!)
                '',                              // Grouped
                '',                              // Upsells
                '',                              // Cross-sells
                '',                              // External URL
                '',                              // Button text
                0,                               // Position
            );
            
            // Attribute values for THIS variation - SINGLE values
            for ($i = 0; $i < $group_count; $i++) {
                $option = $combo[$i];
                $variation_row[] = $config_groups[$i]['display_name'];  // Attribute name
                $variation_row[] = $option['name'];                      // SINGLE value
                $variation_row[] = 1;                                     // Visible
                $variation_row[] = 1;                                     // Global
            }
            
            // Empty ACF fields for variations
            $variation_row = array_merge($variation_row, ps_build_empty_acf_row($install_count, $spec_count, $func_count));
            
            fputcsv($output, $variation_row);
        }
    }
    
    fclose($output);
    exit;
}

/**
 * Build ACF data columns for parent product
 */
function ps_build_acf_row($product_data, $install_count, $spec_count, $func_count) {
    $row = array();
    
    // Installation Instructions (Repeater with title + file)
    $install_instructions = $product_data['installation_instructions'] ?? array();
    $row[] = count($install_instructions);  // Repeater row count
    for ($i = 0; $i < max($install_count, 1); $i++) {
        if (isset($install_instructions[$i])) {
            $inst = $install_instructions[$i];
            $row[] = $inst['title'] ?? '';
            $row[] = $inst['file'] ?? '';  // File URL (ACF will download it)
        } else {
            $row[] = '';
            $row[] = '';
        }
    }
    
    // Specifications (Repeater with title + file)
    $specifications = $product_data['specifications'] ?? array();
    $row[] = count($specifications);  // Repeater row count
    for ($i = 0; $i < max($spec_count, 1); $i++) {
        if (isset($specifications[$i])) {
            $spec = $specifications[$i];
            $row[] = $spec['title'] ?? '';
            $row[] = $spec['file'] ?? '';  // File URL
        } else {
            $row[] = '';
            $row[] = '';
        }
    }
    
    // ACF Functions (Repeater with title + description + image)
    $acf_functions = $product_data['acf_functions'] ?? array();
    $row[] = count($acf_functions);  // Repeater row count
    for ($i = 0; $i < max($func_count, 1); $i++) {
        if (isset($acf_functions[$i])) {
            $func = $acf_functions[$i];
            $row[] = $func['title'] ?? '';
            $row[] = wp_strip_all_tags(html_entity_decode($func['description'] ?? ''));
            $row[] = $func['image'] ?? '';  // Image URL (ACF will import it)
        } else {
            $row[] = '';
            $row[] = '';
            $row[] = '';
        }
    }
    
    // Technical Specs (simple WYSIWYG field)
    $row[] = html_entity_decode($product_data['technical_specs'] ?? '');
    
    return $row;
}

/**
 * Build empty ACF columns for variation rows
 */
function ps_build_empty_acf_row($install_count, $spec_count, $func_count) {
    $row = array();
    
    // Installation Instructions
    $row[] = '';  // count
    for ($i = 0; $i < max($install_count, 1); $i++) {
        $row[] = '';
        $row[] = '';
    }
    
    // Specifications
    $row[] = '';  // count
    for ($i = 0; $i < max($spec_count, 1); $i++) {
        $row[] = '';
        $row[] = '';
    }
    
    // ACF Functions
    $row[] = '';  // count
    for ($i = 0; $i < max($func_count, 1); $i++) {
        $row[] = '';
        $row[] = '';
        $row[] = '';
    }
    
    // Technical Specs
    $row[] = '';
    
    return $row;
}

/**
 * Category Products CSV - Bulk Export
 * Usage: /wp-json/custom/v1/alt-category-csv/?url=CATEGORY_URL
 */
add_action('rest_api_init', function() {
    register_rest_route('custom/v1', '/alt-category-csv/', array(
        'methods'             => 'GET',
        'callback'            => 'ps_alt_category_csv',
        'permission_callback' => '__return_true',
    ));
});

/**
 * Category Products JSON - Preview
 * Usage: /wp-json/custom/v1/alt-category/?url=CATEGORY_URL
 */
add_action('rest_api_init', function() {
    register_rest_route('custom/v1', '/alt-category/', array(
        'methods'             => 'GET',
        'callback'            => 'ps_alt_category_json',
        'permission_callback' => '__return_true',
    ));
});

/**
 * Get product URLs from category listing page
 */
/**
 * Get product URLs from category listing page
 * Works with or without simple_html_dom library
 */
function ps_get_product_urls_from_category($category_url) {
    $response = wp_remote_get($category_url, array(
        'timeout' => 30,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'text/html,application/xhtml+xml',
        ),
    ));
    
    if (is_wp_error($response)) {
        return array();
    }
    
    $html = wp_remote_retrieve_body($response);
    
    // Debug: Check if HTML was fetched
    if (empty($html)) {
        error_log('Viefe Scraper: Empty HTML from ' . $category_url);
        return array();
    }
    
    $product_urls = array();
    
    // Method 1: Try Simple HTML DOM if available
    if (function_exists('str_get_html')) {
        $dom = str_get_html($html);
        
        if ($dom) {
            // Try multiple selectors
            $selectors = array(
                'ul.product-grid_gridList__q6ju2 li.product-grid-tile_tileListItem___80_W a.al-grid__tile',
                '.product-grid_gridList__q6ju2 a[href]',
                'li[class*="tileListItem"] a[href]',
                'a[href*="/all-products/"][class*="tile"]',
            );
            
            foreach ($selectors as $selector) {
                $links = $dom->find($selector);
                if (!empty($links)) break;
            }
            
            if (!empty($links)) {
                foreach ($links as $link) {
                    $href = $link->getAttribute('href');
                    if (!empty($href) && strpos($href, '/all-products/') !== false) {
                        if (strpos($href, 'http') !== 0) {
                            $href = 'https://www.emtek.com' . $href;
                        }
                        if (!in_array($href, $product_urls)) {
                            $product_urls[] = $href;
                        }
                    }
                }
            }
            
            $dom->clear();
            unset($dom);
        }
    }
    
    // Method 2: Regex fallback (always works, no library needed)
    if (empty($product_urls)) {
        // Find all product links in the grid area
        // Pattern matches: href="/all-products/.../.../product-name/"
        preg_match_all('/href="(\/all-products\/(?:[^"]+?\/){2,})"/i', $html, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $href) {
                $href = 'https://www.emtek.com' . $href;
                if (!in_array($href, $product_urls) && strpos($href, '?') === false) {
                    $product_urls[] = $href;
                }
            }
        }
    }
    
    // Method 3: Look for Next.js data containing product list
    if (empty($product_urls)) {
        preg_match('/<script[^>]*id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/si', $html, $matches);
        
        if (!empty($matches[1])) {
            $next_data = json_decode(trim($matches[1]), true);
            
            if ($next_data) {
                // Try to navigate to product list
                $props = $next_data['props']['pageProps'] ?? array();
                
                // Look for products in various possible locations
                $search_paths = array(
                    'products',
                    'items',
                    'results',
                    'data',
                    'wagtail.products',
                    'page.products',
                );
                
                foreach ($search_paths as $path) {
                    $keys = explode('.', $path);
                    $data = $props;
                    foreach ($keys as $key) {
                        $data = $data[$key] ?? null;
                        if ($data === null) break;
                    }
                    
                    if (is_array($data)) {
                        foreach ($data as $item) {
                            $slug = $item['slug'] ?? $item['url'] ?? $item['path'] ?? $item['meta']['slug'] ?? '';
                            if (!empty($slug)) {
                                $href = 'https://www.emtek.com/all-products/' . ltrim($slug, '/');
                                if (!in_array($href, $product_urls)) {
                                    $product_urls[] = $href;
                                }
                            }
                        }
                        if (!empty($product_urls)) break;
                    }
                }
            }
        }
    }
    
    // Debug log
    error_log('Viefe Scraper: Found ' . count($product_urls) . ' product URLs from ' . $category_url);
    
    return $product_urls;
}
/**
 * Extract breadcrumb categories from HTML
 */
function ps_extract_breadcrumb_categories($html) {
    $categories = array();
    
    if (function_exists('str_get_html')) {
        $dom = str_get_html($html);
        
        if ($dom) {
            $breadcrumb_links = $dom->find('.breadcrumbs_link__Asi34');
            $all_parts = array();
            
            foreach ($breadcrumb_links as $link) {
                $text = trim($link->innertext);
                $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                
                if (!empty($text) && $text !== 'All Products' && $text !== 'Home') {
                    $all_parts[] = $text;
                }
            }
            
            // Build hierarchy with parent > child
            $current_path = '';
            foreach ($all_parts as $part) {
                if (empty($current_path)) {
                    $current_path = $part;
                } else {
                    $current_path .= ' > ' . $part;
                }
                $categories[] = $current_path;
            }
            
            $dom->clear();
            unset($dom);
        }
    }
    
    return $categories;
}

/**
 * Scrape single product via alt method - WITH FULL HIERARCHY CATEGORIES
 */
function ps_scrape_product_alt($product_url) {
    $response = wp_remote_get($product_url, array(
        'timeout' => 30,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ),
    ));
    
    if (is_wp_error($response)) return null;
    
    $html = wp_remote_retrieve_body($response);
    
    // Extract JSON from <pre><code>
    $product_data = ps_extract_json_from_pre_code($html);
    
    if (empty($product_data)) {
        $product_data = ps_extract_json_from_next_data($html);
    }
    
    if ($product_data) {
        // Get categories from URL (full hierarchy)
        $url_cats = ps_extract_categories_from_url($product_url);
        
        // Get breadcrumbs as backup
        $breadcrumb_cats = ps_extract_breadcrumb_categories($html);
        
        // Use URL categories (more complete), fallback to breadcrumbs
        if (!empty($url_cats)) {
            $product_data['categories'] = $url_cats;
        } elseif (!empty($breadcrumb_cats)) {
            $product_data['categories'] = $breadcrumb_cats;
        }
    }
    
    return $product_data;
}

/**
 * Extract categories from product URL path - FULL HIERARCHY
 * Example: /all-products/door-hardware/sliding-door-hardware/pocket-door-locks/product-name/
 * Returns: ['Door Hardware', 'Sliding Door Hardware', 'Pocket Door Locks']
 * Hierarchy: 'Door Hardware > Sliding Door Hardware > Pocket Door Locks'
 */
function ps_extract_categories_from_url($url) {
    $path = parse_url($url, PHP_URL_PATH);
    $all_categories = array();
    
    // Remove /all-products/ prefix
    if (preg_match('/\/all-products\/(.+?)\/[^\/]+\/?$/', $path, $matches)) {
        $category_path = $matches[1];
        $parts = explode('/', $category_path);
        
        $formatted = array();
        foreach ($parts as $cat) {
            $cat = str_replace('-', ' ', $cat);
            $cat = ucwords($cat);
            $formatted[] = $cat;
        }
        
        // Build full hierarchy with parent > child
        $current_path = '';
        foreach ($formatted as $cat) {
            if (empty($current_path)) {
                $current_path = $cat;
            } else {
                $current_path .= ' > ' . $cat;
            }
            $all_categories[] = $current_path;
        }
        
        return $all_categories;
    }
    
    return array();
}

/**
 * Count total variations for a product
 */
function ps_count_variations($product_data) {
    if (empty($product_data['configurator_groups'])) return 0;
    
    $count = 1;
    foreach ($product_data['configurator_groups'] as $group) {
        $count *= count($group['options'] ?? array());
    }
    return $count;
}

/**
 * Category JSON endpoint - preview products before CSV download
 */
function ps_alt_category_json($request) {
    set_time_limit(300);
    
    $url = $request->get_param('url');
    
    if (empty($url)) {
        return new WP_Error('missing_url', 'URL is required', array('status' => 400));
    }
    
    $product_urls = ps_get_product_urls_from_category($url);
    
    if (empty($product_urls)) {
        return new WP_Error('no_products', 'No product URLs found', array('status' => 404));
    }
    
    $products = array();
    
    foreach ($product_urls as $index => $product_url) {
        $product_data = ps_scrape_product_alt($product_url);
        
        $products[] = array(
            'index' => $index + 1,
            'url' => $product_url,
            'title' => $product_data['title'] ?? 'FAILED',
            'variations' => ps_count_variations($product_data),
            'groups' => array_map(function($g) { 
                return $g['display_name'] . ' (' . count($g['options']) . ')'; 
            }, $product_data['configurator_groups'] ?? array()),
        );
        
        usleep(300000); // 0.3s delay
    }
    
    return array(
        'success' => true,
        'category_url' => $url,
        'total_products' => count($products),
        'products' => $products,
    );
}

/**
 * Category CSV endpoint - download combined CSV
 */
function ps_alt_category_csv($request) {
    set_time_limit(600);
    ini_set('memory_limit', '1024M');
    
    $url = $request->get_param('url');
    
    if (empty($url)) {
        return new WP_Error('missing_url', 'URL is required', array('status' => 400));
    }
    
    // Get product URLs
    $product_urls = ps_get_product_urls_from_category($url);
    
    if (empty($product_urls)) {
        return new WP_Error('no_products', 'No product URLs found', array('status' => 404));
    }
    
    // Start CSV
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $filename = 'emtek-products-' . date('Y-m-d-His') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Build max attribute count from first few products
    $max_attrs = 0;
    $sample = array_slice($product_urls, 0, 5);
    foreach ($sample as $purl) {
        $pdata = ps_scrape_product_alt($purl);
        if ($pdata) {
            $max_attrs = max($max_attrs, count($pdata['configurator_groups'] ?? array()));
        }
    }
    
    // Headers
    $headers = array(
        'ID', 'Type', 'SKU', 'Name', 'Published', 'Is featured?',
        'Visibility in catalog', 'Short description', 'Description',
        'Date sale price starts', 'Date sale price ends', 'Tax status', 'Tax class',
        'In stock?', 'Stock', 'Low stock amount', 'Backorders allowed?',
        'Sold individually?', 'Weight (kg)', 'Length (cm)', 'Width (cm)', 'Height (cm)',
        'Allow customer reviews?', 'Purchase note', 'Sale price', 'Regular price',
        'Categories', 'Tags', 'Shipping class', 'Images',
        'Download limit', 'Download expiry days', 'Parent',
        'Grouped products', 'Upsells', 'Cross-sells', 'External URL', 'Button text', 'Position',
    );
    
    for ($i = 1; $i <= max($max_attrs, 1); $i++) {
        $headers[] = "Attribute {$i} name";
        $headers[] = "Attribute {$i} value(s)";
        $headers[] = "Attribute {$i} visible";
        $headers[] = "Attribute {$i} global";
    }
    
    // ACF columns
    $max_install = 5; $max_spec = 5; $max_func = 5;
    $headers[] = 'Meta: installation_instructions';
    for ($i = 0; $i < $max_install; $i++) {
        $headers[] = "Meta: installation_instructions_{$i}_title";
        $headers[] = "Meta: installation_instructions_{$i}_file";
    }
    $headers[] = 'Meta: specifications';
    for ($i = 0; $i < $max_spec; $i++) {
        $headers[] = "Meta: specifications_{$i}_title";
        $headers[] = "Meta: specifications_{$i}_file";
    }
    $headers[] = 'Meta: functions';
    for ($i = 0; $i < $max_func; $i++) {
        $headers[] = "Meta: functions_{$i}_title";
        $headers[] = "Meta: functions_{$i}_description";
        $headers[] = "Meta: functions_{$i}_image";
    }
    $headers[] = 'Meta: technical_specs';
    
    fputcsv($output, $headers);
    
    // Process each product
    foreach ($product_urls as $product_url) {
        $product_data = ps_scrape_product_alt($product_url);
        
        if (!$product_data || empty($product_data['title'])) {
            continue;
        }
        
        ps_write_product_to_csv($output, $product_data, $max_attrs, $max_install, $max_spec, $max_func);
        
        usleep(300000); // 0.3s delay
    }
    
    fclose($output);
    exit;
}

/**
 * Write a single product (parent + variations) to CSV
 */
function ps_write_product_to_csv($output, $product_data, $max_attrs, $max_install, $max_spec, $max_func) {
    $parent_sku = sanitize_title($product_data['title']);
    $parent_sku = preg_replace('/[^a-z0-9-]/', '', $parent_sku);
    $parent_sku = trim($parent_sku, '-');
    
    $has_variations = !empty($product_data['configurator_groups']);
    $config_groups = $product_data['configurator_groups'] ?? array();
    $group_count = count($config_groups);
    
    // Short description
    $short_desc = '';
    if (!empty($product_data['technical_specs'])) {
        $short_desc = wp_trim_words(strip_tags(html_entity_decode($product_data['technical_specs'])), 50);
    }
    
      // Use all category levels, separated by commas for WooCommerce
    $all_cats = $product_data['categories'] ?? array();
    $categories = implode(', ', $all_cats);

    // $categories = !empty($product_data['categories']) ? implode(' > ', $product_data['categories']) : '';
    
    $images_str = !empty($product_data['image']) ? $product_data['image'] : '';
    
    // === PARENT ROW ===
    $parent_row = array(
        '', $has_variations ? 'variable' : 'simple', $parent_sku, $product_data['title'],
        1, 0, 'visible', $short_desc, $product_data['description'],
        '', '', 'taxable', '', 1, '', '', '', 0, '', '', '', '',
        1, '', '', '', $categories, '', '', $images_str,
        '', '', '', '', '', '', '', '', 0,
    );
    
    // Attributes for parent
    for ($i = 0; $i < $max_attrs; $i++) {
        if ($i < $group_count) {
            $group = $config_groups[$i];
            $names = array_map(function($o) { return $o['name']; }, $group['options']);
            $parent_row[] = $group['display_name'];
            $parent_row[] = implode(' , ', $names);
            $parent_row[] = 1;
            $parent_row[] = 1;
        } else {
            $parent_row[] = ''; $parent_row[] = ''; $parent_row[] = ''; $parent_row[] = '';
        }
    }
    
    // ACF fields
    $parent_row = array_merge($parent_row, ps_build_acf_row_fixed($product_data, $max_install, $max_spec, $max_func));
    
    fputcsv($output, $parent_row);
    
    // === VARIATION ROWS ===
    if ($has_variations && $group_count > 0) {
        $combinations = ps_generate_combinations($config_groups);
        
        foreach ($combinations as $combo) {
            $sku_parts = array($parent_sku);
            $var_image = '';
            
            foreach ($combo as $opt) {
                $sku_parts[] = $opt['code'];
                if (empty($var_image) && !empty($opt['image'])) {
                    $var_image = $opt['image'];
                }
            }
            
            $var_sku = implode('-', $sku_parts);
            
            $var_row = array(
                '', 'variation', $var_sku, '',
                1, 0, 'visible', '', '',
                '', '', 'taxable', '', 1, '', '', '', 0, '', '', '', '',
                1, '', '', '', '', '', '', $var_image,
                '', '', $parent_sku, '', '', '', '', '', 0,
            );
            
            // Single attribute values
            for ($i = 0; $i < $max_attrs; $i++) {
                if ($i < $group_count && isset($combo[$i])) {
                    $var_row[] = $config_groups[$i]['display_name'];
                    $var_row[] = $combo[$i]['name'];
                    $var_row[] = 1;
                    $var_row[] = 1;
                } else {
                    $var_row[] = ''; $var_row[] = ''; $var_row[] = ''; $var_row[] = '';
                }
            }
            
            // Empty ACF
            $var_row = array_merge($var_row, ps_build_empty_acf_row_fixed($max_install, $max_spec, $max_func));
            
            fputcsv($output, $var_row);
        }
    }
}

/**
 * Build ACF row with fixed max counts
 */
function ps_build_acf_row_fixed($product_data, $max_install, $max_spec, $max_func) {
    $row = array();
    
    $install = $product_data['installation_instructions'] ?? array();
    $row[] = count($install);
    for ($i = 0; $i < $max_install; $i++) {
        $row[] = $install[$i]['title'] ?? '';
        $row[] = $install[$i]['file'] ?? '';
    }
    
    $specs = $product_data['specifications'] ?? array();
    $row[] = count($specs);
    for ($i = 0; $i < $max_spec; $i++) {
        $row[] = $specs[$i]['title'] ?? '';
        $row[] = $specs[$i]['file'] ?? '';
    }
    
    $funcs = $product_data['acf_functions'] ?? array();
    $row[] = count($funcs);
    for ($i = 0; $i < $max_func; $i++) {
        $row[] = $funcs[$i]['title'] ?? '';
        $row[] = wp_strip_all_tags(html_entity_decode($funcs[$i]['description'] ?? ''));
        $row[] = $funcs[$i]['image'] ?? '';
    }
    
    $row[] = html_entity_decode($product_data['technical_specs'] ?? '');
    
    return $row;
}

/**
 * Build empty ACF row
 */
function ps_build_empty_acf_row_fixed($max_install, $max_spec, $max_func) {
    $row = array();
    
    $row[] = '';
    for ($i = 0; $i < $max_install; $i++) { $row[] = ''; $row[] = ''; }
    
    $row[] = '';
    for ($i = 0; $i < $max_spec; $i++) { $row[] = ''; $row[] = ''; }
    
    $row[] = '';
    for ($i = 0; $i < $max_func; $i++) { $row[] = ''; $row[] = ''; $row[] = ''; }
    
    $row[] = '';
    
    return $row;
}