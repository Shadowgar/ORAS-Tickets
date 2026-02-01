<?php
namespace ORAS\Tickets\Commerce\Woo;

use ORAS\Tickets\Domain\Ticket_Collection;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Product_Sync {

    /** Running flags per post to prevent recursion */
    private static array $running = [];

    public function register(): void {
        add_action( 'save_post_tribe_events', [ $this, 'on_save_event' ], 30, 3 );
    }

    /**
     * Return true if the given product ID is a valid mapping for this event/index.
     */
    private function is_valid_mapped_product( int $product_id, int $event_id, int $index ): bool {
        if ( $product_id <= 0 ) {
            return false;
        }

        if ( ! function_exists( 'wc_get_product' ) ) {
            return false;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        $linked = get_post_meta( $product_id, '_oras_ticket_event_id', true );
        $mapped_index = get_post_meta( $product_id, '_oras_ticket_index', true );

        if ( (string) $linked !== (string) $event_id ) {
            return false;
        }

        if ( (string) $mapped_index !== (string) $index ) {
            return false;
        }

        return true;
    }

    /**
     * Return an existing mapped product instance if valid, otherwise create a new simple product instance.
     * Does not persist new product to DB.
     *
     * @param int $event_id
     * @param int $index
     * @param array $old_map
     * @return \WC_Product
     */
    private function get_or_create_product( int $event_id, int $index, array $old_map ): \WC_Product {
        $key = (string) $index;
        $existing_pid = isset( $old_map[ $key ] ) ? absint( $old_map[ $key ] ) : 0;

        if ( $existing_pid > 0 && $this->is_valid_mapped_product( $existing_pid, $event_id, $index ) ) {
            $prod = function_exists( 'wc_get_product' ) ? wc_get_product( $existing_pid ) : null;
            if ( $prod ) {
                return $prod;
            }
        }

        return new \WC_Product_Simple();
    }

    public function on_save_event( int $post_id, \WP_Post $post, bool $update ): void {
        // Guards
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        if ( isset( self::$running[ $post_id ] ) && self::$running[ $post_id ] ) {
            return;
        }
        self::$running[ $post_id ] = true;

        try {
            $collection = Ticket_Collection::load_for_event( $post_id );

            $old_map = get_post_meta( $post_id, '_oras_tickets_woo_map_v1', true );
            if ( ! is_array( $old_map ) ) {
                $old_map = [];
            }

            // If no tickets, clear mapping and return
            if ( $collection->count() === 0 ) {
                update_post_meta( $post_id, '_oras_tickets_woo_map_v1', [] );
                self::$running[ $post_id ] = false;
                return;
            }

            $new_map = [];

            $tickets = $collection->all();
            foreach ( $tickets as $index => $ticket_obj ) {
                $idx = (string) $index;

                // Obtain an existing mapped product only if it is valid for this event/index.
                $product = $this->get_or_create_product( (int) $post_id, $index, $old_map );

                $ticket = method_exists( $ticket_obj, 'to_array' ) ? $ticket_obj->to_array() : [];

                // Name & description
                if ( isset( $ticket['name'] ) && method_exists( $product, 'set_name' ) ) {
                    $product->set_name( (string) $ticket['name'] );
                }
                if ( isset( $ticket['description'] ) && method_exists( $product, 'set_description' ) ) {
                    $product->set_description( (string) $ticket['description'] );
                }

                // Price: normalize numeric values to two-decimal string, otherwise preserve string.
                if ( isset( $ticket['price'] ) && method_exists( $product, 'set_regular_price' ) ) {
                    $price_raw = $ticket['price'];
                    if ( is_numeric( $price_raw ) ) {
                        $price_val = number_format( (float) $price_raw, 2, '.', '' );
                    } else {
                        $price_val = (string) $price_raw;
                    }
                    $product->set_regular_price( $price_val );
                }

                // Sale dates (if provided): expect 'Y-m-d H:i' storage format
                $sale_start = isset( $ticket['sale_start'] ) ? (string) $ticket['sale_start'] : '';
                $sale_end = isset( $ticket['sale_end'] ) ? (string) $ticket['sale_end'] : '';
                if ( $sale_start !== '' && method_exists( $product, 'set_date_on_sale_from' ) ) {
                    $product->set_date_on_sale_from( $sale_start );
                } elseif ( method_exists( $product, 'set_date_on_sale_from' ) ) {
                    $product->set_date_on_sale_from( null );
                }
                if ( $sale_end !== '' && method_exists( $product, 'set_date_on_sale_to' ) ) {
                    $product->set_date_on_sale_to( $sale_end );
                } elseif ( method_exists( $product, 'set_date_on_sale_to' ) ) {
                    $product->set_date_on_sale_to( null );
                }

                // Virtual / visibility / status
                if ( method_exists( $product, 'set_virtual' ) ) {
                    $product->set_virtual( true );
                }
                if ( method_exists( $product, 'set_catalog_visibility' ) ) {
                    $product->set_catalog_visibility( 'hidden' );
                }
                if ( method_exists( $product, 'set_status' ) ) {
                    $product->set_status( 'private' );
                }

                // Stock / capacity: always apply capacity rules so mapped products are updated.
                $capacity = isset( $ticket['capacity'] ) ? absint( $ticket['capacity'] ) : 0;
                if ( $capacity > 0 ) {
                    if ( method_exists( $product, 'set_manage_stock' ) ) {
                        $product->set_manage_stock( true );
                    }
                    if ( method_exists( $product, 'set_stock_quantity' ) ) {
                        $product->set_stock_quantity( (int) $capacity );
                    }
                    if ( method_exists( $product, 'set_stock_status' ) ) {
                        $product->set_stock_status( 'instock' );
                    }
                    if ( method_exists( $product, 'set_backorders' ) ) {
                        $product->set_backorders( 'no' );
                    }
                } else {
                    if ( method_exists( $product, 'set_manage_stock' ) ) {
                        $product->set_manage_stock( false );
                    }
                    // Clear stock quantity when not managing stock; set to 0 to avoid stale values.
                    if ( method_exists( $product, 'set_stock_quantity' ) ) {
                        $product->set_stock_quantity( 0 );
                    }
                    if ( method_exists( $product, 'set_stock_status' ) ) {
                        $product->set_stock_status( 'instock' );
                    }
                }

                // Save product
                $pid = $product->save();
                if ( ! $pid ) {
                    continue;
                }

                // Link meta
                update_post_meta( $pid, '_oras_ticket_event_id', (int) $post_id );
                update_post_meta( $pid, '_oras_ticket_index', (int) $index );

                $new_map[ $idx ] = (int) $pid;
            }

            // Removed tickets: draft products that no longer map
            foreach ( $old_map as $old_idx => $old_pid ) {
                $old_idx = (string) $old_idx;
                if ( isset( $new_map[ $old_idx ] ) ) {
                    continue;
                }

                $old_pid = absint( $old_pid );
                if ( $old_pid <= 0 ) {
                    continue;
                }

                // Set to draft but keep product record
                wp_update_post( [ 'ID' => $old_pid, 'post_status' => 'draft' ] );
            }

            // Persist mapping
            update_post_meta( $post_id, '_oras_tickets_woo_map_v1', $new_map );
        } finally {
            self::$running[ $post_id ] = false;
        }
    }
}
