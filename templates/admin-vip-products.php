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
    <h1><?php echo esc_html__('VIP Products Admin', 'wc-vip-products'); ?></h1>
    
    <div class="tablenav top">
        <div class="alignleft actions">
            <form method="get">
                <input type="hidden" name="post_type" value="product">
                <input type="hidden" name="page" value="vip-products-admin">
                <select name="per_page">
                    <?php foreach ($per_page_options as $option) : ?>
                        <option value="<?php echo esc_attr($option); ?>" <?php selected($per_page, $option); ?>>
                            <?php echo sprintf(esc_html__('%d per page', 'wc-vip-products'), $option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" class="button" value="<?php esc_attr_e('Apply', 'wc-vip-products'); ?>">
            </form>
        </div>
    </div>
    
    <div class="vip-products-table-wrapper">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__('Product Name', 'wc-vip-products'); ?></th>
                    <th scope="col"><?php echo esc_html__('Unit Price', 'wc-vip-products'); ?></th>
                    <th scope="col"><?php echo esc_html__('Stock Status', 'wc-vip-products'); ?></th>
                    <th scope="col"><?php echo esc_html__('Assigned To', 'wc-vip-products'); ?></th>
                    <th scope="col"><?php echo esc_html__('Actions', 'wc-vip-products'); ?></th>
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
                        
                        if (!empty($vip_user_ids)) {
                            if (count($vip_user_ids) > 1) {
                                $assigned_to = esc_html__('Multiple', 'wc-vip-products');
                                $sort_key = 'zzz_multiple';
                            } else {
                                $user = get_user_by('id', $vip_user_ids[0]);
                                if ($user) {
                                    $assigned_to = esc_html($user->display_name);
                                    $sort_key = strtolower($user->display_name);
                                } else {
                                    $assigned_to = esc_html__('Unknown', 'wc-vip-products');
                                    $sort_key = 'zzz_unknown';
                                }
                            }
                        } else {
                            $assigned_to = esc_html__('Unassigned', 'wc-vip-products');
                        }

                        $products_data[] = array(
                            'sort_key' => $sort_key,
                            'html' => sprintf(
                                '<tr>
                                    <td>%s</td>
                                    <td>%s</td>
                                    <td>%s</td>
                                    <td>%s</td>
                                    <td>
                                        <a href="%s" class="button">%s</a>
                                        <a href="%s" class="button delete-product" onclick="return confirm(\'%s\')">%s</a>
                                    </td>
                                </tr>',
                                esc_html($product->get_name()),
                                wp_kses_post(wc_price($product->get_price())),
                                $product->get_stock_status() === 'instock' ? esc_html__('In Stock', 'wc-vip-products') : esc_html__('Out of Stock', 'wc-vip-products'),
                                $assigned_to,
                                esc_url(get_edit_post_link()),
                                esc_html__('Edit', 'wc-vip-products'),
                                esc_url(get_delete_post_link()),
                                esc_js(__('Are you sure you want to delete this product?', 'wc-vip-products')),
                                esc_html__('Delete', 'wc-vip-products')
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
                        <td colspan="5"><?php echo esc_html__('No VIP products found.', 'wc-vip-products'); ?></td>
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
                    _n('%s item', '%s items', $total_products, 'wc-vip-products'),
                    number_format_i18n($total_products)
                ); ?>
            </span>
            <span class="pagination-links">
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
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
</style>
