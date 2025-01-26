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
    
    if ($total_pages > 1) {
        echo '<div class="tablenav bottom">';
        echo paginate_links(array(
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => __('&laquo;'),
            'next_text' => __('&raquo;'),
            'total' => $total_pages,
            'current' => $current_page
        ));
        echo '</div>';
    }
    ?>

    <div class="vip-products-update-status postbox">
        <h2 class="hndle"><span>Plugin Update Status</span></h2>
        <div class="inside">
            <?php
            // Create a new instance of the updater just for checking status
            $updater = new WC_VIP_Products_Updater();
            try {
                $current_version = $updater->get_plugin_version();
                $remote_version = $updater->get_remote_version();
                ?>
                <div class="notice <?php echo ($remote_version && version_compare($current_version, $remote_version, '<')) ? 'notice-warning' : 'notice-info'; ?> inline">
                    <p><strong>Current Version:</strong> <?php echo esc_html($current_version); ?></p>
                    <p><strong>Latest Version:</strong> <?php echo $remote_version ? esc_html($remote_version) : 'Unable to check'; ?></p>
                    <?php if ($remote_version && version_compare($current_version, $remote_version, '<')): ?>
                        <p>An update is available! Please visit the <a href="<?php echo esc_url(admin_url('update-core.php')); ?>">WordPress Updates</a> page to update the plugin.</p>
                    <?php else: ?>
                        <p>You are running the latest version.</p>
                    <?php endif; ?>
                </div>
                <?php
            } catch (Exception $e) {
                ?>
                <div class="notice notice-error inline">
                    <p>Unable to check for updates at this time. Please try again later.</p>
                </div>
                <?php
            }
            ?>
        </div>
    </div>
</div>

<style>
.vip-products-table-wrapper {
    margin-top: 20px;
    margin-bottom: 20px;
}

.tooltip-icon {
    color: #666;
    vertical-align: middle;
    margin-left: 5px;
}

.tooltip-icon:hover {
    color: #000;
}

.vip-products-update-status {
    margin-top: 20px;
}

.vip-products-update-status .notice {
    margin: 0;
    padding: 12px;
}

.vip-products-update-status .notice p {
    margin: 0.5em 0;
}

.vip-products-update-status .notice a {
    font-weight: 500;
}
</style>

<?php
// Add Dashicons to admin page
add_action('admin_enqueue_scripts', function($hook) {
    if('toplevel_page_vip-products-admin' === $hook) {
        wp_enqueue_style('dashicons');
    }
});
