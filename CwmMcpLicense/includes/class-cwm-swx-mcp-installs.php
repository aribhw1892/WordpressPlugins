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
