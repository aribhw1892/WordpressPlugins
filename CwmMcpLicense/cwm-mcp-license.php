<?php
/**
 * Plugin Name: CWM SolidWorks MCP License
 * Description: Dodo Payments trial and subscription check for the SolidWorks MCP host, plus daily usage totals and an administrator dashboard.
 * Version: 1.3.0
 * Author: Cad Workflow Manager
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('CWM_SWX_MCP_LICENSE_FILE', __FILE__);
define('CWM_SWX_MCP_LICENSE_DIR', __DIR__);

require_once CWM_SWX_MCP_LICENSE_DIR . '/includes/class-cwm-swx-mcp-settings.php';
require_once CWM_SWX_MCP_LICENSE_DIR . '/includes/class-cwm-swx-mcp-usage.php';
require_once CWM_SWX_MCP_LICENSE_DIR . '/includes/class-cwm-swx-mcp-installs.php';
require_once CWM_SWX_MCP_LICENSE_DIR . '/includes/class-cwm-swx-mcp-dodo.php';
require_once CWM_SWX_MCP_LICENSE_DIR . '/includes/class-cwm-swx-mcp-rest.php';
require_once CWM_SWX_MCP_LICENSE_DIR . '/includes/class-cwm-swx-mcp-dashboard.php';

register_activation_hook(CWM_SWX_MCP_LICENSE_FILE, array('CWM_Swx_Mcp_Usage', 'activate'));
register_activation_hook(CWM_SWX_MCP_LICENSE_FILE, array('CWM_Swx_Mcp_Installs', 'maybe_install'));
add_action('plugins_loaded', array('CWM_Swx_Mcp_Installs', 'maybe_install'));
register_deactivation_hook(CWM_SWX_MCP_LICENSE_FILE, array('CWM_Swx_Mcp_Usage', 'deactivate'));

add_action('admin_init', array('CWM_Swx_Mcp_Settings', 'register'));
add_action('admin_init', array('CWM_Swx_Mcp_Dashboard', 'redirect_admin'));
add_action('admin_menu', 'cwm_swx_mcp_license_menu');
add_action('init', array('CWM_Swx_Mcp_Dashboard', 'register_route'));
add_action('init', array('CWM_Swx_Mcp_Dashboard', 'maybe_flush'), 99);
add_filter('query_vars', array('CWM_Swx_Mcp_Dashboard', 'query_vars'));
add_action('template_redirect', array('CWM_Swx_Mcp_Dashboard', 'maybe_serve'));
add_action('rest_api_init', array('CWM_Swx_Mcp_Rest', 'register'));
add_action('cwm_swx_mcp_usage_prune', array('CWM_Swx_Mcp_Usage', 'prune'));

/**
 * Settings pages for the Dodo Payments key and the usage totals.
 */
function cwm_swx_mcp_license_menu(): void
{
    add_options_page(
        'SolidWorks MCP',
        'SolidWorks MCP',
        'manage_options',
        'cwm-swx-mcp-license',
        array('CWM_Swx_Mcp_Settings', 'render_page')
    );
    add_options_page(
        'SolidWorks MCP usage',
        'SolidWorks MCP usage',
        'manage_options',
        'cwm-swx-mcp-usage',
        array('CWM_Swx_Mcp_Usage', 'render_page')
    );
    CWM_Swx_Mcp_Dashboard::register_menu();
}
