<?php
/** 
 * Plugin Name: Nochex Script Monitor
 * Description: A plugin to monitor inline, internal, external, and dynamically created scripts.
 * Version: 1.0
 * Author: Nochex DevTeam
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include the ScriptMonitor class
require_once plugin_dir_path(__FILE__) . 'includes/script-monitor.php';

// Initialize the ScriptMonitor class and hooks
ScriptMonitor::init();

// Adds the Script Monitor admin menu and configuration submenu to the dashboard
add_action('admin_menu', function () {
    add_menu_page(
        'Script Monitor',
        'Nochex Script Monitor',
        'manage_options',
        'ncx-script-monitor',
        'render_script_monitor_admin_page',
        'dashicons-visibility',
        25
    );
    add_submenu_page(
        'ncx-script-monitor',
        'Configuration',
        'Email Configuration',
        'manage_options',
        'ncx-script-monitor-config',
        'script_monitor_config_page'
    );
});

require_once plugin_dir_path(__FILE__) . 'includes/admin-config-page.php';

// Loads and displays the Script Monitor admin page
function render_script_monitor_admin_page() {
    require_once plugin_dir_path(__FILE__) . 'includes/admin-page.php';
}

// Display admin notices for pending scripts
add_action('admin_notices', function () {
    global $wpdb;
    $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}script_monitor WHERE script_type = 'pending'");
    if ($pending_count > 0) {
        echo '<div class="notice notice-error" style="width:30%; padding:5px;"><p>';
        echo sprintf(
            'There are <strong>%d</strong> new or changed scripts that need review in <a href="%s">Script Monitor</a>.',
            intval($pending_count),
            esc_url(admin_url('admin.php?page=ncx-script-monitor&filter=pending'))
        );
        echo '</p></div>'; 
    }
});
?>