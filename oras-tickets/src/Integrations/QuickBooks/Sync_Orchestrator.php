<?php

namespace ORAS\Tickets\Integrations\QuickBooks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Sync_Orchestrator {

    public const ACTION_HOOK = 'oras_tickets_qbo_sync_order';
    private const AS_GROUP   = 'oras-tickets';

    private Split_Calculator $split_calculator;
    private Journal_Entry_Creator $journal_entry_creator;
    private Retry_Handler $retry_handler;
    private QuickBooks_Logger $logger;

    public function __construct(
        ?Split_Calculator $split_calculator = null,
        ?Journal_Entry_Creator $journal_entry_creator = null,
        ?Retry_Handler $retry_handler = null,
        ?QuickBooks_Logger $logger = null
    ) {
        $this->logger               = $logger ?: new QuickBooks_Logger();
        $this->split_calculator     = $split_calculator ?: new Split_Calculator( $this->logger );
        $this->journal_entry_creator = $journal_entry_creator ?: new Journal_Entry_Creator( null, $this->logger );
        $this->retry_handler        = $retry_handler ?: new Retry_Handler( $this->logger );
    }

    public function register(): void {
        add_action( 'woocommerce_order_status_processing', array( $this, 'enqueue_order_sync' ), 10, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'enqueue_order_sync' ), 10, 1 );
        add_action( self::ACTION_HOOK, array( $this, 'sync_order_async' ), 10, 1 );
    }

    public function enqueue_order_sync( int $order_id ): void {
        if ( ! Settings::is_enabled() ) {
            return;
        }

        $order_id = absint( $order_id );
        if ( $order_id <= 0 ) {
            return;
        }

        if ( $this->has_scheduled_action( $order_id ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( $order->get_meta( '_oras_qbo_je_id', true ) ) {
            return;
        }

        $order->update_meta_data( '_oras_qbo_sync_status', 'queued' );
        $order->save();

        $this->schedule_sync( $order_id, 0 );
    }

    public function sync_order_async( int $order_id ): void {
        $this->sync_order( absint( $order_id ) );
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    public function sync_order( int $order_id, bool $force = false ) {
        if ( $order_id <= 0 ) {
            return new \WP_Error( 'oras_qbo_invalid_order_id', 'Order ID must be a positive integer.' );
        }

        if ( ! Settings::is_enabled() ) {
            return new \WP_Error( 'oras_qbo_disabled', 'QuickBooks Revenue Split Sync is disabled.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new \WP_Error( 'oras_qbo_order_not_found', 'WooCommerce order not found.' );
        }

        $status = $order->get_status();
        if ( ! in_array( $status, array( 'processing', 'completed' ), true ) ) {
            return new \WP_Error( 'oras_qbo_order_not_paid', 'Order must be processing or completed before syncing.' );
        }

        $qbo_settings = Settings::get_quickbooks_settings();
        $order_hash   = $this->build_order_hash( $order );
        $existing_je  = (string) $order->get_meta( '_oras_qbo_je_id', true );
        $existing_hash = (string) $order->get_meta( '_oras_qbo_je_hash', true );

        if ( ! $force && $existing_je !== '' && $existing_hash === $order_hash ) {
            $order->update_meta_data( '_oras_qbo_sync_status', 'synced' );
            $order->save();
            return array(
                'status' => 'already_synced',
                'je_id'  => $existing_je,
            );
        }

        if ( ! $force && $existing_je !== '' && $existing_hash !== '' && $existing_hash !== $order_hash ) {
            $order->update_meta_data( '_oras_qbo_sync_status', 'changed_after_sync' );
            $order->save();
            return new \WP_Error(
                'oras_qbo_already_synced_changed',
                'Order was already synced to QuickBooks and changed afterwards. Manual review is required.'
            );
        }

        $order->update_meta_data( '_oras_qbo_sync_status', 'syncing' );
        $order->update_meta_data( '_oras_qbo_last_attempt_at', gmdate( 'Y-m-d H:i:s' ) );
        $order->save();

        $split = $this->split_calculator->calculate( $order, $qbo_settings );
        if ( is_wp_error( $split ) ) {
            $this->handle_sync_failure( $order, $split->get_error_message() );
            return $split;
        }

        $result = $this->journal_entry_creator->create_for_order( $order, $split, $qbo_settings );
        if ( is_wp_error( $result ) ) {
            $this->handle_sync_failure( $order, $result->get_error_message() );
            return $result;
        }

        $je_id = isset( $result['je_id'] ) ? (string) $result['je_id'] : '';
        if ( $je_id === '' ) {
            $error = new \WP_Error( 'oras_qbo_missing_je_id', 'QuickBooks sync completed without a JournalEntry ID.' );
            $this->handle_sync_failure( $order, $error->get_error_message() );
            return $error;
        }

        $order->update_meta_data( '_oras_qbo_je_id', $je_id );
        $order->update_meta_data( '_oras_qbo_je_hash', $order_hash );
        $order->update_meta_data( '_oras_qbo_sync_status', 'synced' );
        $order->update_meta_data( '_oras_qbo_synced_at', gmdate( 'Y-m-d H:i:s' ) );
        $order->delete_meta_data( '_oras_qbo_sync_error' );
        $order->save();

        $this->retry_handler->mark_success( $order );

        return array(
            'status'   => 'synced',
            'order_id' => $order_id,
            'je_id'    => $je_id,
            'split'    => $split,
        );
    }

    /**
     * Queue a retry for failed entries.
     */
    public function retry_failed_orders( int $limit = 25 ): int {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return 0;
        }

        $order_ids = wc_get_orders(
            array(
                'type'       => 'shop_order',
                'limit'      => max( 1, $limit ),
                'return'     => 'ids',
                'meta_key'   => '_oras_qbo_sync_status',
                'meta_value' => 'failed',
                'orderby'    => 'date',
                'order'      => 'DESC',
            )
        );

        $count = 0;
        foreach ( $order_ids as $order_id ) {
            $order_id = absint( $order_id );
            if ( $order_id <= 0 ) {
                continue;
            }

            $this->schedule_sync( $order_id, 0 );
            $count++;
        }

        return $count;
    }

    /**
     * @param \WC_Order $order
     */
    private function handle_sync_failure( $order, string $error_message ): void {
        $order->update_meta_data( '_oras_qbo_sync_status', 'failed' );
        $order->update_meta_data( '_oras_qbo_sync_error', sanitize_text_field( $error_message ) );
        $order->save();

        $this->retry_handler->record_failure(
            $order,
            $error_message,
            function ( int $order_id, int $delay_minutes ): void {
                $this->schedule_sync( $order_id, $delay_minutes );
            }
        );
    }

    private function has_scheduled_action( int $order_id ): bool {
        if ( function_exists( 'as_has_scheduled_action' ) ) {
            $scheduled = as_has_scheduled_action( self::ACTION_HOOK, array( $order_id ), self::AS_GROUP );
            return (bool) $scheduled;
        }

        $timestamp = wp_next_scheduled( self::ACTION_HOOK, array( $order_id ) );
        return ! empty( $timestamp );
    }

    private function schedule_sync( int $order_id, int $delay_minutes ): void {
        $delay_seconds = max( 0, $delay_minutes ) * 60;

        if ( function_exists( 'as_enqueue_async_action' ) && $delay_seconds === 0 ) {
            as_enqueue_async_action( self::ACTION_HOOK, array( $order_id ), self::AS_GROUP );
            return;
        }

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action( time() + $delay_seconds, self::ACTION_HOOK, array( $order_id ), self::AS_GROUP );
            return;
        }

        wp_schedule_single_event( time() + $delay_seconds, self::ACTION_HOOK, array( $order_id ) );
    }

    /**
     * Generate deterministic hash for idempotency checks.
     */
    private function build_order_hash( $order ): string {
        $items = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $items[] = array(
                'product_id'            => method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0,
                'variation_id'          => method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0,
                'quantity'              => method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0,
                'subtotal'              => round( (float) $item->get_subtotal(), 2 ),
                'total'                 => round( (float) $item->get_total(), 2 ),
                'oras_ticket_event_id'  => (string) $item->get_meta( '_oras_ticket_event_id', true ),
                'oras_ticket_name'      => (string) $item->get_meta( '_oras_ticket_name', true ),
            );
        }

        $signature = array(
            'order_id'         => (int) $order->get_id(),
            'status'           => (string) $order->get_status(),
            'currency'         => (string) $order->get_currency(),
            'line_items_total' => round( (float) $order->get_subtotal(), 2 ),
            'order_total'      => round( (float) $order->get_total(), 2 ),
            'discount_total'   => round( (float) $order->get_discount_total(), 2 ),
            'discount_tax'     => round( (float) $order->get_discount_tax(), 2 ),
            'shipping_total'   => round( (float) $order->get_shipping_total(), 2 ),
            'tax_total'        => round( (float) $order->get_total_tax(), 2 ),
            'items'            => $items,
        );

        return hash( 'sha256', wp_json_encode( $signature ) ?: '' );
    }
}
