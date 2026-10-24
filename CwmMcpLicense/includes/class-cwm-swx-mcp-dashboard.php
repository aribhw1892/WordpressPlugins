<?php
/**
 * Administrator dashboard for installs, tool-call totals, Dodo payments, and store downloads.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CWM_Swx_Mcp_Dashboard
{
    const PAGE = 'cwm-swx-mcp-dashboard';
    const PER_PAGE = 25;

    public static function register_menu(): void
    {
        add_menu_page(
            'SolidWorks MCP',
            'SolidWorks MCP',
            'manage_options',
            self::PAGE,
            array(self::class, 'redirect_admin_page'),
            'dashicons-chart-bar',
            58
        );
    }

    public static function register_route(): void
    {
        add_rewrite_rule('^cwm-mcp/?$', 'index.php?cwm_swx_mcp_dash=1', 'top');
        add_rewrite_tag('%cwm_swx_mcp_dash%', '([0-9])');
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public static function query_vars(array $vars): array
    {
        $vars[] = 'cwm_swx_mcp_dash';
        return $vars;
    }

    public static function maybe_flush(): void
    {
        if (get_option('cwm_swx_mcp_dash_route') === '2') {
            return;
        }
        flush_rewrite_rules(false);
        update_option('cwm_swx_mcp_dash_route', '2', true);
    }

    public static function maybe_serve(): void
    {
        $flag = (string) get_query_var('cwm_swx_mcp_dash');
        $query = isset($_GET['cwm_swx_mcp_dash']) && is_string($_GET['cwm_swx_mcp_dash']) && $_GET['cwm_swx_mcp_dash'] === '1';
        if ($flag !== '1' && !$query) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        self::serve();
        exit;
    }

    public static function redirect_admin(): void
    {
        if (!isset($_GET['page']) || !is_string($_GET['page']) || $_GET['page'] !== self::PAGE) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_safe_redirect(self::public_url());
        exit;
    }

    public static function redirect_admin_page(): void
    {
        wp_safe_redirect(self::public_url());
        exit;
    }

    /**
     * @param array<string, string> $args
     */
    public static function public_url(array $args = array()): string
    {
        if (get_option('permalink_structure') === '') {
            $args['cwm_swx_mcp_dash'] = '1';
            return add_query_arg($args, home_url('/'));
        }
        return add_query_arg($args, home_url('/cwm-mcp/'));
    }

    public static function serve(): void
    {
        $here = self::current_url();
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($here));
            exit;
        }
        if (!current_user_can('manage_options')) {
            status_header(403);
            nocache_headers();
            wp_die('This page is only available to an administrator.', 'SolidWorks MCP', array('response' => 403));
        }

        CWM_Swx_Mcp_Installs::maybe_install();
        $view = sanitize_key(self::query_text('view'));
        if ($view === '') {
            $view = 'overview';
        }

        status_header(200);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));

        echo '<!DOCTYPE html><html ';
        language_attributes();
        echo '><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<meta name="robots" content="noindex, nofollow">';
        echo '<title>SolidWorks MCP</title>';
        echo '<style>' . self::css() . '</style></head><body>';
        echo '<header class="cwm-top"><div><strong>SolidWorks MCP</strong><span>Analytics</span></div>';
        echo '<a href="' . esc_url(wp_logout_url($here)) . '">Sign out</a></header>';
        echo '<main class="cwm-app">';
        self::nav($view);

        switch ($view) {
            case 'installs':
                self::render_installs();
                break;
            case 'install':
                self::render_install();
                break;
            case 'usage':
                self::render_usage();
                break;
            case 'call':
                self::render_call();
                break;
            case 'orders':
                self::render_orders();
                break;
            case 'order':
                self::render_order();
                break;
            case 'downloads':
                self::render_downloads();
                break;
            case 'download':
                self::render_download();
                break;
            default:
                self::render_overview();
                break;
        }

        echo '</main></body></html>';
    }

    private static function nav(string $view): void
    {
        $items = array(
            'overview' => array('Overview', array('overview')),
            'installs' => array('Installs', array('installs', 'install')),
            'usage' => array('Tool calls', array('usage', 'call')),
            'orders' => array('Orders', array('orders', 'order')),
            'downloads' => array('Downloads', array('downloads', 'download')),
        );
        echo '<nav class="cwm-swx-nav" aria-label="Dashboard">';
        foreach ($items as $key => $item) {
            $class = in_array($view, $item[1], true) ? ' class="is-current"' : '';
            echo '<a' . $class . ' href="' . esc_url(self::url(array('view' => $key))) . '">' . esc_html($item[0]) . '</a>';
        }
        echo '</nav>';
    }

    private static function render_overview(): void
    {
        $since = gmdate('Y-m-d', time() - (30 * DAY_IN_SECONDS));
        $recent_from = gmdate('Y-m-d', time() - (6 * DAY_IN_SECONDS));
        $prior_from = gmdate('Y-m-d', time() - (13 * DAY_IN_SECONDS));
        $prior_to = gmdate('Y-m-d', time() - (7 * DAY_IN_SECONDS));
        $today = gmdate('Y-m-d');
        $counts = CWM_Swx_Mcp_Installs::counts();
        $mix = CWM_Swx_Mcp_Installs::mix();
        $usage = CWM_Swx_Mcp_Usage::summary($since);
        $recent = CWM_Swx_Mcp_Usage::calls_between($recent_from, $today, false);
        $prior = CWM_Swx_Mcp_Usage::calls_between($prior_from, $prior_to, false);
        $days = CWM_Swx_Mcp_Usage::by_day($since);
        $outcomes = CWM_Swx_Mcp_Usage::grouped_calls($since, 'outcome', 4, '');
        $tools = CWM_Swx_Mcp_Usage::grouped_calls($since, 'tool_name', 8, '');
        $errors = CWM_Swx_Mcp_Usage::grouped_calls($since, 'error_class', 6, 'errors');
        $clients = CWM_Swx_Mcp_Usage::grouped_calls($since, 'client_name', 6, 'named');
        $solidworks = CWM_Swx_Mcp_Usage::grouped_calls($since, 'solidworks_version', 6, 'named');
        $downloads = self::download_count_since($since . ' 00:00:00');

        echo '<p class="cwm-lead">Last 30 days, UTC. A tool-call count is a daily total, so one bar can hold many results. Rows older than 90 days are removed.</p>';
        echo '<div class="cwm-kpis">';
        self::kpi(self::url(array('view' => 'usage', 'from' => $since)), $usage['calls'], 'Tool calls', self::trend($recent, $prior));
        self::kpi(self::url(array('view' => 'usage', 'outcome' => 'error', 'from' => $since)), $usage['errors'], 'Error calls', $usage['tools'] . ' tools');
        self::kpi(self::url(array('view' => 'usage', 'from' => $since)), $usage['machines'], 'Machines reporting', 'Sent at least one event');
        self::kpi(self::url(array('view' => 'installs')), (string) $counts['installs'], 'Installs', $counts['trials'] . ' open trials');
        self::kpi(self::url(array('view' => 'installs')), (string) $counts['subscribed'], 'Subscriptions stored', 'Dodo id on the install');
        $download_hint = $downloads === null ? 'WooCommerce downloads are not on this site' : 'File downloads in 30 days';
        $download_value = $downloads === null ? '—' : (string) $downloads;
        self::kpi(self::url(array('view' => 'downloads')), $download_value, 'Downloads', $download_hint);
        echo '</div>';

        echo '<section class="cwm-panel cwm-panel-wide">';
        echo '<header><h2>Tool calls by day</h2><p>Blue is every result. Red is the part that ended in error.</p></header>';
        self::column_chart($days);
        echo '<p class="cwm-axis"><span>' . esc_html($since) . '</span><span>' . esc_html($today) . '</span></p>';
        echo '</section>';

        echo '<div class="cwm-grid">';
        echo '<section class="cwm-panel"><header><h2>Outcomes</h2></header>';
        self::donut($outcomes);
        echo '</section>';
        echo '<section class="cwm-panel"><header><h2>Top tools</h2><p><a href="' . esc_url(self::url(array('view' => 'usage', 'from' => $since))) . '">All tool calls</a></p></header>';
        self::rank_list($tools, 'No tool calls in this window.');
        echo '</section>';
        echo '<section class="cwm-panel"><header><h2>Errors</h2><p><a href="' . esc_url(self::url(array('view' => 'usage', 'outcome' => 'error', 'from' => $since))) . '">Error rows</a></p></header>';
        self::rank_list($errors, 'No errors in this window.', 'error');
        echo '</section>';
        echo '<section class="cwm-panel"><header><h2>Machines</h2><p><a href="' . esc_url(self::url(array('view' => 'installs'))) . '">Install list</a></p></header>';
        self::stack(array(
            array('label' => 'Subscription stored', 'value' => $mix['subscribed'], 'color' => '#1d4ed8'),
            array('label' => 'Trial open', 'value' => $mix['trials'], 'color' => '#0891b2'),
            array('label' => 'Trial ended', 'value' => $mix['ended'], 'color' => '#94a3b8'),
            array('label' => 'No trial', 'value' => $mix['none'], 'color' => '#cbd5e1'),
        ));
        echo '</section>';
        echo '<section class="cwm-panel"><header><h2>Clients</h2></header>';
        self::rank_list($clients, 'No client name stored yet.');
        echo '</section>';
        echo '<section class="cwm-panel"><header><h2>SolidWorks</h2></header>';
        self::rank_list($solidworks, 'No SolidWorks version stored yet.');
        echo '</section>';
        echo '</div>';

        echo '<p class="cwm-lead"><a href="' . esc_url(self::url(array('view' => 'orders'))) . '">Orders</a> reads Dodo payments for the plugin mode, then WooCommerce orders when that plugin is active.</p>';
    }

    private static function render_installs(): void
    {
        $page = self::request_page('paged');
        $found = CWM_Swx_Mcp_Installs::list_page($page, self::PER_PAGE);
        echo '<h2>Installs</h2>';
        echo '<p>One row per Windows machine. Times are UTC, as stored.</p>';
        self::pager($page, $found['total'], 'paged');
        echo '<div class="cwm-swx-scroll"><table class="widefat striped"><thead><tr>';
        echo '<th>Install</th><th>State</th><th>Trial end (UTC)</th><th>Customer</th><th>Subscription</th><th>Pending checkout</th><th>Updated (UTC)</th>';
        echo '</tr></thead><tbody>';
        if ($found['rows'] === array()) {
            echo '<tr><td colspan="7">No installs yet.</td></tr>';
        }
        foreach ($found['rows'] as $row) {
            echo '<tr>';
            echo '<td>' . self::install_link($row['install_id']) . '</td>';
            echo '<td>' . esc_html(self::install_state($row)) . '</td>';
            echo '<td>' . esc_html(self::blank($row['trial_end_gmt'])) . '</td>';
            echo '<td>' . esc_html(self::blank($row['customer_id'])) . '</td>';
            echo '<td>' . esc_html(self::blank($row['subscription_id'])) . '</td>';
            echo '<td>' . esc_html(self::blank($row['pending_session'])) . '</td>';
            echo '<td>' . esc_html(self::blank($row['updated_gmt'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        self::pager($page, $found['total'], 'paged');
    }

    private static function render_install(): void
    {
        $install_id = self::request_install_id();
        echo '<h2>Install</h2>';
        if ($install_id === '') {
            self::notice('That install id is not a machine id.');
            return;
        }

        $row = CWM_Swx_Mcp_Installs::find_row($install_id);
        if ($row === null) {
            self::notice('No install is stored for that machine id.');
            return;
        }

        echo '<dl class="cwm-swx-kv">';
        self::kv('Install', $row['install_id']);
        self::kv('State', self::install_state($row));
        self::kv('Trial end (UTC)', $row['trial_end_gmt']);
        self::kv('Customer', $row['customer_id']);
        self::kv('Subscription', $row['subscription_id']);
        self::kv('Pending checkout', $row['pending_session']);
        self::kv('Updated (UTC)', $row['updated_gmt']);
        echo '</dl>';
        echo '<p><a href="' . esc_url(self::url(array('view' => 'usage', 'install_id' => $install_id))) . '">Tool calls for this machine</a></p>';

        echo '<h2>Tools on this machine</h2>';
        $tools = CWM_Swx_Mcp_Usage::tools_for_install($install_id);
        echo '<div class="cwm-swx-scroll"><table class="widefat striped"><thead><tr><th>Tool</th><th>Outcome</th><th>Calls</th><th>Duration</th></tr></thead><tbody>';
        if ($tools === array()) {
            echo '<tr><td colspan="4">No tool calls stored for this machine.</td></tr>';
        }
        foreach ($tools as $tool) {
            echo '<tr>';
            echo '<td>' . esc_html(self::blank($tool['tool_name'])) . '</td>';
            echo '<td>' . esc_html(self::blank($tool['outcome'])) . '</td>';
            echo '<td>' . esc_html(self::blank($tool['calls'])) . '</td>';
            echo '<td>' . esc_html(self::duration_label($tool['duration_ms'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        if ($row['subscription_id'] !== '') {
            echo '<h2>Payments for this subscription</h2>';
            self::render_payment_list(0, $row['subscription_id'], false);
        }
    }

    private static function render_usage(): void
    {
        $filters = self::usage_filters();
        $page = self::request_page('paged');
        $found = CWM_Swx_Mcp_Usage::search($filters, $page, self::PER_PAGE);

        echo '<h2>Tool calls</h2>';
        echo '<p>Each row is one daily bucket. The call count is how many results were added into that bucket. Open a row for every stored column.</p>';
        echo '<form class="cwm-swx-filters" method="get" action="' . esc_url(self::public_url()) . '">';
        if (get_option('permalink_structure') === '') {
            echo '<input type="hidden" name="cwm_swx_mcp_dash" value="1" />';
        }
        echo '<input type="hidden" name="view" value="usage" />';
        echo '<label>From <input type="date" name="from" value="' . esc_attr($filters['from']) . '" /></label>';
        echo '<label>To <input type="date" name="to" value="' . esc_attr($filters['to']) . '" /></label>';
        echo '<label>Install <input type="text" name="install_id" value="' . esc_attr($filters['install_id']) . '" /></label>';
        echo '<label>Tool <input type="text" name="tool" value="' . esc_attr($filters['tool']) . '" /></label>';
        echo '<label>Outcome <select name="outcome">';
        echo '<option value="">Any</option>';
        foreach (array('ok', 'error', 'denied', 'blocked') as $outcome) {
            echo '<option value="' . esc_attr($outcome) . '"' . selected($filters['outcome'], $outcome, false) . '>' . esc_html($outcome) . '</option>';
        }
        echo '</select></label>';
        echo '<button type="submit" class="button button-primary">Filter</button>';
        echo '</form>';

        self::pager($page, $found['total'], 'paged');
        echo '<div class="cwm-swx-scroll"><table class="widefat striped"><thead><tr>';
        echo '<th>Date (UTC)</th><th>Install</th><th>Tool</th><th>Outcome</th><th>Calls</th><th>Duration</th><th>Client</th><th></th>';
        echo '</tr></thead><tbody>';
        if ($found['rows'] === array()) {
            echo '<tr><td colspan="8">No tool calls match.</td></tr>';
        }
        foreach ($found['rows'] as $row) {
            $detail = self::url(array('view' => 'call', 'id' => $row['id']));
            echo '<tr>';
            echo '<td>' . esc_html($row['usage_date']) . '</td>';
            echo '<td>' . self::install_link($row['install_id']) . '</td>';
            echo '<td>' . esc_html($row['tool_name']) . '</td>';
            echo '<td>' . esc_html($row['outcome']) . '</td>';
            echo '<td>' . esc_html($row['call_count']) . '</td>';
            echo '<td>' . esc_html(self::duration_label($row['duration_ms'])) . '</td>';
            echo '<td>' . esc_html(trim($row['client_name'] . ' ' . $row['client_version'])) . '</td>';
            echo '<td><a href="' . esc_url($detail) . '">Details</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        self::pager($page, $found['total'], 'paged');
    }

    private static function render_call(): void
    {
        $id = absint(self::query_text('id'));
        echo '<h2>Tool call</h2>';
        $row = CWM_Swx_Mcp_Usage::find_by_id($id);
        if ($row === null) {
            self::notice('That tool-call row was not found.');
            return;
        }

        $fields = array(
            'id' => 'Id',
            'usage_date' => 'Date (UTC)',
            'install_id' => 'Install',
            'tool_name' => 'Tool',
            'outcome' => 'Outcome',
            'call_count' => 'Calls',
            'duration_ms' => 'Duration (ms)',
            'error_class' => 'Error class',
            'api_member' => 'API member',
            'host_version' => 'Host version',
            'windows_version' => 'Windows',
            'solidworks_version' => 'SolidWorks',
            'client_name' => 'Client',
            'client_version' => 'Client version',
            'write_policy' => 'Write policy',
            'tool_profile' => 'Tool profile',
            'session_tool_count' => 'Session tool count',
            'session_error_count' => 'Session error count',
            'bucket_key' => 'Bucket',
        );
        echo '<dl class="cwm-swx-kv">';
        foreach ($fields as $key => $label) {
            if ($key === 'install_id') {
                self::kv_html($label, self::install_link($row[$key]));
                continue;
            }
            self::kv($label, $row[$key]);
        }
        echo '</dl>';
    }

    private static function render_orders(): void
    {
        $settings = CWM_Swx_Mcp_Settings::get();
        $page = self::request_page('paged');
        echo '<h2>Payments</h2>';
        echo '<p>These are Dodo Payments charges for the plugin mode <strong>' . esc_html($settings['mode']) . '</strong>. Test and live are separate lists. WordPress does not store a copy. The list refreshes about once a minute.</p>';
        self::render_payment_list($page - 1, '', true);

        echo '<h2>Store orders</h2>';
        if (!function_exists('wc_get_orders')) {
            echo '<p>WooCommerce is not active on this site, so there is no store order list.</p>';
            return;
        }

        $store_page = self::request_page('gpage');
        $result = wc_get_orders(array(
            'limit' => self::PER_PAGE,
            'page' => $store_page,
            'paginate' => true,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => 'any',
        ));
        $orders = is_object($result) && isset($result->orders) && is_array($result->orders) ? $result->orders : array();
        $total = is_object($result) && isset($result->total) ? (int) $result->total : count($orders);

        echo '<p>WooCommerce orders on this site. Open a row for the lines and any file permissions.</p>';
        self::pager($store_page, $total, 'gpage');
        echo '<div class="cwm-swx-scroll"><table class="widefat striped"><thead><tr>';
        echo '<th>Order</th><th>Status</th><th>Date</th><th>Total</th><th>Email</th><th></th>';
        echo '</tr></thead><tbody>';
        if ($orders === array()) {
            echo '<tr><td colspan="6">No store orders.</td></tr>';
        }
        foreach ($orders as $order) {
            if (!is_object($order) || !method_exists($order, 'get_id')) {
                continue;
            }
            $order_id = (int) $order->get_id();
            $created = $order->get_date_created();
            $when = $created ? $created->date_i18n('Y-m-d H:i') : '';
            echo '<tr>';
            echo '<td>' . esc_html((string) $order->get_order_number()) . '</td>';
            echo '<td>' . esc_html((string) $order->get_status()) . '</td>';
            echo '<td>' . esc_html($when) . '</td>';
            echo '<td>' . esc_html(self::order_total($order)) . '</td>';
            echo '<td>' . esc_html((string) $order->get_billing_email()) . '</td>';
            echo '<td><a href="' . esc_url(self::url(array('view' => 'order', 'order_id' => (string) $order_id))) . '">Details</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        self::pager($store_page, $total, 'gpage');
    }

    private static function render_order(): void
    {
        $payment_id = self::request_payment_id();
        if ($payment_id !== '') {
            self::render_payment($payment_id);
            return;
        }

        $order_id = absint(self::query_text('order_id'));
        echo '<h2>Store order</h2>';
        if ($order_id < 1 || !function_exists('wc_get_order')) {
            self::notice('That order was not found.');
            return;
        }
        $order = wc_get_order($order_id);
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            self::notice('That order was not found.');
            return;
        }

        $created = $order->get_date_created();
        echo '<dl class="cwm-swx-kv">';
        self::kv('Order', (string) $order->get_order_number());
        self::kv('Status', (string) $order->get_status());
        self::kv('Date', $created ? $created->date_i18n('Y-m-d H:i') : '');
        self::kv('Total', self::order_total($order));
        self::kv('Email', (string) $order->get_billing_email());
        self::kv('Payment method', (string) $order->get_payment_method_title());
        echo '</dl>';
        echo '<p><a href="' . esc_url(self::store_order_admin_url((int) $order->get_id())) . '">Open in WooCommerce</a></p>';

        echo '<h2>Lines</h2>';
        echo '<div class="cwm-swx-scroll"><table class="widefat striped"><thead><tr><th>Item</th><th>Qty</th><th>Total</th></tr></thead><tbody>';
        $items = $order->get_items();
        if (!is_array($items) || $items === array()) {
            echo '<tr><td colspan="3">No lines.</td></tr>';
        }
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_object($item) || !method_exists($item, 'get_name')) {
                    continue;
                }
                echo '<tr>';
                echo '<td>' . esc_html((string) $item->get_name()) . '</td>';
                echo '<td>' . esc_html((string) $item->get_quantity()) . '</td>';
                echo '<td>' . esc_html((string) $item->get_total()) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';

        $tables = self::download_tables();
        if ($tables['permissions'] === '') {
            return;
        }
        echo '<h2>File permissions</h2>';
        self::render_permission_rows($tables['permissions'], (int) $order->get_id());
    }

    private static function render_downloads(): void
    {
        $tables = self::download_tables();
        echo '<h2>File downloads</h2>';
        if ($tables['log'] === '' && $tables['permissions'] === '') {
            echo '<p>This site has no WooCommerce download tables. Installer files published on GitHub are not listed here.</p>';
            return;
        }

        if ($tables['log'] !== '') {
            $page = self::request_page('paged');
            $found = self::download_log_page($tables, $page);
            echo '<h3>Download events</h3>';
            self::pager($page, $found['total'], 'paged');
            echo '<div class="cwm-swx-scroll"><table class="widefat striped"><thead><tr>';
            echo '<th>When</th><th>Product</th><th>Order</th><th>User</th><th>IP</th><th></th>';
            echo '</tr></thead><tbody>';
            if ($found['rows'] === array()) {
                echo '<tr><td colspan="6">No download events.</td></tr>';
            }
            foreach ($found['rows'] as $row) {
                echo '<tr>';
                echo '<td>' . esc_html(self::blank($row['timestamp'])) . '</td>';
                echo '<td>' . esc_html(self::product_label($row['product_id'])) . '</td>';
                echo '<td>' . self::order_link($row['order_id']) . '</td>';
                echo '<td>' . esc_html(self::user_label($row['user_id'], $row['user_email'])) . '</td>';
                echo '<td>' . esc_html(self::blank($row['user_ip_address'])) . '</td>';
                echo '<td><a href="' . esc_url(self::url(array('view' => 'download', 'log_id' => $row['download_log_id']))) . '">Details</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            self::pager($page, $found['total'], 'paged');
        }

        if ($tables['permissions'] !== '') {
            $page = self::request_page('gpage');
            $found = self::permission_page($tables['permissions'], $page);
            echo '<h3>Who can download</h3>';
            self::pager($page, $found['total'], 'gpage');
            echo '<div class="cwm-swx-scroll"><table class="widefat striped"><thead><tr>';
            echo '<th>Product</th><th>Order</th><th>Email</th><th>Used</th><th>Remaining</th><th>Expires</th><th></th>';
            echo '</tr></thead><tbody>';
            if ($found['rows'] === array()) {
                echo '<tr><td colspan="7">No download permissions.</td></tr>';
            }
            foreach ($found['rows'] as $row) {
                echo '<tr>';
                echo '<td>' . esc_html(self::product_label($row['product_id'])) . '</td>';
                echo '<td>' . self::order_link($row['order_id']) . '</td>';
                echo '<td>' . esc_html(self::blank($row['user_email'])) . '</td>';
                echo '<td>' . esc_html(self::blank($row['download_count'])) . '</td>';
                echo '<td>' . esc_html(self::blank($row['downloads_remaining'])) . '</td>';
                echo '<td>' . esc_html(self::blank($row['access_expires'])) . '</td>';
                echo '<td><a href="' . esc_url(self::url(array('view' => 'download', 'permission_id' => $row['permission_id']))) . '">Details</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            self::pager($page, $found['total'], 'gpage');
        }
    }

    private static function render_download(): void
    {
        $tables = self::download_tables();
        $log_id = absint(self::query_text('log_id'));
        $permission_id = absint(self::query_text('permission_id'));
        echo '<h2>Download</h2>';

        if ($log_id > 0 && $tables['log'] !== '') {
            $row = self::download_log_row($tables, $log_id);
            if ($row === null) {
                self::notice('That download event was not found.');
                return;
            }
            self::render_download_fields($row);
            return;
        }

        if ($permission_id > 0 && $tables['permissions'] !== '') {
            $row = self::permission_row($tables['permissions'], $permission_id);
            if ($row === null) {
                self::notice('That download permission was not found.');
                return;
            }
            self::render_download_fields($row);
            return;
        }

        self::notice('That download was not found.');
    }

    /**
     * @param array<string, string> $row
     */
    private static function render_download_fields(array $row): void
    {
        echo '<dl class="cwm-swx-kv">';
        $labels = array(
            'download_log_id' => 'Event id',
            'timestamp' => 'When',
            'permission_id' => 'Permission id',
            'product_id' => 'Product id',
            'order_id' => 'Order id',
            'user_id' => 'User id',
            'user_email' => 'Email',
            'user_ip_address' => 'IP',
            'download_id' => 'File id',
            'download_count' => 'Times used',
            'downloads_remaining' => 'Remaining',
            'access_granted' => 'Access granted',
            'access_expires' => 'Access expires',
            'order_key' => 'Order key',
        );
        foreach ($labels as $key => $label) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            if ($key === 'product_id') {
                self::kv($label, $row[$key] . ($row[$key] !== '' ? ' (' . self::product_label($row[$key]) . ')' : ''));
                continue;
            }
            if ($key === 'order_id') {
                self::kv_html($label, self::order_link($row[$key]));
                continue;
            }
            self::kv($label, $row[$key]);
        }
        echo '</dl>';
    }

    private static function render_payment(string $payment_id): void
    {
        echo '<h2>Payment</h2>';
        $payment = CWM_Swx_Mcp_Dodo::get_payment($payment_id);
        if (is_wp_error($payment)) {
            self::notice($payment->get_error_message());
            return;
        }

        $customer = isset($payment['customer']) && is_array($payment['customer']) ? $payment['customer'] : array();
        $billing = isset($payment['billing']) && is_array($payment['billing']) ? $payment['billing'] : array();
        $metadata = isset($payment['metadata']) && is_array($payment['metadata']) ? $payment['metadata'] : array();
        $install_id = isset($metadata['install_id']) ? strtolower((string) $metadata['install_id']) : '';
        $invoice = isset($payment['invoice_url']) && is_string($payment['invoice_url']) ? $payment['invoice_url'] : '';

        echo '<dl class="cwm-swx-kv">';
        self::kv('Payment', self::scalar($payment, 'payment_id'));
        self::kv('Status', self::scalar($payment, 'status'));
        self::kv('Created', self::scalar($payment, 'created_at'));
        self::kv('Total', self::money(isset($payment['total_amount']) ? $payment['total_amount'] : '', self::scalar($payment, 'currency')));
        self::kv('Settlement', self::money(isset($payment['settlement_amount']) ? $payment['settlement_amount'] : '', self::scalar($payment, 'settlement_currency')));
        self::kv('Charge type', self::scalar($payment, 'charge_type'));
        self::kv('Customer', self::scalar($customer, 'name'));
        self::kv('Email', self::scalar($customer, 'email'));
        self::kv('Customer id', self::scalar($customer, 'customer_id'));
        self::kv('Subscriptions', self::subscription_ids($payment));
        self::kv_html('Install', self::install_link($install_id));
        self::kv('Method', trim(self::scalar($payment, 'payment_method_type') . ' ' . self::scalar($payment, 'payment_method')));
        self::kv('Card', trim(self::scalar($payment, 'card_network') . ' ' . self::scalar($payment, 'card_last_four')));
        self::kv('Invoice id', self::scalar($payment, 'invoice_id'));
        self::kv('Refund', self::scalar($payment, 'refund_status'));
        self::kv('Digital products delivered', self::yes_no(isset($payment['digital_products_delivered']) ? $payment['digital_products_delivered'] : null));
        self::kv('Billing country', self::scalar($billing, 'country'));
        self::kv('Billing city', self::scalar($billing, 'city'));
        echo '</dl>';
        if (strpos($invoice, 'https://') === 0) {
            echo '<p><a href="' . esc_url($invoice) . '" target="_blank" rel="noopener noreferrer">Invoice</a></p>';
        }

        if (isset($payment['product_cart']) && is_array($payment['product_cart']) && $payment['product_cart'] !== array()) {
            echo '<h2>Products</h2>';
            echo '<div class="cwm-swx-scroll"><table class="widefat striped"><thead><tr><th>Product</th><th>Qty</th></tr></thead><tbody>';
            foreach ($payment['product_cart'] as $line) {
                if (!is_array($line)) {
                    continue;
                }
                echo '<tr><td>' . esc_html(self::scalar($line, 'product_id')) . '</td><td>' . esc_html(self::scalar($line, 'quantity')) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    private static function render_payment_list(int $page_number, string $subscription_id, bool $pager): void
    {
        $settings = CWM_Swx_Mcp_Settings::get();
        if ($settings['api_key'] === '') {
            self::notice('Add the Dodo Payments API key under Settings → SolidWorks MCP to load payments.');
            return;
        }

        $found = CWM_Swx_Mcp_Dodo::list_payments($page_number, self::PER_PAGE, $subscription_id);
        if (is_wp_error($found)) {
            self::notice($found->get_error_message());
            return;
        }

        $items = array();
        foreach ($found['items'] as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        $has_more = count($items) === self::PER_PAGE;
        if ($pager) {
            self::pager_more($page_number + 1, $has_more);
        } elseif ($has_more) {
            echo '<p>Showing the first ' . esc_html((string) self::PER_PAGE) . ' payments.</p>';
        }

        echo '<div class="cwm-swx-scroll"><table class="widefat striped"><thead><tr>';
        echo '<th>Created</th><th>Payment</th><th>Status</th><th>Total</th><th>Email</th><th>Subscription</th><th>Install</th>';
        echo '</tr></thead><tbody>';
        if ($items === array()) {
            echo '<tr><td colspan="7">No payments on this page.</td></tr>';
        }
        foreach ($items as $item) {
            $customer = isset($item['customer']) && is_array($item['customer']) ? $item['customer'] : array();
            $metadata = isset($item['metadata']) && is_array($item['metadata']) ? $item['metadata'] : array();
            $install_id = isset($metadata['install_id']) ? strtolower((string) $metadata['install_id']) : '';
            $payment_id = self::scalar($item, 'payment_id');
            echo '<tr>';
            echo '<td>' . esc_html(self::scalar($item, 'created_at')) . '</td>';
            echo '<td><a href="' . esc_url(self::url(array('view' => 'order', 'payment_id' => $payment_id))) . '">' . esc_html($payment_id) . '</a></td>';
            echo '<td>' . esc_html(self::scalar($item, 'status')) . '</td>';
            echo '<td>' . esc_html(self::money(isset($item['total_amount']) ? $item['total_amount'] : '', self::scalar($item, 'currency'))) . '</td>';
            echo '<td>' . esc_html(self::scalar($customer, 'email')) . '</td>';
            echo '<td>' . esc_html(self::subscription_ids($item)) . '</td>';
            echo '<td>' . self::install_link($install_id) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if ($pager) {
            self::pager_more($page_number + 1, $has_more);
        }
    }

    /**
     * @param array{log: string, permissions: string} $tables
     * @return array{rows: array<int, array<string, string>>, total: int}
     */
    private static function download_log_page(array $tables, int $page): array
    {
        global $wpdb;
        $log = $tables['log'];
        $permissions = $tables['permissions'];
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$log}");
        $offset = max(0, $page - 1) * self::PER_PAGE;
        if ($permissions !== '') {
            $sql = "SELECT l.download_log_id, l.timestamp, l.permission_id, l.user_id, l.user_ip_address, p.user_email, p.product_id, p.order_id, p.download_count, p.downloads_remaining, p.access_expires, p.download_id, p.access_granted, p.order_key FROM {$log} l LEFT JOIN {$permissions} p ON p.permission_id = l.permission_id ORDER BY l.timestamp DESC LIMIT %d OFFSET %d";
        } else {
            $sql = "SELECT download_log_id, timestamp, permission_id, user_id, user_ip_address, '' AS user_email, '' AS product_id, '' AS order_id, '' AS download_count, '' AS downloads_remaining, '' AS access_expires, '' AS download_id, '' AS access_granted, '' AS order_key FROM {$log} ORDER BY timestamp DESC LIMIT %d OFFSET %d";
        }
        $rows = $wpdb->get_results($wpdb->prepare($sql, self::PER_PAGE, $offset), ARRAY_A);
        return array(
            'rows' => self::string_rows(is_array($rows) ? $rows : array()),
            'total' => $total,
        );
    }

    /**
     * @param array{log: string, permissions: string} $tables
     * @return array<string, string>|null
     */
    private static function download_log_row(array $tables, int $log_id): ?array
    {
        global $wpdb;
        $log = $tables['log'];
        $permissions = $tables['permissions'];
        if ($permissions !== '') {
            $sql = "SELECT l.download_log_id, l.timestamp, l.permission_id, l.user_id, l.user_ip_address, p.user_email, p.product_id, p.order_id, p.download_count, p.downloads_remaining, p.access_expires, p.download_id, p.access_granted, p.order_key FROM {$log} l LEFT JOIN {$permissions} p ON p.permission_id = l.permission_id WHERE l.download_log_id = %d";
        } else {
            $sql = "SELECT download_log_id, timestamp, permission_id, user_id, user_ip_address, '' AS user_email, '' AS product_id, '' AS order_id, '' AS download_count, '' AS downloads_remaining, '' AS access_expires, '' AS download_id, '' AS access_granted, '' AS order_key FROM {$log} WHERE download_log_id = %d";
        }
        $row = $wpdb->get_row($wpdb->prepare($sql, $log_id), ARRAY_A);
        return is_array($row) ? self::string_row($row) : null;
    }

    /**
     * @return array{rows: array<int, array<string, string>>, total: int}
     */
    private static function permission_page(string $table, int $page): array
    {
        global $wpdb;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $offset = max(0, $page - 1) * self::PER_PAGE;
        $sql = "SELECT permission_id, download_id, product_id, order_id, order_key, user_email, user_id, downloads_remaining, access_granted, access_expires, download_count FROM {$table} ORDER BY permission_id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, self::PER_PAGE, $offset), ARRAY_A);
        return array(
            'rows' => self::string_rows(is_array($rows) ? $rows : array()),
            'total' => $total,
        );
    }

    /**
     * @return array<string, string>|null
     */
    private static function permission_row(string $table, int $permission_id): ?array
    {
        global $wpdb;
        $sql = "SELECT permission_id, download_id, product_id, order_id, order_key, user_email, user_id, downloads_remaining, access_granted, access_expires, download_count FROM {$table} WHERE permission_id = %d";
        $row = $wpdb->get_row($wpdb->prepare($sql, $permission_id), ARRAY_A);
        return is_array($row) ? self::string_row($row) : null;
    }

    private static function render_permission_rows(string $table, int $order_id): void
    {
        global $wpdb;
        $sql = "SELECT permission_id, product_id, user_email, downloads_remaining, download_count, access_expires FROM {$table} WHERE order_id = %d ORDER BY permission_id DESC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $order_id), ARRAY_A);
        $rows = self::string_rows(is_array($rows) ? $rows : array());
        echo '<div class="cwm-swx-scroll"><table class="widefat striped"><thead><tr><th>Product</th><th>Email</th><th>Used</th><th>Remaining</th><th>Expires</th><th></th></tr></thead><tbody>';
        if ($rows === array()) {
            echo '<tr><td colspan="6">No file permissions on this order.</td></tr>';
        }
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html(self::product_label($row['product_id'])) . '</td>';
            echo '<td>' . esc_html(self::blank($row['user_email'])) . '</td>';
            echo '<td>' . esc_html(self::blank($row['download_count'])) . '</td>';
            echo '<td>' . esc_html(self::blank($row['downloads_remaining'])) . '</td>';
            echo '<td>' . esc_html(self::blank($row['access_expires'])) . '</td>';
            echo '<td><a href="' . esc_url(self::url(array('view' => 'download', 'permission_id' => $row['permission_id']))) . '">Details</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * @return array{log: string, permissions: string}
     */
    private static function download_tables(): array
    {
        global $wpdb;
        $log = $wpdb->prefix . 'wc_download_log';
        $permissions = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
        return array(
            'log' => self::table_exists($log) ? $log : '',
            'permissions' => self::table_exists($permissions) ? $permissions : '',
        );
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return is_string($found) && $found === $table;
    }

    private static function store_order_admin_url(int $order_id): string
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && method_exists('\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
        }
        return admin_url('post.php?post=' . $order_id . '&action=edit');
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function url(array $args): string
    {
        $clean = array();
        foreach ($args as $key => $value) {
            $clean[(string) $key] = (string) $value;
        }
        return self::public_url($clean);
    }

    private static function current_url(): string
    {
        $args = array();
        foreach (array('view', 'install_id', 'tool', 'outcome', 'from', 'to', 'paged', 'gpage', 'id', 'order_id', 'payment_id', 'log_id', 'permission_id') as $key) {
            $value = self::query_text($key);
            if ($value !== '') {
                $args[$key] = $value;
            }
        }
        return self::public_url($args);
    }

    private static function pager(int $page, int $total, string $page_key): void
    {
        $pages = $total > 0 ? (int) ceil($total / self::PER_PAGE) : 1;
        if ($pages <= 1) {
            return;
        }
        echo '<p class="cwm-swx-pager">';
        if ($page > 1) {
            echo '<a class="button" href="' . esc_url(self::paged_url($page - 1, $page_key)) . '">Previous</a> ';
        }
        echo esc_html('Page ' . $page . ' of ' . $pages);
        if ($page < $pages) {
            echo ' <a class="button" href="' . esc_url(self::paged_url($page + 1, $page_key)) . '">Next</a>';
        }
        echo '</p>';
    }

    private static function pager_more(int $page, bool $has_more): void
    {
        if ($page === 1 && !$has_more) {
            return;
        }
        echo '<p class="cwm-swx-pager">';
        if ($page > 1) {
            echo '<a class="button" href="' . esc_url(self::paged_url($page - 1, 'paged')) . '">Previous</a> ';
        }
        echo esc_html('Page ' . $page);
        if ($has_more) {
            echo ' <a class="button" href="' . esc_url(self::paged_url($page + 1, 'paged')) . '">Next</a>';
        }
        echo '</p>';
    }

    private static function paged_url(int $page, string $page_key): string
    {
        $args = array();
        foreach (array('view', 'install_id', 'tool', 'outcome', 'from', 'to', 'paged', 'gpage') as $key) {
            $value = self::query_text($key);
            if ($value === '') {
                continue;
            }
            $args[$key] = $value;
        }
        if (!isset($args['view']) || $args['view'] === '') {
            $args['view'] = 'orders';
        }
        $args[$page_key] = (string) max(1, $page);
        return self::url($args);
    }

    private static function card(string $href, string $value, string $label): void
    {
        echo '<a class="cwm-swx-card" href="' . esc_url($href) . '"><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span></a>';
    }

    private static function notice(string $message): void
    {
        echo '<div class="cwm-note"><p>' . esc_html($message) . '</p></div>';
    }

    private static function kv(string $label, string $value): void
    {
        echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html(self::blank($value)) . '</dd>';
    }

    private static function kv_html(string $label, string $html): void
    {
        echo '<dt>' . esc_html($label) . '</dt><dd>' . ($html === '' ? '—' : $html) . '</dd>';
    }

    /**
     * @param array<string, string> $row
     */
    private static function install_state(array $row): string
    {
        if ($row['subscription_id'] !== '') {
            return 'Subscription stored';
        }
        if ($row['trial_end_gmt'] === '') {
            return 'No trial';
        }
        $end = strtotime($row['trial_end_gmt'] . ' UTC');
        if ($end !== false && $end >= time()) {
            return 'Trial open';
        }
        return 'Trial ended';
    }

    private static function install_link(string $install_id): string
    {
        $install_id = strtolower(trim($install_id));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $install_id)) {
            return esc_html(self::blank($install_id));
        }
        return '<a href="' . esc_url(self::url(array('view' => 'install', 'install_id' => $install_id))) . '">' . esc_html($install_id) . '</a>';
    }

    private static function order_link(string $order_id): string
    {
        $id = (int) $order_id;
        if ($id < 1) {
            return '—';
        }
        return '<a href="' . esc_url(self::url(array('view' => 'order', 'order_id' => (string) $id))) . '">' . esc_html((string) $id) . '</a>';
    }

    /**
     * @return array{install_id: string, tool: string, outcome: string, from: string, to: string}
     */
    private static function usage_filters(): array
    {
        $install_id = self::request_install_id();
        $tool = self::query_text('tool');
        if (strlen($tool) > 64) {
            $tool = substr($tool, 0, 64);
        }
        $outcome = sanitize_key(self::query_text('outcome'));
        if (!in_array($outcome, array('ok', 'error', 'denied', 'blocked'), true)) {
            $outcome = '';
        }
        return array(
            'install_id' => $install_id,
            'tool' => $tool,
            'outcome' => $outcome,
            'from' => self::request_date('from'),
            'to' => self::request_date('to'),
        );
    }

    private static function query_text(string $key): string
    {
        if (!isset($_GET[$key]) || !is_string($_GET[$key])) {
            return '';
        }
        return sanitize_text_field(wp_unslash($_GET[$key]));
    }

    private static function request_install_id(): string
    {
        $raw = strtolower(self::query_text('install_id'));
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $raw) ? $raw : '';
    }

    private static function request_payment_id(): string
    {
        $raw = self::query_text('payment_id');
        return preg_match('/^pay_[A-Za-z0-9]+$/', $raw) ? $raw : '';
    }

    private static function request_date(string $key): string
    {
        $raw = self::query_text($key);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
    }

    private static function request_page(string $key): int
    {
        $raw = self::query_text($key);
        $page = absint($raw === '' ? '1' : $raw);
        return $page < 1 ? 1 : $page;
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function scalar(array $source, string $key): string
    {
        if (!isset($source[$key]) || is_array($source[$key]) || is_object($source[$key])) {
            return '';
        }
        return trim((string) $source[$key]);
    }

    /**
     * @param array<string, mixed> $payment
     */
    private static function subscription_ids(array $payment): string
    {
        if (isset($payment['subscription_ids']) && is_array($payment['subscription_ids'])) {
            $ids = array();
            foreach ($payment['subscription_ids'] as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[] = $id;
                }
            }
            if ($ids !== array()) {
                return implode(', ', $ids);
            }
        }
        return self::scalar($payment, 'subscription_id');
    }

    /**
     * @param mixed $amount
     */
    private static function money($amount, string $currency): string
    {
        if (!is_scalar($amount) || $amount === '' || !is_numeric($amount)) {
            return '';
        }
        $currency = strtoupper($currency);
        $minor = (int) $amount;
        $zero = array('BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF');
        $three = array('BHD', 'IQD', 'JOD', 'KWD', 'OMR', 'TND');
        if (in_array($currency, $zero, true)) {
            $major = (float) $minor;
            $decimals = 0;
        } elseif (in_array($currency, $three, true)) {
            $major = $minor / 1000;
            $decimals = 3;
        } else {
            $major = $minor / 100;
            $decimals = 2;
        }
        $formatted = number_format($major, $decimals);
        return $currency === '' ? $formatted : $formatted . ' ' . $currency;
    }

    /**
     * @param mixed $value
     */
    private static function yes_no($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return $value ? 'Yes' : 'No';
    }

    private static function duration_label(string $ms): string
    {
        if (!is_numeric($ms)) {
            return '';
        }
        $value = (int) $ms;
        if ($value < 1000) {
            return $value . ' ms';
        }
        return number_format($value / 1000, 1) . ' s';
    }

    /**
     * @param object $order
     */
    private static function order_total($order): string
    {
        if (is_object($order) && method_exists($order, 'get_formatted_order_total')) {
            return html_entity_decode(wp_strip_all_tags((string) $order->get_formatted_order_total()), ENT_QUOTES, 'UTF-8');
        }
        if (is_object($order) && method_exists($order, 'get_total')) {
            return (string) $order->get_total();
        }
        return '';
    }

    private static function product_label(string $product_id): string
    {
        $id = (int) $product_id;
        if ($id < 1) {
            return self::blank($product_id);
        }
        $title = get_the_title($id);
        if (!is_string($title) || $title === '') {
            return (string) $id;
        }
        return $title;
    }

    private static function user_label(string $user_id, string $email): string
    {
        $id = (int) $user_id;
        if ($id > 0) {
            $user = get_userdata($id);
            if ($user instanceof WP_User) {
                $name = $user->display_name !== '' ? $user->display_name : $user->user_login;
                if ($user->user_email !== '') {
                    return $name . ' (' . $user->user_email . ')';
                }
                return $name;
            }
        }
        return self::blank($email);
    }

    private static function blank(string $value): string
    {
        return $value === '' ? '—' : $value;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private static function string_row(array $row): array
    {
        $out = array();
        foreach ($row as $key => $value) {
            $out[(string) $key] = $value === null ? '' : (string) $value;
        }
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private static function string_rows(array $rows): array
    {
        $out = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::string_row($row);
            }
        }
        return $out;
    }

    private static function kpi(string $href, string $value, string $label, string $hint): void
    {
        $shown = is_numeric($value) ? number_format((float) $value) : $value;
        echo '<a class="cwm-kpi" href="' . esc_url($href) . '">';
        echo '<span>' . esc_html($label) . '</span>';
        echo '<strong>' . esc_html($shown) . '</strong>';
        echo '<em>' . esc_html($hint) . '</em>';
        echo '</a>';
    }

    private static function trend(int $recent, int $prior): string
    {
        if ($recent === 0 && $prior === 0) {
            return 'No calls in the last 14 days';
        }
        if ($prior === 0) {
            return 'New in the last 7 days';
        }
        $pct = (int) round((($recent - $prior) / $prior) * 100);
        if ($pct > 0) {
            return '+' . $pct . '% vs the previous 7 days';
        }
        if ($pct < 0) {
            return (string) $pct . '% vs the previous 7 days';
        }
        return 'Same as the previous 7 days';
    }

    /**
     * @param array<int, array{date: string, calls: int, errors: int}> $days
     */
    private static function column_chart(array $days): void
    {
        $count = count($days);
        $max = 1;
        $total = 0;
        foreach ($days as $day) {
            $total += $day['calls'];
            if ($day['calls'] > $max) {
                $max = $day['calls'];
            }
        }
        if ($count < 1 || $total < 1) {
            echo '<p class="cwm-empty">No tool calls in this window.</p>';
            return;
        }

        echo '<svg class="cwm-columns" viewBox="0 0 100 40" role="img" aria-label="Tool calls by day">';
        $gap = 0.35;
        $bar = ($count > 1) ? ((100 - (($count - 1) * $gap)) / $count) : 100;
        $index = 0;
        foreach ($days as $day) {
            $height = $day['calls'] > 0 ? max(0.8, ($day['calls'] / $max) * 36) : 0.4;
            $error_height = $day['errors'] > 0 ? min($height, ($day['errors'] / $max) * 36) : 0;
            $x = $index * ($bar + $gap);
            $y = 38 - $height;
            $title = $day['date'] . ': ' . $day['calls'] . ' calls, ' . $day['errors'] . ' errors';
            echo '<rect class="is-call" x="' . esc_attr(self::svg_num($x)) . '" y="' . esc_attr(self::svg_num($y)) . '" width="' . esc_attr(self::svg_num($bar)) . '" height="' . esc_attr(self::svg_num($height)) . '" rx="0.35"><title>' . esc_html($title) . '</title></rect>';
            if ($error_height > 0) {
                $ey = 38 - $error_height;
                echo '<rect class="is-error" x="' . esc_attr(self::svg_num($x)) . '" y="' . esc_attr(self::svg_num($ey)) . '" width="' . esc_attr(self::svg_num($bar)) . '" height="' . esc_attr(self::svg_num($error_height)) . '" rx="0.35"></rect>';
            }
            $index++;
        }
        echo '</svg>';
    }

    /**
     * @param array<int, array{label: string, calls: int}> $rows
     */
    private static function donut(array $rows): void
    {
        $slices = array();
        $total = 0;
        foreach ($rows as $row) {
            if ($row['calls'] < 1) {
                continue;
            }
            $total += $row['calls'];
            $slices[] = $row;
        }
        if ($total < 1) {
            echo '<p class="cwm-empty">No outcomes yet.</p>';
            return;
        }

        $radius = 15.5;
        $circumference = 2 * M_PI * $radius;
        $offset = 0.0;
        echo '<div class="cwm-donut-wrap"><svg class="cwm-donut" viewBox="0 0 42 42" role="img" aria-label="Outcomes">';
        echo '<circle class="cwm-donut-track" cx="21" cy="21" r="15.5"></circle>';
        foreach ($slices as $slice) {
            $length = ($slice['calls'] / $total) * $circumference;
            $dash = self::svg_num($length) . ' ' . self::svg_num($circumference - $length);
            echo '<circle cx="21" cy="21" r="15.5" fill="none" stroke="' . esc_attr(self::outcome_color($slice['label'])) . '" stroke-width="6" stroke-dasharray="' . esc_attr($dash) . '" stroke-dashoffset="' . esc_attr(self::svg_num(-$offset)) . '" transform="rotate(-90 21 21)"><title>' . esc_html(self::chart_label($slice['label']) . ': ' . $slice['calls']) . '</title></circle>';
            $offset += $length;
        }
        echo '</svg><ul class="cwm-legend">';
        foreach ($slices as $slice) {
            $pct = (int) round(($slice['calls'] / $total) * 100);
            echo '<li><i style="background:' . esc_attr(self::outcome_color($slice['label'])) . '"></i>' . esc_html(self::chart_label($slice['label'])) . '<strong>' . esc_html((string) $slice['calls']) . ' · ' . esc_html((string) $pct) . '%</strong></li>';
        }
        echo '</ul></div>';
    }

    /**
     * @param array<int, array{label: string, calls: int}> $rows
     */
    private static function rank_list(array $rows, string $empty, string $tone = 'call'): void
    {
        $max = 1;
        foreach ($rows as $row) {
            if ($row['calls'] > $max) {
                $max = $row['calls'];
            }
        }
        if ($rows === array() || $max < 1 || ($rows[0]['calls'] < 1)) {
            echo '<p class="cwm-empty">' . esc_html($empty) . '</p>';
            return;
        }
        $class = $tone === 'error' ? 'cwm-ranks is-error' : 'cwm-ranks';
        echo '<ol class="' . esc_attr($class) . '">';
        foreach ($rows as $row) {
            if ($row['calls'] < 1) {
                continue;
            }
            $pct = max(2, (int) round(($row['calls'] / $max) * 100));
            echo '<li><span>' . esc_html(self::chart_label($row['label'])) . '</span>';
            echo '<span class="cwm-rank-track"><span style="width:' . esc_attr((string) $pct) . '%"></span></span>';
            echo '<strong>' . esc_html(number_format($row['calls'])) . '</strong></li>';
        }
        echo '</ol>';
    }

    /**
     * @param array<int, array{label: string, value: int, color: string}> $parts
     */
    private static function stack(array $parts): void
    {
        $total = 0;
        foreach ($parts as $part) {
            $total += $part['value'];
        }
        if ($total < 1) {
            echo '<p class="cwm-empty">No installs yet.</p>';
            return;
        }
        echo '<div class="cwm-stack" role="img" aria-label="Install mix">';
        foreach ($parts as $part) {
            if ($part['value'] < 1) {
                continue;
            }
            echo '<span style="flex:' . esc_attr((string) $part['value']) . ' 1 0;background:' . esc_attr($part['color']) . '" title="' . esc_attr($part['label'] . ': ' . $part['value']) . '"></span>';
        }
        echo '</div><ul class="cwm-legend">';
        foreach ($parts as $part) {
            echo '<li><i style="background:' . esc_attr($part['color']) . '"></i>' . esc_html($part['label']) . '<strong>' . esc_html((string) $part['value']) . '</strong></li>';
        }
        echo '</ul>';
    }

    private static function download_count_since(string $since): ?int
    {
        $tables = self::download_tables();
        if ($tables['log'] === '') {
            return null;
        }
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['log']} WHERE timestamp >= %s", $since));
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function outcome_color(string $label): string
    {
        switch ($label) {
            case 'ok':
                return '#059669';
            case 'error':
                return '#e11d48';
            case 'denied':
                return '#d97706';
            case 'blocked':
                return '#7c3aed';
            default:
                return '#64748b';
        }
    }

    private static function chart_label(string $label): string
    {
        return $label === '' ? 'Not set' : $label;
    }

    private static function svg_num(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private static function css(): string
    {
        return '
body{margin:0;background:#f1f5f9;color:#0f172a;font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif}
a{color:#1d4ed8}
.cwm-top{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;background:#0f172a;color:#fff;position:sticky;top:0;z-index:2}
.cwm-top strong{font-size:16px}
.cwm-top span{display:block;color:#94a3b8;font-size:12px}
.cwm-top a{color:#fff;text-decoration:none;border:1px solid #334155;border-radius:999px;padding:6px 12px}
.cwm-app{max-width:1100px;margin:0 auto;padding:16px}
.cwm-swx-nav{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}
.cwm-swx-nav a{display:inline-block;padding:8px 12px;background:#fff;border-radius:999px;text-decoration:none;color:#334155;box-shadow:0 1px 2px rgba(15,23,42,.06)}
.cwm-swx-nav a.is-current{background:#1d4ed8;color:#fff}
.cwm-lead{color:#475569;margin:0 0 14px}
.cwm-kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
.cwm-kpi{display:flex;flex-direction:column;gap:4px;background:#fff;border-radius:14px;padding:14px 16px;text-decoration:none;color:inherit;box-shadow:0 1px 2px rgba(15,23,42,.06)}
.cwm-kpi span{color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.cwm-kpi strong{font-size:28px;line-height:1.1}
.cwm-kpi em{font-style:normal;color:#475569;font-size:13px}
.cwm-panel{background:#fff;border-radius:14px;padding:14px 16px;box-shadow:0 1px 2px rgba(15,23,42,.06);margin-bottom:12px}
.cwm-panel header{display:flex;justify-content:space-between;gap:8px;align-items:baseline;margin-bottom:10px}
.cwm-panel h2,.cwm-app h2{font-size:16px;margin:0 0 8px}
.cwm-panel header h2{margin:0}
.cwm-panel header p{margin:0;color:#64748b;font-size:13px}
.cwm-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.cwm-columns{width:100%;height:180px;display:block}
.cwm-columns .is-call{fill:#1d4ed8}
.cwm-columns .is-error{fill:#e11d48}
.cwm-axis{display:flex;justify-content:space-between;color:#64748b;font-size:12px;margin:6px 0 0}
.cwm-donut-wrap{display:flex;gap:16px;align-items:center}
.cwm-donut{width:140px;height:140px;flex:0 0 auto}
.cwm-donut-track{fill:none;stroke:#e2e8f0;stroke-width:6}
.cwm-legend{list-style:none;margin:8px 0 0;padding:0}
.cwm-legend li{display:flex;align-items:center;gap:8px;margin:4px 0;font-size:13px}
.cwm-legend i{width:10px;height:10px;border-radius:99px;display:inline-block}
.cwm-legend strong{margin-left:auto}
.cwm-ranks{list-style:none;margin:0;padding:0}
.cwm-ranks li{display:grid;grid-template-columns:minmax(80px,1fr) 2fr auto;gap:8px;align-items:center;margin:8px 0;font-size:13px}
.cwm-rank-track{background:#e2e8f0;border-radius:99px;height:8px;overflow:hidden}
.cwm-rank-track span{display:block;height:100%;background:#1d4ed8;border-radius:99px}
.cwm-ranks.is-error .cwm-rank-track span{background:#e11d48}
.cwm-stack{display:flex;height:14px;border-radius:99px;overflow:hidden;background:#e2e8f0}
.cwm-empty{color:#64748b;margin:8px 0}
.cwm-note{background:#eff6ff;border-radius:10px;padding:10px 12px}
.cwm-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:8px 0 16px}
.cwm-app table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px}
.cwm-app th,.cwm-app td{text-align:left;padding:8px 10px;border-bottom:1px solid #e2e8f0;font-size:13px;vertical-align:top;word-break:break-word}
.cwm-app th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
.cwm-swx-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin:8px 0 12px}
.cwm-swx-filters label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#475569}
.cwm-swx-filters input,.cwm-swx-filters select{padding:8px;border:1px solid #cbd5e1;border-radius:8px;font:inherit}
.cwm-swx-kv{display:grid;grid-template-columns:minmax(120px,180px) 1fr;gap:6px 12px;background:#fff;border-radius:14px;padding:12px 14px;margin:8px 0 16px}
.cwm-swx-kv dt{font-weight:600}
.cwm-swx-kv dd{margin:0;word-break:break-word}
.cwm-swx-pager{margin:8px 0}
.button{display:inline-block;padding:8px 12px;background:#fff;border:1px solid #cbd5e1;border-radius:8px;text-decoration:none;color:#0f172a}
.button-primary{background:#1d4ed8;border-color:#1d4ed8;color:#fff}
@media (max-width:800px){
.cwm-kpis{grid-template-columns:1fr 1fr}
.cwm-grid{grid-template-columns:1fr}
.cwm-donut-wrap{flex-direction:column;align-items:flex-start}
.cwm-ranks li{grid-template-columns:1fr;gap:4px}
.cwm-swx-kv{grid-template-columns:1fr}
}
';
    }
}
