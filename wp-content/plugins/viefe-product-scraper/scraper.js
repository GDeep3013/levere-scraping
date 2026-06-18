jQuery(document).ready(function($) {
    let batchId = null;
    let currentIndex = 0;
    let totalProducts = 0;
    let updateExisting = true;
    let importImages = true;
    let publish = true;
    
    $('#viefe-start-scrape').on('click', function() {
        const url = $('#viefe_url').val().trim();
        if (!url) {
            alert('Please enter a valid URL');
            return;
        }
        
        updateExisting = $('#viefe_update_existing').is(':checked');
        importImages = $('#viefe_import_images').is(':checked');
        publish = $('#viefe_publish').is(':checked');
        
        $(this).prop('disabled', true);
        $('.spinner').addClass('is-active');
        $('#viefe-progress-container').show();
        $('#viefe-progress-log').html('');
        $('#viefe-progress-bar').css('width', '0%');
        $('#viefe-progress-text').text('Initializing...');
        
        $.ajax({
            url: viefe_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'viefe_init_scrape',
                nonce: viefe_ajax.nonce,
                url: url
            },
            success: function(response) {
                if (response.success) {
                    batchId = response.data.batch_id;
                    totalProducts = response.data.total;
                    currentIndex = 0;
                    
                    $('#viefe-progress-text').html(response.data.message);
                    addLogEntry('info', 'Started import of ' + totalProducts + ' products');
                    processBatch();
                } else {
                    addLogEntry('error', 'Error: ' + response.data);
                    resetButton();
                }
            },
            error: function(xhr, status, error) {
                addLogEntry('error', 'AJAX Error: ' + error);
                resetButton();
            }
        });
    });
    
    function processBatch() {
        if (!batchId) return;
        
        $.ajax({
            url: viefe_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'viefe_process_batch',
                nonce: viefe_ajax.nonce,
                batch_id: batchId,
                batch_index: currentIndex,
                update_existing: updateExisting ? 1 : 0,
                import_images: importImages ? 1 : 0,
                publish: publish ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    $('#viefe-progress-bar').css('width', data.progress + '%');
                    
                    if (data.complete) {
                        $('#viefe-progress-text').html('<strong>Complete!</strong> ' + data.message);
                        addLogEntry('success', '✅ ' + data.message);
                        resetButton();
                    } else {
                        $('#viefe-progress-text').html(data.message);
                        addLogEntry('info', data.message);
                        currentIndex = data.next_index;
                        processBatch();
                    }
                } else {
                    addLogEntry('error', 'Error: ' + response.data);
                    resetButton();
                }
            },
            error: function(xhr, status, error) {
                addLogEntry('error', 'AJAX Error: ' + error);
                resetButton();
            }
        });
    }
    
    function addLogEntry(type, message) {
        const logDiv = $('#viefe-progress-log');
        const entryClass = type === 'error' ? 'color: #dc3232;' : 
                          (type === 'success' ? 'color: #46b450;' : 'color: #444;');
        logDiv.append('<div style="' + entryClass + '">' + message + '</div>');
        logDiv.scrollTop(logDiv[0].scrollHeight);
    }
    
    function resetButton() {
        $('#viefe-start-scrape').prop('disabled', false);
        $('.spinner').removeClass('is-active');
    }
});