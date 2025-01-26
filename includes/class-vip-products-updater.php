<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_VIP_Products_Updater {
    private $github_repo = 'dbaldaro/vip-products';
    private $github_api_url = 'https://api.github.com/repos/dbaldaro/vip-products/releases/latest';
    private $plugin_slug = 'vip-products';
    private $plugin_path;
    private $current_version;

    public function __construct() {
        $this->plugin_path = plugin_dir_path(dirname(__FILE__));
        $this->current_version = $this->get_plugin_version();
        
        // Register hooks on init to prevent early loading
        add_action('init', array($this, 'register_hooks'));
    }

    public function register_hooks() {
        // Add update checker to WordPress admin
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        
        // Add plugin info to WordPress updates screen
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
    }

    public function get_plugin_version() {
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        $plugin_data = get_plugin_data($this->plugin_path . 'vip-products.php');
        return $plugin_data['Version'];
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get remote version
        $remote_version = $this->get_remote_version();
        
        // Get plugin basename
        $plugin_basename = plugin_basename($this->plugin_path . 'vip-products.php');
        
        if ($remote_version && version_compare($this->current_version, $remote_version, '<')) {
            $obj = new stdClass();
            $obj->slug = $this->plugin_slug;
            $obj->plugin = $plugin_basename;
            $obj->new_version = $remote_version;
            $obj->url = "https://github.com/{$this->github_repo}";
            $obj->package = $this->get_download_url($remote_version);
            
            // Add to WordPress transient
            $transient->response[$plugin_basename] = $obj;
        } else {
            // No update available
            $obj = new stdClass();
            $obj->slug = $this->plugin_slug;
            $obj->plugin = $plugin_basename;
            $obj->new_version = $this->current_version;
            $obj->url = "https://github.com/{$this->github_repo}";
            $obj->package = '';
            
            // Add to no_update list
            $transient->no_update[$plugin_basename] = $obj;
        }

        return $transient;
    }

    public function get_remote_version() {
        $response = wp_remote_get($this->github_api_url, array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (empty($data->tag_name)) {
            return false;
        }

        return ltrim($data->tag_name, 'v');
    }

    private function get_download_url($version) {
        return "https://github.com/{$this->github_repo}/archive/refs/tags/v{$version}.zip";
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if ($this->plugin_slug !== $args->slug) {
            return $result;
        }

        $response = wp_remote_get($this->github_api_url, array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            )
        ));

        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response));
            if ($data) {
                $plugin_info = new stdClass();
                $plugin_info->name = 'WooCommerce VIP Products';
                $plugin_info->slug = $this->plugin_slug;
                $plugin_info->version = ltrim($data->tag_name, 'v');
                $plugin_info->author = 'David Baldaro';
                $plugin_info->homepage = "https://github.com/{$this->github_repo}";
                $plugin_info->requires = '5.0';
                $plugin_info->tested = get_bloginfo('version');
                $plugin_info->downloaded = 0;
                $plugin_info->last_updated = $data->published_at;
                $plugin_info->sections = array(
                    'description' => $data->body,
                    'changelog' => $this->get_changelog()
                );
                $plugin_info->download_link = $this->get_download_url($plugin_info->version);

                return $plugin_info;
            }
        }

        return $result;
    }

    private function get_changelog() {
        $response = wp_remote_get("https://raw.githubusercontent.com/{$this->github_repo}/master/CHANGELOG.md");
        if (!is_wp_error($response)) {
            return wp_remote_retrieve_body($response);
        }
        return 'No changelog available.';
    }

    public function settings_page() {
        $remote_version = $this->get_remote_version();
        ?>
        <div class="wrap">
            <h1>VIP Products Update Status</h1>
            <div class="notice notice-info">
                <p>Current Version: <?php echo esc_html($this->current_version); ?></p>
                <p>Latest Version: <?php echo $remote_version ? esc_html($remote_version) : 'Unable to check'; ?></p>
                <?php if ($remote_version && version_compare($this->current_version, $remote_version, '<')): ?>
                    <p>An update is available! Please use the WordPress updates page to update the plugin.</p>
                <?php else: ?>
                    <p>You are running the latest version.</p>
                <?php endif; ?>
            </div>
            <p>This plugin automatically checks for updates from GitHub. When updates are available, they will appear in your WordPress updates section.</p>
        </div>
        <?php
    }
}
