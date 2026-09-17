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

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function page_for_event( int $event_id, int $page = 1, int $limit = 50 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'oras_desk_source_unavailable', 'WooCommerce order access is unavailable.', array( 'status' => 503 ) );
		}
		$orders   = wc_get_orders(
			array(
				'status'  => array( 'processing', 'completed', 'on-hold', 'pending', 'failed', 'cancelled', 'refunded' ),
				'limit'   => max( 1, min( 100, $limit ) ),
				'page'    => max( 1, $page ),
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		$evidence = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( (int) $item->get_meta( '_oras_ticket_event_id', true ) === $event_id ) {
					$evidence[] = $this->evidence( $order, $item );
				}
			}
		}

		return $evidence;
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
