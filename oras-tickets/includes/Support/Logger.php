<?php

/**
 * Plugin logger.
 *
 * @package ORAS\Tickets
 */

namespace ORAS\Tickets\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logger service.
 */
final class Logger {

    /**
     * Singleton instance.
     *
     * @var Logger|null
     */
    private static ?Logger $instance = null;

    /**
     * Get singleton logger instance.
     */
    public static function instance(): Logger {
        return self::$instance ??= new self();
    }

    /**
     * Constructor.
     */
    private function __construct() {}

    /**
     * Write message to debug log when enabled.
     *
     * @param string $message Message to write.
     */
    public function log( string $message ): void {
        $debug_enabled = false;

        if ( isset( $_ENV['ORAS_TICKETS_DEBUG'] ) ) {
            $debug_enabled = (bool) $_ENV['ORAS_TICKETS_DEBUG'];
        } elseif ( \defined( 'ORAS_TICKETS_DEBUG' ) ) {
            $debug_enabled = (bool) \constant( 'ORAS_TICKETS_DEBUG' );
        }

        if ( ! $debug_enabled ) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( '[ORAS-Tickets] ' . $message );
    }
}
