<?php

namespace ORAS\Tickets\Commerce\Woo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Order_Autocomplete { // NOSONAR legacy WP class naming

    public function register(): void {
        add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_autocomplete' ), 30, 1 );
    }

    public function maybe_autocomplete( int $order_id ): void {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( $order->has_status( 'completed' ) ) {
            return;
        }

        if ( $order->get_meta( '_oras_autocompleted', true ) ) {
            return;
        }

        $items = $order->get_items( 'line_item' );
        if ( empty( $items ) ) {
            return;
        }

        $has_oras_ticket = false;
        foreach ( $items as $item ) {
            if ( ! method_exists( $item, 'get_product_id' ) ) {
                return;
            }

            $pid = (int) $item->get_product_id();
            if ( $pid <= 0 ) {
                continue;
            }

            $event_id = get_post_meta( $pid, '_oras_ticket_event_id', true );
            $index    = get_post_meta( $pid, '_oras_ticket_index', true );
            if ( $event_id !== '' && $index !== '' ) {
                $has_oras_ticket = true;
                continue;
            }

            return;
        }

        if ( ! $has_oras_ticket ) {
            return;
        }

        $order->update_meta_data( '_oras_autocompleted', '1' );
        $order->save();
        $order->update_status( 'completed', 'Auto-completed ORAS ticket-only order.', true );
    }
}
