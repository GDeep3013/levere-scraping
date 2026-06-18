<?php
// =============================================
// VIEFE FINISH SYNC - UPDATED FOR EXTERNAL API
// =============================================

if (!defined('ABSPATH')) {
    exit;
}

define('VIEFE_EXTERNAL_API_URL', 'https://610weblab.in/scraper/viefe-products.php');

/**
 * Class Viefe_Finish_Sync
 * Shows finishes in admin editor and on frontend
 */
class Viefe_Finish_Sync {

    public function __construct() {
        // Admin menu for batch sync
        add_action('admin_menu', array($this, 'add_sync_menu'), 200);
        
        // AJAX handlers
        add_action('wp_ajax_viefe_sync_finishes', array($this, 'ajax_sync_finishes'));
        add_action('wp_ajax_viefe_update_single_finish', array($this, 'ajax_update_single_finish'));
        
        // Display finishes in admin product editor
        add_action('add_meta_boxes', array($this, 'add_finish_meta_box'));
        add_action('save_post_product', array($this, 'save_finish_images'), 10, 3);
        
        // Display finish swatches on frontend
        add_action('woocommerce_before_add_to_cart_button', array($this, 'display_finish_swatches'), 5);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // Save variation finish image
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_finish_image'), 10, 2);
    }
    
    /**
     * Normalize product title for matching (remove special chars, lowercase)
     */
    private function normalize_title($title) {
        $title = strtolower($title);
        $title = str_replace('door stopper', '', $title);
        $title = str_replace('door-stopper', '', $title);
        $title = trim($title);
        return $title;
    }
    
    /**
     * Save variation finish image
     */
    public function save_variation_finish_image($variation_id, $loop) {
        if (isset($_POST['finish_image'][$loop])) {
            update_post_meta($variation_id, '_finish_image', esc_url_raw($_POST['finish_image'][$loop]));
        }
    }
    
    /**
     * Add finish meta box to admin product editor
     */
    public function add_finish_meta_box() {
        add_meta_box(
            'viefe_finish_images_box',
            __('Viefe Finish Images', 'viefe-scraper'),
            array($this, 'render_finish_meta_box'),
            'product',
            'side',
            'high'
        );
    }
    
    /**
     * Render finish meta box in admin
     */
    public function render_finish_meta_box($post) {
        $finishes = get_post_meta($post->ID, '_viefe_finish_images', true);
        
        echo '<div class="viefe-admin-finishes">';
        
        if (!empty($finishes) && is_array($finishes)) {
            echo '<div style="margin-bottom: 15px;">';
            echo '<strong>Saved Finishes:</strong>';
            echo '</div>';
            
            foreach ($finishes as $finish) {
                echo '<div style="margin-bottom: 12px; display: flex; align-items: center; gap: 10px; padding: 8px; background: #f9f9f9; border-radius: 4px;">';
                if (!empty($finish['image_url'])) {
                    echo '<img src="' . esc_url($finish['image_url']) . '" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 1px solid #ddd;" />';
                } else {
                    echo '<div style="width: 40px; height: 40px; border-radius: 50%; background: #ddd; display: flex; align-items: center; justify-content: center;">?</div>';
                }
                echo '<span style="flex: 1;"><strong>' . esc_html($finish['name']) . '</strong></span>';
                echo '</div>';
            }
        } else {
            echo '<p style="color: #666;">No finishes added yet.</p>';
        }
        
        echo '<hr style="margin: 15px 0;">';
        echo '<p><a href="' . admin_url('admin.php?page=viefe-sync-finishes-api') . '" class="button button-primary button-small">Sync Finishes from API</a></p>';
        echo '<p class="description">Click sync to automatically fetch finishes from Viefe API.</p>';
        echo '</div>';
        
        echo '<style>
        .viefe-admin-finishes { padding: 5px 0; }
        </style>';
    }
    
    /**
     * Save finish images (for manual edits)
     */
    public function save_finish_images($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if ($post->post_type != 'product') return;
        // Manual save is handled by the sync tool
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        if (is_product()) {
            wp_add_inline_style('woocommerce-general', $this->get_frontend_css());
        }
    }
    
    /**
     * Get frontend CSS
     */
    private function get_frontend_css() {
        return '
        .viefe-product-finishes { margin: 20px 0 25px; padding: 18px; background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 8px; }
        .viefe-finishes-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e5e5e5; }
        .viefe-finishes-header strong { font-size: 16px; font-weight: 600; color: #333; }
        .selected-finish-text { font-size: 14px; color: #2271b1; font-weight: 500; }
        .viefe-finishes-grid { display: flex; flex-wrap: wrap; gap: 15px; }
        .viefe-finish-option { cursor: pointer; text-align: center; transition: all 0.2s ease; }
        .viefe-finish-option:hover { transform: translateY(-3px); }
        .swatch-circle { width: 65px; height: 65px; border-radius: 50%; border: 2px solid #ddd; overflow: hidden; transition: all 0.2s ease; background: white; margin-bottom: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .swatch-circle img { width: 100%; height: 100%; object-fit: cover; }
        .viefe-finish-option.selected .swatch-circle { border-color: #2271b1; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2271b1; transform: scale(1.05); }
        .swatch-label { font-size: 12px; display: block; max-width: 75px; word-break: break-word; color: #666; }
        .viefe-finish-option.selected .swatch-label { color: #2271b1; font-weight: 600; }
        .variations tbody tr:first-child { display: none !important; }
        ';
    }
    
    /**
     * Get finish to variation image mapping from _ps_finish_image
     */
    private function get_finish_variation_image_map($product) {
        $finish_image_map = array();
        
        if (!$product->is_type('variable')) {
            return $finish_image_map;
        }
        
        $variations = $product->get_children();
        
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            
            // Check for ps_finish_image meta
            $ps_finish_image = get_post_meta($variation_id, '_ps_finish_image', true);
            if (empty($ps_finish_image)) {
                $ps_finish_image = get_post_meta($variation_id, 'ps_finish_image', true);
            }
            
            if (!empty($ps_finish_image)) {
                // Get variation attributes
                $attributes = $variation->get_attributes();
                
                // Look for finish attribute
                foreach ($attributes as $attr_name => $attr_value) {
                    if (!empty($attr_value) && (strpos($attr_name, 'finish') !== false || strpos($attr_name, 'pa_finish') !== false)) {
                        $finish_image_map[$attr_value] = $ps_finish_image;
                        break;
                    }
                }
                
                // Also try to get finish name directly
                $finish_attribute = $variation->get_attribute('finish');
                if (empty($finish_attribute)) {
                    $finish_attribute = $variation->get_attribute('pa_finish');
                }
                
                if (!empty($finish_attribute) && !isset($finish_image_map[$finish_attribute])) {
                    $finish_image_map[$finish_attribute] = $ps_finish_image;
                }
            }
        }
        
        return $finish_image_map;
    }
    
    /**
     * Display finish swatches on frontend
     */
    public function display_finish_swatches() {
        global $product;
        
        if (!$this->is_viefe_brand_product($product->get_id())) {
            return;
        }
        
        $finishes = get_post_meta($product->get_id(), '_viefe_finish_images', true);
        
        if (empty($finishes) || !is_array($finishes)) {
            return;
        }
        
        $finish_variation_images = $this->get_finish_variation_image_map($product);
        ?>
        <div class="viefe-product-finishes">
            <label><strong style="font-size: 16px; font-weight: 600; color: #3b2520;;">Finishes:</strong></label>
            <div class="viefe-finishes-grid">
                <?php foreach ($finishes as $finish): 
                    $finish_name = $finish['name'];
                    $finish_image = $finish['image_url'];
                    $variation_image = isset($finish_variation_images[$finish_name]) ? $finish_variation_images[$finish_name] : '';
                ?>
                <div class="viefe-finish-option" 
                     data-finish-name="<?php echo esc_attr($finish_name); ?>"
                     data-finish-image="<?php echo esc_url($finish_image); ?>"
                     data-variation-image="<?php echo esc_url($variation_image); ?>" style="cursor: pointer; text-align: center; transition: all 0.2s ease;">
                    <div class="swatch-circle" style="width: 65px; height: 65px; border-radius: 50%; border: 2px solid #ddd; overflow: hidden; transition: all 0.2s ease; background: white; margin-bottom: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <?php if ($finish_image): ?>
                            <img src="<?php echo esc_url($finish_image); ?>" alt="<?php echo esc_attr($finish_name); ?>">
                        <?php else: ?>
                            <div class="swatch-placeholder">?</div>
                        <?php endif; ?>
                    </div>
                    <span class="swatch-label" style="font-size: 12px; display: block; max-width: 75px; word-break: break-word; color: #666;"><?php echo esc_html($finish_name); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <style>
            /* .viefe-product-finishes { margin: 20px 0 25px; padding: 18px; background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 8px; } */
        .viefe-finishes-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e5e5e5; }
        .viefe-finishes-header strong { font-size: 16px; font-weight: 600; color: #333; }
        .selected-finish-text { font-size: 14px; color: #2271b1; font-weight: 500; }
        .viefe-finishes-grid { display: flex; flex-wrap: wrap; gap: 15px; }
        .viefe-finish-option { cursor: pointer; text-align: center; transition: all 0.2s ease; }
        .viefe-finish-option:hover { transform: translateY(-3px); }
        .swatch-circle { width: 65px; height: 65px; border-radius: 50%; border: 2px solid #ddd; overflow: hidden; transition: all 0.2s ease; background: white; margin-bottom: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .swatch-circle img { width: 100%; height: 100%; object-fit: cover; }
        .viefe-finish-option.selected .swatch-circle { border-color: #2271b1; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2271b1; transform: scale(1.05); }
        .swatch-label { font-size: 12px; display: block; max-width: 75px; word-break: break-word; color: #666; }
        .viefe-finish-option.selected .swatch-label { color: #2271b1; font-weight: 600; }
        .variations tbody tr:first-child { display: none !important; }
        .variations tbody tr:first-child {
           display:none !important;
        }
        .viefe-finish-option:hover { transform: translateY(-3px); }
        .viefe-finish-option.selected .swatch-circle { border-color: #2271b1; box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2271b1; transform: scale(1.05); }
        .viefe-finish-option.selected .swatch-label { color: #2271b1; font-weight: 600; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            var selectedFinishName = "";
            var selectedFinishImage = "";
            var selectedVariationImage = "";
            var selectedFinishElement = null;
            
            function changeProductImage(imageUrl) {
                if (!imageUrl || imageUrl === "") return false;
                
                var mainImage = $('.woocommerce-product-gallery__wrapper img:first');
                if (mainImage.length) {
                    mainImage.attr('src', imageUrl);
                    mainImage.attr('srcset', imageUrl);
                    mainImage.attr('data-large-image', imageUrl);
                    mainImage.attr('data-zoom-image', imageUrl);
                    
                    var mainLink = $('.woocommerce-product-gallery__wrapper a:first');
                    if (mainLink.length) {
                        mainLink.attr('href', imageUrl);
                    }
                    
                    var gallery = $('.woocommerce-product-gallery');
                    if (gallery.hasClass('woocommerce-product-gallery--without-images')) {
                        gallery.removeClass('woocommerce-product-gallery--without-images');
                    }
                    return true;
                }
                return false;
            }
            
            $(".viefe-finish-option").on("click", function() {
                var finishName = $(this).data("finish-name");
                var finishImage = $(this).data("finish-image");
                var variationImage = $(this).data("variation-image");
                
                $(".viefe-finish-option").removeClass("selected");
                $(this).addClass("selected");
                
                selectedFinishName = finishName;
                selectedFinishImage = finishImage;
                selectedVariationImage = variationImage;
                selectedFinishElement = $(this);
                
                var imageToShow = (variationImage && variationImage !== "") ? variationImage : finishImage;
                if (imageToShow) {
                    changeProductImage(imageToShow);
                }
                
                // Find and select the variation
                var found = false;
                $(".variations select").each(function() {
                    var $select = $(this);
                    $select.find("option").each(function() {
                        if ($(this).text().toLowerCase().indexOf(finishName.toLowerCase()) !== -1) {
                            $select.val($(this).val()).trigger("change");
                            found = true;
                            return false;
                        }
                    });
                    if (found) return false;
                });
            });
            
            $(document).on("found_variation", function(event, variation) {
                var imageToShow = (selectedVariationImage && selectedVariationImage !== "") ? selectedVariationImage : selectedFinishImage;
                if (imageToShow) {
                    changeProductImage(imageToShow);
                }
            });
            
            $(document).on("reset_data", function() {
                if (selectedFinishName && selectedFinishElement) {
                    selectedFinishElement.addClass("selected");
                    var imageToShow = (selectedVariationImage && selectedVariationImage !== "") ? selectedVariationImage : selectedFinishImage;
                    if (imageToShow) {
                        setTimeout(function() { changeProductImage(imageToShow); }, 100);
                    }
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Check if product is Viefe brand
     */
    private function is_viefe_brand_product($product_id) {
        $brands = wp_get_post_terms($product_id, 'product_brand', array('fields' => 'names'));
        foreach ($brands as $brand) {
            if (stripos($brand, 'viefe') !== false) {
                return true;
            }
        }
        
        $product = wc_get_product($product_id);
        if ($product) {
            $sku = $product->get_sku();
            if (!empty($sku) && (strpos($sku, 'VIEFE-') === 0 || preg_match('/^\d+$/', $sku))) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Fetch finish data from the EXTERNAL API
     */
    private function fetch_finishes_from_external_api($category_url) {
        $all_products = array();
        
        // Build the external API URL
        $api_url = VIEFE_EXTERNAL_API_URL . '?url=' . urlencode($category_url);
        
        error_log('VIEFE SYNC: Fetching from external API: ' . $api_url);
        
        $response = wp_remote_get($api_url, array(
            'timeout' => 60,
            'headers' => array(
                'Accept' => 'application/json',
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('VIEFE SYNC ERROR: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('VIEFE SYNC ERROR: Bad response code: ' . $response_code);
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('VIEFE SYNC ERROR: JSON decode error: ' . json_last_error_msg());
            return false;
        }
        
        // Check if response has products array (your API returns {"products": [...]})
        if (isset($data['products']) && is_array($data['products'])) {
            $all_products = $data['products'];
        } elseif (is_array($data) && isset($data[0])) {
            $all_products = $data;
        }
        
        if (empty($all_products)) {
            error_log('VIEFE SYNC: No products found in API response');
            return false;
        }
        
        error_log('VIEFE SYNC: Found ' . count($all_products) . ' products from external API');
        
        $finish_data = array();
        
        foreach ($all_products as $product) {
            $sku = $product['sku'] ?? '';
            $product_id = $product['product_id'] ?? '';
            $title = $product['title'] ?? '';
            
            error_log('VIEFE SYNC: Processing product: ' . $title . ' (SKU: ' . ($sku ?: 'empty') . ', ID: ' . $product_id . ')');
            
            $converted_finishes = array();
            
            // Extract finishes from API response
            if (!empty($product['finishes']) && is_array($product['finishes'])) {
                foreach ($product['finishes'] as $finish) {
                    if (!empty($finish['title']) && !empty($finish['image'])) {
                        $converted_finishes[] = array(
                            'name' => $finish['title'],
                            'image_url' => $finish['image']
                        );
                    }
                }
            }
            
            if (empty($converted_finishes)) {
                error_log('VIEFE SYNC: No finishes for product: ' . $title);
                continue;
            }
            
            error_log('VIEFE SYNC: Product has ' . count($converted_finishes) . ' finishes');
            
            // Map by regular SKU (if exists)
            if (!empty($sku)) {
                $finish_data[$sku] = $converted_finishes;
                error_log('VIEFE SYNC: Mapped by SKU: ' . $sku);
            }
            
            // Map by VIEFE-{product_id} (for products without SKU)
            if (!empty($product_id)) {
                $viefe_sku = 'VIEFE-' . $product_id;
                $finish_data[$viefe_sku] = $converted_finishes;
                error_log('VIEFE SYNC: Mapped by VIEFE SKU: ' . $viefe_sku);
            }
            
            // Also map by title (normalized) as fallback
            if (!empty($title)) {
                $normalized_title = $this->normalize_title($title);
                $finish_data['title_' . $normalized_title] = $converted_finishes;
                error_log('VIEFE SYNC: Mapped by normalized title: ' . $normalized_title);
            }
        }
        
        return $finish_data;
    }
    
    /**
     * Admin menu page
     */
    public function add_sync_menu() {
        add_submenu_page(
            'woocommerce',
            'Sync Viefe Finishes',
            'Sync Viefe Finishes',
            'manage_woocommerce',
            'viefe-sync-finishes-api',
            array($this, 'sync_page')
        );
    }
    
    /**
     * Render the sync page
     */
    public function sync_page() {
        ?>
        <div class="wrap">
            <h1>Sync Viefe Finishes from External API</h1>
            <p>This tool fetches finish images from your external API (<code><?php echo VIEFE_EXTERNAL_API_URL; ?></code>) and saves them to products.</p>
            
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin: 20px 0;">
                <h3>Step 1: Enter Viefe Category URL</h3>
                <div style="display: flex; gap: 15px; align-items: flex-end;">
                    <div style="flex: 1;">
                        <input type="url" id="viefe-category-url" class="regular-text" style="width: 100%;" 
                               placeholder="https://www.viefe.com/en/knobs-and-handles" value="https://www.viefe.com/en/knobs-and-handles">
                        <p class="description">Example: https://www.viefe.com/en/knobs-and-handles or https://www.viefe.com/en/door-stoppers</p>
                    </div>
                    <div>
                        <button type="button" id="fetch-category-data" class="button button-primary">Fetch Finishes</button>
                    </div>
                </div>
                <div id="fetch-status" style="margin-top: 10px;"></div>
            </div>
            
            <div id="sync-container" style="display: none;">
                <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin: 20px 0;">
                    <h3>Step 2: Select Products to Update</h3>
                    <div id="products-list-container">
                        <div id="products-checkboxes"></div>
                    </div>
                    <div style="margin-top: 20px;">
                        <button type="button" id="start-sync" class="button button-primary">Update Selected Products</button>
                        <span class="spinner" style="float: none;"></span>
                    </div>
                </div>
            </div>
            
            <div id="progress-container" style="display: none; background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin: 20px 0;">
                <h3>Update Progress:</h3>
                <div style="background: #ddd; height: 30px; border-radius: 4px;">
                    <div id="progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
                </div>
                <p id="progress-text"></p>
                <div id="progress-log" style="max-height: 300px; overflow: auto; background: #f1f1f1; padding: 10px; font-family: monospace;"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var finishDataMap = {};
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
            var nonce = '<?php echo wp_create_nonce("viefe_sync_finishes"); ?>';
            
            $('#fetch-category-data').on('click', function() {
                var url = $('#viefe-category-url').val();
                if (!url) {
                    alert('Please enter a category URL');
                    return;
                }
                
                var button = $(this);
                button.prop('disabled', true).text('Fetching...');
                $('#fetch-status').html('<span class="spinner is-active" style="float:none;"></span> Fetching finishes from external API...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'viefe_sync_finishes',
                        category_url: url,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            var productsHtml = '<div style="margin-bottom: 15px;">';
                            productsHtml += '<label><input type="checkbox" id="select-all"> Select All (' + response.data.total_products + ' products)</label>';
                            productsHtml += '</div><div id="products-list">';
                            
                            $.each(response.data.products, function(i, product) {
                                productsHtml += '<div style="margin-bottom: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">';
                                productsHtml += '<label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">';
                                productsHtml += '<input type="checkbox" class="product-checkbox" value="' + product.id + '"> ';
                                productsHtml += '<strong>' + product.title + '</strong>';
                                productsHtml += '<span style="color: #666; font-size: 11px;">(SKU: ' + product.sku + ')</span>';
                                productsHtml += '<span style="color: #2271b1; font-size: 11px;"> - ' + product.matched_by + '</span>';
                                productsHtml += '</label>';
                                productsHtml += '</div>';
                            });
                            
                            productsHtml += '</div>';
                            $('#products-checkboxes').html(productsHtml);
                            $('#sync-container').show();
                            $('#fetch-status').html('<span style="color: green;">✓ Found ' + response.data.total_products + ' products with ' + response.data.total_finishes + ' finishes</span>');
                        } else {
                            $('#fetch-status').html('<span style="color: red;">✗ Error: ' + response.data.message + '</span>');
                        }
                        button.prop('disabled', false).text('Fetch Finishes');
                    },
                    error: function(xhr, status, error) {
                        $('#fetch-status').html('<span style="color: red;">✗ AJAX error: ' + error + '</span>');
                        button.prop('disabled', false).text('Fetch Finishes');
                    }
                });
            });
            
            $(document).on('change', '#select-all', function() {
                $('.product-checkbox').prop('checked', $(this).is(':checked'));
            });
            
            $('#start-sync').on('click', function() {
                var selectedProducts = [];
                $('.product-checkbox:checked').each(function() {
                    selectedProducts.push($(this).val());
                });
                
                if (selectedProducts.length === 0) {
                    alert('Please select at least one product');
                    return;
                }
                
                var button = $(this);
                var spinner = button.siblings('.spinner');
                button.prop('disabled', true);
                spinner.addClass('is-active');
                $('#progress-container').show();
                $('#progress-log').empty();
                
                function processBatch(index, total, updated, failed) {
                    if (index >= selectedProducts.length) {
                        $('#progress-log').prepend('<div style="color: green; font-weight: bold; margin-top: 10px;">✓ Complete! Updated: ' + updated + ', Failed: ' + failed + '</div>');
                        button.prop('disabled', false);
                        spinner.removeClass('is-active');
                        return;
                    }
                    
                    var productId = selectedProducts[index];
                    var progress = ((index + 1) / total) * 100;
                    $('#progress-bar').css('width', progress + '%');
                    $('#progress-text').text('Processing ' + (index + 1) + ' of ' + total);
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'viefe_update_single_finish',
                            product_id: productId,
                            nonce: nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#progress-log').prepend('<div style="color: green;">✓ ' + response.data.message + '</div>');
                                updated++;
                            } else {
                                $('#progress-log').prepend('<div style="color: red;">✗ ' + response.data.message + '</div>');
                                failed++;
                            }
                            processBatch(index + 1, total, updated, failed);
                        },
                        error: function() {
                            $('#progress-log').prepend('<div style="color: red;">✗ Error updating product</div>');
                            failed++;
                            processBatch(index + 1, total, updated, failed);
                        }
                    });
                }
                
                processBatch(0, selectedProducts.length, 0, 0);
            });
        });
        </script>
        <?php
    }
    
    // ========== AJAX Handlers ==========
    
    public function ajax_sync_finishes() {
        error_log('=== VIEFE SYNC STARTED (External API) ===');
        
        try {
            if (!check_ajax_referer('viefe_sync_finishes', 'nonce', false)) {
                wp_send_json_error('Security check failed');
                return;
            }
            
            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error('Permission denied');
                return;
            }
            
            $category_url = isset($_POST['category_url']) ? esc_url_raw($_POST['category_url']) : '';
            if (empty($category_url)) {
                wp_send_json_error('Category URL is required');
                return;
            }
            
            error_log('VIEFE SYNC: Category URL: ' . $category_url);
            
            // Fetch finishes from external API
            $finish_data_map = $this->fetch_finishes_from_external_api($category_url);
            
            if (!$finish_data_map || empty($finish_data_map)) {
                wp_send_json_error('No finish data found from external API');
                return;
            }
            
            error_log('VIEFE SYNC: Finish data map size: ' . count($finish_data_map));
            
            // Get all products from your store
            $args = array(
                'post_type' => 'product',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'fields' => 'ids',
            );
            
            $product_ids = get_posts($args);
            error_log('VIEFE SYNC: Found ' . count($product_ids) . ' products in store');
            
            $products = array();
            $total_finishes = 0;
            
            foreach ($product_ids as $id) {
                $product = wc_get_product($id);
                if (!$product) continue;
                
                $sku = $product->get_sku();
                $product_name = $product->get_name();
                $normalized_name = $this->normalize_title($product_name);
                $finishes = null;
                $matched_by = '';
                
                // Strategy 1: Match by SKU directly
                if (!empty($sku) && isset($finish_data_map[$sku])) {
                    $finishes = $finish_data_map[$sku];
                    $matched_by = 'SKU: ' . $sku;
                    error_log('VIEFE SYNC: Matched by SKU: ' . $sku);
                }
                // Strategy 2: Match by VIEFE-{product_id} SKU pattern
                elseif (!empty($sku) && isset($finish_data_map[$sku])) {
                    $finishes = $finish_data_map[$sku];
                    $matched_by = 'VIEFE SKU: ' . $sku;
                }
                // Strategy 3: Match by normalized title
                elseif (isset($finish_data_map['title_' . $normalized_name])) {
                    $finishes = $finish_data_map['title_' . $normalized_name];
                    $matched_by = 'Title: ' . $product_name;
                    error_log('VIEFE SYNC: Matched by title: ' . $product_name);
                }
                
                if ($finishes) {
                    $products[] = array(
                        'id' => $id,
                        'title' => $product_name,
                        'sku' => $sku ?: 'no-sku',
                        'matched_by' => $matched_by,
                        'finish_count' => count($finishes)
                    );
                    $total_finishes += count($finishes);
                    
                    // Cache the finishes for this product
                    set_transient('viefe_finish_for_product_' . $id, $finishes, HOUR_IN_SECONDS);
                }
            }
            
            error_log('VIEFE SYNC: Total matched products: ' . count($products));
            error_log('VIEFE SYNC: Total finishes: ' . $total_finishes);
            error_log('=== VIEFE SYNC COMPLETED SUCCESSFULLY ===');
            
            wp_send_json_success(array(
                'products' => $products,
                'total_products' => count($products),
                'total_finishes' => $total_finishes,
                'debug' => array(
                    'api_finish_map_size' => count($finish_data_map),
                    'store_products_count' => count($product_ids)
                )
            ));
            
        } catch (Exception $e) {
            error_log('VIEFE SYNC ERROR: ' . $e->getMessage());
            wp_send_json_error('Critical error: ' . $e->getMessage());
        }
    }
    
    public function ajax_update_single_finish() {
        check_ajax_referer('viefe_sync_finishes', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        $product_id = intval($_POST['product_id']);
        
        if (!$product_id) {
            wp_send_json_error('Invalid product ID');
        }
        
        // Get finishes from cache
        $finishes = get_transient('viefe_finish_for_product_' . $product_id);
        
        if (!$finishes) {
            wp_send_json_error('Finish data expired or not found. Please re-fetch from API.');
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error('Product not found');
        }
        
        // Save finishes to product meta
        update_post_meta($product_id, '_viefe_finish_images', $finishes);
        
        // Also update the product's category if needed
        if (!has_term('', 'product_cat', $product_id)) {
            wp_set_object_terms($product_id, 'Handles', 'product_cat');
        }
        
        wp_send_json_success(array(
            'message' => "Updated {$product->get_name()} with " . count($finishes) . " finishes",
            'finishes' => $finishes
        ));
    }
}

// Initialize the sync class
new Viefe_Finish_Sync();