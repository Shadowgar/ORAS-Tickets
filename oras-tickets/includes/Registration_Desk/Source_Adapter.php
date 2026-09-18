<?php

namespace ORAS\Tickets\Registration_Desk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Source_Adapter {
	/** @return array<string,mixed>|\WP_Error */
	public function load( int $order_id, int $order_item_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new \WP_Error( 'oras_desk_source_unavailable', 'WooCommerce order access is unavailable.', array( 'status' => 503 ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'oras_desk_source_missing', 'Website order could not be found.', array( 'status' => 404 ) );
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( (int) $item->get_id() === $order_item_id ) {
				return $this->evidence( $order, $item );
			}
		}

		return new \WP_Error( 'oras_desk_source_item_missing', 'Website registration item could not be found.', array( 'status' => 404 ) );
	}

	/** @return array{count:int,highest_id:int}|\WP_Error */
	public function snapshot() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'oras_desk_source_unavailable', 'WooCommerce order access is unavailable.', array( 'status' => 503 ) );
		}
		$result = wc_get_orders(
			array(
				'status'   => $this->statuses(),
				'limit'    => 1,
				'page'     => 1,
				'paginate' => true,
				'orderby'  => 'ID',
				'order'    => 'DESC',
				'return'   => 'ids',
			)
		);
		if ( ! is_object( $result ) || ! isset( $result->orders, $result->total ) ) {
			return new \WP_Error( 'oras_desk_source_query_failed', 'Website order coverage could not be read.', array( 'status' => 503 ) );
		}
		$ids = is_array( $result->orders ) ? $result->orders : array();

		return array(
			'count'      => (int) $result->total,
			'highest_id' => empty( $ids ) ? 0 : (int) reset( $ids ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function page_for_event( int $event_id, int $page = 1, int $limit = 50, array $config = array() ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'oras_desk_source_unavailable', 'WooCommerce order access is unavailable.', array( 'status' => 503 ) );
		}
		$result = wc_get_orders(
			array(
				'status'   => $this->statuses(),
				'limit'    => max( 1, min( 100, $limit ) ),
				'page'     => max( 1, $page ),
				'paginate' => true,
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'return'   => 'objects',
			)
		);
		if ( ! is_object( $result ) || ! isset( $result->orders, $result->total, $result->max_num_pages ) ) {
			return new \WP_Error( 'oras_desk_source_query_failed', 'Website orders could not be read.', array( 'status' => 503 ) );
		}
		$evidence = array();
		$orders   = is_array( $result->orders ) ? $result->orders : array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$item_evidence = $this->evidence( $order, $item );
				if ( Source_Resolver::matches_configured_source( $item_evidence, $event_id, $config ) ) {
					$evidence[] = $item_evidence;
				}
			}
		}

		return array(
			'page'           => max( 1, $page ),
			'scanned_orders' => count( $orders ),
			'matching_items' => count( $evidence ),
			'source_orders'  => (int) $result->total,
			'total_pages'    => (int) $result->max_num_pages,
			'has_more'       => max( 1, $page ) < (int) $result->max_num_pages,
			'items'          => $evidence,
		);
	}

	/** @return array<int,string> */
	private function statuses(): array {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();

		return ! empty( $statuses ) ? $statuses : array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' );
	}

	/** @return array<string,mixed> */
	private function evidence( \WC_Order $order, $item ): array {
		$item_id  = (int) $item->get_id();
		$quantity = max( 1, (int) $item->get_quantity() );
		$refunded = method_exists( $order, 'get_qty_refunded_for_item' ) ? abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) : 0;

		return array(
			'order_id'          => (int) $order->get_id(),
			'order_item_id'     => $item_id,
			'product_id'        => (int) $item->get_product_id(),
			'source_event_id'   => (int) $item->get_meta( '_oras_ticket_event_id', true ),
			'quantity'          => $quantity,
			'refunded_quantity' => $refunded,
			'order_status'      => (string) $order->get_status(),
			'contact_name'      => trim( (string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name() ),
			'email'             => (string) $order->get_billing_email(),
			'phone'             => (string) $order->get_billing_phone(),
			'ticket_index'      => (string) $item->get_meta( '_oras_ticket_index', true ),
			'item_label'        => (string) $item->get_meta( '_oras_ticket_name', true ),
		);
	}
}
