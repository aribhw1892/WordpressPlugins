<?php
/**
 * Plugin Name: CWM SolidWorks MCP License
 * Description: Stripe trial and subscription check for the SolidWorks MCP host, plus daily usage totals.
 * Version: 1.0.2
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
require_once CWM_SWX_MCP_LICENSE_DIR . '/includes/class-cwm-swx-mcp-stripe.php';
require_once CWM_SWX_MCP_LICENSE_DIR . '/includes/class-cwm-swx-mcp-rest.php';

register_activation_hook(CWM_SWX_MCP_LICENSE_FILE, array('CWM_Swx_Mcp_Usage', 'activate'));
register_activation_hook(CWM_SWX_MCP_LICENSE_FILE, array('CWM_Swx_Mcp_Installs', 'maybe_install'));
add_action('plugins_loaded', array('CWM_Swx_Mcp_Installs', 'maybe_install'));
register_deactivation_hook(CWM_SWX_MCP_LICENSE_FILE, array('CWM_Swx_Mcp_Usage', 'deactivate'));

add_action('admin_init', array('CWM_Swx_Mcp_Settings', 'register'));
add_action('admin_menu', 'cwm_swx_mcp_license_menu');
add_action('rest_api_init', array('CWM_Swx_Mcp_Rest', 'register'));
add_action('cwm_swx_mcp_usage_prune', array('CWM_Swx_Mcp_Usage', 'prune'));

/**
 * Settings pages for the Stripe keys and the usage totals.
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
}
