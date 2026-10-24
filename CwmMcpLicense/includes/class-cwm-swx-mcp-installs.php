<?php
/**
 * Maps a machine id to the Stripe customer and subscription. Stripe Search is unavailable for this account.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CWM_Swx_Mcp_Installs
{
    const DB_VERSION = '2';

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
            customer_id varchar(64) NOT NULL DEFAULT '',
            subscription_id varchar(64) NOT NULL DEFAULT '',
            updated_gmt datetime NOT NULL,
            PRIMARY KEY  (install_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * @return array{customer_id: string, subscription_id: string}|null
     */
    public static function find(string $install_id): ?array
    {
        global $wpdb;
        self::maybe_install();
        $table = self::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT customer_id, subscription_id FROM {$table} WHERE install_id = %s", $install_id),
            ARRAY_A
        );
        if (!is_array($row)) {
            return null;
        }

        return array(
            'customer_id' => isset($row['customer_id']) ? (string) $row['customer_id'] : '',
            'subscription_id' => isset($row['subscription_id']) ? (string) $row['subscription_id'] : '',
        );
    }

    public static function remember(string $install_id, string $customer_id, string $subscription_id): bool
    {
        global $wpdb;
        self::maybe_install();
        $result = $wpdb->replace(
            self::table_name(),
            array(
                'install_id' => $install_id,
                'customer_id' => $customer_id,
                'subscription_id' => $subscription_id,
                'updated_gmt' => current_time('mysql', true),
            ),
            array('%s', '%s', '%s', '%s')
        );
        return $result !== false;
    }
}
