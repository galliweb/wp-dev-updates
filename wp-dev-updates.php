<?php
/**
 * Plugin Name: Galliweb Dev Updates
 * Description: Erweiterte Kontrolle über Update-Benachrichtigungen 1
 * Version: 1.0
 */

if (!defined('ABSPATH')) exit;

class SelectiveUpdateNotificationsPro {
    private $settings;

    public function __construct() {
        $this->settings = array(
            'dev_email' => 'dev@galliweb.ch',
            'send_on_success' => false,
            'send_on_fail' => true,
            'send_on_critical' => true
        );
        add_action('init', array($this, 'init'));
    }

    public function init() {
        add_filter('auto_core_update_send_email', array($this, 'should_send_email'), 10, 4);
        add_filter('auto_plugin_update_send_email', array($this, 'should_send_email'), 10, 4);
        add_filter('auto_theme_update_send_email', array($this, 'should_send_email'), 10, 4);
        add_filter('auto_core_update_email', array($this, 'modify_email'));
        add_filter('auto_plugin_update_email', array($this, 'modify_email'));
        add_filter('auto_theme_update_email', array($this, 'modify_email'));
    }

    public function should_send_email($send, $type, $core_update, $result) {
        switch($type) {
            case 'success':
                return $this->settings['send_on_success'];
            case 'fail':
                return $this->settings['send_on_fail'];
            case 'critical':
                return $this->settings['send_on_critical'];
            default:
                return false;
        }
    }

    public function modify_email($email) {
        $email['to'] = $this->settings['dev_email'];
        
        // E-Mail-Subject anpassen mit Website-Name
        if (isset($email['subject'])) {
            $site_name = get_bloginfo('name');
            $email['subject'] = '[ERROR - ' . $site_name . '] ' . $email['subject'];
        }
        
        return $email;
    }
}

class GalliwebUpdater {
    private $plugin_slug;
    private $version;
    private $github_repo;
    private $plugin_file;
    
    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->version = '1.0'; // Diese Version muss mit der im Header übereinstimmen
        $this->github_repo = 'galliweb/wp-dev-updates';
        
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_source_dir'), 10, 4);
    }
    
    public function check_for_update($transient) {
        if (empty($transient->checked)) return $transient;
        
        $remote_version = $this->get_remote_version();
        
        if ($remote_version && version_compare($this->version, $remote_version, '<')) {
            $transient->response[$this->plugin_slug] = (object) array(
                'slug' => dirname($this->plugin_slug),
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_version,
                'url' => "https://github.com/{$this->github_repo}",
                'package' => "https://github.com/{$this->github_repo}/archive/refs/tags/v{$remote_version}.zip"
            );
        }
        
        return $transient;
    }
    
    public function plugin_info($false, $action, $response) {
        if ($action !== 'plugin_information') return false;
        if (dirname($this->plugin_slug) !== $response->slug) return false;
        
        $remote_version = $this->get_remote_version();
        
        $response = (object) array(
            'name' => 'Galliweb Dev Updates',
            'slug' => dirname($this->plugin_slug),
            'version' => $remote_version,
            'author' => 'Galliweb',
            'homepage' => "https://github.com/{$this->github_repo}",
            'download_link' => "https://github.com/{$this->github_repo}/archive/refs/tags/v{$remote_version}.zip",
            'sections' => array(
                'description' => 'Erweiterte Kontrolle über Update-Benachrichtigungen',
            )
        );
        
        return $response;
    }
    
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra) {
        global $wp_filesystem;
        
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $source;
        }
        
        $plugin_folder = dirname($this->plugin_slug);
        $new_source = trailingslashit($remote_source) . $plugin_folder;
        
        if ($wp_filesystem->move($source, $new_source)) {
            return $new_source;
        }
        
        return $source;
    }
    
    private function get_remote_version() {
        $request = wp_remote_get("https://api.github.com/repos/{$this->github_repo}/releases/latest", array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
            )
        ));
        
        if (is_wp_error($request) || wp_remote_retrieve_response_code($request) !== 200) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($request);
        $data = json_decode($body, true);
        
        if (!isset($data['tag_name'])) return false;
        
        return ltrim($data['tag_name'], 'v');
    }
}

// Plugin initialisieren
new SelectiveUpdateNotificationsPro();
new GalliwebUpdater(__FILE__);