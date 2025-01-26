<?php
if (!defined('ABSPATH')) {
    exit;
}

// Get pagination parameters
$per_page_options = array(25, 50, 100);
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
if (!in_array($per_page, $per_page_options)) {
    $per_page = 25;
}

?><div class="wrap">
    <h1>VIP Products Admin</h1>
    
    <div class="tablenav top">
        <div class="alignleft actions">
            <form method="get">
                <input type="hidden" name="post_type" value="product">
                <input type="hidden" name="page" value="vip-products-admin">
                <select name="per_page">
                    <?php foreach ($per_page_options as $option) : ?>
                        <option value="<?php echo esc_attr($option); ?>" <?php selected($per_page, $option); ?>>
                            <?php echo sprintf('%d per page', $option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" class="button" value="Apply">
            </form>
        </div>
    </div>
    
    <div class="vip-products-table-wrapper">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col">Product Name</th>
                    <th scope="col">Unit Price</th>
                    <th scope="col">Stock Status</th>
                    <th scope="col">Assigned To</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $args = array(
                    'post_type' => 'product',
                    'posts_per_page' => $per_page,
                    'paged' => $current_page,
                    'meta_query' => array(
                        array(
                            'key' => '_vip_product',
                            'value' => 'yes',
                            'compare' => '='
                        )
                    )
                );

                $vip_products = new WP_Query($args);
                $products_data = array();

                // First, gather all products data with user information
                if ($vip_products->have_posts()) :
                    while ($vip_products->have_posts()) : $vip_products->the_post();
                        $product = wc_get_product(get_the_ID());
                        $vip_user_ids = get_post_meta(get_the_ID(), '_vip_user_ids', true);
                        
                        $assigned_to = '';
                        $sort_key = 'zzz_unassigned'; // For sorting - unassigned goes last
                        $tooltip = '';
                        
                        if (!empty($vip_user_ids)) {
                            if (count($vip_user_ids) > 1) {
                                $assigned_to_text = 'Multiple Users';
                                $assigned_to = sprintf(
                                    '%s %s',
                                    $assigned_to_text,
                                    '<span class="dashicons dashicons-info-outline tooltip-icon"></span>'
                                );
                                $sort_key = 'zzz_multiple';
                                $user_names = array();
                                foreach ($vip_user_ids as $user_id) {
                                    $user = get_user_by('id', $user_id);
                                    if ($user) {
                                        $user_names[] = trim($user->first_name . ' ' . $user->last_name);
                                    }
                                }
                                $tooltip = 'title="' . esc_attr(implode(', ', $user_names)) . '"';
                            } else {
                                $user = get_user_by('id', $vip_user_ids[0]);
                                if ($user) {
                                    $full_name = trim($user->first_name . ' ' . $user->last_name);
                                    $assigned_to = !empty($full_name) ? $full_name : $user->display_name;
                                    $sort_key = strtolower($assigned_to);
                                } else {
                                    $assigned_to = 'Unknown';
                                    $sort_key = 'zzz_unknown';
                                }
                            }
                        } else {
                            $assigned_to = 'Unassigned';
                        }

                        $products_data[] = array(
                            'sort_key' => $sort_key,
                            'html' => sprintf(
                                '<tr>
                                    <td><a href="%s" style="font-weight: bold;">%s</a></td>
                                    <td>%s</td>
                                    <td>%s</td>
                                    <td %s>%s</td>
                                    <td>
                                        <a href="%s" class="button">View</a>
                                        <a href="%s" class="button">Edit</a>
                                        <a href="%s" class="button delete-product" onclick="return confirm(\'Are you sure you want to delete this product?\')">Delete</a>
                                    </td>
                                </tr>',
                                get_permalink($product->get_id()),
                                esc_html($product->get_name()),
                                wc_price($product->get_price()),
                                $product->get_stock_status(),
                                $tooltip,
                                wp_kses($assigned_to, array('span' => array('class' => array()))),
                                get_permalink($product->get_id()),
                                esc_url(admin_url('post.php?post=' . $product->get_id() . '&action=edit')),
                                esc_url(wp_nonce_url(admin_url('admin-post.php?action=delete_vip_product&product_id=' . $product->get_id()), 'delete_vip_product'))
                            )
                        );
                    endwhile;

                    // Sort products by assigned user
                    usort($products_data, function($a, $b) {
                        return strcmp($a['sort_key'], $b['sort_key']);
                    });

                    // Output sorted products
                    foreach ($products_data as $product_data) {
                        echo $product_data['html'];
                    }

                    wp_reset_postdata();
                else :
                    ?>
                    <tr>
                        <td colspan="5">No VIP products found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php
    $total_products = $vip_products->found_posts;
    $total_pages = ceil($total_products / $per_page);
    
    if ($total_pages > 1) :
    ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num">
                <?php echo sprintf(
                    '%s item%s',
                    number_format_i18n($total_products),
                    $total_products > 1 ? 's' : ''
                ); ?>
            </span>
            <span class="pagination-links">
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $current_page
                ));
                ?>
            </span>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.vip-products-table-wrapper {
    margin-top: 20px;
}
.vip-products-table-wrapper .button {
    margin-right: 5px;
}
.vip-products-table-wrapper .button.delete-product {
    color: #a00;
}
.vip-products-table-wrapper .button.delete-product:hover {
    color: #dc3232;
    border-color: #dc3232;
}
.tablenav-pages {
    float: right;
    margin: 1em 0;
}
.tablenav.top {
    margin-bottom: 1em;
}
.tooltip-icon {
    font-size: 16px !important;
    width: 16px !important;
    height: 16px !important;
    vertical-align: middle !important;
    margin-left: 4px !important;
    color: #666;
}

.tooltip-icon:hover {
    color: #000;
}
</style>

<?php
// Add Dashicons to admin page
add_action('admin_enqueue_scripts', function($hook) {
    if('toplevel_page_vip-products-admin' === $hook) {
        wp_enqueue_style('dashicons');
    }
});
