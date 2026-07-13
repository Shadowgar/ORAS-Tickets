<?php

namespace ORAS\Tickets\Commerce\Woo;

use ORAS\Tickets\Domain\Pricing\Price_Resolver;
use ORAS\Tickets\Domain\Ticket;
use ORAS\Tickets\Domain\Ticket_Collection;
use ORAS\Tickets\Event_Question_Attention_Store;
use ORAS\Tickets\Event_Questions;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Product_Sync { // NOSONAR legacy WP class naming



    /** Running flags per post to prevent recursion */
    private static array $running = array();

    public function register(): void {
        add_action( 'save_post_tribe_events', array( $this, 'on_save_event' ), 30, 3 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'snapshot_order_item_ticket_meta' ), 10, 4 );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'generate_order_attention_items' ), 20, 3 );
    }

    /**
     * Snapshot ORAS ticket context onto Woo order items.
     *
     * @param \WC_Order_Item_Product $item
     * @param string                 $cart_item_key
     * @param array                  $values
     * @param \WC_Order              $order
     */
    public function snapshot_order_item_ticket_meta( $item, string $cart_item_key, array $values, $order ): void {
        if ( ! $item || ! method_exists( $item, 'get_product_id' ) ) {
            return;
        }

        $product_id = (int) $item->get_product_id();
        if ( $product_id <= 0 ) {
            return;
        }

        $event_id_raw = get_post_meta( $product_id, '_oras_ticket_event_id', true );
        $index_raw    = get_post_meta( $product_id, '_oras_ticket_index', true );
        if ( $event_id_raw === '' || $index_raw === '' ) {
            return;
        }

        $event_id = (int) $event_id_raw;
        $index    = (int) $index_raw;
        if ( $event_id <= 0 || $index < 0 ) {
            return;
        }

        $ticket_name = $this->get_ticket_name_for_event_index( $event_id, $index );
        if ( $ticket_name === '' ) {
            $ticket_name = $item->get_name();
        }

        $collection  = Ticket_Collection::load_for_event( $event_id );
        $tickets     = $collection->all();
        $ticket_data = array();
        if ( array_key_exists( $index, $tickets ) ) {
            $ticket_obj  = $tickets[ $index ];
            $ticket_data = $ticket_obj->to_array();
        }

        $resolved        = ! empty( $ticket_data ) ? Price_Resolver::resolve_ticket_price( $ticket_data ) : array();
        $attendance_mode = Ticket::normalizeAttendanceMode(
            isset( $ticket_data['attendance_mode'] ) ? (string) $ticket_data['attendance_mode'] : (string) get_post_meta( $product_id, '_oras_ticket_attendance_mode', true ),
            Ticket::ATTENDANCE_MODE_VIRTUAL
        );
        $phase_key   = isset( $resolved['phase_key'] ) && is_string( $resolved['phase_key'] ) ? $resolved['phase_key'] : '';
        $phase_label = isset( $resolved['phase_label'] ) && is_string( $resolved['phase_label'] ) ? $resolved['phase_label'] : '';
        $phase_price = isset( $resolved['price'] ) ? $resolved['price'] : '';
        $has_phase   = ( $phase_key !== '' || $phase_label !== '' );
        $base_price  = '';
        if ( isset( $ticket_data['price'] ) ) {
            $base_price_raw = $ticket_data['price'];
            $base_price     = is_numeric( $base_price_raw )
                ? number_format( (float) $base_price_raw, 2, '.', '' )
                : (string) $base_price_raw;
        }
        $phase_price_differs = false;
        if ( is_numeric( $phase_price ) && is_numeric( $base_price ) ) {
            $phase_price_differs = abs( (float) $phase_price - (float) $base_price ) > 0.0001;
        }

        $quantity   = method_exists( $item, 'get_quantity' ) ? max( 1, (int) $item->get_quantity() ) : 1;
        $subtotal   = method_exists( $item, 'get_subtotal' ) ? (float) $item->get_subtotal() : 0.0;
        $unit_price = $subtotal / $quantity;

        $item->add_meta_data( '_oras_ticket_event_id', (string) $event_id, true );
        $item->add_meta_data( '_oras_ticket_index', (string) $index, true );
        $item->add_meta_data( '_oras_ticket_name', $ticket_name, true );
        $item->add_meta_data( '_oras_ticket_unit_price', wc_format_decimal( $unit_price, wc_get_price_decimals() ), true );
        $item->add_meta_data( '_oras_ticket_currency', get_woocommerce_currency(), true );
        $item->add_meta_data( '_oras_ticket_attendance_mode', $attendance_mode, true );
        $item->add_meta_data( '_oras_ticket_schema', '1', true );

        if ( isset( $values[ Event_Questions::CART_ITEM_KEY ] ) && is_array( $values[ Event_Questions::CART_ITEM_KEY ] ) ) {
            $answers = $values[ Event_Questions::CART_ITEM_KEY ];
            $item->add_meta_data( Event_Questions::ORDER_ITEM_KEY, $answers, true );
            $summary = Event_Questions::snapshots_to_label_map( $answers );
            if ( ! empty( $summary ) ) {
                $item->add_meta_data( '_oras_event_question_summary', $summary, true );
            }
        }

        if ( $has_phase ) {
            if ( $phase_key !== '' ) {
                $item->add_meta_data( '_oras_ticket_price_phase_key', $phase_key, true );
            }
            if ( $phase_label !== '' ) {
                $item->add_meta_data( '_oras_ticket_price_phase_label', $phase_label, true );
            }
            if ( is_numeric( $phase_price ) ) {
                $item->add_meta_data( '_oras_ticket_price_phase_price', wc_format_decimal( $phase_price, wc_get_price_decimals() ), true );
            }
        } elseif ( $phase_price_differs && is_numeric( $phase_price ) ) {
            $item->add_meta_data( '_oras_ticket_price_phase_price', wc_format_decimal( $phase_price, wc_get_price_decimals() ), true );
        }
    }

    /**
     * Generate attention items after order item IDs exist.
     *
     * @param int       $order_id
     * @param array     $posted_data
     * @param \WC_Order $order
     */
    public function generate_order_attention_items( int $order_id, array $posted_data, $order ): void {
        unset( $posted_data );

        if ( $order_id <= 0 || ! $order || ! method_exists( $order, 'get_items' ) ) {
            return;
        }

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! $item || ! method_exists( $item, 'get_meta' ) ) {
                continue;
            }

            $event_id = absint( $item->get_meta( '_oras_ticket_event_id', true ) );
            if ( $event_id <= 0 ) {
                continue;
            }

            $answers = $item->get_meta( Event_Questions::ORDER_ITEM_KEY, true );
            if ( ! is_array( $answers ) || empty( $answers ) ) {
                continue;
            }

            $attendance_mode = Ticket::normalizeAttendanceMode(
                (string) $item->get_meta( '_oras_ticket_attendance_mode', true ),
                Ticket::ATTENDANCE_MODE_ONSITE
            );
            $questions = Event_Questions::filter_questions(
                Event_Questions::load_definitions( $event_id ),
                Event_Questions::APPLIES_TICKETS,
                $attendance_mode
            );
            if ( empty( $questions ) ) {
                continue;
            }

            Event_Question_Attention_Store::upsert_for_answer_snapshots(
                $event_id,
                'ticket',
                'order:' . $order_id . ':item:' . absint( $item_id ),
                array(
                    'order_id'      => $order_id,
                    'order_item_id' => absint( $item_id ),
                    'attendee_name' => method_exists( $order, 'get_formatted_billing_full_name' ) ? (string) $order->get_formatted_billing_full_name() : '',
                    'email'         => method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '',
                ),
                $questions,
                $answers
            );
        }
    }

    /**
     * Fetch ticket name for an event/index from the ticket envelope.
     */
    private function get_ticket_name_for_event_index( int $event_id, int $index ): string {
        if ( $event_id <= 0 || $index < 0 ) {
            return '';
        }

        $collection = Ticket_Collection::load_for_event( $event_id );
        $tickets    = $collection->all();

        if ( ! array_key_exists( $index, $tickets ) ) {
            return '';
        }

        $ticket_obj = $tickets[ $index ];
        $ticket     = $ticket_obj->to_array();

        if ( isset( $ticket['name'] ) && $ticket['name'] !== '' ) {
            return (string) $ticket['name'];
        }

        return '';
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

        $linked       = get_post_meta( $product_id, '_oras_ticket_event_id', true );
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
     * @param int   $event_id
     * @param int   $index
     * @param array $old_map
     * @return \WC_Product
     */
    private function get_or_create_product( int $event_id, int $index, array $old_map ): \WC_Product {
        $key          = (string) $index;
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
                $old_map = array();
            }

            // If no tickets, clear mapping and return
            if ( $collection->count() === 0 ) {
                update_post_meta( $post_id, '_oras_tickets_woo_map_v1', array() );
                self::$running[ $post_id ] = false;
                return;
            }

            $new_map = array();

            $tickets = $collection->all();
            foreach ( $tickets as $index => $ticket_obj ) {
                $idx = (string) $index;

                // Obtain an existing mapped product only if it is valid for this event/index.
                $product = $this->get_or_create_product( (int) $post_id, $index, $old_map );

                $ticket = method_exists( $ticket_obj, 'to_array' ) ? $ticket_obj->to_array() : array();

                // Name & description
                if ( isset( $ticket['name'] ) ) {
                    $product->set_name( (string) $ticket['name'] );
                }
                if ( isset( $ticket['description'] ) ) {
                    $product->set_description( (string) $ticket['description'] );
                }

                // Price: normalize numeric values to two-decimal string, otherwise preserve string.
                if ( isset( $ticket['price'] ) ) {
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
                $sale_end   = isset( $ticket['sale_end'] ) ? (string) $ticket['sale_end'] : '';
                if ( $sale_start !== '' ) {
                    $product->set_date_on_sale_from( $sale_start );
                } else {
                    $product->set_date_on_sale_from( null );
                }
                if ( $sale_end !== '' ) {
                    $product->set_date_on_sale_to( $sale_end );
                } else {
                    $product->set_date_on_sale_to( null );
                }

                // Virtual / visibility / status
                $product->set_virtual( true );
                $product->set_catalog_visibility( 'hidden' );
                // Must be publish for Woo to treat the product as purchasable.
                $product->set_status( 'publish' );

                // Stock / capacity: always apply capacity rules so mapped products are updated.
                $capacity     = isset( $ticket['capacity'] ) ? $ticket['capacity'] : 0;
                $capacity_int = max( 0, (int) $capacity );
                if ( $capacity_int > 0 ) {
                    $product->set_manage_stock( true );
                    $product->set_stock_quantity( $capacity_int );
                    $product->set_stock_status( $capacity_int > 0 ? 'instock' : 'outofstock' );
                    $product->set_backorders( 'no' );
                } else {
                    $product->set_manage_stock( false );
                    $product->set_stock_quantity( 0 );
                    $product->set_stock_status( 'instock' );
                    $product->set_backorders( 'no' );
                }

                // Save product
                $pid = $product->save();
                if ( ! $pid ) {
                    continue;
                }

                $attendance_mode = Ticket::normalizeAttendanceMode(
                    isset( $ticket['attendance_mode'] ) ? (string) $ticket['attendance_mode'] : '',
                    Ticket::ATTENDANCE_MODE_VIRTUAL
                );

                // Link meta
                update_post_meta( $pid, '_oras_ticket_event_id', (int) $post_id );
                update_post_meta( $pid, '_oras_ticket_index', (int) $index );
                update_post_meta( $pid, '_oras_ticket_attendance_mode', $attendance_mode );

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
                wp_update_post(
                    array(
                        'ID'          => $old_pid,
                        'post_status' => 'draft',
                    )
                );
            }

            // Persist mapping
            update_post_meta( $post_id, '_oras_tickets_woo_map_v1', $new_map );
        } finally {
            self::$running[ $post_id ] = false;
        }
    }
}
