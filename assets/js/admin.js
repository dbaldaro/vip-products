console.log('Local admin.js loaded');

// Add error handler for uncaught errors
window.onerror = function(msg, url, line, col, error) {
    console.error('Uncaught error:', {
        message: msg,
        url: url,
        line: line,
        column: col,
        error: error
    });
    return false;
};

jQuery(document).ready(function($) {
    // Initialize Select2
    $('.wc-customer-search').select2({
        ajax: {
            url: vipProducts.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    term: params.term, // search term
                    action: 'search_users',
                    search_nonce: vipProducts.search_nonce
                };
            },
            processResults: function(response) {
                // Check if we have an error
                if (!response.success) {
                    console.error('Error searching users:', response.data);
                    return { results: [] };
                }
                return {
                    results: response.data
                };
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX error:', textStatus, errorThrown);
            },
            cache: true
        },
        minimumInputLength: 2,
        placeholder: 'Searching...',
        allowClear: true,
        width: '400px',
        language: {
            errorLoading: function() {
                return 'The results could not be loaded.';
            },
            searching: function() {
                return 'Searching...';
            },
            noResults: function() {
                return 'No results found';
            }
        }
    });

    // Get references to the elements
    const vipUsersSelect = $('.wc-customer-search');
    const vipStatusSelect = $('#_product_visibility_type');
    const primaryCategorySelect = $('#product_cat');

    // Handle VIP status change
    vipStatusSelect.on('change', function(e) {
        const isVip = $(this).val() === 'vip';
        const vipCategoryId = '538'; // VIP Products category ID

        if (isVip) {
            // Add VIP category if not already selected
            if (!primaryCategorySelect.find(`option[value="${vipCategoryId}"]`).prop('selected')) {
                primaryCategorySelect.val(vipCategoryId).trigger('change');
            }
        }
    });

    // Handle user selection/deselection
    vipUsersSelect.on('change', function(e) {
        const selectedUsers = $(this).val();
        const vipCategoryId = '538';
        
        if (selectedUsers && selectedUsers.length > 0) {
            vipStatusSelect.val('vip').trigger('change');
            primaryCategorySelect.val(vipCategoryId).trigger('change');
        } else {
            vipStatusSelect.val('public').trigger('change');
        }
    });

    // Handle Create VIP Product button click using event delegation
    $(document).on('click', '.create-vip-product', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const orderId = button.data('order-id');
        const itemId = button.data('item-id');
        const productId = button.data('product-id');
        const userId = button.data('user-id');
        
        if (!orderId || !itemId || !productId || !userId) {
            alert('Error: Missing required data for VIP product creation');
            return;
        }
        
        if (!window.vipProducts || !window.vipProducts.ajax_url || !window.vipProducts.create_nonce) {
            alert('Error: VIP Products configuration is missing');
            return;
        }
        
        // Disable button and show loading state
        const originalText = button.text();
        button.prop('disabled', true).text('Creating...');
        
        // Make AJAX request
        $.ajax({
            url: vipProducts.ajax_url,
            type: 'POST',
            data: {
                action: 'create_vip_from_order_item',
                create_nonce: vipProducts.create_nonce,
                order_id: orderId,
                item_id: itemId,
                product_id: productId,
                user_id: userId
            },
            success: function(response) {
                if (response.success) {
                    alert('VIP product created successfully!');
                    if (response.data && response.data.redirect) {
                        window.location.href = response.data.redirect;
                    }
                } else {
                    alert('Failed to create VIP product: ' + (response.data || 'Unknown error'));
                    button.prop('disabled', false).text(originalText);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                alert('Failed to create VIP product. Please try again.');
                button.prop('disabled', false).text(originalText);
            }
        });
    });

    // Check initial state on page load
    const initialUsers = vipUsersSelect.val();
    if (initialUsers && initialUsers.length > 0) {
        vipStatusSelect.val('vip').trigger('change');
    }
}); 