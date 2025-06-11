<?php
// This file handles the cleanup process when the plugin is uninstalled.
// It removes any stored data related to the monitored scripts.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete options or any custom database tables related to the plugin
delete_option('script_monitor_options');
delete_option('script_monitor_data');

// If you have custom tables, you can drop them here
global $wpdb;

        $this->table_name = $wpdb->prefix . 'script_monitor';
		
$wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
?>