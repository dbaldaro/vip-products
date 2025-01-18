<?php
/*
Plugin Name: WooCommerce VIP Products
Description: Allows for creation of VIP-exclusive products for specific users.
Version: 1.1.7
Author: David Baldaro
New Release
*/

if (!defined('ABSPATH')) {
    exit;
}

// Include the updater class
require_once plugin_dir_path(__FILE__) . 'includes/class-vip-products-updater.php';

class WC_VIP_Products {
    private static $instance = null;
    private $updater = null;

    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Initialize the updater
        $this->updater = new WC_VIP_Products_Updater();

        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Add product tab
        add_filter('woocommerce_product_data_tabs', array($this, 'add_vip_products_tab'));
        
        // Add tab content
        add_action('woocommerce_product_data_panels', array($this, 'add_vip_products_fields'));
        
        // Save custom fields
        add_action('woocommerce_process_product_meta', array($this, 'save_vip_products_fields'));
        
        // Filter products query
        add_filter('woocommerce_product_query', array($this, 'filter_vip_products'));
        
        // Add endpoint for My Account page
        add_action('init', array($this, 'add_endpoints'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_vip_products_tab_myaccount'));
        add_action('woocommerce_account_vip-products_endpoint', array($this, 'vip_products_content'));

        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Add AJAX handler for user search
        add_action('wp_ajax_search_users', array($this, 'ajax_search_users'));

        // Exclude VIP products from search results
        add_filter('pre_get_posts', array($this, 'exclude_vip_products_from_search'));

        // Handle Flatsome theme search
        add_filter('flatsome_ajax_search_args', array($this, 'filter_flatsome_search'));
        add_filter('flatsome_ajax_search_products_args', array($this, 'filter_flatsome_search'));
        
        // Additional filters for Flatsome AJAX search
        add_filter('posts_where', array($this, 'filter_search_where'), 10, 2);
        add_filter('woocommerce_product_data_store_cpt_get_products_query', array($this, 'filter_products_query'), 10, 2);
    }

    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('WooCommerce VIP Products requires WooCommerce to be installed and active.', 'wc-vip-products'); ?></p>
        </div>
        <?php
    }

    public function enqueue_admin_scripts($hook) {
        global $post_type;
        
        // Only load on product edit screens
        if ('post.php' !== $hook && 'post-new.php' !== $hook && 'product' !== $post_type) {
            return;
        }

        wp_enqueue_style('select2');
        wp_enqueue_script('select2');
        
        // Add our custom JavaScript
        wp_enqueue_script(
            'vip-products-admin',
            plugins_url('assets/js/admin.js', __FILE__),
            array('jquery', 'select2'),
            '1.0.0',
            true
        );

        // Add localized data for AJAX
        wp_localize_script('vip-products-admin', 'vipProducts', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('search_users_nonce')
        ));
    }

    public function add_vip_products_tab($tabs) {
        $tabs['vip_products'] = array(
            'label'    => __('VIP Access', 'wc-vip-products'),
            'target'   => 'vip_products_options',
            'class'    => array(),
            'priority' => 80
        );
        return $tabs;
    }

    public function add_vip_products_fields() {
        global $post;

        echo '<div id="vip_products_options" class="panel woocommerce_options_panel">';
        
        woocommerce_wp_select(array(
            'id'      => '_product_visibility_type',
            'label'   => __('VIP Status', 'wc-vip-products'),
            'options' => array(
                'public' => __('Public (Everyone)', 'wc-vip-products'),
                'vip'    => __('VIP Members Only', 'wc-vip-products')
            )
        ));
        
        echo '<p class="form-field vip_users_field">';
        echo '<label for="vip_users">' . __('Select VIP Users', 'wc-vip-products') . '</label>';
        echo '<select class="wc-customer-search" name="vip_users[]" multiple="multiple" data-placeholder="' . 
             esc_attr__('Search for users...', 'wc-vip-products') . '">';
        
        $vip_users = get_post_meta($post->ID, '_vip_users', true);
        if (!empty($vip_users)) {
            foreach ($vip_users as $user_id) {
                $user = get_user_by('id', $user_id);
                if ($user) {
                    echo '<option value="' . esc_attr($user_id) . '" selected="selected">' . 
                         esc_html($user->display_name) . ' (#' . $user_id . ')</option>';
                }
            }
        }
        
        echo '</select>';
        echo '</p>';
        echo '</div>';
    }

    public function save_vip_products_fields($post_id) {
        // Save visibility type
        $visibility_type = isset($_POST['_product_visibility_type']) ? $_POST['_product_visibility_type'] : 'public';
        $visibility_type = sanitize_text_field($visibility_type);
        
        // Save VIP users
        $vip_users = isset($_POST['vip_users']) ? array_map('absint', $_POST['vip_users']) : array();
        
        // If it's a VIP product but no users are selected, keep it private
        if ($visibility_type === 'vip' && empty($vip_users)) {
            $visibility_type = 'private';
        }
        
        update_post_meta($post_id, '_product_visibility_type', $visibility_type);
        update_post_meta($post_id, '_vip_users', $vip_users);

        // Handle VIP category assignment
        if ($visibility_type === 'vip') {
            // Assign VIP category (538)
            $vip_cat_id = 538;
            
            // Get current categories
            $current_categories = wp_get_post_terms($post_id, 'product_cat', array('fields' => 'ids'));
            
            // Add VIP category if not already assigned
            if (!in_array($vip_cat_id, $current_categories)) {
                // Set VIP category as primary by making it first in the array
                array_unshift($current_categories, $vip_cat_id);
                
                // Remove any duplicate entries
                $current_categories = array_unique($current_categories);
                
                // Update the product categories
                wp_set_object_terms($post_id, $current_categories, 'product_cat');
                
                // Set primary category using Yoast's primary term (if Yoast SEO is active)
                if (function_exists('wpseo_set_primary_term')) {
                    wpseo_set_primary_term('product_cat', $vip_cat_id, $post_id);
                }
            }
        }
    }

    public function filter_vip_products($query) {
        if (is_admin()) {
            return $query;
        }

        $current_user_id = get_current_user_id();
        
        // Start with a simple meta query
        $meta_query = array('relation' => 'OR');
        
        // Always include public products and products with no visibility setting
        $meta_query[] = array(
            'key'     => '_product_visibility_type',
            'value'   => 'public',
            'compare' => '='
        );
        
        $meta_query[] = array(
            'key'     => '_product_visibility_type',
            'compare' => 'NOT EXISTS'
        );

        // For logged-in users, add their VIP products
        if ($current_user_id > 0) {
            $meta_query[] = array(
                'key'     => '_vip_users',
                'value'   => sprintf(':%d;', $current_user_id),
                'compare' => 'LIKE'
            );
        }

        // Set the meta query
        $query->set('meta_query', $meta_query);
        
        return $query;
    }

    public function add_endpoints() {
        add_rewrite_endpoint('vip-products', EP_ROOT | EP_PAGES);
    }

    public function add_vip_products_tab_myaccount($items) {
        $items['vip-products'] = __('My VIP Products', 'wc-vip-products');
        return $items;
    }

    public function vip_products_content() {
        $current_user_id = get_current_user_id();
        
        if ($current_user_id === 0) {
            echo '<p class="woocommerce-info">' . __('Please log in to view your VIP products.', 'wc-vip-products') . '</p>';
            return;
        }
        
        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_product_visibility_type',
                    'value'   => 'vip',
                    'compare' => '='
                ),
                array(
                    'key'     => '_vip_users',
                    'value'   => sprintf(':%s;', $current_user_id),
                    'compare' => 'LIKE'
                )
            )
        );
        
        $products = new WP_Query($args);
        
        wc_get_template(
            'vip-products.php',
            array('products' => $products),
            'woocommerce/',
            plugin_dir_path(__FILE__) . 'templates/'
        );
    }

    public function ajax_search_users() {
        check_ajax_referer('search_users_nonce', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_die(-1);
        }

        $search_term = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
        
        if (empty($search_term)) {
            wp_die();
        }

        $users = get_users(array(
            'search'         => "*{$search_term}*",
            'search_columns' => array('user_login', 'user_email', 'user_nicename', 'display_name'),
            'number'         => 10
        ));

        $results = array();
        
        foreach ($users as $user) {
            $results[] = array(
                'id'   => $user->ID,
                'text' => sprintf(
                    '%s (%s)',
                    $user->display_name,
                    $user->user_email
                )
            );
        }

        wp_send_json($results);
    }

    public function add_vip_product_notice() {
        global $product;
        
        if (!$product) return;
        
        $visibility_type = get_post_meta($product->get_id(), '_product_visibility_type', true);
        
        if ($visibility_type === 'vip') {
            $current_user_id = get_current_user_id();
            $vip_users = get_post_meta($product->get_id(), '_vip_users', true);
            
            if (!is_array($vip_users)) {
                $vip_users = array();
            }
            
            if (!in_array($current_user_id, $vip_users)) {
                wc_print_notice(
                    __('This is a VIP-exclusive product. Please contact us for access.', 'wc-vip-products'),
                    'notice'
                );
            } else {
                wc_print_notice(
                    __('This is one of your VIP-exclusive products.', 'wc-vip-products'),
                    'success'
                );
            }
        }
    }

    public function protect_vip_products() {
        // Only check on single product pages
        if (!is_product()) {
            return;
        }

        global $post;
        $product_id = $post->ID;
        $visibility_type = get_post_meta($product_id, '_product_visibility_type', true);

        // If it's a VIP product
        if ($visibility_type === 'vip') {
            $current_user_id = get_current_user_id();
            
            // If user is not logged in, redirect
            if ($current_user_id === 0) {
                wp_safe_redirect(wc_get_page_permalink('shop'));
                exit;
            }

            // Check if user has access
            $vip_users = get_post_meta($product_id, '_vip_users', true);
            if (!is_array($vip_users) || !in_array($current_user_id, $vip_users)) {
                wp_safe_redirect(wc_get_page_permalink('shop'));
                exit;
            }
        }
    }

    public function filter_search_results($query) {
        // Only filter front-end searches
        if (is_admin() || !$query->is_search() || !$query->is_main_query()) {
            return $query;
        }

        $current_user_id = get_current_user_id();
        
        $meta_query = $query->get('meta_query');
        if (!is_array($meta_query)) {
            $meta_query = array();
        }
        
        // Use the same meta query logic as filter_vip_products
        if ($current_user_id === 0) {
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_product_visibility_type',
                    'value'   => 'public',
                    'compare' => '='
                ),
                array(
                    'key'     => '_product_visibility_type',
                    'compare' => 'NOT EXISTS'
                )
            );
        } else {
            $meta_query[] = array(
                'relation' => 'OR',
                // Show all public products
                array(
                    'key'     => '_product_visibility_type',
                    'value'   => 'public',
                    'compare' => '='
                ),
                // Show products where visibility type doesn't exist (regular products)
                array(
                    'key'     => '_product_visibility_type',
                    'compare' => 'NOT EXISTS'
                ),
                // Show VIP products assigned to this user
                array(
                    'relation' => 'AND',
                    array(
                        'key'     => '_product_visibility_type',
                        'value'   => 'vip',
                        'compare' => '='
                    ),
                    array(
                        'key'     => '_vip_users',
                        'value'   => sprintf(':%s;', $current_user_id),
                        'compare' => 'LIKE'
                    )
                )
            );
        }
        
        $query->set('meta_query', $meta_query);
        return $query;
    }

    public function exclude_vip_products_from_search($query) {
        // Only filter front-end searches
        if (is_admin() || !$query->is_search() || !$query->is_main_query()) {
            return $query;
        }

        $meta_query = $query->get('meta_query');
        if (!is_array($meta_query)) {
            $meta_query = array();
        }
        
        $meta_query[] = array(
            'key'     => '_product_visibility_type',
            'value'   => 'vip',
            'compare' => '!='
        );
        
        $query->set('meta_query', $meta_query);
        return $query;
    }

    /**
     * Filter the WHERE clause for any search query
     */
    public function filter_search_where($where, $query) {
        global $wpdb;
        
        // Only apply to searches
        if (!is_search() && !isset($_REQUEST['action']) || $_REQUEST['action'] !== 'flatsome_ajax_search_products') {
            return $where;
        }

        $where .= " AND {$wpdb->posts}.ID NOT IN (
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_product_visibility_type' 
            AND meta_value = 'vip'
        )";

        return $where;
    }

    /**
     * Filter products query for WooCommerce
     */
    public function filter_products_query($query, $query_vars) {
        if (!isset($query['meta_query'])) {
            $query['meta_query'] = array();
        }

        $query['meta_query'][] = array(
            'relation' => 'OR',
            array(
                'key'     => '_product_visibility_type',
                'value'   => 'vip',
                'compare' => '!='
            ),
            array(
                'key'     => '_product_visibility_type',
                'compare' => 'NOT EXISTS'
            )
        );

        return $query;
    }

    /**
     * Filter Flatsome theme search results to exclude VIP products
     */
    public function filter_flatsome_search($args) {
        global $wpdb;
        
        if (!isset($args['meta_query'])) {
            $args['meta_query'] = array();
        }

        // Add our VIP product exclusion
        $args['meta_query'][] = array(
            'relation' => 'OR',
            array(
                'key'     => '_product_visibility_type',
                'value'   => 'vip',
                'compare' => '!='
            ),
            array(
                'key'     => '_product_visibility_type',
                'compare' => 'NOT EXISTS'
            )
        );

        // Exclude VIP products by ID
        if (!isset($args['post__not_in'])) {
            $args['post__not_in'] = array();
        }

        // Get all VIP product IDs
        $vip_products = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_product_visibility_type' 
            AND meta_value = 'vip'"
        );

        if (!empty($vip_products)) {
            $args['post__not_in'] = array_merge($args['post__not_in'], $vip_products);
        }

        return $args;
    }
}

// Initialize plugin
function wc_vip_products() {
    return WC_VIP_Products::init();
}

add_action('plugins_loaded', 'wc_vip_products');

// Activation hook
register_activation_hook(__FILE__, 'wc_vip_products_activate');

function wc_vip_products_activate() {
    // Flush rewrite rules
    flush_rewrite_rules();
} 