jQuery(document).ready(function($) {
    // Initialize Select2
    $('.wc-customer-search').select2({
        ajax: {
            url: vipProducts.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    q: params.term,
                    action: 'search_users',
                    nonce: vipProducts.nonce
                };
            },
            processResults: function(data) {
                return {
                    results: data
                };
            },
            cache: true
        },
        minimumInputLength: 2,
        placeholder: 'Search for users...',
        allowClear: true,
        width: '400px'
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

    // Check initial state on page load
    const initialUsers = vipUsersSelect.val();
    if (initialUsers && initialUsers.length > 0) {
        vipStatusSelect.val('vip').trigger('change');
    }
}); 