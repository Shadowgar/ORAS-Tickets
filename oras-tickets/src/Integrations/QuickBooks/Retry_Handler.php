<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Retry_Handler {

    private const MAX_ATTEMPTS = 3;

    private QuickBooks_Logger $logger;

    public function __construct( ?QuickBooks_Logger $logger = null ) {
        $this->logger = $logger ?: new QuickBooks_Logger();
    }

    /**
     * @param \WC_Order $order
     * @param callable(int,int):void $schedule_retry_callback
     */
    public function record_failure( $order, string $error_message, string $error_code, bool $should_retry, callable $schedule_retry_callback ): void {
        $attempts = (int) $order->get_meta( '_oras_qbo_retry_count', true );
        $attempts++;

        $order->update_meta_data( '_oras_qbo_retry_count', (string) $attempts );
        $order->update_meta_data( '_oras_qbo_sync_error_code', sanitize_text_field( $error_code ) );
        $order->update_meta_data( '_oras_qbo_sync_error', sanitize_text_field( $error_message ) );

        if ( $should_retry && $attempts < self::MAX_ATTEMPTS ) {
            $order->update_meta_data( '_oras_qbo_sync_status', 'retrying' );
            $order->save();

            $delay_minutes = min( 30, 5 * $attempts );
            $schedule_retry_callback( (int) $order->get_id(), $delay_minutes );

            $this->logger->warning(
                'Scheduled retry for QuickBooks sync failure',
                array(
                    'order_id'      => (int) $order->get_id(),
                    'retry_attempt' => $attempts,
                    'delay_minutes' => $delay_minutes,
                )
            );

            return;
        }

        $order->update_meta_data( '_oras_qbo_sync_status', 'failed' );
        $order->save();

        $this->logger->error(
            $should_retry ? 'QuickBooks sync exhausted retry attempts' : 'QuickBooks sync failure is non-retriable',
            array(
                'order_id'      => (int) $order->get_id(),
                'retry_attempt' => $attempts,
                'error'         => $error_message,
                'error_code'    => $error_code,
                'retriable'     => $should_retry,
            )
        );
    }

    /**
     * @param \WC_Order $order
     */
    public function mark_success( $order ): void {
        $order->update_meta_data( '_oras_qbo_retry_count', '0' );
        $order->delete_meta_data( '_oras_qbo_sync_error_code' );
        $order->delete_meta_data( '_oras_qbo_sync_error' );
        $order->save();
    }

    public static function should_retry_error( string $error_code, $error_data = null ): bool {
        if ( $error_code === 'http_request_failed' || $error_code === 'oras_qbo_network_error' ) {
            return true;
        }

        if ( strpos( $error_code, 'oras_qbo_api_http_' ) === 0 ) {
            $status = (int) substr( $error_code, strlen( 'oras_qbo_api_http_' ) );
            if ( $status === 429 ) {
                return true;
            }

            return $status >= 500 && $status <= 599;
        }

        if ( is_array( $error_data ) && array_key_exists( 'retriable', $error_data ) ) {
            return ! empty( $error_data['retriable'] );
        }

        return false;
    }
}
