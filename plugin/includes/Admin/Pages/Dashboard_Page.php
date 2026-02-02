<?php

namespace ORAS\Tickets\Admin\Pages;

use ORAS\Tickets\Domain\Meta;
use ORAS\Tickets\Domain\Ticket_Collection;

if (! defined('ABSPATH')) {
  exit;
}

final class Dashboard_Page
{

  public function render(): void
  {
    if (! current_user_can('manage_woocommerce')) {
      return;
    }

    $events = $this->get_events_with_tickets();

?>
    <div class="wrap">
      <h1><?php echo esc_html__('ORAS Tickets Dashboard', 'oras-tickets'); ?></h1>

      <table class="widefat striped">
        <thead>
          <tr>
            <th><?php echo esc_html__('Event', 'oras-tickets'); ?></th>
            <th><?php echo esc_html__('Ticket Count', 'oras-tickets'); ?></th>
            <th><?php echo esc_html__('Any Sold Out', 'oras-tickets'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($events)) : ?>
            <tr>
              <td colspan="3"><?php echo esc_html__('No events found.', 'oras-tickets'); ?></td>
            </tr>
          <?php else : ?>
            <?php foreach ($events as $event_id) :
              $title = get_the_title($event_id);
              $edit_link = get_edit_post_link($event_id);
              $envelope = Ticket_Collection::load_envelope_for_event($event_id);
              $tickets = isset($envelope['tickets']) && is_array($envelope['tickets']) ? $envelope['tickets'] : [];
              $count = count($tickets);
              $sold_out = $this->has_sold_out_limited_ticket($event_id);
            ?>
              <tr>
                <td>
                  <?php if ($edit_link) : ?>
                    <a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($title); ?></a>
                  <?php else : ?>
                    <?php echo esc_html($title); ?>
                  <?php endif; ?>
                </td>
                <td><?php echo esc_html((string) $count); ?></td>
                <td><?php echo esc_html($sold_out ? __('Yes', 'oras-tickets') : __('No', 'oras-tickets')); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
<?php
  }

  /**
   * @return int[]
   */
  private function get_events_with_tickets(): array
  {
    $query = get_posts(
      [
        'post_type' => Meta::EVENT_POST_TYPE,
        'post_status' => ['publish', 'draft', 'future', 'private'],
        'fields' => 'ids',
        'posts_per_page' => 100,
        'no_found_rows' => true,
        'meta_key' => Meta::META_KEY_TICKETS,
        'meta_compare' => 'EXISTS',
      ]
    );

    return is_array($query) ? $query : [];
  }

  private function has_sold_out_limited_ticket(int $event_id): bool
  {
    if (! function_exists('wc_get_product')) {
      return false;
    }

    $map = get_post_meta($event_id, '_oras_tickets_woo_map_v1', true);
    if (! is_array($map)) {
      return false;
    }

    foreach ($map as $product_id) {
      $product_id = absint($product_id);
      if ($product_id <= 0) {
        continue;
      }

      $product = wc_get_product($product_id);
      if (! $product) {
        continue;
      }

      $managing_stock = method_exists($product, 'managing_stock')
        ? (bool) $product->managing_stock()
        : (method_exists($product, 'get_manage_stock') ? (bool) $product->get_manage_stock() : false);

      if (! $managing_stock) {
        continue;
      }

      $stock_qty = method_exists($product, 'get_stock_quantity') ? (int) $product->get_stock_quantity() : null;
      $stock_status = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '';

      if ((null !== $stock_qty && $stock_qty <= 0) || $stock_status === 'outofstock') {
        return true;
      }
    }

    return false;
  }
}
