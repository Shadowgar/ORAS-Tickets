<?php

namespace ORAS\Tickets\Commerce\Woo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Order_Autocomplete { // NOSONAR legacy WP class naming

    public function register(): void {
        add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_autocomplete' ), 30, 1 );
        add_action( 'woocommerce_payment_complete', array( $this, 'maybe_autocomplete' ), 30, 1 );
    }

    public function maybe_autocomplete( int $order_id ): void {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( ! $this->shouldAutocompleteOrder( $order ) ) {
            return;
        }

        $order->update_meta_data( '_oras_autocompleted', '1' );
        $order->save();
        $order->update_status( 'completed', 'Auto-completed ORAS ticket-only order.', true );
    }

    private function shouldAutocompleteOrder( $order ): bool {
        $should_autocomplete = true;

        if ( $order->has_status( 'completed' ) || $order->get_meta( '_oras_autocompleted', true ) ) {
            $should_autocomplete = false;
        }

        if ( $should_autocomplete && ( ! method_exists( $order, 'is_paid' ) || ! $order->is_paid() ) ) {
            $should_autocomplete = false;
        }

        $items = $should_autocomplete ? $order->get_items( 'line_item' ) : array();
        if ( $should_autocomplete && empty( $items ) ) {
            $should_autocomplete = false;
        }

        if ( $should_autocomplete ) {
            $analysis = $this->analyzeOrderItems( $items );
            if ( empty( $analysis['all_virtual'] ) ) {
                $should_autocomplete = ! empty( $analysis['has_oras_ticket'] ) && empty( $analysis['has_disallowed_non_ticket'] );
            }
        }

        return $should_autocomplete;
    }

    /**
     * @param array<int,mixed> $items
     * @return array{has_oras_ticket:bool,all_virtual:bool,has_disallowed_non_ticket:bool}
     */
    private function analyzeOrderItems( array $items ): array {
        $has_oras_ticket = false;
        $all_virtual = true;
        $has_disallowed_non_ticket = false;

        foreach ( $items as $item ) {
            if ( ! method_exists( $item, 'get_product_id' ) ) {
                return array(
                    'has_oras_ticket' => false,
                    'all_virtual' => false,
                    'has_disallowed_non_ticket' => true,
                );
            }

            $pid = (int) $item->get_product_id();
            if ( $pid <= 0 ) {
                continue;
            }

            if ( $this->isOrasTicket( $pid ) ) {
                $has_oras_ticket = true;
                continue;
            }

            $product    = wc_get_product( $pid );
            $is_virtual = ( $product && method_exists( $product, 'is_virtual' ) && $product->is_virtual() );
            if ( ! $is_virtual ) {
                $all_virtual = false;
            }

            if ( $this->isAllowedNonTicket( $pid ) ) {
                continue;
            }

            if ( ! $is_virtual ) {
                $has_disallowed_non_ticket = true;
            }
        }

        return array(
            'has_oras_ticket' => $has_oras_ticket,
            'all_virtual' => $all_virtual,
            'has_disallowed_non_ticket' => $has_disallowed_non_ticket,
        );
    }

    private function isOrasTicket( int $product_id ): bool {
        $event_id = get_post_meta( $product_id, '_oras_ticket_event_id', true );
        $index    = get_post_meta( $product_id, '_oras_ticket_index', true );
        return $event_id !== '' && $index !== '';
    }

    private function isAllowedNonTicket( int $product_id ): bool {
        $is_allowed = false;

        $bucket = sanitize_key( (string) get_post_meta( $product_id, '_oras_qbo_bucket', true ) );
        if ( in_array( $bucket, array( 'donation', 'donations', 'observer', 'observer_pass' ), true ) ) {
            $is_allowed = true;
        }

        if ( ! $is_allowed && get_post_meta( $product_id, '_donable', true ) === 'yes' ) {
            $is_allowed = true;
        }

        if ( ! $is_allowed ) {
            $terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'slugs' ) );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                foreach ( $terms as $slug ) {
                    if ( stripos( $slug, 'donation' ) !== false || stripos( $slug, 'observer' ) !== false ) {
                        $is_allowed = true;
                        break;
                    }
                }
            }
        }

        return $is_allowed;
    }
}
