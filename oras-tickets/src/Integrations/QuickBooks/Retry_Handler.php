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
    public function record_failure( $order, string $error_message, callable $schedule_retry_callback ): void {
        $attempts = (int) $order->get_meta( '_oras_qbo_retry_count', true );
        $attempts++;

        $order->update_meta_data( '_oras_qbo_retry_count', (string) $attempts );
        $order->update_meta_data( '_oras_qbo_sync_error', sanitize_text_field( $error_message ) );

        if ( $attempts < self::MAX_ATTEMPTS ) {
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
            'QuickBooks sync exhausted retry attempts',
            array(
                'order_id'      => (int) $order->get_id(),
                'retry_attempt' => $attempts,
                'error'         => $error_message,
            )
        );
    }

    /**
     * @param \WC_Order $order
     */
    public function mark_success( $order ): void {
        $order->update_meta_data( '_oras_qbo_retry_count', '0' );
        $order->delete_meta_data( '_oras_qbo_sync_error' );
        $order->save();
    }
}
