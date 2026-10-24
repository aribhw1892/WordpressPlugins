<?php
/**
 * Maps a machine id to its trial end and, after checkout, the Dodo customer and subscription.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CWM_Swx_Mcp_Installs
{
    const DB_VERSION = '3';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cwm_swx_mcp_installs';
    }

    public static function maybe_install(): void
    {
        if (get_option('cwm_swx_mcp_db_version') === self::DB_VERSION) {
            return;
        }

        CWM_Swx_Mcp_Usage::create_table();
        self::create_table();
        update_option('cwm_swx_mcp_db_version', self::DB_VERSION, true);
    }

    public static function create_table(): void
    {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            install_id char(36) NOT NULL,
            customer_id varchar(128) NOT NULL DEFAULT '',
            subscription_id varchar(128) NOT NULL DEFAULT '',
            trial_end_gmt datetime NULL,
            pending_session varchar(128) NOT NULL DEFAULT '',
            updated_gmt datetime NOT NULL,
            PRIMARY KEY  (install_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * @return array{customer_id: string, subscription_id: string, trial_end_gmt: string, pending_session: string}|null
     */
    public static function find(string $install_id): ?array
    {
        global $wpdb;
        self::maybe_install();
        $table = self::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT customer_id, subscription_id, trial_end_gmt, pending_session FROM {$table} WHERE install_id = %s",
                $install_id
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }

        return array(
            'customer_id' => isset($row['customer_id']) ? (string) $row['customer_id'] : '',
            'subscription_id' => isset($row['subscription_id']) ? (string) $row['subscription_id'] : '',
            'trial_end_gmt' => isset($row['trial_end_gmt']) && $row['trial_end_gmt'] !== null ? (string) $row['trial_end_gmt'] : '',
            'pending_session' => isset($row['pending_session']) ? (string) $row['pending_session'] : '',
        );
    }

    /**
     * @return array{installs: int, subscribed: int, trials: int}
     */
    public static function counts(): array
    {
        global $wpdb;
        self::maybe_install();
        $table = self::table_name();
        $now = current_time('mysql', true);
        return array(
            'installs' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'subscribed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE subscription_id <> ''"),
            'trials' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE subscription_id = '' AND trial_end_gmt IS NOT NULL AND trial_end_gmt >= %s",
                $now
            )),
        );
    }

    /**
     * @return array{subscribed: int, trials: int, ended: int, none: int}
     */
    public static function mix(): array
    {
        global $wpdb;
        self::maybe_install();
        $table = self::table_name();
        $now = current_time('mysql', true);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN subscription_id <> '' THEN 1 ELSE 0 END), 0) AS subscribed,
                COALESCE(SUM(CASE WHEN subscription_id = '' AND trial_end_gmt IS NOT NULL AND trial_end_gmt >= %s THEN 1 ELSE 0 END), 0) AS trials,
                COALESCE(SUM(CASE WHEN subscription_id = '' AND trial_end_gmt IS NOT NULL AND trial_end_gmt < %s THEN 1 ELSE 0 END), 0) AS ended,
                COALESCE(SUM(CASE WHEN subscription_id = '' AND trial_end_gmt IS NULL THEN 1 ELSE 0 END), 0) AS none
             FROM {$table}",
            $now,
            $now
        ), ARRAY_A);
        if (!is_array($row)) {
            return array('subscribed' => 0, 'trials' => 0, 'ended' => 0, 'none' => 0);
        }
        return array(
            'subscribed' => (int) $row['subscribed'],
            'trials' => (int) $row['trials'],
            'ended' => (int) $row['ended'],
            'none' => (int) $row['none'],
        );
    }

    /**
     * @return array<string, string>|null
     */
    public static function find_row(string $install_id): ?array
    {
        global $wpdb;
        self::maybe_install();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT install_id, customer_id, subscription_id, trial_end_gmt, pending_session, updated_gmt FROM ' . self::table_name() . ' WHERE install_id = %s',
                $install_id
            ),
            ARRAY_A
        );
        return is_array($row) ? self::row_strings($row) : null;
    }

    /**
     * @return array{rows: array<int, array<string, string>>, total: int}
     */
    public static function list_page(int $page, int $per_page): array
    {
        global $wpdb;
        self::maybe_install();
        $table = self::table_name();
        $per_page = max(1, min(100, $per_page));
        $offset = max(0, $page - 1) * $per_page;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT install_id, customer_id, subscription_id, trial_end_gmt, pending_session, updated_gmt FROM {$table} ORDER BY updated_gmt DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        return array(
            'rows' => self::rows_strings(is_array($rows) ? $rows : array()),
            'total' => $total,
        );
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

    public static function begin_trial(string $install_id, string $trial_end_gmt): bool
    {
        $existing = self::find($install_id);
        if ($existing !== null && $existing['trial_end_gmt'] !== '') {
            return true;
        }

        return self::write(
            $install_id,
            $existing === null ? '' : $existing['customer_id'],
            $existing === null ? '' : $existing['subscription_id'],
            $trial_end_gmt,
            $existing === null ? '' : $existing['pending_session']
        );
    }

    public static function remember(string $install_id, string $customer_id, string $subscription_id): bool
    {
        $existing = self::find($install_id);
        return self::write(
            $install_id,
            $customer_id,
            $subscription_id,
            $existing === null ? '' : $existing['trial_end_gmt'],
            ''
        );
    }

    public static function remember_session(string $install_id, string $session_id): bool
    {
        $existing = self::find($install_id);
        return self::write(
            $install_id,
            $existing === null ? '' : $existing['customer_id'],
            $existing === null ? '' : $existing['subscription_id'],
            $existing === null ? '' : $existing['trial_end_gmt'],
            $session_id
        );
    }

    private static function write(string $install_id, string $customer_id, string $subscription_id, string $trial_end_gmt, string $pending_session): bool
    {
        global $wpdb;
        self::maybe_install();
        $result = $wpdb->replace(
            self::table_name(),
            array(
                'install_id' => $install_id,
                'customer_id' => $customer_id,
                'subscription_id' => $subscription_id,
                'trial_end_gmt' => $trial_end_gmt === '' ? null : $trial_end_gmt,
                'pending_session' => $pending_session,
                'updated_gmt' => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s')
        );
        return $result !== false;
    }
}
