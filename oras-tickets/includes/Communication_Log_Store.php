<?php

namespace ORAS\Tickets;

if (! defined('ABSPATH')) {
    exit;
}

final class Communication_Log_Store
{
    private const OPTION_SCHEMA_VERSION = 'oras_tickets_communications_schema_version';
    private const SCHEMA_VERSION = 2;

    /**
     * @var string[]
     */
    private const ALLOWED_STATUSES = array('queued', 'sending', 'sent', 'partial', 'failed');

    public static function maybe_upgrade(): void
    {
        $installed = (int) get_option(self::OPTION_SCHEMA_VERSION, 0);
        if ($installed >= self::SCHEMA_VERSION && self::table_exists()) {
            return;
        }

        self::install_schema();
    }

    public static function install_schema(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            sender_user_id bigint(20) unsigned NOT NULL,
            sender_display_name varchar(191) NOT NULL DEFAULT '',
            sender_email varchar(191) NOT NULL DEFAULT '',
            recipient_segment varchar(64) NOT NULL DEFAULT '',
            recipient_count int(10) unsigned NOT NULL DEFAULT 0,
            email_subject text NOT NULL,
            email_body_snapshot mediumtext NOT NULL,
            sent_at datetime NOT NULL,
            send_status varchar(20) NOT NULL DEFAULT 'sent',
            failed_recipient_count int(10) unsigned NOT NULL DEFAULT 0,
            related_action_type varchar(64) NOT NULL DEFAULT '',
            delivery_payload longtext NULL,
            processed_recipient_count int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY event_sent (event_id,sent_at,id),
            KEY sender_sent (sender_user_id,sent_at,id),
            KEY status_sent (send_status,sent_at,id),
            KEY segment_sent (recipient_segment,sent_at,id)
        ) {$charset_collate};";

        dbDelta($sql);

        if (self::table_exists()) {
            update_option(self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function insert(array $data): int
    {
        self::maybe_upgrade();

        global $wpdb;

        $row = self::prepare_row($data);
        $inserted = $wpdb->insert(
            self::table_name(),
            $row,
            array('%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d')
        );

        if (false === $inserted) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function query(array $filters = array()): array
    {
        self::maybe_upgrade();

        global $wpdb;

        $where = array('1=1');
        $args = array();

        $event_id = isset($filters['event_id']) ? absint($filters['event_id']) : 0;
        if ($event_id > 0) {
            $where[] = 'event_id = %d';
            $args[] = $event_id;
        }

        $sender_user_id = isset($filters['sender_user_id']) ? absint($filters['sender_user_id']) : 0;
        if ($sender_user_id > 0) {
            $where[] = 'sender_user_id = %d';
            $args[] = $sender_user_id;
        }

        $segment = isset($filters['recipient_segment']) ? sanitize_key((string) $filters['recipient_segment']) : '';
        if ($segment !== '') {
            $where[] = 'recipient_segment = %s';
            $args[] = $segment;
        }

        $status = isset($filters['send_status']) ? self::sanitize_status((string) $filters['send_status']) : '';
        if ($status !== '') {
            $where[] = 'send_status = %s';
            $args[] = $status;
        }

        $after = isset($filters['after']) ? self::sanitize_date((string) $filters['after']) : '';
        if ($after !== '') {
            $where[] = 'sent_at >= %s';
            $args[] = $after . ' 00:00:00';
        }

        $before = isset($filters['before']) ? self::sanitize_date((string) $filters['before']) : '';
        if ($before !== '') {
            $where[] = 'sent_at <= %s';
            $args[] = $before . ' 23:59:59';
        }

        $search = isset($filters['search']) ? sanitize_text_field((string) $filters['search']) : '';
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(sender_display_name LIKE %s OR sender_email LIKE %s OR email_subject LIKE %s OR recipient_segment LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }

        $limit = isset($filters['limit']) ? absint($filters['limit']) : 50;
        $limit = min(200, max(1, $limit));
        $offset = isset($filters['offset']) ? absint($filters['offset']) : 0;

        $sql = 'SELECT * FROM ' . self::table_name() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY sent_at DESC, id DESC LIMIT %d OFFSET %d';
        $args[] = $limit;
        $args[] = $offset;

        $prepared = $wpdb->prepare($sql, $args);
        $rows = $wpdb->get_results($prepared, 'ARRAY_A');

        return is_array($rows) ? $rows : array();
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        self::maybe_upgrade();

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id),
            'ARRAY_A'
        );

        return is_array($row) ? $row : null;
    }

    public static function update_delivery(int $id, string $status, int $processed, int $failed): bool
    {
        if ($id <= 0) {
            return false;
        }

        $status = self::sanitize_status($status);
        if ($status === '') {
            return false;
        }

        global $wpdb;
        return false !== $wpdb->update(
            self::table_name(),
            array(
                'send_status'               => $status,
                'processed_recipient_count' => absint($processed),
                'failed_recipient_count'    => absint($failed),
            ),
            array('id' => $id),
            array('%s', '%d', '%d'),
            array('%d')
        );
    }

    public static function table_exists(): bool
    {
        global $wpdb;

        $table = self::table_name();
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $table
            )
        );

        return is_string($found) && $found === $table;
    }

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'oras_ticket_communications';
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function prepare_row(array $data): array
    {
        $sent_at = isset($data['sent_at']) ? self::sanitize_mysql_datetime((string) $data['sent_at']) : '';
        if ($sent_at === '') {
            $sent_at = current_time('mysql', true);
        }

        return array(
            'event_id'               => isset($data['event_id']) ? absint($data['event_id']) : 0,
            'sender_user_id'         => isset($data['sender_user_id']) ? absint($data['sender_user_id']) : 0,
            'sender_display_name'    => self::sanitize_text_snapshot((string) ($data['sender_display_name'] ?? ''), 191),
            'sender_email'           => sanitize_email((string) ($data['sender_email'] ?? '')),
            'recipient_segment'      => self::sanitize_key_snapshot((string) ($data['recipient_segment'] ?? ''), 64),
            'recipient_count'        => isset($data['recipient_count']) ? absint($data['recipient_count']) : 0,
            'email_subject'          => self::sanitize_text_snapshot((string) ($data['email_subject'] ?? ''), 1000),
            'email_body_snapshot'    => self::sanitize_body_snapshot((string) ($data['email_body_snapshot'] ?? '')),
            'sent_at'                => $sent_at,
            'send_status'            => self::sanitize_status((string) ($data['send_status'] ?? 'sent')) ?: 'sent',
            'failed_recipient_count' => isset($data['failed_recipient_count']) ? absint($data['failed_recipient_count']) : 0,
            'related_action_type'    => self::sanitize_key_snapshot((string) ($data['related_action_type'] ?? ''), 64),
            'delivery_payload'       => isset($data['delivery_payload']) ? (string) $data['delivery_payload'] : '',
            'processed_recipient_count' => isset($data['processed_recipient_count']) ? absint($data['processed_recipient_count']) : 0,
        );
    }

    private static function sanitize_text_snapshot(string $value, int $max_length): string
    {
        $clean = sanitize_text_field($value);
        if ($max_length > 0 && strlen($clean) > $max_length) {
            return substr($clean, 0, $max_length);
        }

        return $clean;
    }

    private static function sanitize_body_snapshot(string $value): string
    {
        $clean = sanitize_textarea_field($value);
        if (strlen($clean) > 65535) {
            return substr($clean, 0, 65535);
        }

        return $clean;
    }

    private static function sanitize_key_snapshot(string $value, int $max_length): string
    {
        $clean = sanitize_key($value);
        if ($max_length > 0 && strlen($clean) > $max_length) {
            return substr($clean, 0, $max_length);
        }

        return $clean;
    }

    private static function sanitize_status(string $value): string
    {
        $status = sanitize_key($value);
        return in_array($status, self::ALLOWED_STATUSES, true) ? $status : '';
    }

    private static function sanitize_date(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private static function sanitize_mysql_datetime(string $value): string
    {
        $value = trim($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return '';
        }

        return $value;
    }
}
