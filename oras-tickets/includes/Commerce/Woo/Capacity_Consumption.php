<?php

namespace ORAS\Tickets\Commerce\Woo;

use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;
use ORAS\Tickets\Support\DbLock;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Capacity_Consumption { // NOSONAR legacy WP class naming



    public function register(): void {
        add_action( 'woocommerce_order_status_processing', array( $this, 'handle_paid_order' ), 10, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'handle_paid_order' ), 10, 1 );
        add_action( 'woocommerce_order_status_cancelled', array( $this, 'handle_restore_order' ), 10, 1 );
        add_action( 'woocommerce_order_status_refunded', array( $this, 'handle_restore_order' ), 10, 1 );
    }

    /**
     * Consume capacity for ORAS ticket line items when the order is paid.
     *
     * @param int $order_id
     */
    public function handle_paid_order( int $order_id ): void {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        DbLock::withLock(
            'order:' . $order_id,
            function () use ( $order_id ): void {
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                    return;
                }

                if ( $order->get_meta( '_oras_capacity_consumed', true ) ) {
                    return;
                }

                $changes_by_event = $this->collect_order_ticket_changes( $order );
                if ( ! empty( $changes_by_event ) ) {
                    $this->apply_capacity_changes( $changes_by_event, false );
                }

                $order->update_meta_data( '_oras_capacity_consumed', '1' );
                $order->save();
            }
        );
    }

    /**
     * Restore capacity for ORAS ticket line items when the order is cancelled/refunded.
     *
     * @param int $order_id
     */
    public function handle_restore_order( int $order_id ): void {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        DbLock::withLock(
            'order:' . $order_id,
            function () use ( $order_id ): void {
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                    return;
                }

                if ( ! $order->get_meta( '_oras_capacity_consumed', true ) ) {
                    return;
                }

                if ( $order->get_meta( '_oras_capacity_restored', true ) ) {
                    return;
                }

                $changes_by_event = $this->collect_order_ticket_changes( $order );
                if ( ! empty( $changes_by_event ) ) {
                    $this->apply_capacity_changes( $changes_by_event, true );
                }

                $order->update_meta_data( '_oras_capacity_restored', '1' );
                $order->save();
            }
        );
    }

    /**
     * @param \WC_Order $order
     * @return array<int, array<int, array{quantity:int, product_ids:array<int,int>}>>
     */
    private function collect_order_ticket_changes( \WC_Order $order ): array {
        $changes_by_event = array();

        $items = $order->get_items( 'line_item' );
        foreach ( $items as $item ) {
            if ( ! $item || ! method_exists( $item, 'get_product_id' ) ) {
                continue;
            }

            $product_id = (int) $item->get_product_id();
            if ( $product_id <= 0 ) {
                continue;
            }

            $event_id = 0;
            $index    = -1;

            $event_id_raw = get_post_meta( $product_id, '_oras_ticket_event_id', true );
            $index_raw    = get_post_meta( $product_id, '_oras_ticket_index', true );

            if ( $event_id_raw !== '' && $index_raw !== '' ) {
                $event_id = (int) $event_id_raw;
                $index    = (int) $index_raw;
            } else {
                $event_id_fallback = $item->get_meta( '_oras_ticket_event_id', true );
                $index_fallback    = $item->get_meta( '_oras_ticket_index', true );
                if ( $event_id_fallback !== '' && $index_fallback !== '' ) {
                    $event_id = (int) $event_id_fallback;
                    $index    = (int) $index_fallback;
                }
            }

            if ( $event_id <= 0 || $index < 0 ) {
                continue;
            }

            $quantity = method_exists( $item, 'get_quantity' ) ? max( 0, (int) $item->get_quantity() ) : 0;
            if ( $quantity <= 0 ) {
                continue;
            }

            if ( ! isset( $changes_by_event[ $event_id ] ) ) {
                $changes_by_event[ $event_id ] = array();
            }

            if ( ! isset( $changes_by_event[ $event_id ][ $index ] ) ) {
                $changes_by_event[ $event_id ][ $index ] = array(
                    'quantity'    => 0,
                    'product_ids' => array(),
                );
            }

            $changes_by_event[ $event_id ][ $index ]['quantity'] += $quantity;
            if ( ! in_array( $product_id, $changes_by_event[ $event_id ][ $index ]['product_ids'], true ) ) {
                $changes_by_event[ $event_id ][ $index ]['product_ids'][] = $product_id;
            }
        }

        return $changes_by_event;
    }

    /**
     * @param array<int, array<int, array{quantity:int, product_ids:array<int,int>}>> $changes_by_event
     */
    private function apply_capacity_changes( array $changes_by_event, bool $restore ): void {
        $event_ids = array_keys( $changes_by_event );
        sort( $event_ids, SORT_NUMERIC );

        foreach ( $event_ids as $event_id ) {
            $event_id = (int) $event_id;
            if ( $event_id <= 0 || ! isset( $changes_by_event[ $event_id ] ) || ! is_array( $changes_by_event[ $event_id ] ) ) {
                continue;
            }

            DbLock::forEvent(
                $event_id,
                function () use ( $event_id, $changes_by_event, $restore ): void {
                    $raw = get_post_meta( $event_id, Meta::META_KEY_TICKETS, true );
                    if ( ! is_array( $raw ) ) {
                        return;
                    }

                    $schema = isset( $raw['schema'] ) ? (int) $raw['schema'] : 1;
                    if ( 1 !== $schema ) {
                        return;
                    }

                    $tickets = isset( $raw['tickets'] ) && is_array( $raw['tickets'] ) ? $raw['tickets'] : array();
                    $event_changed = false;

                    foreach ( $changes_by_event[ $event_id ] as $index => $change ) {
                        $index = (int) $index;
                        if ( $index < 0 || ! array_key_exists( $index, $tickets ) || ! is_array( $tickets[ $index ] ) ) {
                            continue;
                        }

                        $capacity = isset( $tickets[ $index ]['capacity'] ) ? absint( $tickets[ $index ]['capacity'] ) : 0;
                        if ( $capacity <= 0 ) {
                            continue;
                        }

                        $quantity = isset( $change['quantity'] ) ? max( 0, (int) $change['quantity'] ) : 0;
                        if ( $quantity <= 0 ) {
                            continue;
                        }

                        $updated_capacity = $restore ? ( $capacity + $quantity ) : max( 0, $capacity - $quantity );
                        $tickets[ $index ]['capacity'] = $updated_capacity;
                        $event_changed = true;

                        $product_ids = isset( $change['product_ids'] ) && is_array( $change['product_ids'] ) ? $change['product_ids'] : array();
                        foreach ( $product_ids as $product_id ) {
                            $this->sync_product_stock( (int) $product_id, $updated_capacity );
                        }
                    }

                    if ( ! $event_changed ) {
                        return;
                    }

                    Ticket_Collection::save_for_event(
                        $event_id,
                        array(
                            'schema'  => 1,
                            'tickets' => $tickets,
                        )
                    );
                }
            );
        }
    }

    private function sync_product_stock( int $product_id, int $remaining ): void {
        if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
            return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        $product->set_manage_stock( true );
        if ( method_exists( $product, 'set_stock_quantity' ) ) {
            $product->set_stock_quantity( $remaining );
        }
        if ( method_exists( $product, 'set_stock_status' ) ) {
            $product->set_stock_status( $remaining > 0 ? 'instock' : 'outofstock' );
        }
        if ( method_exists( $product, 'set_backorders' ) ) {
            $product->set_backorders( 'no' );
        }

        $product->save();
    }
}
