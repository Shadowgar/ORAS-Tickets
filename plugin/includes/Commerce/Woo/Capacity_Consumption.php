<?php

namespace ORAS\Tickets\Commerce\Woo;

use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;

if (! defined('ABSPATH')) {
  exit;
}

final class Capacity_Consumption
{

  public function register(): void
  {
    add_action('woocommerce_order_status_processing', [$this, 'handle_paid_order'], 10, 1);
    add_action('woocommerce_order_status_completed', [$this, 'handle_paid_order'], 10, 1);
    add_action('woocommerce_order_status_cancelled', [$this, 'handle_restore_order'], 10, 1);
    add_action('woocommerce_order_status_refunded', [$this, 'handle_restore_order'], 10, 1);
  }

  /**
   * Consume capacity for ORAS ticket line items when the order is paid.
   *
   * @param int $order_id
   */
  public function handle_paid_order(int $order_id): void
  {
    if (! function_exists('wc_get_order')) {
      return;
    }

    $order = wc_get_order($order_id);
    if (! $order) {
      return;
    }

    if ($order->get_meta('_oras_capacity_consumed', true)) {
      return;
    }

    $envelopes = [];
    $changed = [];

    $items = $order->get_items('line_item');
    foreach ($items as $item) {
      if (! $item || ! method_exists($item, 'get_product_id')) {
        continue;
      }

      $product_id = (int) $item->get_product_id();
      if ($product_id <= 0) {
        continue;
      }

      $event_id = 0;
      $index = -1;

      $event_id_raw = get_post_meta($product_id, '_oras_ticket_event_id', true);
      $index_raw = get_post_meta($product_id, '_oras_ticket_index', true);

      if ($event_id_raw !== '' && $index_raw !== '') {
        $event_id = (int) $event_id_raw;
        $index = (int) $index_raw;
      } else {
        $event_id_fallback = $item->get_meta('_oras_ticket_event_id', true);
        $index_fallback = $item->get_meta('_oras_ticket_index', true);
        if ($event_id_fallback !== '' && $index_fallback !== '') {
          $event_id = (int) $event_id_fallback;
          $index = (int) $index_fallback;
        }
      }

      if ($event_id <= 0 || $index < 0) {
        continue;
      }

      $quantity = method_exists($item, 'get_quantity') ? max(0, (int) $item->get_quantity()) : 0;
      if ($quantity <= 0) {
        continue;
      }

      if (! isset($envelopes[$event_id])) {
        $raw = get_post_meta($event_id, Meta::META_KEY_TICKETS, true);

        if (! is_array($raw)) {
          continue;
        }

        $schema = isset($raw['schema']) ? (int) $raw['schema'] : 1;
        if (1 !== $schema) {
          continue;
        }

        $tickets = isset($raw['tickets']) && is_array($raw['tickets']) ? $raw['tickets'] : [];

        $envelopes[$event_id] = [
          'schema'  => 1,
          'tickets' => $tickets,
        ];
      }

      if (! isset($envelopes[$event_id]['tickets']) || ! array_key_exists($index, $envelopes[$event_id]['tickets'])) {
        continue;
      }

      $ticket = $envelopes[$event_id]['tickets'][$index];
      if (! is_array($ticket)) {
        continue;
      }

      $capacity = isset($ticket['capacity']) ? absint($ticket['capacity']) : 0;
      if ($capacity <= 0) {
        continue;
      }

      $remaining = max(0, $capacity - $quantity);
      $envelopes[$event_id]['tickets'][$index]['capacity'] = $remaining;
      $changed[$event_id] = true;

      $this->sync_product_stock($product_id, $remaining);
    }

    foreach ($changed as $event_id => $_) {
      if (isset($envelopes[$event_id])) {
        Ticket_Collection::save_for_event((int) $event_id, $envelopes[$event_id]);
      }
    }

    $order->update_meta_data('_oras_capacity_consumed', 1);
    $order->save();
  }

  /**
   * Restore capacity for ORAS ticket line items when the order is cancelled/refunded.
   *
   * @param int $order_id
   */
  public function handle_restore_order(int $order_id): void
  {
    if (! function_exists('wc_get_order')) {
      return;
    }

    $order = wc_get_order($order_id);
    if (! $order) {
      return;
    }

    if (! $order->get_meta('_oras_capacity_consumed', true)) {
      return;
    }

    if ($order->get_meta('_oras_capacity_restored', true)) {
      return;
    }

    $envelopes = [];
    $changed = [];

    $items = $order->get_items('line_item');
    foreach ($items as $item) {
      if (! $item || ! method_exists($item, 'get_product_id')) {
        continue;
      }

      $product_id = (int) $item->get_product_id();
      if ($product_id <= 0) {
        continue;
      }

      $event_id = 0;
      $index = -1;

      $event_id_raw = get_post_meta($product_id, '_oras_ticket_event_id', true);
      $index_raw = get_post_meta($product_id, '_oras_ticket_index', true);

      if ($event_id_raw !== '' && $index_raw !== '') {
        $event_id = (int) $event_id_raw;
        $index = (int) $index_raw;
      } else {
        $event_id_fallback = $item->get_meta('_oras_ticket_event_id', true);
        $index_fallback = $item->get_meta('_oras_ticket_index', true);
        if ($event_id_fallback !== '' && $index_fallback !== '') {
          $event_id = (int) $event_id_fallback;
          $index = (int) $index_fallback;
        }
      }

      if ($event_id <= 0 || $index < 0) {
        continue;
      }

      $quantity = method_exists($item, 'get_quantity') ? max(0, (int) $item->get_quantity()) : 0;
      if ($quantity <= 0) {
        continue;
      }

      if (! isset($envelopes[$event_id])) {
        $raw = get_post_meta($event_id, Meta::META_KEY_TICKETS, true);

        if (! is_array($raw)) {
          continue;
        }

        $schema = isset($raw['schema']) ? (int) $raw['schema'] : 1;
        if (1 !== $schema) {
          continue;
        }

        $tickets = isset($raw['tickets']) && is_array($raw['tickets']) ? $raw['tickets'] : [];

        $envelopes[$event_id] = [
          'schema'  => 1,
          'tickets' => $tickets,
        ];
      }

      if (! isset($envelopes[$event_id]['tickets']) || ! array_key_exists($index, $envelopes[$event_id]['tickets'])) {
        continue;
      }

      $ticket = $envelopes[$event_id]['tickets'][$index];
      if (! is_array($ticket)) {
        continue;
      }

      $capacity = isset($ticket['capacity']) ? absint($ticket['capacity']) : 0;
      if ($capacity <= 0) {
        continue;
      }

      $restored = $capacity + $quantity;
      $envelopes[$event_id]['tickets'][$index]['capacity'] = $restored;
      $changed[$event_id] = true;

      $this->sync_product_stock($product_id, $restored);
    }

    foreach ($changed as $event_id => $_) {
      if (isset($envelopes[$event_id])) {
        Ticket_Collection::save_for_event((int) $event_id, $envelopes[$event_id]);
      }
    }

    $order->update_meta_data('_oras_capacity_restored', 1);
    $order->save();
  }

  private function sync_product_stock(int $product_id, int $remaining): void
  {
    if ($product_id <= 0 || ! function_exists('wc_get_product')) {
      return;
    }

    $product = wc_get_product($product_id);
    if (! $product) {
      return;
    }

    if (method_exists($product, 'set_manage_stock')) {
      $product->set_manage_stock(true);
    }
    if (method_exists($product, 'set_stock_quantity')) {
      $product->set_stock_quantity($remaining);
    }
    if (method_exists($product, 'set_stock_status')) {
      $product->set_stock_status($remaining > 0 ? 'instock' : 'outofstock');
    }
    if (method_exists($product, 'set_backorders')) {
      $product->set_backorders('no');
    }

    $product->save();
  }
}
