<?php
/**
 * Daily usage totals in {prefix}cwm_swx_mcp_usage.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CWM_Swx_Mcp_Usage
{
    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cwm_swx_mcp_usage';
    }

    public static function activate(): void
    {
        self::create_table();
        if (!wp_next_scheduled('cwm_swx_mcp_usage_prune')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'cwm_swx_mcp_usage_prune');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('cwm_swx_mcp_usage_prune');
    }

    public static function create_table(): void
    {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            bucket_key char(64) NOT NULL,
            usage_date date NOT NULL,
            install_id char(36) NOT NULL,
            host_version varchar(32) NOT NULL DEFAULT '',
            windows_version varchar(64) NOT NULL DEFAULT '',
            solidworks_version varchar(64) NOT NULL DEFAULT '',
            client_name varchar(64) NOT NULL DEFAULT '',
            client_version varchar(32) NOT NULL DEFAULT '',
            write_policy varchar(16) NOT NULL DEFAULT '',
            tool_profile varchar(16) NOT NULL DEFAULT '',
            tool_name varchar(64) NOT NULL DEFAULT '',
            outcome varchar(16) NOT NULL DEFAULT '',
            error_class varchar(128) NOT NULL DEFAULT '',
            api_member varchar(128) NOT NULL DEFAULT '',
            call_count bigint(20) unsigned NOT NULL DEFAULT 0,
            duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
            session_tool_count bigint(20) unsigned NOT NULL DEFAULT 0,
            session_error_count bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY usage_bucket (bucket_key),
            KEY usage_date (usage_date)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function prune(): void
    {
        global $wpdb;
        $table = self::table_name();
        $cutoff = gmdate('Y-m-d', time() - (90 * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE usage_date < %s", $cutoff));
    }

    /**
     * @param array<int, mixed> $events
     */
    public static function record_batch(string $install_id, array $events): int
    {
        $accepted = 0;
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if (self::record_one($install_id, $event)) {
                $accepted++;
            }
        }
        self::prune();
        return $accepted;
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function record_one(string $install_id, array $event): bool
    {
        $kind = isset($event['kind']) ? (string) $event['kind'] : '';
        $tool = '';
        $outcome = 'ok';
        $error = self::token(isset($event['error_class']) ? (string) $event['error_class'] : '', 128);
        $api = self::member(isset($event['api_member']) ? (string) $event['api_member'] : '');
        $calls = 1;
        $duration = self::duration(isset($event['duration_ms']) ? $event['duration_ms'] : 0);
        $session_tools = 0;
        $session_errors = 0;

        if ($kind === 'session_start') {
            $tool = 'session_start';
        } elseif ($kind === 'session_end') {
            $tool = 'session_end';
            $session_tools = self::count_value(isset($event['tool_count']) ? $event['tool_count'] : 0);
            $session_errors = self::count_value(isset($event['error_count']) ? $event['error_count'] : 0);
        } elseif ($kind === 'tool_result') {
            $tool = self::token(isset($event['tool_name']) ? (string) $event['tool_name'] : '', 64);
            $outcome = isset($event['outcome']) ? (string) $event['outcome'] : '';
            if (!in_array($outcome, array('ok', 'error', 'denied', 'blocked'), true)) {
                return false;
            }
        } else {
            return false;
        }

        if ($tool === '') {
            return false;
        }

        $host = self::text(isset($event['host_version']) ? (string) $event['host_version'] : '', 32);
        $windows = self::text(isset($event['windows_version']) ? (string) $event['windows_version'] : '', 64);
        $solidworks = self::text(isset($event['solidworks_version']) ? (string) $event['solidworks_version'] : '', 64);
        $client = self::text(isset($event['client_name']) ? (string) $event['client_name'] : '', 64);
        $client_version = self::text(isset($event['client_version']) ? (string) $event['client_version'] : '', 32);
        $write_policy = self::token(isset($event['write_policy']) ? (string) $event['write_policy'] : '', 16);
        $tool_profile = self::token(isset($event['tool_profile']) ? (string) $event['tool_profile'] : '', 16);
        $date = gmdate('Y-m-d');
        $bucket = hash('sha256', implode("\n", array(
            $date,
            $install_id,
            $host,
            $solidworks,
            $client,
            $tool,
            $outcome,
            $error,
            $api,
        )));

        global $wpdb;
        $table = self::table_name();
        $sql = "INSERT INTO {$table}
            (bucket_key, usage_date, install_id, host_version, windows_version, solidworks_version, client_name, client_version, write_policy, tool_profile, tool_name, outcome, error_class, api_member, call_count, duration_ms, session_tool_count, session_error_count)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d, %d)
            ON DUPLICATE KEY UPDATE
                call_count = call_count + VALUES(call_count),
                duration_ms = duration_ms + VALUES(duration_ms),
                session_tool_count = session_tool_count + VALUES(session_tool_count),
                session_error_count = session_error_count + VALUES(session_error_count),
                windows_version = IF(VALUES(windows_version) = '', windows_version, VALUES(windows_version)),
                client_version = IF(VALUES(client_version) = '', client_version, VALUES(client_version)),
                write_policy = IF(VALUES(write_policy) = '', write_policy, VALUES(write_policy)),
                tool_profile = IF(VALUES(tool_profile) = '', tool_profile, VALUES(tool_profile))";

        $result = $wpdb->query($wpdb->prepare(
            $sql,
            $bucket,
            $date,
            $install_id,
            $host,
            $windows,
            $solidworks,
            $client,
            $client_version,
            $write_policy,
            $tool_profile,
            $tool,
            $outcome,
            $error,
            $api,
            $calls,
            $duration,
            $session_tools,
            $session_errors
        ));

        return $result !== false;
    }

    /**
     * @return array{calls: string, errors: string, tools: string, machines: string}
     */
    public static function summary(string $since): array
    {
        global $wpdb;
        CWM_Swx_Mcp_Installs::maybe_install();
        $table = self::table_name();
        $calls = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(call_count), 0) FROM {$table} WHERE usage_date >= %s AND tool_name NOT IN ('session_start', 'session_end')",
            $since
        ));
        $errors = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(call_count), 0) FROM {$table} WHERE usage_date >= %s AND outcome = 'error'",
            $since
        ));
        $tools = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT tool_name) FROM {$table} WHERE usage_date >= %s AND tool_name NOT IN ('session_start', 'session_end')",
            $since
        ));
        $machines = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT install_id) FROM {$table} WHERE usage_date >= %s",
            $since
        ));
        return array(
            'calls' => is_numeric($calls) ? (string) $calls : '0',
            'errors' => is_numeric($errors) ? (string) $errors : '0',
            'tools' => is_numeric($tools) ? (string) $tools : '0',
            'machines' => is_numeric($machines) ? (string) $machines : '0',
        );
    }

    public static function calls_between(string $from, string $to, bool $errors_only): int
    {
        global $wpdb;
        CWM_Swx_Mcp_Installs::maybe_install();
        $table = self::table_name();
        $outcome = $errors_only ? " AND outcome = 'error'" : '';
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(call_count), 0) FROM {$table} WHERE usage_date >= %s AND usage_date <= %s AND tool_name NOT IN ('session_start', 'session_end'){$outcome}",
            $from,
            $to
        ));
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * One point per UTC day from $since through today, including days with no rows.
     *
     * @return array<int, array{date: string, calls: int, errors: int}>
     */
    public static function by_day(string $since): array
    {
        global $wpdb;
        CWM_Swx_Mcp_Installs::maybe_install();
        $table = self::table_name();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT usage_date,
                COALESCE(SUM(CASE WHEN tool_name NOT IN ('session_start', 'session_end') THEN call_count ELSE 0 END), 0) AS calls,
                COALESCE(SUM(CASE WHEN outcome = 'error' AND tool_name NOT IN ('session_start', 'session_end') THEN call_count ELSE 0 END), 0) AS errors
             FROM {$table} WHERE usage_date >= %s GROUP BY usage_date",
            $since
        ), ARRAY_A);
        $by_date = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['usage_date'])) {
                    continue;
                }
                $by_date[(string) $row['usage_date']] = array(
                    'calls' => (int) $row['calls'],
                    'errors' => (int) $row['errors'],
                );
            }
        }

        $out = array();
        $start = strtotime($since . ' UTC');
        $end = strtotime(gmdate('Y-m-d') . ' UTC');
        if ($start === false || $end === false) {
            return $out;
        }
        for ($day = $start; $day <= $end; $day += DAY_IN_SECONDS) {
            $key = gmdate('Y-m-d', $day);
            $out[] = array(
                'date' => $key,
                'calls' => isset($by_date[$key]) ? $by_date[$key]['calls'] : 0,
                'errors' => isset($by_date[$key]) ? $by_date[$key]['errors'] : 0,
            );
        }
        return $out;
    }

    /**
     * @return array<int, array{label: string, calls: int}>
     */
    public static function grouped_calls(string $since, string $column, int $limit, string $mode): array
    {
        global $wpdb;
        $allowed = array('tool_name', 'error_class', 'client_name', 'solidworks_version', 'outcome', 'api_member');
        if (!in_array($column, $allowed, true)) {
            return array();
        }
        CWM_Swx_Mcp_Installs::maybe_install();
        $table = self::table_name();
        $where = "usage_date >= %s AND tool_name NOT IN ('session_start', 'session_end')";
        if ($mode === 'errors') {
            $where .= " AND outcome = 'error' AND {$column} <> ''";
        } elseif ($mode === 'named') {
            $where .= " AND {$column} <> ''";
        }
        $limit = max(1, min(12, $limit));
        $sql = "SELECT {$column} AS label, COALESCE(SUM(call_count), 0) AS calls FROM {$table} WHERE {$where} GROUP BY {$column} ORDER BY calls DESC LIMIT %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $since, $limit), ARRAY_A);
        $out = array();
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = array(
                'label' => isset($row['label']) ? (string) $row['label'] : '',
                'calls' => isset($row['calls']) ? (int) $row['calls'] : 0,
            );
        }
        return $out;
    }

    /**
     * @param array{install_id?: string, tool?: string, outcome?: string, from?: string, to?: string} $filters
     * @return array{rows: array<int, array<string, string>>, total: int}
     */
    public static function search(array $filters, int $page, int $per_page): array
    {
        global $wpdb;
        CWM_Swx_Mcp_Installs::maybe_install();
        $table = self::table_name();
        $where = array('1=1');
        $args = array();

        $install_id = isset($filters['install_id']) ? (string) $filters['install_id'] : '';
        if ($install_id !== '') {
            $where[] = 'install_id = %s';
            $args[] = $install_id;
        }
        $tool = isset($filters['tool']) ? (string) $filters['tool'] : '';
        if ($tool !== '') {
            $where[] = 'tool_name LIKE %s';
            $args[] = '%' . $wpdb->esc_like($tool) . '%';
        }
        $outcome = isset($filters['outcome']) ? (string) $filters['outcome'] : '';
        if ($outcome !== '') {
            $where[] = 'outcome = %s';
            $args[] = $outcome;
        }
        $from = isset($filters['from']) ? (string) $filters['from'] : '';
        if ($from !== '') {
            $where[] = 'usage_date >= %s';
            $args[] = $from;
        }
        $to = isset($filters['to']) ? (string) $filters['to'] : '';
        if ($to !== '') {
            $where[] = 'usage_date <= %s';
            $args[] = $to;
        }

        $sql_where = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$sql_where}";
        if ($args === array()) {
            $total = (int) $wpdb->get_var($count_sql);
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $args));
        }

        $per_page = max(1, min(100, $per_page));
        $offset = max(0, $page - 1) * $per_page;
        $list_args = $args;
        $list_args[] = $per_page;
        $list_args[] = $offset;
        $list_sql = "SELECT id, usage_date, install_id, host_version, windows_version, solidworks_version, client_name, client_version, write_policy, tool_profile, tool_name, outcome, error_class, api_member, call_count, duration_ms, session_tool_count, session_error_count FROM {$table} WHERE {$sql_where} ORDER BY usage_date DESC, id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_args), ARRAY_A);
        return array(
            'rows' => self::rows_strings(is_array($rows) ? $rows : array()),
            'total' => $total,
        );
    }

    /**
     * @return array<string, string>|null
     */
    public static function find_by_id(int $id): ?array
    {
        global $wpdb;
        if ($id < 1) {
            return null;
        }
        CWM_Swx_Mcp_Installs::maybe_install();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, bucket_key, usage_date, install_id, host_version, windows_version, solidworks_version, client_name, client_version, write_policy, tool_profile, tool_name, outcome, error_class, api_member, call_count, duration_ms, session_tool_count, session_error_count FROM ' . self::table_name() . ' WHERE id = %d',
                $id
            ),
            ARRAY_A
        );
        return is_array($row) ? self::row_strings($row) : null;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function tools_for_install(string $install_id): array
    {
        global $wpdb;
        CWM_Swx_Mcp_Installs::maybe_install();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT tool_name, outcome, SUM(call_count) AS calls, SUM(duration_ms) AS duration_ms FROM ' . self::table_name() . ' WHERE install_id = %s GROUP BY tool_name, outcome ORDER BY calls DESC',
                $install_id
            ),
            ARRAY_A
        );
        return self::rows_strings(is_array($rows) ? $rows : array());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private static function row_strings(array $row): array
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
    private static function rows_strings(array $rows): array
    {
        $out = array();
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::row_strings($row);
            }
        }
        return $out;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $since = gmdate('Y-m-d', time() - (30 * DAY_IN_SECONDS));
        $dashboard = CWM_Swx_Mcp_Dashboard::public_url();
        echo '<div class="wrap">';
        echo '<h1>SolidWorks MCP usage</h1>';
        echo '<p>Totals since ' . esc_html($since) . ' (UTC). Payments stay in Dodo Payments. <a href="' . esc_url($dashboard) . '">Open the dashboard</a> for each install, each tool-call row, orders, and downloads.</p>';
        echo '<h2>Top tools</h2>';
        self::render_table(self::top_tools($since), 'Tool', 'tool_name');
        echo '<h2>Top errors</h2>';
        self::render_table(self::top_errors($since), 'Error class', 'error_class');
        echo '<h2>Top API members</h2>';
        self::render_table(self::top_members($since), 'API member', 'api_member');
        echo '</div>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function render_table(array $rows, string $label, string $column): void
    {
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html($label) . '</th><th>Calls</th></tr></thead><tbody>';
        if ($rows === array()) {
            echo '<tr><td colspan="2">No rows yet.</td></tr>';
        }
        foreach ($rows as $row) {
            $name = isset($row[$column]) ? (string) $row[$column] : '';
            $calls = isset($row['calls']) ? (string) $row['calls'] : '0';
            echo '<tr><td>' . esc_html($name) . '</td><td>' . esc_html($calls) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function top_tools(string $since): array
    {
        return self::grouped($since, 'tool_name', "tool_name NOT IN ('session_start', 'session_end')");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function top_errors(string $since): array
    {
        return self::grouped($since, 'error_class', "outcome = 'error' AND error_class <> ''");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function top_members(string $since): array
    {
        return self::grouped($since, 'api_member', "api_member <> ''");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function grouped(string $since, string $column, string $where): array
    {
        global $wpdb;
        $table = self::table_name();
        $sql = "SELECT {$column}, SUM(call_count) AS calls FROM {$table} WHERE usage_date >= %s AND {$where} GROUP BY {$column} ORDER BY calls DESC LIMIT 20";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $since), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }

    private static function text(string $value, int $max): string
    {
        $value = trim(wp_strip_all_tags($value));
        if (strlen($value) > $max) {
            $value = substr($value, 0, $max);
        }
        return $value;
    }

    private static function token(string $value, int $max): string
    {
        $value = self::text($value, $max);
        if ($value === '') {
            return '';
        }
        return preg_match('/^[A-Za-z0-9_.-]+$/', $value) ? $value : '';
    }

    private static function member(string $value): string
    {
        $value = self::text($value, 128);
        if ($value === '') {
            return '';
        }
        return preg_match('/^[A-Za-z0-9_.]+$/', $value) ? $value : '';
    }

    /**
     * @param mixed $value
     */
    private static function duration($value): int
    {
        $ms = is_numeric($value) ? (int) $value : 0;
        if ($ms < 0) {
            return 0;
        }
        if ($ms > 86400000) {
            return 86400000;
        }
        return $ms;
    }

    /**
     * @param mixed $value
     */
    private static function count_value($value): int
    {
        $count = is_numeric($value) ? (int) $value : 0;
        if ($count < 0) {
            return 0;
        }
        if ($count > 1000000) {
            return 1000000;
        }
        return $count;
    }
}
