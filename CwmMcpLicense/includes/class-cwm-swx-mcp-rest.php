<?php
/**
 * Public REST routes the SolidWorks MCP host calls.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CWM_Swx_Mcp_Rest
{
    public static function register(): void
    {
        register_rest_route('cwm-mcp/v1', '/trial', array(
            'methods' => 'POST',
            'callback' => array(self::class, 'trial'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('cwm-mcp/v1', '/checkout-return', array(
            'methods' => 'GET',
            'callback' => array(self::class, 'checkout_return'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('cwm-mcp/v1', '/checkout', array(
            'methods' => 'POST',
            'callback' => array(self::class, 'checkout'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('cwm-mcp/v1', '/status', array(
            'methods' => 'GET',
            'callback' => array(self::class, 'status'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('cwm-mcp/v1', '/portal', array(
            'methods' => 'POST',
            'callback' => array(self::class, 'portal'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route('cwm-mcp/v1', '/events', array(
            'methods' => 'POST',
            'callback' => array(self::class, 'events'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function trial(WP_REST_Request $request)
    {
        $install_id = self::install_id($request);
        if (is_wp_error($install_id)) {
            return $install_id;
        }
        $found = CWM_Swx_Mcp_Stripe::start_trial($install_id);
        if (is_wp_error($found)) {
            return $found;
        }
        return new WP_REST_Response(array(
            'status' => $found['status'],
            'trial_end' => $found['trial_end'],
        ), 200);
    }

    public static function checkout_return(WP_REST_Request $request): void
    {
        $session_id = $request->get_param('session_id');
        if (is_string($session_id)) {
            CWM_Swx_Mcp_Stripe::remember_checkout_session($session_id);
        }
        wp_safe_redirect(add_query_arg('cwm_swx_mcp', 'success', home_url('/')));
        exit;
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function checkout(WP_REST_Request $request)
    {
        $install_id = self::install_id($request);
        if (is_wp_error($install_id)) {
            return $install_id;
        }
        $url = CWM_Swx_Mcp_Stripe::checkout_url($install_id);
        if (is_wp_error($url)) {
            return $url;
        }
        return new WP_REST_Response(array('url' => $url), 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function status(WP_REST_Request $request)
    {
        $install_id = self::install_id($request);
        if (is_wp_error($install_id)) {
            return $install_id;
        }
        $found = CWM_Swx_Mcp_Stripe::find_subscription($install_id);
        if (is_wp_error($found)) {
            return $found;
        }
        return new WP_REST_Response(array(
            'status' => $found['status'],
            'trial_end' => $found['trial_end'],
        ), 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function portal(WP_REST_Request $request)
    {
        $install_id = self::install_id($request);
        if (is_wp_error($install_id)) {
            return $install_id;
        }
        $url = CWM_Swx_Mcp_Stripe::portal_url($install_id);
        if (is_wp_error($url)) {
            return $url;
        }
        return new WP_REST_Response(array('url' => $url), 200);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function events(WP_REST_Request $request)
    {
        $install_id = self::install_id($request);
        if (is_wp_error($install_id)) {
            return $install_id;
        }

        $found = CWM_Swx_Mcp_Stripe::find_subscription($install_id);
        if (is_wp_error($found) || ($found['status'] !== 'trialing' && $found['status'] !== 'active')) {
            return new WP_REST_Response(array('accepted' => 0), 200);
        }

        $events = $request->get_param('events');
        if (!is_array($events)) {
            $json = $request->get_json_params();
            if (is_array($json) && isset($json['events']) && is_array($json['events'])) {
                $events = $json['events'];
            }
        }
        if (!is_array($events)) {
            return new WP_Error('cwm_swx_mcp_events', 'Events must be a list.', array('status' => 400));
        }
        if (count($events) > 100) {
            $events = array_slice($events, 0, 100);
        }

        $accepted = CWM_Swx_Mcp_Usage::record_batch($install_id, $events);
        return new WP_REST_Response(array('accepted' => $accepted), 200);
    }

    /**
     * @return string|WP_Error
     */
    private static function install_id(WP_REST_Request $request)
    {
        $install_id = $request->get_param('install_id');
        if (!is_string($install_id)) {
            $json = $request->get_json_params();
            if (is_array($json) && isset($json['install_id']) && is_string($json['install_id'])) {
                $install_id = $json['install_id'];
            }
        }
        if (!is_string($install_id) || !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $install_id)) {
            return new WP_Error('cwm_swx_mcp_install', 'install_id must be a GUID.', array('status' => 400));
        }
        return strtolower($install_id);
    }
}
