<?php

namespace ORAS\Tickets;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Waitlist_Store {
    private const OPTION_SCHEMA_VERSION = 'oras_tickets_waitlist_schema_version';
    private const SCHEMA_VERSION = 1;
    /**
     * @var array<int, string>
     */
    private const ALLOWED_STATUSES = array( 'waiting', 'promoted', 'left' );

    /**
     * @var array<int, bool>
     */
    private static array $backfilled_events = array();

    public static function maybe_upgrade(): void {
        $installed = (int) get_option( self::OPTION_SCHEMA_VERSION, 0 );
        if ( $installed >= self::SCHEMA_VERSION && self::table_exists() ) {
            return;
        }

        self::install_schema();
    }

    public static function install_schema(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'waiting',
            joined_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            promoted_at datetime NULL DEFAULT NULL,
            removed_at datetime NULL DEFAULT NULL,
            last_action varchar(32) NOT NULL DEFAULT 'joined',
            source varchar(32) NOT NULL DEFAULT 'system',
            actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY event_user (event_id,user_id),
            KEY event_status_joined (event_id,status,joined_at,id),
            KEY user_status (user_id,status),
            KEY event_status_updated (event_id,status,updated_at)
        ) {$charset_collate};";

        dbDelta( $sql );

        if ( self::table_exists() ) {
            update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, false );
        }
    }

    public static function count_waiting( int $event_id ): int {
        if ( $event_id <= 0 ) {
            return 0;
        }

        self::maybe_upgrade();
        self::backfill_event_from_legacy_meta( $event_id );

        global $wpdb;
        $table = self::table_name();

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status = %s",
                $event_id,
                'waiting'
            )
        );

        return max( 0, (int) $count );
    }

    /**
     * @return array<int, int>
     */
    public static function get_waiting_user_ids( int $event_id, int $limit = 0 ): array {
        if ( $event_id <= 0 ) {
            return array();
        }

        self::maybe_upgrade();
        self::backfill_event_from_legacy_meta( $event_id );

        global $wpdb;
        $table = self::table_name();

        $sql = $wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE event_id = %d AND status = %s ORDER BY joined_at ASC, id ASC",
            $event_id,
            'waiting'
        );

        if ( $limit > 0 ) {
            $sql .= ' LIMIT ' . absint( $limit );
        }

        $rows = $wpdb->get_col( $sql );
        if ( ! is_array( $rows ) ) {
            return array();
        }

        return array_values(
            array_filter(
                array_map( 'absint', $rows ),
                static function ( int $value ): bool {
                    return $value > 0;
                }
            )
        );
    }

    /**
     * @return array<int, \WP_User>
     */
    public static function get_waiting_users( int $event_id, int $limit = 0 ): array {
        $user_ids = self::get_waiting_user_ids( $event_id, $limit );
        if ( empty( $user_ids ) ) {
            return array();
        }

        $users = get_users(
            array(
                'include' => $user_ids,
                'orderby' => 'include',
            )
        );

        return is_array( $users ) ? $users : array();
    }

    public static function get_current_waitlist_status( int $event_id, int $user_id ): string {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return '';
        }

        self::maybe_upgrade();
        self::backfill_event_from_legacy_meta( $event_id );

        $row = self::get_row( $event_id, $user_id );
        if ( ! is_object( $row ) || ! isset( $row->status ) || ! is_string( $row->status ) ) {
            return '';
        }

        return sanitize_key( $row->status );
    }

    public static function mark_waiting( int $event_id, int $user_id, string $source = 'frontend', int $actor_user_id = 0, int $joined_ts = 0 ): bool {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return false;
        }

        self::maybe_upgrade();

        $now = self::mysql_now();
        $joined_at = self::mysql_from_timestamp( $joined_ts );
        $existing = self::get_row( $event_id, $user_id );

        if ( ! is_object( $existing ) ) {
            return false !== self::insert_row(
                $event_id,
                $user_id,
                array(
                    'status'        => 'waiting',
                    'joined_at'     => $joined_at,
                    'updated_at'    => $now,
                    'promoted_at'   => null,
                    'removed_at'    => null,
                    'last_action'   => 'joined',
                    'source'        => self::sanitize_source( $source ),
                    'actor_user_id' => max( 0, $actor_user_id ),
                )
            );
        }

        $current_joined = isset( $existing->joined_at ) && is_string( $existing->joined_at ) ? $existing->joined_at : '';
        if ( $current_joined !== '' && strtotime( $current_joined ) !== false && strtotime( $current_joined ) < strtotime( $joined_at ) ) {
            $joined_at = $current_joined;
        }

        return false !== self::update_row(
            $event_id,
            $user_id,
            array(
                'status'        => 'waiting',
                'joined_at'     => $joined_at,
                'updated_at'    => $now,
                'promoted_at'   => null,
                'removed_at'    => null,
                'last_action'   => 'joined',
                'source'        => self::sanitize_source( $source ),
                'actor_user_id' => max( 0, $actor_user_id ),
            )
        );
    }

    public static function mark_promoted( int $event_id, int $user_id, string $source = 'admin', int $actor_user_id = 0 ): bool {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return false;
        }

        self::maybe_upgrade();

        $now = self::mysql_now();
        $existing = self::get_row( $event_id, $user_id );
        $joined_at = is_object( $existing ) && isset( $existing->joined_at ) && is_string( $existing->joined_at ) ? $existing->joined_at : $now;

        if ( ! is_object( $existing ) ) {
            return false !== self::insert_row(
                $event_id,
                $user_id,
                array(
                    'status'        => 'promoted',
                    'joined_at'     => $joined_at,
                    'updated_at'    => $now,
                    'promoted_at'   => $now,
                    'removed_at'    => null,
                    'last_action'   => 'promoted',
                    'source'        => self::sanitize_source( $source ),
                    'actor_user_id' => max( 0, $actor_user_id ),
                )
            );
        }

        return false !== self::update_row(
            $event_id,
            $user_id,
            array(
                'status'        => 'promoted',
                'joined_at'     => $joined_at,
                'updated_at'    => $now,
                'promoted_at'   => $now,
                'removed_at'    => null,
                'last_action'   => 'promoted',
                'source'        => self::sanitize_source( $source ),
                'actor_user_id' => max( 0, $actor_user_id ),
            )
        );
    }

    public static function mark_left( int $event_id, int $user_id, string $source = 'frontend', int $actor_user_id = 0 ): bool {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return false;
        }

        self::maybe_upgrade();

        $existing = self::get_row( $event_id, $user_id );
        if ( ! is_object( $existing ) ) {
            return true;
        }

        $now = self::mysql_now();
        $joined_at = isset( $existing->joined_at ) && is_string( $existing->joined_at ) ? $existing->joined_at : $now;

        return false !== self::update_row(
            $event_id,
            $user_id,
            array(
                'status'        => 'left',
                'joined_at'     => $joined_at,
                'updated_at'    => $now,
                'promoted_at'   => null,
                'removed_at'    => $now,
                'last_action'   => 'left_waitlist',
                'source'        => self::sanitize_source( $source ),
                'actor_user_id' => max( 0, $actor_user_id ),
            )
        );
    }

    public static function promote_next_waiting( int $event_id, int $actor_user_id = 0, string $source = 'admin' ): int {
        $user_ids = self::get_waiting_user_ids( $event_id, 1 );
        if ( empty( $user_ids ) ) {
            return 0;
        }

        $user_id = absint( $user_ids[0] );
        if ( $user_id <= 0 ) {
            return 0;
        }

        if ( ! self::mark_promoted( $event_id, $user_id, $source, $actor_user_id ) ) {
            return 0;
        }

        return $user_id;
    }

    public static function promote_user( int $event_id, int $user_id, int $actor_user_id = 0, string $source = 'admin' ): bool {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return false;
        }

        $status = self::get_current_waitlist_status( $event_id, $user_id );
        if ( 'waiting' !== $status ) {
            return false;
        }

        return self::mark_promoted( $event_id, $user_id, $source, $actor_user_id );
    }

    /**
     * @return array<int, int>
     */
    public static function bulk_promote_waiting( int $event_id, int $count, int $actor_user_id = 0, string $source = 'admin' ): array {
        if ( $event_id <= 0 || $count <= 0 ) {
            return array();
        }

        $limit = min( 100, max( 1, $count ) );
        $promoted = array();

        for ( $i = 0; $i < $limit; $i++ ) {
            $user_id = self::promote_next_waiting( $event_id, $actor_user_id, $source );
            if ( $user_id <= 0 ) {
                break;
            }

            $promoted[] = $user_id;
        }

        return $promoted;
    }

    public static function remove_waiting_user( int $event_id, int $user_id, int $actor_user_id = 0, string $source = 'admin' ): bool {
        if ( $event_id <= 0 || $user_id <= 0 ) {
            return false;
        }

        $status = self::get_current_waitlist_status( $event_id, $user_id );
        if ( 'waiting' !== $status ) {
            return false;
        }

        return self::mark_left( $event_id, $user_id, $source, $actor_user_id );
    }

    /**
     * @param array<int, string> $statuses
     * @return array<int, object>
     */
    public static function get_event_rows( int $event_id, array $statuses = array(), int $limit = 0, string $order = 'joined_asc' ): array {
        if ( $event_id <= 0 ) {
            return array();
        }

        self::maybe_upgrade();
        self::backfill_event_from_legacy_meta( $event_id );

        global $wpdb;
        $table = self::table_name();

        $query = "SELECT id,event_id,user_id,status,joined_at,updated_at,promoted_at,removed_at,last_action,source,actor_user_id FROM {$table} WHERE event_id = %d";
        $args = array( $event_id );

        $normalized_statuses = self::normalize_statuses( $statuses );
        if ( ! empty( $normalized_statuses ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $normalized_statuses ), '%s' ) );
            $query .= " AND status IN ({$placeholders})";
            $args = array_merge( $args, $normalized_statuses );
        }

        if ( 'updated_desc' === $order ) {
            $query .= ' ORDER BY updated_at DESC, id DESC';
        } else {
            $query .= ' ORDER BY joined_at ASC, id ASC';
        }

        if ( $limit > 0 ) {
            $query .= ' LIMIT ' . absint( $limit );
        }

        $rows = $wpdb->get_results( $wpdb->prepare( $query, $args ) );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @param array<int, string> $statuses
     * @return array<int, string>
     */
    private static function normalize_statuses( array $statuses ): array {
        if ( empty( $statuses ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $statuses as $status ) {
            if ( ! is_string( $status ) ) {
                continue;
            }

            $value = sanitize_key( $status );
            if ( '' === $value || ! in_array( $value, self::ALLOWED_STATUSES, true ) ) {
                continue;
            }

            $normalized[] = $value;
        }

        return array_values( array_unique( $normalized ) );
    }

    private static function backfill_event_from_legacy_meta( int $event_id ): void {
        if ( $event_id <= 0 || isset( self::$backfilled_events[ $event_id ] ) ) {
            return;
        }

        self::$backfilled_events[ $event_id ] = true;

        $users = get_users(
            array(
                'meta_query' => array(
                    array(
                        'key'     => '_oras_rsvp_event_' . $event_id,
                        'value'   => 'waitlist',
                        'compare' => '=',
                    ),
                ),
                'meta_key'   => '_oras_rsvp_event_' . $event_id . '_ts',
                'orderby'    => 'meta_value_num',
                'order'      => 'ASC',
                'fields'     => 'ID',
            )
        );

        if ( ! is_array( $users ) || empty( $users ) ) {
            return;
        }

        foreach ( $users as $user_id ) {
            $id = absint( $user_id );
            if ( $id <= 0 ) {
                continue;
            }

            $ts = (int) get_user_meta( $id, '_oras_rsvp_event_' . $event_id . '_ts', true );
            self::mark_waiting( $event_id, $id, 'legacy-backfill', 0, $ts );
        }
    }

    private static function get_row( int $event_id, int $user_id ): ?object {
        global $wpdb;

        $table = self::table_name();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event_id = %d AND user_id = %d LIMIT 1",
                $event_id,
                $user_id
            )
        );

        return is_object( $row ) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function insert_row( int $event_id, int $user_id, array $data ): int|false {
        global $wpdb;

        $row = array_merge(
            array(
                'event_id'      => $event_id,
                'user_id'       => $user_id,
                'status'        => 'waiting',
                'joined_at'     => self::mysql_now(),
                'updated_at'    => self::mysql_now(),
                'promoted_at'   => null,
                'removed_at'    => null,
                'last_action'   => 'joined',
                'source'        => 'system',
                'actor_user_id' => 0,
            ),
            $data
        );

        $result = $wpdb->insert(
            self::table_name(),
            $row,
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
        );

        return false === $result ? false : (int) $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function update_row( int $event_id, int $user_id, array $data ): int|false {
        global $wpdb;

        return $wpdb->update(
            self::table_name(),
            $data,
            array(
                'event_id' => $event_id,
                'user_id'  => $user_id,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ),
            array( '%d', '%d' )
        );
    }

    private static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'oras_ticket_waitlist';
    }

    private static function table_exists(): bool {
        global $wpdb;

        $table = self::table_name();
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $table
            )
        );

        return is_string( $found ) && $found === $table;
    }

    private static function mysql_now(): string {
        return current_time( 'mysql', true );
    }

    private static function mysql_from_timestamp( int $timestamp ): string {
        if ( $timestamp <= 0 ) {
            return self::mysql_now();
        }

        return gmdate( 'Y-m-d H:i:s', $timestamp );
    }

    private static function sanitize_source( string $source ): string {
        $val = sanitize_key( $source );
        return '' === $val ? 'system' : substr( $val, 0, 32 );
    }
}
