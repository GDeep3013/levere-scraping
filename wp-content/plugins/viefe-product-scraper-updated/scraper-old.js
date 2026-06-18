jQuery(document).ready(function($) {
    var batchId = null;
    var isRunning = false;
    
    $('#viefe-start-scrape').on('click', function() {
        if (isRunning) return;
        
        var url = $('#viefe_url').val();
        if (!url) {
            alert('Please enter a URL');
            return;
        }
        
        var skipDetails = $('#viefe_skip_details').is(':checked');
        var updateExisting = $('#viefe_update_existing').is(':checked');
        var importImages = $('#viefe_import_images').is(':checked');
        var publish = $('#viefe_publish').is(':checked');
        
        isRunning = true;
        $('#viefe-start-scrape').prop('disabled', true).next('.spinner').addClass('is-active');
        $('#viefe-progress-container').show();
        $('#viefe-progress-log').html('');
        $('#viefe-progress-bar').css('width', '0%');
        
        $.post(viefe_ajax.ajax_url, {
            action: 'viefe_init_scrape',
            nonce: viefe_ajax.nonce,
            url: url,
            skip_details: skipDetails ? 1 : 0
        }, function(response) {
            if (!response.success) {
                alert('Error: ' + response.data);
                resetButton();
                return;
            }
            
            batchId = response.data.batch_id;
            var total = response.data.total;
            
            $('#viefe-progress-text').text('Found ' + total + ' products. Starting import...');
            
            processBatch(0, total, updateExisting, importImages, publish, skipDetails);
        }).fail(function() {
            alert('AJAX error. Please try again.');
            resetButton();
        });
    });
    
    function processBatch(index, total, updateExisting, importImages, publish, skipDetails) {
        $.post(viefe_ajax.ajax_url, {
            action: 'viefe_process_batch',
            nonce: viefe_ajax.nonce,
            batch_id: batchId,
            batch_index: index,
            update_existing: updateExisting ? 1 : 0,
            import_images: importImages ? 1 : 0,
            publish: publish ? 1 : 0,
            skip_details: skipDetails ? 1 : 0
        }, function(response) {
            if (!response.success) {
                $('#viefe-progress-log').append('<div style="color:red;">Error: ' + response.data + '</div>');
                resetButton();
                return;
            }
            
            var data = response.data;
            $('#viefe-progress-bar').css('width', data.progress + '%');
            $('#viefe-progress-text').text(data.message);
            
            if (data.product_title) {
                var color = data.product_result === 'Failed' ? 'red' : (data.product_result === 'Updated' ? 'orange' : 'green');
                $('#viefe-progress-log').append(
                    '<div style="color:' + color + ';">[' + data.processed + '/' + data.total + '] ' + 
                    data.product_title + ' - <strong>' + data.product_result + '</strong></div>'
                );
                $('#viefe-progress-log').scrollTop($('#viefe-progress-log')[0].scrollHeight);
            }
            
            if (data.complete) {
                $('#viefe-progress-text').html('<strong>' + data.message + '</strong>');
                resetButton();
            } else {
                processBatch(data.next_index, total, updateExisting, importImages, publish, skipDetails);
            }
        }).fail(function() {
            $('#viefe-progress-log').append('<div style="color:red;">AJAX error on product ' + (index+1) + '. Retrying in 3 seconds...</div>');
            setTimeout(function() {
                processBatch(index, total, updateExisting, importImages, publish, skipDetails);
            }, 3000);
        });
    }
    
    function resetButton() {
        isRunning = false;
        $('#viefe-start-scrape').prop('disabled', false).next('.spinner').removeClass('is-active');
    }
});


// jQuery(document).ready(function($) {
//     var batchId = null;
//     var isRunning = false;
    
//     $('#viefe-start-scrape').on('click', function() {
//         if (isRunning) return;
        
//         var url = $('#viefe_url').val();
//         if (!url) {
//             alert('Please enter a URL');
//             return;
//         }
        
//         var skipDetails = $('#viefe_skip_details').is(':checked');
//         var updateExisting = $('#viefe_update_existing').is(':checked');
//         var importImages = $('#viefe_import_images').is(':checked');
//         var publish = $('#viefe_publish').is(':checked');
        
//         isRunning = true;
//         $('#viefe-start-scrape').prop('disabled', true).next('.spinner').addClass('is-active');
//         $('#viefe-progress-container').show();
//         $('#viefe-progress-log').html('');
//         $('#viefe-progress-bar').css('width', '0%');
        
//         // Step 1: Initialize scrape
//         $.post(viefe_ajax.ajax_url, {
//             action: 'viefe_init_scrape',
//             nonce: viefe_ajax.nonce,
//             url: url,
//             skip_details: skipDetails ? 1 : 0
//         }, function(response) {
//             if (!response.success) {
//                 alert('Error: ' + response.data);
//                 resetButton();
//                 return;
//             }
            
//             batchId = response.data.batch_id;
//             var total = response.data.total;
            
//             $('#viefe-progress-text').text('Found ' + total + ' products. Starting import...');
            
//             // Step 2: Process batches
//             processBatch(0, total, updateExisting, importImages, publish, skipDetails);
//         }).fail(function() {
//             alert('AJAX error. Please try again.');
//             resetButton();
//         });
//     });
    
//     function processBatch(index, total, updateExisting, importImages, publish, skipDetails) {
//         $.post(viefe_ajax.ajax_url, {
//             action: 'viefe_process_batch',
//             nonce: viefe_ajax.nonce,
//             batch_id: batchId,
//             batch_index: index,
//             update_existing: updateExisting ? 1 : 0,
//             import_images: importImages ? 1 : 0,
//             publish: publish ? 1 : 0,
//             skip_details: skipDetails ? 1 : 0
//         }, function(response) {
//             if (!response.success) {
//                 $('#viefe-progress-log').append('<div style="color:red;">Error: ' + response.data + '</div>');
//                 resetButton();
//                 return;
//             }
            
//             var data = response.data;
//             $('#viefe-progress-bar').css('width', data.progress + '%');
//             $('#viefe-progress-text').text(data.message);
            
//             if (data.product_title) {
//                 var color = data.product_result === 'Failed' ? 'red' : (data.product_result === 'Updated' ? 'orange' : 'green');
//                 $('#viefe-progress-log').append(
//                     '<div style="color:' + color + ';">[' + data.processed + '/' + data.total + '] ' + 
//                     data.product_title + ' - <strong>' + data.product_result + '</strong></div>'
//                 );
//                 $('#viefe-progress-log').scrollTop($('#viefe-progress-log')[0].scrollHeight);
//             }
            
//             if (data.complete) {
//                 $('#viefe-progress-text').html('<strong>' + data.message + '</strong>');
//                 resetButton();
//             } else {
//                 // Process next product
//                 processBatch(data.next_index, total, updateExisting, importImages, publish, skipDetails);
//             }
//         }).fail(function() {
//             $('#viefe-progress-log').append('<div style="color:red;">AJAX error on product ' + (index+1) + '. Retrying in 3 seconds...</div>');
//             setTimeout(function() {
//                 processBatch(index, total, updateExisting, importImages, publish, skipDetails);
//             }, 3000);
//         });
//     }
    
//     function resetButton() {
//         isRunning = false;
//         $('#viefe-start-scrape').prop('disabled', false).next('.spinner').removeClass('is-active');
//     }
// });