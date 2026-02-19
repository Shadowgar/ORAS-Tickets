<?php

/**
 * REST API endpoints for member ticket listings.
 *
 * @package ORAS\Tickets
 */

namespace ORAS\Tickets\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Member Hub tickets REST controller.
 */
final class Member_Hub_Tickets { // NOSONAR legacy WP class naming



    /**
     * Register API hooks.
     */
    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST routes.
     */
    public function register_routes(): void {
        register_rest_route(
            'oras-tickets/v1',
            '/me/tickets',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_my_tickets' ),
                'permission_callback' => 'is_user_logged_in',
                'args'                => array(
                    'scope'    => array(
                        'default'           => 'upcoming',
                        'sanitize_callback' => array( $this, 'sanitize_scope' ),
                    ),
                    'group_by' => array(
                        'default'           => 'none',
                        'sanitize_callback' => array( $this, 'sanitize_group_by' ),
                    ),
                    'page'     => array(
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                    ),
                    'per_page' => array(
                        'default'           => 20,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        register_rest_route(
            'oras-tickets/v1',
            '/me/tickets/summary',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_my_tickets_summary' ),
                'permission_callback' => 'is_user_logged_in',
            )
        );
    }

    /**
     * Fetch paged ticket items for the current user.
     *
     * @param \WP_REST_Request $request REST request.
     */
    public function get_my_tickets( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = (int) get_current_user_id();
        if ( $user_id <= 0 ) {
            return rest_ensure_response(
                array(
                    'items'       => array(),
                    'page'        => 1,
                    'per_page'    => 0,
                    'order_count' => 0,
                    'order_pages' => 0,
                )
            );
        }

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return rest_ensure_response(
                array(
                    'items'       => array(),
                    'page'        => 1,
                    'per_page'    => 0,
                    'order_count' => 0,
                    'order_pages' => 0,
                )
            );
        }

        $scope    = $this->sanitize_scope( $request->get_param( 'scope' ) );
        $group_by = $this->sanitize_group_by( $request->get_param( 'group_by' ) );
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

        $paged     = $this->get_paged_orders( $user_id, $page, $per_page );
        $orders    = $paged['orders'];
        $total     = $paged['total'];
        $max_pages = $paged['max_pages'];

        $now             = (int) time();
        $scope_for_items = 'event' === $group_by ? 'all' : $scope;
        $grouped         = $this->get_ticket_groups( $orders, $scope_for_items, $now );

        if ( 'event' === $group_by ) {
            $events = $this->group_items_by_event( $grouped['items'], $now );

            return rest_ensure_response(
                array(
                    'upcoming' => $events['upcoming'],
                    'past'     => $events['past'],
                    'meta'     => array(
                        'page'        => $page,
                        'per_page'    => $per_page,
                        'order_count' => $total,
                        'order_pages' => $max_pages,
                    ),
                )
            );
        }

        return rest_ensure_response(
            array(
                'items'       => $grouped['items'],
                'page'        => $page,
                'per_page'    => $per_page,
                'order_count' => $total,
                'order_pages' => $max_pages,
            )
        );
    }

    /**
     * Fetch summary counters for the current user.
     */
    public function get_my_tickets_summary(): \WP_REST_Response {
        $user_id = (int) get_current_user_id();
        if ( $user_id <= 0 ) {
            return rest_ensure_response(
                array(
                    'upcoming_count'     => 0,
                    'past_count'         => 0,
                    'last_purchase_date' => null,
                )
            );
        }

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return rest_ensure_response(
                array(
                    'upcoming_count'     => 0,
                    'past_count'         => 0,
                    'last_purchase_date' => null,
                )
            );
        }

        $orders  = $this->get_all_orders( $user_id );
        $now     = (int) time();
        $grouped = $this->get_ticket_groups( $orders, 'all', $now );

        $upcoming = 0;
        $past     = 0;
        foreach ( $grouped['items'] as $item ) {
            $bucket = $this->bucket_event( (int) $item['event_id'], $item['event_start'], $now );
            if ( 'past' === $bucket ) {
                ++$past;
            } else {
                ++$upcoming;
            }
        }

        $last_purchase_date = $grouped['last_purchase_ts'] > 0
            ? wp_date( 'c', $grouped['last_purchase_ts'] )
            : null;

        return rest_ensure_response(
            array(
                'upcoming_count'     => $upcoming,
                'past_count'         => $past,
                'last_purchase_date' => $last_purchase_date,
            )
        );
    }

    /**
     * Sanitize scope value.
     *
     * @param mixed $value Raw scope input.
     */
    public function sanitize_scope( $value ): string {
        $allowed = array( 'upcoming', 'past', 'all' );
        $value   = is_string( $value ) ? strtolower( $value ) : '';

        return in_array( $value, $allowed, true ) ? $value : 'upcoming';
    }

    /**
     * Sanitize grouping option.
     *
     * @param mixed $value Raw grouping input.
     */
    public function sanitize_group_by( $value ): string {
        $allowed = array( 'event', 'none' );
        $value   = is_string( $value ) ? strtolower( $value ) : '';

        return in_array( $value, $allowed, true ) ? $value : 'none';
    }

    /**
     * Get allowed Woo order statuses for ticket queries.
     *
     * @return string[]
     */
    private function get_allowed_statuses(): array {
        return array( 'completed', 'processing', 'refunded' );
    }

    /**
     * Resolve event ID from an order item.
     *
     * @param mixed $item Order item.
     */
    private function get_item_event_id( $item ): int {
        if ( ! $item || ! method_exists( $item, 'get_meta' ) ) {
            return 0;
        }

        $event_id = $item->get_meta( '_oras_ticket_event_id', true );
        if ( '' === $event_id || (int) $event_id <= 0 ) {
            $product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
            if ( $product_id > 0 ) {
                $event_id = get_post_meta( $product_id, '_oras_ticket_event_id', true );
            }
        }

        return (int) $event_id;
    }

    /**
     * Build grouped ticket records from order list.
     *
     * @param \WC_Order[] $orders Order list.
     * @param string      $scope Scope filter.
     * @param int         $now   Current timestamp.
     * @return array{items:array<int,array<string,mixed>>,last_purchase_ts:int}
     */
    private function get_ticket_groups( array $orders, string $scope, int $now ): array {
        $groups           = array();
        $last_purchase_ts = 0;

        foreach ( $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) {
                continue;
            }

            $order_id = (int) $order->get_id();
            if ( $order_id <= 0 ) {
                continue;
            }

            $order_date     = $order->get_date_created();
            $order_date_iso = $order_date ? $order_date->date( 'c' ) : null;
            $order_date_ts  = $order_date ? (int) $order_date->getTimestamp() : 0;

            $items = $order->get_items( 'line_item' );
            foreach ( $items as $item ) {
                $event_id = $this->get_item_event_id( $item );
                if ( $event_id <= 0 ) {
                    continue;
                }

                $event_start = $this->get_event_start( $event_id );
                $event_ts    = null !== $event_start ? strtotime( $event_start ) : 0;
                if ( ! $this->matches_scope( $scope, $event_ts, $event_start, $now ) ) {
                    continue;
                }

                if ( $order_date_ts > 0 && $order_date_ts > $last_purchase_ts ) {
                    $last_purchase_ts = $order_date_ts;
                }

                $key = $order_id . ':' . $event_id;
                if ( ! isset( $groups[ $key ] ) ) {
                    $groups[ $key ] = array(
                        'event_id'       => $event_id,
                        'event_title'    => (string) get_the_title( $event_id ),
                        'event_start'    => $event_start,
                        'event_url'      => (string) get_permalink( $event_id ),
                        'order_id'       => $order_id,
                        'order_status'   => (string) $order->get_status(),
                        'order_date'     => $order_date_iso,
                        'order_total'    => (string) $order->get_total(),
                        'qty'            => 0,
                        'order_view_url' => $this->get_order_view_url( $order_id ),
                    );
                }

                $qty                    = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0;
                $groups[ $key ]['qty'] += max( 0, $qty );
            }
        }

        return array(
            'items'            => array_values( $groups ),
            'last_purchase_ts' => $last_purchase_ts,
        );
    }

    /**
     * Group flattened items by event bucket.
     *
     * @param array<int,array<string,mixed>> $items Item rows.
     * @param int                            $now   Current timestamp.
     * @return array{upcoming:array<int,array<string,mixed>>,past:array<int,array<string,mixed>>}
     */
    private function group_items_by_event( array $items, int $now ): array {
        $events = array();

        foreach ( $items as $item ) {
            $event_id = isset( $item['event_id'] ) ? (int) $item['event_id'] : 0;
            if ( $event_id <= 0 ) {
                continue;
            }

            if ( ! isset( $events[ $event_id ] ) ) {
                $events[ $event_id ] = array(
                    'event_id'    => $event_id,
                    'event_title' => isset( $item['event_title'] ) ? (string) $item['event_title'] : '',
                    'event_start' => $item['event_start'] ?? null,
                    'event_url'   => isset( $item['event_url'] ) ? (string) $item['event_url'] : '',
                    'total_qty'   => 0,
                    'orders'      => array(),
                );
            }

            $events[ $event_id ]['total_qty'] += isset( $item['qty'] ) ? (int) $item['qty'] : 0;
            $events[ $event_id ]['orders'][]   = array(
                'order_id'       => isset( $item['order_id'] ) ? (int) $item['order_id'] : 0,
                'order_status'   => isset( $item['order_status'] ) ? (string) $item['order_status'] : '',
                'order_date'     => $item['order_date'] ?? null,
                'order_total'    => isset( $item['order_total'] ) ? (string) $item['order_total'] : '',
                'qty'            => isset( $item['qty'] ) ? (int) $item['qty'] : 0,
                'order_view_url' => isset( $item['order_view_url'] ) ? (string) $item['order_view_url'] : '',
            );
        }

        $upcoming = array();
        $past     = array();

        foreach ( $events as $event_id => $event ) {
            $bucket = $this->bucket_event( $event_id, $event['event_start'], $now );
            if ( 'past' === $bucket ) {
                $past[] = $event;
            } else {
                $upcoming[] = $event;
            }
        }

        return array(
            'upcoming' => $upcoming,
            'past'     => $past,
        );
    }

    /**
     * Resolve event start datetime.
     *
     * @param int $event_id Event ID.
     */
    private function get_event_start( int $event_id ): ?string {
        if ( $event_id <= 0 ) {
            return null;
        }

        if ( function_exists( 'tribe_get_start_date' ) ) {
            $start = tribe_get_start_date( $event_id, false, 'c' );
            if ( is_string( $start ) && '' !== $start ) {
                return $start;
            }
        }

        $raw = get_post_meta( $event_id, '_EventStartDateUTC', true );
        if ( '' === $raw ) {
            $raw = get_post_meta( $event_id, '_EventStartDate', true );
        }

        if ( ! is_string( $raw ) || '' === $raw ) {
            return null;
        }

        $timestamp = strtotime( $raw );
        if ( ! $timestamp ) {
            return null;
        }

        return wp_date( 'c', $timestamp );
    }

    /**
     * Check whether event matches the requested scope.
     *
     * @param string      $scope Scope value.
     * @param int         $event_ts Event start timestamp.
     * @param string|null $event_start Event start datetime.
     * @param int         $now Current timestamp.
     */
    private function matches_scope( string $scope, int $event_ts, ?string $event_start, int $now ): bool {
        if ( 'all' === $scope ) {
            return true;
        }

        if ( $event_ts <= 0 && null === $event_start ) {
            return 'upcoming' === $scope;
        }

        if ( 'upcoming' === $scope ) {
            return $event_ts >= $now;
        }

        return $event_ts < $now;
    }

    /**
     * Bucket event into upcoming/past.
     *
     * @param int         $event_id    Event ID.
     * @param string|null $event_start Event start datetime.
     * @param int         $now         Current timestamp.
     */
    private function bucket_event( int $event_id, $event_start, int $now ): string {
        if ( $event_id <= 0 ) {
            return 'upcoming';
        }

        $event_ts = is_string( $event_start ) && '' !== $event_start ? strtotime( $event_start ) : 0;
        if ( $event_ts <= 0 ) {
            return 'upcoming';
        }

        return $event_ts < $now ? 'past' : 'upcoming';
    }

    /**
     * Fetch paged orders for user.
     *
     * @param int $user_id  User ID.
     * @param int $page     Page number.
     * @param int $per_page Page size.
     * @return array{orders:\WC_Order[],total:int,max_pages:int}
     */
    private function get_paged_orders( int $user_id, int $page, int $per_page ): array {
        $args      = $this->get_order_args( $user_id, $page, $per_page, true );
        $results   = wc_get_orders( $args );
        $orders    = array();
        $total     = 0;
        $max_pages = 0;

        if ( is_object( $results ) && isset( $results->orders ) ) {
            $orders    = is_array( $results->orders ) ? $results->orders : array();
            $total     = isset( $results->total ) ? (int) $results->total : 0;
            $max_pages = isset( $results->max_num_pages ) ? (int) $results->max_num_pages : 0;
        } elseif ( is_array( $results ) ) {
            $orders    = $results;
            $total     = count( $orders );
            $max_pages = (int) ceil( $total / max( 1, $per_page ) );
        }

        return array(
            'orders'    => $orders,
            'total'     => $total,
            'max_pages' => $max_pages,
        );
    }

    /**
     * Fetch all orders for user.
     *
     * @param int $user_id User ID.
     * @return \WC_Order[]
     */
    private function get_all_orders( int $user_id ): array {
        $orders   = array();
        $page     = 1;
        $per_page = 100;

        do {
            $args        = $this->get_order_args( $user_id, $page, $per_page, false );
            $batch       = wc_get_orders( $args );
            $batch_count = is_array( $batch ) ? count( $batch ) : 0;
            if ( empty( $batch ) ) {
                break;
            }

            foreach ( $batch as $order ) {
                if ( $order instanceof \WC_Order ) {
                    $orders[] = $order;
                }
            }

            ++$page;
        } while ( $batch_count === $per_page );

        return $orders;
    }

    /**
     * Build wc_get_orders arguments.
     *
     * @param int  $user_id  User ID.
     * @param int  $page     Page number.
     * @param int  $per_page Page size.
     * @param bool $paginate Whether to paginate.
     * @return array<string,mixed>
     */
    private function get_order_args( int $user_id, int $page, int $per_page, bool $paginate ): array {
        return array(
            'customer_id' => $user_id,
            'limit'       => $per_page,
            'page'        => $page,
            'status'      => $this->get_allowed_statuses(),
            'orderby'     => 'date',
            'order'       => 'DESC',
            'paginate'    => $paginate,
        );
    }

    /**
     * Resolve My Account order-view URL.
     *
     * @param int $order_id Order ID.
     */
    private function get_order_view_url( int $order_id ): string {
        if ( $order_id <= 0 || ! function_exists( 'wc_get_endpoint_url' ) ) {
            return '';
        }

        $base = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
        if ( '' === $base ) {
            $myaccount_id = (int) get_option( 'woocommerce_myaccount_page_id' );
            $base         = $myaccount_id > 0 ? (string) get_permalink( $myaccount_id ) : '';
        }

        if ( '' === $base ) {
            return '';
        }

        return (string) wc_get_endpoint_url( 'view-order', (string) $order_id, $base );
    }
}
