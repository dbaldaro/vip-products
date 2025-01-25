<?php
/*
Plugin Name: WooCommerce VIP Products
Description: Allows for creation of VIP-exclusive products for specific users.
Version: 1.1.14
Author: David Baldaro
New Release
*/

if (!defined('ABSPATH')) {
    exit;
}

// Debug logging function - only logs to debug.log, never displays
if (!function_exists('vip_debug_log')) {
    function vip_debug_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('VIP Products Debug: ' . $message);
        }
    }
}

// Include the updater class
$updater_path = plugin_dir_path(__FILE__) . 'includes/class-vip-products-updater.php';

if (file_exists($updater_path) && is_readable($updater_path)) {
    require_once $updater_path;
} else {
    vip_debug_log('ERROR: Could not load updater file');
    // Create a more user-friendly error
    add_action('admin_notices', function() {
        echo '<div class="error"><p>VIP Products Plugin Error: Could not load required file. Please check file permissions in the includes directory.</p></div>';
    });
    return;
}

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
        try {
            // Initialize the updater
            $this->updater = new WC_VIP_Products_Updater();

            // Load text domain during init
            add_action('init', array($this, 'load_plugin_textdomain'));

            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
                return;
            }

            // Add AJAX handlers
            add_action('wp_ajax_search_users', array($this, 'ajax_search_users'));

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

            // Add VIP product type filter
            add_filter('product_type_selector', array($this, 'add_vip_product_type'));
            add_filter('woocommerce_product_filters', array($this, 'add_vip_product_filter'));

            // Enqueue scripts and styles
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

            // Add VIP product creation button and handler
            add_action('woocommerce_after_order_itemmeta', array($this, 'add_create_vip_button'), 10, 3);
            add_action('wp_ajax_create_vip_from_order_item', array($this, 'create_vip_from_order_item'));

            // Exclude VIP products from search results
            add_filter('pre_get_posts', array($this, 'exclude_vip_products_from_search'));

            // Handle Flatsome theme search
            add_filter('flatsome_ajax_search_args', array($this, 'filter_flatsome_search'));
            add_filter('flatsome_ajax_search_products_args', array($this, 'filter_flatsome_search'));
            
            // Additional filters for Flatsome AJAX search
            add_filter('posts_where', array($this, 'filter_search_where'), 10, 2);
            add_filter('woocommerce_product_data_store_cpt_get_products_query', array($this, 'filter_products_query'), 10, 2);

            // Filter admin products list
            add_filter('parse_query', array($this, 'filter_admin_vip_products'));

            // Add VIP filter button to products page
            add_action('restrict_manage_posts', array($this, 'add_vip_filter_button'));

        } catch (Exception $e) {
            vip_debug_log('ERROR: ' . $e->getMessage());
            vip_debug_log('ERROR Stack Trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Load plugin text domain
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain('wc-vip-products', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p><?php _e('WooCommerce VIP Products requires WooCommerce to be installed and active.', 'wc-vip-products'); ?></p>
        </div>
        <?php
    }

    public function enqueue_admin_scripts($hook) {
        // Always load on WooCommerce order pages
        $is_order_page = (
            $hook === 'woocommerce_page_wc-orders' || // New WooCommerce order page
            $hook === 'post.php' && isset($_GET['post']) && get_post_type($_GET['post']) === 'shop_order' || // Legacy order page
            isset($_GET['page']) && $_GET['page'] === 'wc-orders' // Another way to check for order page
        );
        
        // Also load on product edit pages
        $is_product_page = (
            in_array($hook, array('post.php', 'post-new.php')) && 
            isset($_GET['post']) && 
            get_post_type($_GET['post']) === 'product'
        );
        
        if (!$is_order_page && !$is_product_page) {
            return;
        }

        // Enqueue Select2
        wp_enqueue_style('select2');
        wp_enqueue_script('select2');
        
        // Enqueue jQuery explicitly
        wp_enqueue_script('jquery');
        
        // Enqueue our custom scripts
        wp_enqueue_script(
            'vip-products-admin', 
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery', 'select2'),
            filemtime(plugin_dir_path(__FILE__) . 'assets/js/admin.js'),
            true
        );
        
        // Localize script with nonce and ajaxurl
        wp_localize_script('vip-products-admin', 'vipProducts', array(
            'nonce' => wp_create_nonce('vip_products_search'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'i18n' => array(
                'error_loading' => __('The results could not be loaded.', 'wc-vip-products'),
                'searching' => __('Searching...', 'wc-vip-products'),
                'no_results' => __('No results found', 'wc-vip-products')
            )
        ));
        
        wp_enqueue_style(
            'vip-products-admin', 
            plugin_dir_url(__FILE__) . 'assets/css/vip-products.css',
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/vip-products.css')
        );
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
        
        // Get current VIP users
        $vip_user_ids = get_post_meta($post->ID, '_vip_user_ids', true);
        $vip_user_ids = !empty($vip_user_ids) ? (array)$vip_user_ids : array();
        
        echo '<div id="vip_products_options" class="panel woocommerce_options_panel">';
        
        // VIP Status field
        woocommerce_wp_select(array(
            'id' => '_vip_product',
            'label' => __('VIP Status', 'wc-vip-products'),
            'description' => __('Is this a VIP-only product?', 'wc-vip-products'),
            'desc_tip' => true,
            'options' => array(
                'no' => __('No', 'wc-vip-products'),
                'yes' => __('VIP Members Only', 'wc-vip-products')
            )
        ));
        
        // VIP Users field
        echo '<div class="form-field vip-users-field">';
        echo '<label for="_vip_user_ids">' . __('Select VIP Users', 'wc-vip-products') . '</label>';
        echo '<select id="_vip_user_ids" name="_vip_user_ids[]" class="wc-customer-search" multiple="multiple" data-placeholder="' . esc_attr__('Search for users...', 'wc-vip-products') . '" style="width: 100%;">';
        
        // Add existing users to the select
        if (!empty($vip_user_ids)) {
            foreach ($vip_user_ids as $user_id) {
                $user = get_user_by('id', $user_id);
                if ($user) {
                    echo '<option value="' . esc_attr($user->ID) . '" selected>' . 
                         esc_html(sprintf('%s (%s)', $user->display_name ?: $user->user_login, $user->user_email)) . 
                         '</option>';
                }
            }
        }
        echo '</select>';
        echo '<span class="description">' . __('Search and select users who can access this VIP product. You can select multiple users.', 'wc-vip-products') . '</span>';
        echo '</div>';
        echo '</div>';
    }

    public function save_vip_products_fields($post_id) {
        // Save VIP status
        $vip_status = isset($_POST['_vip_product']) ? sanitize_text_field($_POST['_vip_product']) : 'no';
        update_post_meta($post_id, '_vip_product', $vip_status);
        
        // Save VIP users
        $vip_user_ids = isset($_POST['_vip_user_ids']) ? (array)$_POST['_vip_user_ids'] : array();
        $vip_user_ids = array_map('absint', $vip_user_ids);
        $vip_user_ids = array_filter($vip_user_ids);
        update_post_meta($post_id, '_vip_user_ids', $vip_user_ids);

        if ($vip_status === 'yes') {
            // Assign VIP category (538)
            $vip_cat_id = 538;
            wp_set_object_terms($post_id, array($vip_cat_id), 'product_cat', true);
            
            // Clean up legacy meta fields if they exist
            delete_post_meta($post_id, '_product_visibility_type');
            delete_post_meta($post_id, '_vip_users');
        } else {
            // If not VIP, ensure all VIP-related meta is removed
            delete_post_meta($post_id, '_product_visibility_type');
            delete_post_meta($post_id, '_vip_users');
            wp_remove_object_terms($post_id, array(538), 'product_cat');
        }
    }

    private function user_has_vip_access($product_id, $user_id) {
        // Get VIP status
        $vip_status = get_post_meta($product_id, '_vip_product', true);
        if ($vip_status !== 'yes') {
            return false;
        }

        // Get VIP user IDs
        $vip_user_ids = get_post_meta($product_id, '_vip_user_ids', true);
        if (empty($vip_user_ids)) {
            return false;
        }

        // Unserialize if it's a string
        if (is_string($vip_user_ids)) {
            $vip_user_ids = maybe_unserialize($vip_user_ids);
        }

        // Convert to array if it's not already
        if (!is_array($vip_user_ids)) {
            $vip_user_ids = array($vip_user_ids);
        }

        // Check if user is in the allowed list
        return in_array($user_id, $vip_user_ids);
    }

    public function filter_vip_products($query) {
        // Only filter front-end queries
        if (is_admin()) {
            return $query;
        }

        $current_user_id = get_current_user_id();
        
        $meta_query = $query->get('meta_query');
        if (!is_array($meta_query)) {
            $meta_query = array();
        }
        
        if ($current_user_id === 0) {
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_vip_product',
                    'value'   => 'no',
                    'compare' => '='
                ),
                array(
                    'key'     => '_vip_product',
                    'compare' => 'NOT EXISTS'
                )
            );
        } else {
            // Create the possible serialized formats
            $single_user = serialize(array($current_user_id)); // a:1:{i:0;i:1;}
            $user_at_start = sprintf('a:%%{i:0;i:%d;%%', $current_user_id); // a:2:{i:0;i:1;...}
            $user_anywhere = sprintf('i:%d;', $current_user_id); // matches i:1; anywhere in string

            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_vip_product',
                    'value'   => 'yes',
                    'compare' => '!='
                ),
                array(
                    'relation' => 'AND',
                    array(
                        'key'     => '_vip_product',
                        'value'   => 'yes',
                        'compare' => '='
                    ),
                    array(
                        'relation' => 'OR',
                        array(
                            'key'     => '_vip_user_ids',
                            'value'   => $single_user,
                            'compare' => '='
                        ),
                        array(
                            'key'     => '_vip_user_ids',
                            'value'   => $user_at_start,
                            'compare' => 'LIKE'
                        ),
                        array(
                            'key'     => '_vip_user_ids',
                            'value'   => $user_anywhere,
                            'compare' => 'LIKE'
                        )
                    )
                )
            );
        }
        
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
        try {
            $current_user_id = get_current_user_id();
            
            if ($current_user_id === 0) {
                echo '<p class="woocommerce-info">' . __('Please log in to view your VIP products.', 'wc-vip-products') . '</p>';
                return;
            }
            
            global $wpdb;
            $meta_value_pattern = $wpdb->esc_like(serialize(array($current_user_id))) . '%';
            $meta_value_pattern2 = '%' . $wpdb->esc_like(sprintf(':%d;', $current_user_id)) . '%';
            
            $args = array(
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'     => '_vip_product',
                        'value'   => 'yes',
                        'compare' => '='
                    ),
                    array(
                        'relation' => 'OR',
                        array(
                            'key'     => '_vip_user_ids',
                            'value'   => $meta_value_pattern,
                            'compare' => 'LIKE'
                        ),
                        array(
                            'key'     => '_vip_user_ids',
                            'value'   => $meta_value_pattern2,
                            'compare' => 'LIKE'
                        )
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
        } catch (Exception $e) {
            vip_debug_log('ERROR in vip_products_content: ' . $e->getMessage());
            vip_debug_log('ERROR Stack Trace: ' . $e->getTraceAsString());
        }
    }

    public function ajax_search_users() {
        check_ajax_referer('vip_products_search', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
            return;
        }
        
        $search_term = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';
        if (empty($search_term)) {
            wp_send_json_error('No search term provided');
            return;
        }

        $users = get_users(array(
            'search' => '*' . $search_term . '*',
            'search_columns' => array('user_login', 'user_email', 'display_name'),
            'number' => 10,
            'orderby' => 'display_name',
            'order' => 'ASC'
        ));

        $results = array();
        foreach ($users as $user) {
            $results[] = array(
                'id' => $user->ID,
                'text' => sprintf('%s (%s)', 
                    $user->display_name ?: $user->user_login,
                    $user->user_email
                )
            );
        }
        
        wp_send_json_success($results);
    }

    public function add_vip_product_notice() {
        global $product;
        
        if (!$product) return;
        
        $visibility_type = get_post_meta($product->get_id(), '_vip_product', true);
        
        if ($visibility_type === 'yes') {
            $current_user_id = get_current_user_id();
            $vip_user_ids = get_post_meta($product->get_id(), '_vip_user_ids', true);
            
            if ($current_user_id !== 0 && !in_array($current_user_id, (array)$vip_user_ids)) {
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
        global $post;
        
        if (!$post || $post->post_type !== 'product') {
            return;
        }
        
        $product_id = $post->ID;
        $visibility_type = get_post_meta($product_id, '_vip_product', true);

        // If it's a VIP product
        if ($visibility_type === 'yes') {
            $current_user_id = get_current_user_id();
            
            // If user is not logged in, redirect
            if (!$current_user_id) {
                wp_safe_redirect(wc_get_page_permalink('myaccount'));
                exit;
            }

            // Check if user has access
            if (!$this->user_has_vip_access($product_id, $current_user_id)) {
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
        
        if ($current_user_id === 0) {
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_vip_product',
                    'value'   => 'no',
                    'compare' => '='
                ),
                array(
                    'key'     => '_vip_product',
                    'compare' => 'NOT EXISTS'
                )
            );
        } else {
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_vip_product',
                    'value'   => 'yes',
                    'compare' => '!='
                ),
                array(
                    'relation' => 'AND',
                    array(
                        'key'     => '_vip_product',
                        'value'   => 'yes',
                        'compare' => '='
                    ),
                    array(
                        'key'     => '_vip_user_ids',
                        'value'   => serialize(array($current_user_id)),
                        'compare' => 'LIKE'
                    )
                )
            );
        }
        
        $query->set('meta_query', $meta_query);
        return $query;
    }

    public function filter_search_where($where, $query) {
        global $wpdb;
        
        // Only apply to searches
        if (!is_search() && (!isset($_REQUEST['action']) || $_REQUEST['action'] !== 'flatsome_ajax_search_products')) {
            return $where;
        }

        $current_user_id = get_current_user_id();
        
        if ($current_user_id === 0) {
            $where .= " AND {$wpdb->posts}.ID NOT IN (
                SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_vip_product' 
                AND meta_value = 'yes'
            )";
        } else {
            // Create the possible serialized formats
            $single_user = serialize(array($current_user_id)); // a:1:{i:0;i:1;}
            $user_at_start = sprintf('a:%%{i:0;i:%d;%%', $current_user_id); // a:2:{i:0;i:1;...}
            $user_anywhere = sprintf('i:%d;', $current_user_id); // matches i:1; anywhere in string

            $where .= $wpdb->prepare(" AND ({$wpdb->posts}.ID NOT IN (
                SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_vip_product' 
                AND meta_value = 'yes'
            ) OR {$wpdb->posts}.ID IN (
                SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_vip_user_ids' 
                AND (meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s)
            ))", $single_user, $user_at_start, $user_anywhere);
        }

        return $where;
    }

    public function filter_flatsome_search($args) {
        $current_user_id = get_current_user_id();
        
        if (!isset($args['meta_query'])) {
            $args['meta_query'] = array();
        }
        
        if ($current_user_id === 0) {
            $args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_vip_product',
                    'value'   => 'no',
                    'compare' => '='
                ),
                array(
                    'key'     => '_vip_product',
                    'compare' => 'NOT EXISTS'
                )
            );
        } else {
            // Create the possible serialized formats
            $single_user = serialize(array($current_user_id)); // a:1:{i:0;i:1;}
            $user_at_start = sprintf('a:%%{i:0;i:%d;%%', $current_user_id); // a:2:{i:0;i:1;...}
            $user_anywhere = sprintf('i:%d;', $current_user_id); // matches i:1; anywhere in string

            $args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_vip_product',
                    'value'   => 'yes',
                    'compare' => '!='
                ),
                array(
                    'relation' => 'AND',
                    array(
                        'key'     => '_vip_product',
                        'value'   => 'yes',
                        'compare' => '='
                    ),
                    array(
                        'relation' => 'OR',
                        array(
                            'key'     => '_vip_user_ids',
                            'value'   => $single_user,
                            'compare' => '='
                        ),
                        array(
                            'key'     => '_vip_user_ids',
                            'value'   => $user_at_start,
                            'compare' => 'LIKE'
                        ),
                        array(
                            'key'     => '_vip_user_ids',
                            'value'   => $user_anywhere,
                            'compare' => 'LIKE'
                        )
                    )
                )
            );
        }
        
        return $args;
    }

    public function filter_products_query($query, $query_vars) {
        if (!isset($query['meta_query'])) {
            $query['meta_query'] = array();
        }

        $query['meta_query'][] = array(
            'relation' => 'OR',
            array(
                'key'     => '_vip_product',
                'value'   => 'yes',
                'compare' => '!='
            ),
            array(
                'key'     => '_vip_product',
                'compare' => 'NOT EXISTS'
            )
        );

        return $query;
    }

    public function filter_admin_vip_products($query) {
        global $pagenow, $typenow;
        
        // Only run on the products admin page
        if (!is_admin() || $pagenow !== 'edit.php' || $typenow !== 'product') {
            return $query;
        }

        // Check if we want to show VIP products
        $show_vip = isset($_GET['show_vip']) ? $_GET['show_vip'] : '0';
        if ($show_vip !== '1') {
            return $query;
        }

        // Add meta query to show only VIP products
        $meta_query = array(
            array(
                'key'     => '_vip_product',
                'value'   => 'yes',
                'compare' => '='
            )
        );

        // Merge with existing meta query if any
        $existing_meta_query = $query->get('meta_query');
        if (!empty($existing_meta_query)) {
            $meta_query = array_merge($meta_query, $existing_meta_query);
        }

        $query->set('meta_query', $meta_query);
        
        return $query;
    }

    public function add_vip_filter_button() {
        global $typenow;
        
        if ($typenow !== 'product') {
            return;
        }

        $show_vip = isset($_GET['show_vip']) ? sanitize_text_field($_GET['show_vip']) : '0';
        ?>
        <select name="show_vip" id="dropdown_show_vip">
            <option value="0" <?php selected($show_vip, '0'); ?>><?php _e('All Products', 'wc-vip-products'); ?></option>
            <option value="1" <?php selected($show_vip, '1'); ?>><?php _e('VIP Products Only', 'wc-vip-products'); ?></option>
        </select>
        <?php
    }

    public function add_vip_product_type($types) {
        $types['vip'] = __('VIP Product', 'wc-vip-products');
        return $types;
    }

    public function add_vip_product_filter($output) {
        global $wp_query;
        
        // Get current value and sanitize
        $current_product_type = isset($_GET['product_type']) ? sanitize_text_field($_GET['product_type']) : '';
        
        // Modify the output to include VIP filter
        $output = str_replace('</select>', '<option value="vip"' . selected($current_product_type, 'vip', false) . '>' . __('VIP Products', 'wc-vip-products') . '</option></select>', $output);
        
        return $output;
    }

    public function add_create_vip_button($item_id, $item, $product) {
        // Get the order ID and order object
        $order_id = $item->get_order_id();
        $order = wc_get_order($order_id);
        
        if (!$order || !$product) {
            return;
        }
        
        // Check if the order has a registered user
        $user_id = $order->get_user_id();
        if (!$user_id) {
            return;
        }
        
        // Add the create VIP button
        ?>
        <div class="create-vip-product-wrapper">
            <button type="button" 
                    class="button create-vip-product" 
                    data-order-id="<?php echo esc_attr($order_id); ?>"
                    data-item-id="<?php echo esc_attr($item_id); ?>"
                    data-product-id="<?php echo esc_attr($product->get_id()); ?>"
                    data-user-id="<?php echo esc_attr($user_id); ?>">
                <?php _e('Create VIP Product', 'wc-vip-products'); ?>
            </button>
        </div>
        <?php
        
        // Ensure scripts are loaded in the footer
        add_action('admin_footer', function() {
            wp_enqueue_script('jquery');
            wp_enqueue_script(
                'vip-products-admin',
                plugin_dir_url(__FILE__) . 'assets/js/admin.js',
                array('jquery'),
                filemtime(plugin_dir_path(__FILE__) . 'assets/js/admin.js'),
                true
            );
            
            wp_localize_script('vip-products-admin', 'vipProducts', array(
                'nonce' => wp_create_nonce('wp_rest'),
                'ajax_url' => admin_url('admin-ajax.php')
            ));
        });
    }
    
    /**
     * Handle AJAX request to create VIP product from order item
     */
    public function create_vip_from_order_item() {
        check_ajax_referer('wp_rest', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
            return;
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $item_id = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        
        if (!$order_id || !$item_id || !$user_id || !$product_id) {
            wp_send_json_error('Missing required data');
            return;
        }

        $order = wc_get_order($order_id);
        $item = $order->get_item($item_id);
        
        if (!$order || !$item) {
            wp_send_json_error('Invalid order or item');
            return;
        }
        
        // Get the base product to duplicate
        $base_product = wc_get_product(9504);
        if (!$base_product) {
            wp_send_json_error('Base VIP product not found');
            return;
        }
        
        // Get customer info
        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_send_json_error('User not found');
            return;
        }
        
        // Create new product as a duplicate
        $new_product = new WC_Product_Simple();
        
        // Set the product data
        $new_product->set_name(sprintf('%s (%s)', $item->get_name(), $user->first_name . ' ' . $user->last_name));   
        $new_product->set_status('publish');
        $new_product->set_catalog_visibility('hidden');
        $new_product->set_description($base_product->get_description());
        
        // Format meta data in human readable format
        $meta_data = $item->get_meta_data();
        $formatted_meta = '';
        if ($meta_data) {
            foreach ($meta_data as $meta) {
                $formatted_meta .= sprintf("<strong>%s</strong>: %s\n", wp_strip_all_tags($meta->key), wp_strip_all_tags($meta->value));
            }
        }
        $new_product->set_short_description($formatted_meta);
        
        $new_product->set_price($base_product->get_price());
        $new_product->set_regular_price($base_product->get_regular_price());
        
        // Set as VIP product
        $new_product->update_meta_data('_vip_product', 'yes');
        $new_product->update_meta_data('_vip_user_ids', array($user_id));
        
        // Save the product
        $new_product_id = $new_product->save();
        
        if (!$new_product_id) {
            wp_send_json_error('Failed to create VIP product');
            return;
        }
        
        // Return success with edit URL
        wp_send_json_success(array(
            'message' => 'VIP product created successfully',
            'redirect' => get_edit_post_link($new_product_id, 'url')
        ));
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
    // Add the endpoint
    add_rewrite_endpoint('vip-products', EP_ROOT | EP_PAGES);
    
    // Flush rewrite rules
    flush_rewrite_rules();
} 