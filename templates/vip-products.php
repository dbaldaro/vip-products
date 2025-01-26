<?php
/**
 * Template for displaying VIP products
 */

defined('ABSPATH') || exit;

$current_user_id = get_current_user_id();
if (!$current_user_id) {
    return;
}

// Create the possible serialized formats
$single_user = serialize(array($current_user_id)); // a:1:{i:0;i:1;}
$user_at_start = sprintf('a:%%{i:0;i:%d;%%', $current_user_id); // a:2:{i:0;i:1;...}
$user_anywhere = sprintf('i:%d;', $current_user_id); // matches i:1; anywhere in string

$args = array(
    'post_type' => 'product',
    'posts_per_page' => -1,
    'meta_query' => array(
        'relation' => 'AND',
        array(
            'key' => '_vip_product',
            'value' => 'yes',
            'compare' => '='
        ),
        array(
            'relation' => 'OR',
            array(
                'key' => '_vip_user_ids',
                'value' => $single_user,
                'compare' => '='
            ),
            array(
                'key' => '_vip_user_ids',
                'value' => $user_at_start,
                'compare' => 'LIKE'
            ),
            array(
                'key' => '_vip_user_ids',
                'value' => $user_anywhere,
                'compare' => 'LIKE'
            )
        )
    )
);

$products = new WP_Query($args);
?>

<div class="woocommerce-vip-products">
    <h2>My VIP Products</h2>
    <p class="vip-description">Exclusive products available just for you.</p>
    <p>
        You can view the products below, and add them to your cart to checkout.
    </p>

    <?php if ($products->have_posts()) : ?>
        <table class="shop_table shop_table_responsive my_account_orders">
            <thead>
                <tr>
                    <th class="order-number">ID</th>
                    <th class="order-date">Product</th>
                    <th class="order-status">Price</th>
                    <th class="order-total">Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php
                while ($products->have_posts()) :
                    $products->the_post();
                    global $product;
                    if (!is_a($product, 'WC_Product')) {
                        $product = wc_get_product(get_the_ID());
                    }
                    if (!$product) {
                        continue;
                    }
                    ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url($product->get_permalink()); ?>"><?php echo esc_html($product->get_id()); ?></a></strong></td>
                        <td><?php echo esc_html($product->get_name()); ?></td>
                        <td><?php echo wp_kses_post($product->get_price_html()); ?></td>
                        <td>
                            <a href="<?php echo esc_url($product->get_permalink()); ?>" class="button view">View</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else : ?>
        <div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">
            No VIP products found.
        </div>
    <?php endif; ?>
</div>

<?php wp_reset_postdata(); ?>