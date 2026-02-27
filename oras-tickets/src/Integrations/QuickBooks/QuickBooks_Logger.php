<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

use ORAS\Tickets\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class QuickBooks_Logger {

    private const SOURCE = 'oras-tickets-qbo';

    /**
     * @param array<string,mixed> $context
     */
    public function info( string $message, array $context = array() ): void {
        $this->log( 'info', $message, $context );
    }

    /**
     * @param array<string,mixed> $context
     */
    public function warning( string $message, array $context = array() ): void {
        $this->log( 'warning', $message, $context );
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error( string $message, array $context = array() ): void {
        $this->log( 'error', $message, $context );
    }

    /**
     * @param array<string,mixed> $context
     */
    private function log( string $level, string $message, array $context = array() ): void {
        $safe_context = $this->redact( $context );
        $rendered     = $message;

        if ( ! empty( $safe_context ) ) {
            $rendered .= ' | ' . wp_json_encode( $safe_context );
        }

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->log(
                $level,
                $rendered,
                array(
                    'source' => self::SOURCE,
                )
            );
            return;
        }

        Logger::instance()->log( '[QBO][' . strtoupper( $level ) . '] ' . $rendered );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function redact( array $context ): array {
        $safe = array();
        foreach ( $context as $key => $value ) {
            $k = strtolower( (string) $key );
            if ( strpos( $k, 'token' ) !== false || strpos( $k, 'secret' ) !== false || strpos( $k, 'authorization' ) !== false ) {
                $safe[ $key ] = '[redacted]';
                continue;
            }

            if ( is_string( $value ) && strlen( $value ) > 700 ) {
                $safe[ $key ] = substr( $value, 0, 700 ) . '...';
                continue;
            }

            $safe[ $key ] = $value;
        }

        return $safe;
    }
}
