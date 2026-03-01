<?php

namespace ORAS\Tickets\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class DbLock {

    private const DEFAULT_TIMEOUT_SECONDS = 5;

    /**
     * @template T
     * @param string $lock_key
     * @param callable():T $callback
     * @param int $timeout_seconds
     * @return T|\WP_Error
     */
    public static function withLock( string $lock_key, callable $callback, int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS ) {
        global $wpdb;

        if ( ! isset( $wpdb ) || ! $wpdb instanceof \wpdb ) {
            return $callback();
        }

        $normalized_timeout = max( 1, $timeout_seconds );
        $db_lock_key = self::buildLockName( $lock_key );

        $acquired = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT GET_LOCK(%s, %d)',
                $db_lock_key,
                $normalized_timeout
            )
        );

        if ( (int) $acquired !== 1 ) {
            return new \WP_Error(
                'oras_tickets_lock_timeout',
                __( 'Another RSVP operation is in progress. Please retry.', 'oras-tickets' )
            );
        }

        try {
            return $callback();
        } finally {
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT RELEASE_LOCK(%s)',
                    $db_lock_key
                )
            );
        }
    }

    /**
     * @template T
     * @param int $event_id
     * @param callable():T $callback
     * @param int $timeout_seconds
     * @return T|\WP_Error
     */
    public static function forEvent( int $event_id, callable $callback, int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS ) {
        return self::withLock( 'event:' . max( 0, $event_id ), $callback, $timeout_seconds );
    }

    private static function buildLockName( string $lock_key ): string {
        return 'oras_tickets:' . substr( md5( $lock_key ), 0, 40 );
    }
}
