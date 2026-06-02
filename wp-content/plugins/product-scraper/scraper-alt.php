<?php
/**
 * Alternative Scraper - Extracts JSON from <pre><code> tags and __NEXT_DATA__
 * Handles both nested (Next.js) and flat (Wagtail API) JSON structures
 * 
 * Usage: 
 * http://localhost/viefe/wp-json/custom/v1/alt-product/?url=YOUR_URL
 * http://localhost/viefe/wp-json/custom/v1/alt-product-csv/?url=YOUR_URL
 * http://localhost/viefe/wp-json/custom/v1/alt-category/?url=CATEGORY_URL
 * http://localhost/viefe/wp-json/custom/v1/alt-category-csv/?url=CATEGORY_URL
 */

// =============================================
// SINGLE PRODUCT ENDPOINTS
// =============================================

add_action('rest_api_init', function() {
    register_rest_route('custom/v1', '/alt-product/', array(
        'methods'             => 'GET',
        'callback'            => 'ps_alt_scrape_product',
        'permission_callback' => '__return_true',
    ));
});

add_action('rest_api_init', function() {
    register_rest_route('custom/v1', '/alt-product-csv/', array(
        'methods'             => 'GET',
        'callback'            => 'ps_alt_export_csv',
        'permission_callback' => '__return_true',
    ));
});

// =============================================
// CATEGORY ENDPOINTS
// =============================================

add_action('rest_api_init', function() {
    register_rest_route('custom/v1', '/alt-category/', array(
        'methods'             => 'GET',
        'callback'            => 'ps_alt_category_json',
        'permission_callback' => '__return_true',
    ));
});

add_action('rest_api_init', function() {
    register_rest_route('custom/v1', '/alt-category-csv/', array(
        'methods'             => 'GET',
        'callback'            => 'ps_alt_category_csv',
        'permission_callback' => '__return_true',
    ));
});

// =============================================
// SINGLE PRODUCT SCRAPING
// =============================================

function ps_alt_scrape_product($request) {
    set_time_limit(60);
    
    $url = $request->get_param('url');
    
    if (empty($url)) {
        return new WP_Error('missing_url', 'URL is required', array('status' => 400));
    }
    
    $product_data = ps_scrape_product_alt($url);
    
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

function ps_alt_export_csv($request) {
    set_time_limit(120);
    
    $url = $request->get_param('url');
    
    if (empty($url)) {
        return new WP_Error('missing_url', 'URL is required', array('status' => 400));
    }
    
    $product_data = ps_scrape_product_alt($url);
    
    if (empty($product_data)) {
        return new WP_Error('no_data', 'No product data found', array('status' => 404));
    }
    
    ps_generate_woocommerce_csv($product_data);
    exit;
}

// =============================================
// CATEGORY SCRAPING
// =============================================

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
            'categories' => $product_data['categories'] ?? array(),
            'groups' => array_map(function($g) { 
                return $g['display_name'] . ' (' . count($g['options']) . ')'; 
            }, $product_data['configurator_groups'] ?? array()),
        );
        
        usleep(300000);
    }
    
    return array(
        'success' => true,
        'category_url' => $url,
        'total_products' => count($products),
        'products' => $products,
    );
}

function ps_alt_category_csv($request) {
    set_time_limit(600);
    ini_set('memory_limit', '1024M');
    
    $url = $request->get_param('url');
    
    if (empty($url)) {
        return new WP_Error('missing_url', 'URL is required', array('status' => 400));
    }
    
    $product_urls = ps_get_product_urls_from_category($url);
    
    if (empty($product_urls)) {
        return new WP_Error('no_products', 'No product URLs found', array('status' => 404));
    }
    
    while (ob_get_level()) { ob_end_clean(); }
    
    $filename = 'emtek-products-' . date('Y-m-d-His') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Determine max attributes from first few products
    $max_attrs = 0;
    $sample = array_slice($product_urls, 0, 3);
    foreach ($sample as $purl) {
        $pdata = ps_scrape_product_alt($purl);
        if ($pdata) {
            $max_attrs = max($max_attrs, count($pdata['configurator_groups'] ?? array()));
        }
    }
    
    $max_install = 5; $max_spec = 5; $max_func = 5;
    
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
        
        usleep(300000);
    }
    
    fclose($output);
    exit;
}

// =============================================
// CORE SCRAPING FUNCTIONS
// =============================================

function ps_scrape_product_alt($product_url) {
    $response = wp_remote_get($product_url, array(
        'timeout' => 30,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ),
    ));
    
    if (is_wp_error($response)) return null;
    
    $html = wp_remote_retrieve_body($response);
    
    // Method 1: Try pre_code first
    $product_data = ps_extract_json_from_pre_code($html);
    
    // Method 2: Try __NEXT_DATA__
    $next_data = ps_extract_json_from_next_data($html);
    
    if ($next_data) {
        if (empty($product_data)) {
            $product_data = $next_data;
        } else {
            // Merge categories and product codes from next_data
            if (!empty($next_data['categories'])) {
                $product_data['categories'] = $next_data['categories'];
            }
            if (!empty($next_data['product_codes'])) {
                $product_data['product_codes'] = $next_data['product_codes'];
            }
            if (!empty($next_data['meta'])) {
                $product_data['meta'] = $next_data['meta'];
            }
        }
    }
    
    // Fallback: Extract categories from URL
    if ($product_data && empty($product_data['categories'])) {
        $url_cats = ps_extract_categories_from_url($product_url);
        if (!empty($url_cats)) {
            $product_data['categories'] = $url_cats;
        }
    }
    
    return $product_data;
}

function ps_extract_json_from_pre_code($html) {
    preg_match_all('/<pre[^>]*>\s*<code[^>]*>(.*?)<\/code>\s*<\/pre>/si', $html, $matches);
    
    if (empty($matches[1])) return null;
    
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
        }
    }
    
    return null;
}

function ps_extract_json_from_next_data($html) {
    preg_match('/<script[^>]*id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/si', $html, $matches);
    
    if (empty($matches[1])) return null;
    
    $data = json_decode(trim($matches[1]), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) return null;
    
    return ps_parse_next_data_full($data);
}

// =============================================
// JSON PARSERS
// =============================================

function ps_parse_next_data_full($data) {
    $pageProps = $data['props']['pageProps'] ?? array();
    $wagtail = $pageProps['wagtail'] ?? array();
    $attribute = $wagtail['attribute'] ?? array();
    $ancestors = $wagtail['ancestors'] ?? array();
    
    if (empty($attribute) && empty($wagtail['title'])) return null;
    
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
        'product_codes' => array(),
    );
    
    $product['title'] = sanitize_text_field($wagtail['title'] ?? $attribute['option'] ?? $attribute['title'] ?? '');
    $product['product_codes'] = $pageProps['productCodes'] ?? array();
    $product['image'] = esc_url_raw($attribute['primary_image']['image'] ?? $attribute['images'][0]['image'] ?? $wagtail['meta_image_url'] ?? '');
    $product['description'] = html_entity_decode($attribute['description'] ?? '', ENT_QUOTES, 'UTF-8');
    $product['technical_specs'] = html_entity_decode($attribute['technical_specs'] ?? '', ENT_QUOTES, 'UTF-8');
    
    // Categories from ancestors
    if (!empty($ancestors)) {
        $categories = array();
        $current_path = '';
        foreach ($ancestors as $ancestor) {
            $title = $ancestor['title'] ?? '';
            if (!empty($title) && $title !== 'Emtek Home') {
                if (empty($current_path)) {
                    $current_path = $title;
                } else {
                    $current_path .= ' > ' . $title;
                }
                $categories[] = $current_path;
            }
        }
        $product['categories'] = $categories;
    }
    
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
                    'id' => $option_id,
                    'name' => $option['option'] ?? '',
                    'code' => $code,
                    'image' => $image_url,
                );
            }
            
            if (!empty($processed)) {
                $product['configurator_groups'][] = array(
                    'display_name' => $group_name,
                    'options' => $processed,
                );
            }
        }
    }
    
    // Installation instructions
    if (!empty($attribute['installation_instructions']) && is_array($attribute['installation_instructions'])) {
        foreach ($attribute['installation_instructions'] as $inst) {
            $product['installation_instructions'][] = array(
                'title' => $inst['title'] ?? '',
                'file' => $inst['file'] ?? $inst['url'] ?? '',
            );
        }
    }
    
    // Specifications
    if (!empty($attribute['specifications']) && is_array($attribute['specifications'])) {
        foreach ($attribute['specifications'] as $spec) {
            $product['specifications'][] = array(
                'title' => $spec['title'] ?? '',
                'file' => $spec['file'] ?? $spec['url'] ?? '',
            );
        }
    }
    
    // ACF Functions
    if (!empty($attribute['functions']) && is_array($attribute['functions'])) {
        foreach ($attribute['functions'] as $func) {
            $modal = $func['function_modal'] ?? $func;
            $desc = $modal['description'] ?? '';
            if (!empty($desc)) {
                $desc = html_entity_decode($desc, ENT_QUOTES, 'UTF-8');
            }
            $product['acf_functions'][] = array(
                'title' => $modal['title'] ?? $func['option'] ?? '',
                'description' => $desc,
                'image' => $modal['image']['url'] ?? $modal['image'] ?? $func['primary_image']['image'] ?? '',
            );
        }
    }
    
    $product['_method'] = 'next_data';
    return $product;
}

function ps_parse_flat_product_json($data) {
    if (empty($data['option']) && empty($data['title']) && empty($data['configurator'])) return null;
    
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
    
    $product['title'] = $data['option'] ?? $data['title'] ?? '';
    $product['image'] = esc_url_raw($data['primary_image']['image'] ?? $data['images'][0]['image'] ?? '');
    $product['description'] = html_entity_decode($data['description'] ?? '', ENT_QUOTES, 'UTF-8');
    $product['technical_specs'] = html_entity_decode($data['technical_specs'] ?? '', ENT_QUOTES, 'UTF-8');
    
    $finish_images = $data['finish_images'] ?? array();
    if (!is_array($finish_images)) $finish_images = array();
    
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
                
                if (!empty($finish_images[$option_id])) {
                    $image_url = $finish_images[$option_id];
                } elseif (!empty($option['primary_image']['image'])) {
                    $image_url = $option['primary_image']['image'];
                }
                
                $code = $option['code'] ?? '';
                if (empty($code)) $code = sanitize_title($option['option'] ?? '');
                
                $processed[] = array(
                    'id' => $option_id,
                    'name' => $option['option'] ?? '',
                    'code' => $code,
                    'image' => $image_url,
                );
            }
            
            if (!empty($processed)) {
                $product['configurator_groups'][] = array(
                    'display_name' => $group_name,
                    'options' => $processed,
                );
            }
        }
    }
    
    if (!empty($data['installation_instructions']) && is_array($data['installation_instructions'])) {
        foreach ($data['installation_instructions'] as $inst) {
            $product['installation_instructions'][] = array(
                'title' => $inst['title'] ?? '',
                'file' => $inst['file'] ?? $inst['url'] ?? '',
            );
        }
    }
    
    if (!empty($data['specifications']) && is_array($data['specifications'])) {
        foreach ($data['specifications'] as $spec) {
            $product['specifications'][] = array(
                'title' => $spec['title'] ?? '',
                'file' => $spec['file'] ?? $spec['url'] ?? '',
            );
        }
    }
    
    if (!empty($data['functions']) && is_array($data['functions'])) {
        foreach ($data['functions'] as $func) {
            $modal = $func['function_modal'] ?? $func;
            $desc = $modal['description'] ?? '';
            if (!empty($desc)) $desc = html_entity_decode($desc, ENT_QUOTES, 'UTF-8');
            $product['acf_functions'][] = array(
                'title' => $modal['title'] ?? '',
                'description' => $desc,
                'image' => $modal['image']['url'] ?? $modal['image'] ?? '',
            );
        }
    }
    
    return $product;
}

// =============================================
// CATEGORY & URL EXTRACTION
// =============================================

function ps_get_product_urls_from_category($category_url) {
    $response = wp_remote_get($category_url, array(
        'timeout' => 30,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'text/html,application/xhtml+xml',
        ),
    ));
    
    if (is_wp_error($response)) return array();
    
    $html = wp_remote_retrieve_body($response);
    $product_urls = array();
    
    // Method 1: Extract from __NEXT_DATA__ siblings
    preg_match('/<script[^>]*id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/si', $html, $matches);
    
    if (!empty($matches[1])) {
        $next_data = json_decode(trim($matches[1]), true);
        
        if ($next_data) {
            $pageProps = $next_data['props']['pageProps'] ?? array();
            $wagtail = $pageProps['wagtail'] ?? array();
            $ancestors = $wagtail['ancestors'] ?? array();
            
            // Find the category page ancestor that has siblings (products)
            foreach (array_reverse($ancestors) as $ancestor) {
                $siblings = $ancestor['siblings'] ?? array();
                if (!empty($siblings)) {
                    foreach ($siblings as $sibling) {
                        $url = $sibling['url'] ?? '';
                        if (!empty($url) && strpos($url, '/all-products/') !== false) {
                            if (strpos($url, 'http') !== 0) {
                                $url = 'https://www.emtek.com' . $url;
                            }
                            if (!in_array($url, $product_urls)) {
                                $product_urls[] = $url;
                            }
                        }
                    }
                    break;
                }
            }
        }
    }
    
    // Method 2: Regex fallback
    if (empty($product_urls)) {
        preg_match_all('/href="(\/all-products\/[^"]+?\/[^"]+?\/)"/i', $html, $link_matches);
        if (!empty($link_matches[1])) {
            foreach ($link_matches[1] as $href) {
                $href = 'https://www.emtek.com' . $href;
                if (!in_array($href, $product_urls)) {
                    $product_urls[] = $href;
                }
            }
        }
    }
    
    return $product_urls;
}

function ps_extract_categories_from_url($url) {
    $path = parse_url($url, PHP_URL_PATH);
    $categories = array();
    
    if (preg_match('/\/all-products\/(.+?)\/[^\/]+\/?$/', $path, $matches)) {
        $parts = explode('/', $matches[1]);
        $current_path = '';
        foreach ($parts as $cat) {
            $cat = ucwords(str_replace('-', ' ', $cat));
            if (empty($current_path)) {
                $current_path = $cat;
            } else {
                $current_path .= ' > ' . $cat;
            }
            $categories[] = $current_path;
        }
    }
    
    return $categories;
}

function ps_count_variations($product_data) {
    if (empty($product_data['configurator_groups'])) return 0;
    $count = 1;
    foreach ($product_data['configurator_groups'] as $group) {
        $count *= count($group['options'] ?? array());
    }
    return $count;
}

// =============================================
// CSV GENERATION
// =============================================

function ps_generate_woocommerce_csv($product_data) {
    while (ob_get_level()) { ob_end_clean(); }
    
    $filename = 'emtek-product-' . sanitize_title($product_data['title']) . '-' . date('Y-m-d') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    $parent_sku = sanitize_title($product_data['title']);
    $parent_sku = preg_replace('/[^a-z0-9-]/', '', $parent_sku);
    $parent_sku = trim($parent_sku, '-');
    
    $has_variations = !empty($product_data['configurator_groups']);
    $config_groups = $product_data['configurator_groups'] ?? array();
    $group_count = count($config_groups);
    
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
    
    for ($i = 1; $i <= max($group_count, 1); $i++) {
        $headers[] = "Attribute {$i} name";
        $headers[] = "Attribute {$i} value(s)";
        $headers[] = "Attribute {$i} visible";
        $headers[] = "Attribute {$i} global";
    }
    
    $install_count = count($product_data['installation_instructions'] ?? array());
    $spec_count = count($product_data['specifications'] ?? array());
    $func_count = count($product_data['acf_functions'] ?? array());
    
    $headers[] = 'Meta: installation_instructions';
    for ($i = 0; $i < max($install_count, 1); $i++) {
        $headers[] = "Meta: installation_instructions_{$i}_title";
        $headers[] = "Meta: installation_instructions_{$i}_file";
    }
    $headers[] = 'Meta: specifications';
    for ($i = 0; $i < max($spec_count, 1); $i++) {
        $headers[] = "Meta: specifications_{$i}_title";
        $headers[] = "Meta: specifications_{$i}_file";
    }
    $headers[] = 'Meta: functions';
    for ($i = 0; $i < max($func_count, 1); $i++) {
        $headers[] = "Meta: functions_{$i}_title";
        $headers[] = "Meta: functions_{$i}_description";
        $headers[] = "Meta: functions_{$i}_image";
    }
    $headers[] = 'Meta: technical_specs';
    
    fputcsv($output, $headers);
    
    // Parent row
    ps_write_product_to_csv($output, $product_data, $group_count, $install_count, $spec_count, $func_count);
    
    fclose($output);
    exit;
}

// function ps_write_product_to_csv($output, $product_data, $max_attrs, $max_install, $max_spec, $max_func) {
//     $parent_sku = sanitize_title($product_data['title']);
//     $parent_sku = preg_replace('/[^a-z0-9-]/', '', $parent_sku);
//     $parent_sku = trim($parent_sku, '-');
    
//     $has_variations = !empty($product_data['configurator_groups']);
//     $config_groups = $product_data['configurator_groups'] ?? array();
//     $group_count = count($config_groups);
    
//     $short_desc = '';
//     if (!empty($product_data['technical_specs'])) {
//         $short_desc = wp_trim_words(strip_tags(html_entity_decode($product_data['technical_specs'])), 50);
//     }
    
//     // Use all category levels
//     $all_cats = $product_data['categories'] ?? array();
//     $categories = implode(', ', $all_cats);
    
//     $images_str = !empty($product_data['image']) ? $product_data['image'] : '';
    
//     // Parent row
//     $parent_row = array(
//         '', $has_variations ? 'variable' : 'simple', $parent_sku, $product_data['title'],
//         1, 0, 'visible', $short_desc, $product_data['description'],
//         '', '', 'taxable', '', 1, '', '', '', 0, '', '', '', '',
//         1, '', '', '', $categories, '', '', $images_str,
//         '', '', '', '', '', '', '', '', 0,
//     );
    
//     for ($i = 0; $i < $max_attrs; $i++) {
//         if ($i < $group_count) {
//             $group = $config_groups[$i];
//             $names = array_map(function($o) { return $o['name']; }, $group['options']);
//             $parent_row[] = $group['display_name'];
//             $parent_row[] = implode(' | ', $names);
//             $parent_row[] = 1;
//             $parent_row[] = 1;
//         } else {
//             $parent_row[] = ''; $parent_row[] = ''; $parent_row[] = ''; $parent_row[] = '';
//         }
//     }
    
//     // ACF data
//     $parent_row = array_merge($parent_row, ps_build_acf_row_fixed($product_data, $max_install, $max_spec, $max_func));
//     fputcsv($output, $parent_row);
    
//     // Variations
//     if ($has_variations && $group_count > 0) {
//         $combinations = ps_generate_combinations($config_groups);
        
//         foreach ($combinations as $combo) {
//             $sku_parts = array($parent_sku);
//             $var_image = '';

            
//             foreach ($combo as $opt) {
//                 $sku_parts[] = $opt['code'];
//                 if (empty($var_image) && !empty($opt['image'])) {
//                     $var_image = $opt['image'];
//                 }
//             }
            
//             $var_sku = implode('-', $sku_parts);
            
//             $var_row = array(
//                 '', 'variation', $var_sku, '',
//                 1, 0, 'visible', '', '',
//                 '', '', 'taxable', '', 1, '', '', '', 0, '', '', '', '',
//                 1, '', '', '', '', '', '', $var_image,
//                 '', '', $parent_sku, '', '', '', '', '', 0,
//             );
            
//             for ($i = 0; $i < $max_attrs; $i++) {
//                 if ($i < $group_count && isset($combo[$i])) {
//                     $var_row[] = $config_groups[$i]['display_name'];
//                     $var_row[] = $combo[$i]['name'];
//                     $var_row[] = 1;
//                     $var_row[] = 1;
//                 } else {
//                     $var_row[] = ''; $var_row[] = ''; $var_row[] = ''; $var_row[] = '';
//                 }
//             }
            
//             $var_row = array_merge($var_row, ps_build_empty_acf_row_fixed($max_install, $max_spec, $max_func));
//             fputcsv($output, $var_row);
//         }
//     }
// }

function ps_write_product_to_csv($output, $product_data, $max_attrs, $max_install, $max_spec, $max_func) {
    $parent_sku = sanitize_title($product_data['title']);
    $parent_sku = preg_replace('/[^a-z0-9-]/', '', $parent_sku);
    $parent_sku = trim($parent_sku, '-');
    
    $has_variations = !empty($product_data['configurator_groups']);
    $config_groups = $product_data['configurator_groups'] ?? array();
    $group_count = count($config_groups);
    
    $short_desc = '';
    if (!empty($product_data['technical_specs'])) {
        $short_desc = wp_trim_words(strip_tags(html_entity_decode($product_data['technical_specs'])), 50);
    }
    
    $all_cats = $product_data['categories'] ?? array();
    $categories = implode(', ', $all_cats);
    
    $images_str = !empty($product_data['image']) ? $product_data['image'] : '';
    
    // === PARENT ROW ===
    $parent_row = array(
        '', $has_variations ? 'variable' : 'simple', $parent_sku, $product_data['title'],
        1, 0, 'visible', $short_desc, $product_data['description'],
        '', '', 'taxable', '', 1, '', '', '', 0, '', '', '', '',
        1, '', '', '', $categories, '', '', $images_str,
        '', '', '', '', '', '', '', '', 0,
    );
    
    for ($i = 0; $i < $max_attrs; $i++) {
        if ($i < $group_count) {
            $group = $config_groups[$i];
            $names = array_map(function($o) { return $o['name']; }, $group['options']);
            $parent_row[] = $group['display_name'];
            $parent_row[] = implode(' | ', $names);
            $parent_row[] = 1;
            $parent_row[] = 1;
        } else {
            $parent_row[] = ''; $parent_row[] = ''; $parent_row[] = ''; $parent_row[] = '';
        }
    }
    
    $parent_row = array_merge($parent_row, ps_build_acf_row_fixed($product_data, $max_install, $max_spec, $max_func));
    fputcsv($output, $parent_row);
    
    // === VARIATION ROWS ===
    if ($has_variations && $group_count > 0) {
        $combinations = ps_generate_combinations($config_groups);
        
        foreach ($combinations as $combo) {
            $sku_parts = array($parent_sku);
            
            foreach ($combo as $group_index => $opt) {
                $sku_parts[] = $opt['code'];
            }
            
            $var_sku = implode('-', $sku_parts);
            
            // FIXED: No variation images - leave empty
            $var_row = array(
                '', 'variation', $var_sku, '',
                1, 0, 'visible', '', '',
                '', '', 'taxable', '', 1, '', '', '', 0, '', '', '', '',
                1, '', '', '', '', '', '', '',  // Empty image
                '', '', $parent_sku, '', '', '', '', '', 0,
            );
            
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
            
            $var_row = array_merge($var_row, ps_build_empty_acf_row_fixed($max_install, $max_spec, $max_func));
            fputcsv($output, $var_row);
        }
    }
}

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