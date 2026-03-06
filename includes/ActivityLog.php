<?php

namespace AlwaysCurious\WorkOSWP;

defined('ABSPATH') || exit;

class ActivityLog {

    private const TABLE_SUFFIX = 'workos_activity_log';
    private const DB_VERSION_KEY = 'workos_activity_log_db_version';
    private const DB_VERSION = '1.0';

    /**
     * Get the full table name (with WP prefix).
     */
    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Check if activity tracking is enabled.
     */
    public static function is_enabled(): bool {
        return (bool) get_option('workos_activity_tracking', false);
    }

    /**
     * Create or upgrade the custom table.
     */
    public static function create_table(): void {
        global $wpdb;

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(50) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            user_email VARCHAR(200) DEFAULT NULL,
            workos_user_id VARCHAR(100) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            metadata TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::DB_VERSION_KEY, self::DB_VERSION);
    }

    /**
     * Drop the custom table entirely.
     */
    public static function drop_table(): void {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        delete_option(self::DB_VERSION_KEY);
    }

    /**
     * Ensure the table exists (called when tracking is enabled).
     */
    public static function maybe_create_table(): void {
        if (get_option(self::DB_VERSION_KEY) !== self::DB_VERSION) {
            self::create_table();
        }
    }

    /**
     * Record an activity event.
     */
    public static function record(string $event_type, array $data = []): void {
        if (!self::is_enabled()) {
            return;
        }

        self::maybe_create_table();

        global $wpdb;

        $wpdb->insert(self::table_name(), [
            'event_type'     => $event_type,
            'user_id'        => $data['user_id'] ?? null,
            'user_email'     => $data['user_email'] ?? null,
            'workos_user_id' => $data['workos_user_id'] ?? null,
            'ip_address'     => self::get_client_ip(),
            'user_agent'     => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            'metadata'       => !empty($data['metadata']) ? wp_json_encode($data['metadata']) : null,
            'created_at'     => current_time('mysql', true),
        ]);
    }

    /**
     * Get summary stats for a date range.
     */
    public static function get_stats(int $days = 30): array {
        global $wpdb;
        $table = self::table_name();

        if (!self::table_exists()) {
            return self::empty_stats();
        }

        $since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $totals = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, COUNT(*) as count FROM {$table} WHERE created_at >= %s GROUP BY event_type",
            $since
        ), ARRAY_A);

        $unique_users = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT user_email) FROM {$table} WHERE event_type = 'login' AND created_at >= %s",
            $since
        ));

        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, event_type, COUNT(*) as count FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at), event_type ORDER BY date ASC",
            $since
        ), ARRAY_A);

        $stats = self::empty_stats();
        $stats['unique_users'] = $unique_users;
        $stats['daily'] = $daily;

        foreach ($totals as $row) {
            $stats['totals'][$row['event_type']] = (int) $row['count'];
        }

        return $stats;
    }

    /**
     * Get recent events with pagination.
     */
    public static function get_events(int $limit = 25, int $offset = 0): array {
        global $wpdb;
        $table = self::table_name();

        if (!self::table_exists()) {
            return ['events' => [], 'total' => 0];
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);

        return ['events' => $events, 'total' => $total];
    }

    /**
     * Delete all events from the table.
     */
    public static function clear(): int {
        global $wpdb;
        $table = self::table_name();

        if (!self::table_exists()) {
            return 0;
        }

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $wpdb->query("TRUNCATE TABLE {$table}");
        return $count;
    }

    /**
     * Get total row count.
     */
    public static function count(): int {
        global $wpdb;
        $table = self::table_name();

        if (!self::table_exists()) {
            return 0;
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    private static function table_exists(): bool {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }

    private static function empty_stats(): array {
        return [
            'totals'       => [],
            'unique_users' => 0,
            'daily'        => [],
        ];
    }

    private static function get_client_ip(): ?string {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', $_SERVER[$header])[0];
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return null;
    }
}
