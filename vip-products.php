<?php
/*
Plugin Name: WooCommerce VIP Products
Description: Allows for creation of VIP-exclusive products for specific users.
Version: 1.1.23
Author: David Baldaro
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
        // Register initialization hook
        add_action('init', array($this, 'initialize_plugin'));
        
        // Initialize updater early
        require_once plugin_dir_path(__FILE__) . 'includes/class-vip-products-updater.php';
        $this->updater = new WC_VIP_Products_Updater();
    }

    public function initialize_plugin() {
        try {
            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
                return;
            }

            // Register all hooks
            $this->register_hooks();
        } catch (Exception $e) {
            error_log('VIP Products Error: ' . $e->getMessage());
        }
    }

    public function register_hooks() {
        // Add AJAX handlers
        add_action('wp_ajax_search_users', array($this, 'ajax_search_users'));
        add_action('wp_ajax_create_vip_from_order_item', array($this, 'create_vip_from_order_item'));
        add_action('wp_ajax_search_products', array($this, 'ajax_search_products'));
        add_action('wp_ajax_create_vip_product_from_template', array($this, 'ajax_create_vip_product_from_template'));
        add_action('wp_ajax_delete_vip_product', array($this, 'ajax_delete_vip_product'));

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Add product tab
        add_filter('woocommerce_product_data_tabs', array($this, 'add_vip_products_tab'));
        
        // Add tab content
        add_action('woocommerce_product_data_panels', array($this, 'add_vip_products_fields'));
        
        // Save custom fields
        add_action('woocommerce_process_product_meta', array($this, 'save_vip_products_fields'));
        
        // Filter products query
        add_filter('woocommerce_product_query', array($this, 'filter_vip_products'));
        
        // Add endpoint for My Account page
        $this->add_endpoints();
        add_filter('woocommerce_account_menu_items', array($this, 'add_vip_products_tab_myaccount'));
        add_action('woocommerce_account_vip-products_endpoint', array($this, 'vip_products_content'));

        // Add VIP product type filter
        add_filter('product_type_selector', array($this, 'add_vip_product_type'));
        add_filter('woocommerce_product_filters', array($this, 'add_vip_product_filter'));

        // Add VIP product creation button and handler
        add_action('woocommerce_after_order_itemmeta', array($this, 'add_create_vip_button'), 10, 3);

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

        // Add VIP Products admin page
        add_action('admin_menu', array($this, 'add_vip_products_admin_menu'));
    }

    public function woocommerce_missing_notice() {
        ?>
        <div class="error">
            <p>WooCommerce VIP Products requires WooCommerce to be installed and active.</p>
        </div>
        <?php
    }

    public function enqueue_admin_scripts($hook) {
        // Only load on order edit page
        global $post_type;
        if ($hook !== 'post.php' && $hook !== 'post-new.php' && $hook !== 'woocommerce_page_wc-orders') {
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
            'create_nonce' => wp_create_nonce('vip_products_create'),
            'search_nonce' => wp_create_nonce('vip_products_search'),
            'ajax_url' => admin_url('admin-ajax.php')
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
            'label'    => 'VIP Access',
            'target'   => 'vip_products_data',
            'class'    => array(),
            'priority' => 21
        );
        return $tabs;
    }

    public function add_vip_products_fields() {
        echo '<div id="vip_products_data" class="panel woocommerce_options_panel">';
        
        woocommerce_wp_select(array(
            'id' => '_vip_product',
            'label' => 'VIP Status',
            'description' => 'Is this a VIP-only product?',
            'options' => array(
                'no' => 'No',
                'yes' => 'Yes'
            ),
            'style' => 'width: 150px;'
        ));

        echo '<div class="options_group">';
        echo '<p class="form-field _vip_user_ids_field">';
        echo '<label for="_vip_user_ids">VIP Users</label>';
        echo '<select id="_vip_user_ids" name="_vip_user_ids[]" class="wc-customer-search" multiple="multiple" data-placeholder="Search and select users..." style="width: 100%;">';

        // Get saved user IDs
        global $post;
        $user_ids = get_post_meta($post->ID, '_vip_user_ids', true);
        
        if (!empty($user_ids)) {
            foreach ((array)$user_ids as $user_id) {
                $user = get_user_by('id', $user_id);
                if ($user) {
                    echo '<option value="' . esc_attr($user_id) . '" selected="selected">' . esc_html($user->display_name) . ' (#' . absint($user->ID) . ' &ndash; ' . esc_html($user->user_email) . ')</option>';
                }
            }
        }
        
        echo '</select>';
        echo '<span class="description">Search and select users who can access this VIP product. You can select multiple users.</span>';
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
                    'value'   => 'no',
                    'compare' => '='
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
        $items['vip-products'] = 'My VIP Products';
        return $items;
    }

    public function vip_products_content() {
        try {
            $current_user_id = get_current_user_id();
            
            if ($current_user_id === 0) {
                echo '<p class="woocommerce-info">Please log in to view your VIP products.</p>';
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
        check_ajax_referer('vip_products_search', 'search_nonce');
        
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
                    'This is a VIP-exclusive product. Please contact us for access.',
                    'notice'
                );
            } else {
                wc_print_notice(
                    'This is one of your VIP-exclusive products.',
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
        if (!$query->is_search() || !$query->is_main_query() || is_admin()) {
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
        if (!isset($args['meta_query'])) {
            $args['meta_query'] = array();
        }
        
        // Exclude all VIP products from search results
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
        <select name="show_vip" class="dropdown_product_cat">
            <option value="0" <?php selected($show_vip, '0'); ?>>All Products</option>
            <option value="1" <?php selected($show_vip, '1'); ?>>VIP Products Only</option>
        </select>
        <?php
    }

    public function add_vip_product_type($types) {
        $types['vip'] = 'VIP Product';
        return $types;
    }

    public function add_vip_product_filter($output) {
        global $post;
        
        if ($post) {
            $current_product_type = get_post_meta($post->ID, '_vip_product', true);
            $output = str_replace('</select>', '<option value="vip"' . selected($current_product_type, 'vip', false) . '>VIP Products</option></select>', $output);
        }
        
        return $output;
    }

    public function add_create_vip_button($item_id, $item, $product) {
        // Skip if product is null or item doesn't have an order
        if (!$product || !$item || !$item->get_order()) {
            return;
        }
        
        $order = $item->get_order();
        $user_id = $order ? $order->get_user_id() : 0;
        
        if ($user_id) {
        ?>
        <button type="button" class="button create-vip-product" 
                data-order-id="<?php echo esc_attr($item->get_order_id()); ?>"
                data-item-id="<?php echo esc_attr($item_id); ?>"
                data-product-id="<?php echo esc_attr($product->get_id()); ?>"
                data-user-id="<?php echo esc_attr($user_id); ?>">
            Create VIP Product
        </button>
        <?php
        }
    }
    
    /**
     * Exclude VIP products from search results unless user has access
     *
     * @param WP_Query $query The WordPress query object
     * @return WP_Query
     */
    public function exclude_vip_products_from_search($query) {
        // Only modify search queries
        if (!$query->is_search() || !$query->is_main_query() || is_admin()) {
            return $query;
        }

        $current_user_id = get_current_user_id();

        // If user is not logged in, exclude all VIP products
        if ($current_user_id === 0) {
            $meta_query = array(
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
        } else {
            // For logged in users, show only their VIP products plus regular products
            $meta_query = array(
                'relation' => 'OR',
                // Regular products
                array(
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
                ),
                // User's VIP products
                array(
                    'relation' => 'AND',
                    array(
                        'key'     => '_vip_product',
                        'value'   => 'yes',
                        'compare' => '='
                    ),
                    array(
                        'key'     => '_vip_user_ids',
                        'value'   => sprintf(':%d;', $current_user_id),
                        'compare' => 'LIKE'
                    )
                )
            );
        }

        // Add our meta query
        $existing_meta_query = $query->get('meta_query');
        if (!empty($existing_meta_query)) {
            $meta_query = array(
                'relation' => 'AND',
                $existing_meta_query,
                $meta_query
            );
        }
        $query->set('meta_query', $meta_query);

        return $query;
    }

    /**
     * Add VIP Products admin page
     */
    public function add_vip_products_admin_menu() {
        add_menu_page(
            'VIP Products',
            'VIP Products',
            'manage_woocommerce',
            'vip-products-admin',
            array($this, 'render_vip_products_admin_page'),
            'dashicons-star-filled',
            56
        );
    }

    /**
     * Render VIP Products admin page
     */
    public function render_vip_products_admin_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }
        
        include_once plugin_dir_path(__FILE__) . 'templates/admin-vip-products.php';
    }

    /**
     * Handle AJAX request to create VIP product from order item
     */
    public function create_vip_from_order_item() {
        check_ajax_referer('vip_products_create', 'create_nonce');
        
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
        $base_product = wc_get_product(73363);
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
        
        // Get all meta data from the order item
        $item_meta_data = $item->get_meta_data();
        $meta_text = '';
        
        if (!empty($item_meta_data)) {
            $meta_text .= "<strong>Original Order Details:</strong>\n\n";
            
            // Get all item data including product options
            $formatted_meta = array();
            
            // Get the raw item meta
            $raw_meta = $item->get_formatted_meta_data('_', true);
            
            // Format extra product options
            foreach ($raw_meta as $meta_id => $meta) {
                if (!empty($meta->display_key) && !empty($meta->display_value)) {
                    $value = $meta->display_value;
                    
                    // Check if the value is an image URL or contains an image URL
                    if (preg_match('/(https?:\/\/[^\s<>"]+?\.(?:jpg|jpeg|gif|png))/', $value, $matches)) {
                        // If it's an image URL, add both the URL and an img tag
                        $img_url = $matches[1];
                        $value = sprintf('%s<br><img src="%s" style="max-width: 300px; height: auto;" />', 
                            $value,
                            esc_url($img_url)
                        );
                    }
                    
                    $formatted_meta[] = sprintf("<strong>%s</strong>: %s", 
                        wp_kses_post($meta->display_key), 
                        wp_kses_post($value)
                    );
                }
            }
            
            // Add any additional meta data not covered by formatted meta
            foreach ($item_meta_data as $meta) {
                // Skip internal meta keys that start with underscore
                if (substr($meta->key, 0, 1) !== '_' && !isset($raw_meta[$meta->id])) {
                    $value = maybe_serialize($meta->value);
                    
                    // Check if the value is an image URL or contains an image URL
                    if (preg_match('/(https?:\/\/[^\s<>"]+?\.(?:jpg|jpeg|gif|png))/', $value, $matches)) {
                        // If it's an image URL, add both the URL and an img tag
                        $img_url = $matches[1];
                        $value = sprintf('%s<br><img src="%s" style="max-width: 300px; height: auto;" />', 
                            $value,
                            esc_url($img_url)
                        );
                    }
                    
                    $formatted_meta[] = sprintf("<strong>%s</strong>: %s", 
                        wc_attribute_label($meta->key), 
                        wp_kses_post($value)
                    );
                }
            }
            
            // Add the formatted meta to the description
            if (!empty($formatted_meta)) {
                $meta_text .= "<strong>Product Options:</strong>\n";
                $meta_text .= implode("\n", $formatted_meta) . "\n";
            }
            
            // Add order information
            $meta_text .= sprintf("\n<strong>Order ID</strong>: %s\n", $order_id);
            $meta_text .= sprintf("<strong>Order Date</strong>: %s\n", $order->get_date_created()->date('Y-m-d H:i:s'));
            $meta_text .= sprintf("<strong>Original Product ID</strong>: %s\n", $product_id);
            
            // Add quantity information
            $meta_text .= sprintf("<strong>Quantity Ordered</strong>: %s\n", $item->get_quantity());
        }
        
        // Create new product as a duplicate
        $new_product = new WC_Product_Simple();
        
        // Set initial non-price product data
        $new_product->set_props(array(
            'name' => sprintf('%s (%s)', $item->get_name(), $user->first_name . ' ' . $user->last_name),
            'status' => 'publish',
            'catalog_visibility' => 'hidden',
            'description' => $base_product->get_description(),
            'short_description' => $meta_text, // Set the meta data as short description
        ));
        
        // Save first to get an ID
        $new_product_id = $new_product->save();
        
        if (!$new_product_id) {
            wp_send_json_error('Failed to create VIP product');
            return;
        }
        
        // Copy all product meta data except price and VIP specific ones
        $exclude_meta = array(
            '_vip_product', '_vip_user_ids', '_edit_lock', '_edit_last',
            '_price', '_regular_price', '_sale_price' // Exclude price meta only
        );
        $meta_data = get_post_meta($base_product->get_id());
        foreach ($meta_data as $meta_key => $meta_values) {
            if (!in_array($meta_key, $exclude_meta)) {
                foreach ($meta_values as $meta_value) {
                    update_post_meta($new_product_id, $meta_key, maybe_unserialize($meta_value));
                }
            }
        }

        // Copy featured image and gallery
        $thumbnail_id = get_post_thumbnail_id($base_product->get_id());
        if ($thumbnail_id) {
            set_post_thumbnail($new_product_id, $thumbnail_id);
        }

        $product_gallery = $base_product->get_gallery_image_ids();
        if (!empty($product_gallery)) {
            update_post_meta($new_product_id, '_product_image_gallery', implode(',', $product_gallery));
        }

        // Set price from the original order line item
        $item_total = floatval($item->get_total()) + floatval($item->get_total_tax());
        $item_quantity = $item->get_quantity();
        $item_price = $item_quantity > 0 ? round($item_total / $item_quantity, 2) : 0;
        $formatted_price = wc_format_decimal($item_price, 2);
        
        // Now set the price after all other meta is copied
        remove_all_filters('woocommerce_product_get_price');
        remove_all_filters('woocommerce_product_get_regular_price');
        
        // Set price directly in meta
        update_post_meta($new_product_id, '_regular_price', $formatted_price);
        update_post_meta($new_product_id, '_price', $formatted_price);
        delete_post_meta($new_product_id, '_sale_price');
        
        // Get a fresh product object and set price again
        $product = wc_get_product($new_product_id);
        $product->set_regular_price($formatted_price);
        $product->set_price($formatted_price);
        
        // Set VIP meta
        $product->update_meta_data('_vip_product', 'yes');
        $product->update_meta_data('_vip_user_ids', array($user_id));
        $product->update_meta_data('_vip_status', 'VIP Members Only'); // Set VIP status to VIP Members Only
        $product->save();
        
        // Set the primary category to "VIP Products" and other meta first
        $vip_category = get_term_by('name', 'VIP Products', 'product_cat');
        if (!$vip_category) {
            // Create the category if it doesn't exist
            $vip_category = wp_insert_term('VIP Products', 'product_cat');
            if (is_wp_error($vip_category)) {
                vip_debug_log('Failed to create VIP Products category: ' . $vip_category->get_error_message());
            } else {
                $vip_category = get_term($vip_category['term_id'], 'product_cat');
            }
        }
        
        if ($vip_category && !is_wp_error($vip_category)) {
            wp_set_object_terms($new_product_id, $vip_category->term_id, 'product_cat');
            update_post_meta($new_product_id, '_yoast_wpseo_primary_product_cat', $vip_category->term_id);
        }
        
        // Return success with edit URL
        wp_send_json_success(array(
            'message' => 'VIP product created successfully',
            'redirect' => get_edit_post_link($new_product_id, 'url')
        ));
    }

    /**
     * AJAX handler for searching products
     */
    public function ajax_search_products() {
        check_ajax_referer('search-products', 'security');

        $term = sanitize_text_field($_GET['term']);
        
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            's' => $term
        );

        $products = get_posts($args);
        $results = array();

        foreach ($products as $product) {
            $results[] = array(
                'id' => $product->ID,
                'label' => $product->post_title,
                'value' => $product->post_title
            );
        }

        wp_send_json($results);
    }

    /**
     * AJAX handler for creating a VIP product from template
     */
    public function ajax_create_vip_product_from_template() {
        check_ajax_referer('create-vip-product', 'security');

        $template_id = intval($_POST['product_id']);
        if (!$template_id) {
            wp_send_json_error('Invalid product ID');
            return;
        }

        $template_product = wc_get_product($template_id);
        if (!$template_product) {
            wp_send_json_error('Template product not found');
            return;
        }

        // Create a new product as a duplicate of the template
        $new_product = new WC_Product();
        
        // Set initial non-price product data
        $new_product->set_name($template_product->get_name() . ' (VIP)');
        $new_product->set_status('publish');
        $new_product->set_catalog_visibility('hidden');
        $new_product->set_description($template_product->get_description());
        $new_product->set_short_description($template_product->get_short_description());
        $new_product->set_regular_price($template_product->get_regular_price());
        $new_product->set_sale_price($template_product->get_sale_price());
        $new_product->set_tax_status($template_product->get_tax_status());
        $new_product->set_tax_class($template_product->get_tax_class());
        $new_product->set_manage_stock($template_product->get_manage_stock());
        $new_product->set_stock_quantity($template_product->get_stock_quantity());
        $new_product->set_stock_status($template_product->get_stock_status());
        $new_product->set_backorders($template_product->get_backorders());
        $new_product->set_reviews_allowed($template_product->get_reviews_allowed());
        $new_product->set_sold_individually($template_product->get_sold_individually());
        $new_product->set_weight($template_product->get_weight());
        $new_product->set_length($template_product->get_length());
        $new_product->set_width($template_product->get_width());
        $new_product->set_height($template_product->get_height());

        // Save the new product
        $new_product_id = $new_product->save();

        // Copy product images
        $template_image_id = $template_product->get_image_id();
        if ($template_image_id) {
            set_post_thumbnail($new_product_id, $template_image_id);
        }

        $template_gallery_ids = $template_product->get_gallery_image_ids();
        if (!empty($template_gallery_ids)) {
            update_post_meta($new_product_id, '_product_image_gallery', implode(',', $template_gallery_ids));
        }

        // Set VIP product meta according to dev note guidelines
        update_post_meta($new_product_id, '_vip_product', 'yes');
        update_post_meta($new_product_id, '_vip_user_ids', array()); // Empty array initially, users can be assigned later

        // Get the edit link for the new product
        $edit_link = get_edit_post_link($new_product_id, 'raw'); // 'raw' returns the URL without entities

        wp_send_json_success(array(
            'product_id' => $new_product_id,
            'message' => 'VIP product created successfully',
            'redirect_url' => $edit_link
        ));
    }

    /**
     * AJAX handler for deleting VIP products
     */
    public function ajax_delete_vip_product() {
        // Verify nonce
        if (!isset($_POST['security']) || !wp_verify_nonce($_POST['security'], 'delete_vip_product')) {
            wp_send_json_error('Invalid security token');
        }

        // Check if product ID is provided
        if (!isset($_POST['product_id'])) {
            wp_send_json_error('Product ID is required');
        }

        $product_id = intval($_POST['product_id']);

        // Check if user has permission
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('You do not have permission to delete products');
        }

        // Delete the product
        $result = wp_delete_post($product_id, true);

        if ($result) {
            wp_send_json_success(array(
                'message' => 'Product deleted successfully'
            ));
        } else {
            wp_send_json_error('Failed to delete product');
        }
    }
}

// Initialize plugin
function wc_vip_products() {
    return WC_VIP_Products::init();
}

// Initialize plugin after WooCommerce is loaded
add_action('plugins_loaded', 'wc_vip_products');

// Activation hook
register_activation_hook(__FILE__, 'wc_vip_products_activate');

function wc_vip_products_activate() {
    // Add the endpoint
    add_rewrite_endpoint('vip-products', EP_ROOT | EP_PAGES);
    
    // Flush rewrite rules
    flush_rewrite_rules();
}