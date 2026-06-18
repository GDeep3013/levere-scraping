/**
 * Emtek Product Configurator
 * Handles swatch selection and dynamic image switching
 */
jQuery(document).ready(function($) {
    var selectedOptions = {};
    var isLoading = false;
    
    // Initialize selected options - select first option in each group
    $('.ps-configurator-group').each(function() {
        var groupName = $(this).data('group-name');
        var firstSelected = $(this).find('.ps-swatch:first');
        if (firstSelected.length) {
            selectedOptions[groupName] = firstSelected.data('option-name');
            firstSelected.addClass('selected');
        }
    });
    
    // Update hidden input
    $('#ps_selected_options').val(JSON.stringify(selectedOptions));
    
    // Handle swatch click
    $(document).on('click', '.ps-swatch', function() {
        if (isLoading) return;
        
        var $swatch = $(this);
        var $group = $swatch.closest('.ps-configurator-group');
        var groupName = $group.data('group-name');
        var optionName = $swatch.data('option-name');
        
        // Update selected state
        $group.find('.ps-swatch').removeClass('selected');
        $swatch.addClass('selected');
        
        // Update selected options
        selectedOptions[groupName] = optionName;
        $('#ps_selected_options').val(JSON.stringify(selectedOptions));
        
        // Get new product image
        fetchConfiguratorImage();
    });
    
    function fetchConfiguratorImage() {
        var $container = $('.ps-configurator-swatches');
        var productId = $container.data('product-id');
        var buildId = $container.data('build-id');
        var productSlug = $container.data('product-slug');
        
        if (!productId || !buildId || !productSlug) {
            return;
        }
        
        isLoading = true;
        $container.addClass('ps-loading');
        
        $.ajax({
            url: ps_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ps_get_configurator_image',
                nonce: ps_ajax.nonce,
                product_id: productId,
                selected_options: JSON.stringify(selectedOptions)
            },
            success: function(response) {
                if (response.success && response.data.image_url) {
                    updateProductImage(response.data.image_url);
                }
            },
            error: function(xhr, status, error) {
                console.error('Configurator error:', error);
            },
            complete: function() {
                isLoading = false;
                $container.removeClass('ps-loading');
            }
        });
    }
    
    function updateProductImage(imageUrl) {
        // Update main product image
        var $mainImage = $('.woocommerce-product-gallery__image:first');
        var $mainImg = $mainImage.find('img');
        
        if ($mainImg.length) {
            $mainImg.css('opacity', '0.5');
            setTimeout(function() {
                $mainImg.attr('src', imageUrl);
                $mainImg.attr('srcset', imageUrl);
                $mainImg.css('opacity', '1');
            }, 200);
        }
        
        // Update thumbnail gallery
        var $firstThumb = $('.flex-control-nav li:first img');
        if ($firstThumb.length) {
            $firstThumb.attr('src', imageUrl);
        }
        
        // Update data-large-image for zoom
        if ($mainImage.find('a').length) {
            $mainImage.find('a').attr('href', imageUrl);
        }
    }
});