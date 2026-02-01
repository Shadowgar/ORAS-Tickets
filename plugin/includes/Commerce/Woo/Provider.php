<?php
namespace ORAS\Tickets\Commerce\Woo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Minimal WooCommerce provider skeleton for Event Tickets integration.
 * This class intentionally implements a very small subset of functionality
 * to allow the native Event Tickets editor to CRUD tickets backed by Woo products.
 *
 * NOTE: This is a lightweight adapter — many Event Tickets features are out-of-scope
 * for this component and are intentionally not implemented here.
 */
class Provider extends \Tribe__Tickets__Tickets {

    private static ?Provider $instance = null;

    public static function init(): void {
        // Hook early on plugins_loaded so Woo is available.
        add_action( 'plugins_loaded', [ self::class, 'maybe_init' ], 5 );
    }

    public static function maybe_init(): void {
        if ( self::$instance !== null ) {
            return;
        }

        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( '\\Tribe__Tickets__Tickets' ) ) {
            return;
        }

        self::$instance = new self();
    }

    public function __construct() {
        // Identify provider for debugging/tracing in ET.
        $this->plugin_name = 'oras-tickets-woo-provider';

        // Ensure parent initialization occurs.
        if ( is_callable( [ '\\Tribe__Tickets__Tickets', '__construct' ] ) ) {
            parent::__construct();
        }

        // Provider-specific hooks could be added here if needed.
    }

    /**
     * Return an array of product IDs linked to the given event via postmeta.
     */
    public function get_tickets_ids( int $event_id, $context = null ): array {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_oras_ticket_event_id',
                    'value' => (string) $event_id,
                ],
            ],
        ];

        $posts = get_posts( $args );
        return is_array( $posts ) ? $posts : [];
    }

    /**
     * Return a Ticket_Object for the given product/ticket id if linked to event.
     */
    public function get_ticket( int $event_id, int $ticket_id ) {
        $linked = get_post_meta( $ticket_id, '_oras_ticket_event_id', true );
        if ( (string) $linked !== (string) $event_id ) {
            return false;
        }

        if ( ! function_exists( 'wc_get_product' ) ) {
            return false;
        }

        $product = wc_get_product( $ticket_id );
        if ( ! $product ) {
            return false;
        }

        $price = $product->get_regular_price();
        $desc = $product->get_description();
        $name = $product->get_name();

        $capacity = 0;
        if ( $product->managing_stock() ) {
            $capacity = (int) $product->get_stock_quantity();
        }

        $start = get_post_meta( $ticket_id, '_oras_ticket_sale_start', true );
        $end = get_post_meta( $ticket_id, '_oras_ticket_sale_end', true );

        $obj = new Ticket_Object( [
            'ID' => $ticket_id,
            'name' => $name,
            'description' => $desc,
            'price' => $price,
            'capacity' => $capacity,
            'start_date' => $start,
            'end_date' => $end,
            'provider_class' => static::class,
        ] );

        return $obj;
    }

    /**
     * Create or update a WooCommerce simple product representing the ticket.
     * $ticket is an array with fields like name, price, capacity, sale_start, sale_end, description
     */
    public function save_ticket( int $event_id, $ticket, array $raw_data = [] ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return false;
        }

        $ticket_id = isset( $ticket['ID'] ) ? absint( $ticket['ID'] ) : 0;

        if ( $ticket_id > 0 ) {
            $product = wc_get_product( $ticket_id );
            if ( ! $product ) {
                // fallback to create new
                $ticket_id = 0;
            }
        }

        if ( $ticket_id === 0 ) {
            $product = new \WC_Product_Simple();
        }

        // Name/description
        if ( isset( $ticket['name'] ) ) {
            $product->set_name( (string) $ticket['name'] );
        }
        if ( isset( $ticket['description'] ) ) {
            $product->set_description( (string) $ticket['description'] );
        }

        // Price
        if ( isset( $ticket['price'] ) ) {
            $product->set_regular_price( (string) $ticket['price'] );
        }

        // Capacity / stock
        $capacity = isset( $ticket['capacity'] ) ? absint( $ticket['capacity'] ) : 0;
        if ( $capacity > 0 ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $capacity );
        } else {
            $product->set_manage_stock( false );
        }

        // Visibility: hide product from catalog
        if ( is_callable( [ $product, 'set_catalog_visibility' ] ) ) {
            $product->set_catalog_visibility( 'hidden' );
        }

        // Save product and attach meta linking to event
        $pid = $product->save();
        if ( ! $pid ) {
            return false;
        }

        update_post_meta( $pid, '_oras_ticket_event_id', (string) $event_id );
        update_post_meta( $pid, '_oras_ticket_capacity', (string) $capacity );

        if ( isset( $ticket['sale_start'] ) ) {
            update_post_meta( $pid, '_oras_ticket_sale_start', (string) $ticket['sale_start'] );
        }
        if ( isset( $ticket['sale_end'] ) ) {
            update_post_meta( $pid, '_oras_ticket_sale_end', (string) $ticket['sale_end'] );
        }

        return (int) $pid;
    }

    /**
     * Trash the product if it is linked to the given event.
     */
    public function delete_ticket( int $event_id, int $ticket_id ): bool {
        $linked = get_post_meta( $ticket_id, '_oras_ticket_event_id', true );
        if ( (string) $linked !== (string) $event_id ) {
            return false;
        }

        if ( ! current_user_can( 'delete_post', $ticket_id ) ) {
            return false;
        }

        wp_trash_post( $ticket_id );
        return true;
    }
}
