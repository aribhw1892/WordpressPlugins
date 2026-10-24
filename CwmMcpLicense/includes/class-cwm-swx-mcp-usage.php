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

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $since = gmdate('Y-m-d', time() - (30 * DAY_IN_SECONDS));
        echo '<div class="wrap">';
        echo '<h1>SolidWorks MCP usage</h1>';
        echo '<p>Totals since ' . esc_html($since) . ' (UTC). Payments stay in Stripe.</p>';
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
