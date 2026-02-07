<?php

namespace ORAS\Tickets\Commerce\Woo;

use ORAS\Tickets\Domain\Pricing\Price_Resolver;
use ORAS\Tickets\Domain\Ticket_Collection;

if (! defined('ABSPATH')) {
  exit;
}

final class Cart_Pricing
{

  public static function register(): void
  {
    add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_cart_pricing'], 20, 1);
  }

  /**
   * Apply time-based pricing to cart items.
   *
   * @param \WC_Cart $cart
   */
  public static function apply_cart_pricing($cart): void
  {
    if (! $cart || ! method_exists($cart, 'get_cart')) {
      return;
    }

    if (is_admin() && ! (defined('DOING_AJAX') && DOING_AJAX)) {
      return;
    }

    foreach ($cart->get_cart() as $cart_item) {
      if (! isset($cart_item['data'])) {
        continue;
      }

      $product = $cart_item['data'];
      if (! $product || ! method_exists($product, 'get_meta')) {
        continue;
      }

      $event_id = (int) $product->get_meta('_oras_ticket_event_id', true);
      $index    = (int) $product->get_meta('_oras_ticket_index', true);
      if ($event_id <= 0 || $index < 0) {
        continue;
      }

      $collection = Ticket_Collection::load_for_event($event_id);
      $tickets    = $collection->all();
      if (! array_key_exists($index, $tickets)) {
        continue;
      }

      $ticket_obj  = $tickets[$index];
      $ticket_data = method_exists($ticket_obj, 'to_array') ? $ticket_obj->to_array() : (is_array($ticket_obj) ? $ticket_obj : []);
      if (empty($ticket_data)) {
        continue;
      }

      $resolved = Price_Resolver::resolve_ticket_price($ticket_data);
      if (empty($resolved['price']) || ! is_numeric($resolved['price'])) {
        continue;
      }

      $new_price = (float) $resolved['price'];
      $current   = method_exists($product, 'get_price') ? (float) $product->get_price() : $new_price;

      if (abs($new_price - $current) > 0.0001 && method_exists($product, 'set_price')) {
        $product->set_price($new_price);
      }
    }
  }
}
