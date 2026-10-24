<?php
/**
 * Removes the SolidWorks MCP license option and usage table.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('cwm_mcp_license_settings');
delete_option('cwm_swx_mcp_db_version');

global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'cwm_swx_mcp_usage');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'cwm_swx_mcp_installs');

wp_clear_scheduled_hook('cwm_swx_mcp_usage_prune');
