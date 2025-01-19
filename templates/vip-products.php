<?php
if (!defined('ABSPATH')) {
    exit;
}

// Ensure WooCommerce is loaded
if (!class_exists('WooCommerce')) {
    return;
}

// Get current user ID
$current_user_id = get_current_user_id();

// Get current sort parameters
$orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'id';
$order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'asc';

// Query for VIP products
$args = array(
    'post_type' => 'product',
    'posts_per_page' => -1,
    'orderby' => $orderby,
    'order' => strtoupper($order),
    'meta_query' => array(
        'relation' => 'AND',
        array(
            'key' => '_product_visibility_type',
            'value' => 'vip',
            'compare' => '='
        ),
        array(
            'key' => '_vip_users',
            'value' => sprintf(':%s;', $current_user_id),
            'compare' => 'LIKE'
        )
    )
);

$products = new WP_Query($args);
?>

<div class="woocommerce-vip-products">
    <h2><?php esc_html_e('My VIP Products', 'wc-vip-products'); ?></h2>
    <p class="vip-description"><?php esc_html_e('Exclusive products available just for you.', 'wc-vip-products'); ?></p>
    <p class="vip-description">
        <?php esc_html_e('You can view the products below, and add them to your cart to checkout.', 'wc-vip-products'); ?>
    </p>

    <?php if ($products->have_posts()) : ?>
        <table class="vip-products-table">
            <thead>
                <tr>
                    <th class="sortable">
                        <a href="<?php echo add_query_arg(array('orderby' => 'id', 'order' => ($orderby === 'id' && $order === 'asc') ? 'desc' : 'asc')); ?>">
                            ID 
                            <?php if ($orderby === 'id'): ?>
                                <?php echo $order === 'asc' ? '↑' : '↓'; ?>
                            <?php else: ?>
                                ↕
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="sortable">
                        <a href="<?php echo add_query_arg(array('orderby' => 'title', 'order' => ($orderby === 'title' && $order === 'asc') ? 'desc' : 'asc')); ?>">
                            PRODUCT
                            <?php if ($orderby === 'title'): ?>
                                <?php echo $order === 'asc' ? '↑' : '↓'; ?>
                            <?php else: ?>
                                ↕
                            <?php endif; ?>
                        </a>
                    </th>
                    <th class="sortable">
                        <a href="<?php echo add_query_arg(array('orderby' => 'price', 'order' => ($orderby === 'price' && $order === 'asc') ? 'desc' : 'asc')); ?>">
                            PRICE
                            <?php if ($orderby === 'price'): ?>
                                <?php echo $order === 'asc' ? '↑' : '↓'; ?>
                            <?php else: ?>
                                ↕
                            <?php endif; ?>
                        </a>
                    </th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php while ($products->have_posts()) : $products->the_post(); 
                    global $product;
                    if (!is_a($product, 'WC_Product')) {
                        $product = wc_get_product(get_the_ID());
                    }
                    if (!$product) {
                        continue;
                    }
                    ?>
                    <tr>
                        <td class="product-id"><a href="<?php echo esc_url(get_permalink($product->get_id())); ?>"><?php echo esc_html($product->get_id()); ?></a></td>
                        <td class="product-name">
                            <?php echo esc_html(get_the_title()); ?>
                            <span class="vip-badge">VIP</span>
                        </td>
                        <td class="product-price"><?php echo $product->get_price_html(); ?></td>
                        <td class="product-action">
                            <a href="<?php echo esc_url(get_permalink()); ?>" class="button view-product">
                                <?php esc_html_e('View', 'wc-vip-products'); ?>
                            </a>
                        </td>
                    </tr>
                <?php endwhile; wp_reset_postdata(); ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="woocommerce-info"><?php esc_html_e('No VIP products available at this time.', 'wc-vip-products'); ?></p>
    <?php endif; ?>
</div> 