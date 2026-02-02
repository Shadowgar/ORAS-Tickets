<?php

namespace ORAS\Tickets\Admin;

if (! defined('ABSPATH')) {
  exit;
}

final class Reports_Aggregator
{

  private const CACHE_TTL = 600;

  /**
   * @param int $event_id
   * @param string[] $statuses
   * @param array{after?:string,before?:string} $date_range
   * @return array{summary:array,by_ticket:array}
   */
  public function get_aggregates(int $event_id, array $statuses, array $date_range = []): array
  {
    $statuses = $this->normalize_statuses($statuses);
    $cache_key = $this->get_cache_key($event_id, $statuses, $date_range);

    $cached = get_transient($cache_key);
    if (is_array($cached)) {
      return $cached;
    }

    $summary = [
      'gross_sales'     => 0.0,
      'refunded_mapped_total' => 0.0,
      'refunded_amount' => 0.0,
      'net_sales'       => 0.0,
      'orders_count'    => 0,
      'tickets_sold'    => 0,
      'refunded_qty'    => 0,
      'unattributed_refunds_amount' => 0.0,
      'unattributed_refunds_count' => 0,
      'adjustments_detected' => false,
    ];

    $by_ticket = [];

    $refund_statuses = array_unique(array_merge($statuses, ['cancelled', 'refunded']));

    $this->iterate_orders(
      $event_id,
      $refund_statuses,
      $date_range,
      function ($order) use (&$summary, &$by_ticket, $event_id, $statuses): void {
        if (! $order || ! method_exists($order, 'get_refunds')) {
          return;
        }

        $order_status = (string) $order->get_status();
        $status_allowed = in_array($order_status, $statuses, true);

        $has_event_items = false;
        $order_oras_gross = 0.0;
        $order_oras_qty = 0;
        $order_by_ticket = [];

        $order_items = method_exists($order, 'get_items') ? $order->get_items('line_item') : [];
        foreach ($order_items as $item) {
          $context = $this->get_item_ticket_context($item);
          if ((int) $context['event_id'] === (int) $event_id) {
            $has_event_items = true;
            $qty = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 0;
            if ($qty <= 0) {
              $summary['adjustments_detected'] = true;
              continue;
            }

            $line_total = method_exists($item, 'get_total') ? (float) $item->get_total() : 0.0;
            $order_oras_gross += $line_total;
            $order_oras_qty += $qty;

            $ticket_key = $context['ticket_index'] !== '' ? (string) $context['ticket_index'] : (string) $context['ticket_name'];
            if (! isset($order_by_ticket[$ticket_key])) {
              $order_by_ticket[$ticket_key] = [
                'ticket_name' => (string) $context['ticket_name'],
                'ticket_index' => (string) $context['ticket_index'],
                'sold_qty' => 0,
                'gross' => 0.0,
              ];
            }
            $order_by_ticket[$ticket_key]['sold_qty'] += $qty;
            $order_by_ticket[$ticket_key]['gross'] += $line_total;
          }
        }

        if (! $has_event_items) {
          return;
        }

        $mapped_abs_sum = 0.0;
        $mapped_qty_sum = 0;
        $unattributed_for_order = 0.0;

        foreach ($order->get_refunds() as $refund) {
          if (! $refund) {
            continue;
          }

          $refund_total = abs((float) $refund->get_total());
          $refund_items = method_exists($refund, 'get_items') ? $refund->get_items('line_item') : [];
          if (empty($refund_items)) {
            $unattributed_for_order += $refund_total;
            $summary['unattributed_refunds_count']++;
            continue;
          }

          $mapped_this_refund = 0.0;

          foreach ($refund_items as $ref_item) {
            $orig_id = (int) $ref_item->get_meta('_refunded_item_id');
            if ($orig_id <= 0) {
              continue;
            }

            $orig_item = $order->get_item($orig_id);
            if (! $orig_item) {
              continue;
            }

            $context = $this->get_item_ticket_context($orig_item);
            if ((int) $context['event_id'] !== (int) $event_id) {
              continue;
            }

            $ticket_key = $context['ticket_index'] !== '' ? (string) $context['ticket_index'] : (string) $context['ticket_name'];
            if (! isset($by_ticket[$ticket_key])) {
              $by_ticket[$ticket_key] = [
                'ticket_name'     => (string) $context['ticket_name'],
                'ticket_index'    => (string) $context['ticket_index'],
                'sold_qty'        => 0,
                'gross'           => 0.0,
                'refunded_qty'    => 0,
                'refunded_amount' => 0.0,
                'net'             => 0.0,
              ];
            }

            $ref_qty = abs((int) $ref_item->get_quantity());
            $ref_total = abs((float) $ref_item->get_total());

            $by_ticket[$ticket_key]['refunded_qty'] += $ref_qty;
            $by_ticket[$ticket_key]['refunded_amount'] += $ref_total;
            $mapped_this_refund += $ref_total;
            $mapped_qty_sum += $ref_qty;
          }

          $other_abs_items = 0.0;
          $other_types = ['shipping', 'fee', 'tax'];
          foreach ($other_types as $type) {
            $other_items = method_exists($refund, 'get_items') ? $refund->get_items($type) : [];
            foreach ($other_items as $other_item) {
              if (! $other_item || ! method_exists($other_item, 'get_total')) {
                continue;
              }
              $other_abs_items += abs((float) $other_item->get_total());
            }
          }

          $remaining = $refund_total - $mapped_this_refund - $other_abs_items;
          if ($remaining > 0) {
            $unattributed_for_order += $remaining;
          }

          if ($other_abs_items > 0) {
            $unattributed_for_order += $other_abs_items;
          }

          $mapped_abs_sum += $mapped_this_refund;
        }

        if ($mapped_abs_sum > 0) {
          $summary['refunded_mapped_total'] += $mapped_abs_sum;
          $summary['refunded_qty'] += $mapped_qty_sum;
        }

        if ($unattributed_for_order > 0) {
          $summary['unattributed_refunds_amount'] += $unattributed_for_order;
        }

        $fully_refunded = $order_oras_gross > 0 && ($mapped_abs_sum + $unattributed_for_order) >= $order_oras_gross;

        if ($status_allowed && in_array($order_status, ['processing', 'completed'], true) && ! $fully_refunded) {
          $summary['gross_sales'] += $order_oras_gross;
          $summary['tickets_sold'] += $order_oras_qty;
          $summary['orders_count']++;

          foreach ($order_by_ticket as $ticket_key => $data) {
            if (! isset($by_ticket[$ticket_key])) {
              $by_ticket[$ticket_key] = [
                'ticket_name'     => (string) $data['ticket_name'],
                'ticket_index'    => (string) $data['ticket_index'],
                'sold_qty'        => 0,
                'gross'           => 0.0,
                'refunded_qty'    => 0,
                'refunded_amount' => 0.0,
                'net'             => 0.0,
              ];
            }
            $by_ticket[$ticket_key]['sold_qty'] += $data['sold_qty'];
            $by_ticket[$ticket_key]['gross'] += $data['gross'];
          }
        }
      }
    );

    $summary['refunded_amount'] = $summary['refunded_mapped_total'] + $summary['unattributed_refunds_amount'];
    $summary['net_sales'] = $summary['gross_sales'] - $summary['refunded_amount'];

    foreach ($by_ticket as $ticket_key => $data) {
      $by_ticket[$ticket_key]['net'] = $by_ticket[$ticket_key]['gross'] - $by_ticket[$ticket_key]['refunded_amount'];
    }

    $rows = array_values($by_ticket);
    usort(
      $rows,
      static function (array $a, array $b): int {
        return strcmp($a['ticket_name'], $b['ticket_name']);
      }
    );

    if ($summary['unattributed_refunds_amount'] > 0) {
      $rows[] = [
        'ticket_name' => __('Unattributed refunds', 'oras-tickets'),
        'ticket_index' => '',
        'sold_qty' => 0,
        'gross' => 0.0,
        'refunded_qty' => 0,
        'refunded_amount' => $summary['unattributed_refunds_amount'],
        'net' => 0.0 - $summary['unattributed_refunds_amount'],
      ];
    }

    $result = [
      'summary' => $summary,
      'by_ticket' => $rows,
    ];

    set_transient($cache_key, $result, self::CACHE_TTL);

    return $result;
  }

  /**
   * @param int $event_id
   * @param string[] $statuses
   * @param array{after?:string,before?:string} $date_range
   * @param callable $callback
   */
  public function iterate_order_items(int $event_id, array $statuses, array $date_range, callable $callback): void
  {
    if (! function_exists('wc_get_orders')) {
      return;
    }

    $statuses = $this->normalize_statuses($statuses);
    $page = 1;
    $per_page = 50;

    do {
      $args = [
        'limit' => $per_page,
        'page' => $page,
        'status' => $statuses,
        'orderby' => 'date',
        'order' => 'DESC',
      ];

      if (! empty($date_range['after'])) {
        $args['date_created'] = $args['date_created'] ?? [];
        $args['date_created']['after'] = $date_range['after'];
      }
      if (! empty($date_range['before'])) {
        $args['date_created'] = $args['date_created'] ?? [];
        $args['date_created']['before'] = $date_range['before'];
      }

      $orders = wc_get_orders($args);
      if (empty($orders)) {
        break;
      }

      foreach ($orders as $order) {
        if (! $order || ! method_exists($order, 'get_items')) {
          continue;
        }

        $order_id = (int) $order->get_id();
        $order_date = $order->get_date_created();
        $order_date_str = $order_date ? $order_date->date('Y-m-d H:i:s') : '';
        $order_status = (string) $order->get_status();

        $items = $order->get_items('line_item');
        foreach ($items as $item) {
          if (! $item) {
            continue;
          }

          $context = $this->get_item_ticket_context($item);
          if ((int) $context['event_id'] !== (int) $event_id) {
            continue;
          }

          $ticket_name = $context['ticket_name'];
          $ticket_index = $context['ticket_index'];
          $qty = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 0;
          $unit_price = (string) $item->get_meta('_oras_ticket_unit_price', true);
          $line_total = method_exists($item, 'get_total') ? (string) $item->get_total() : '';
          $currency = (string) $item->get_meta('_oras_ticket_currency', true);

          $callback(
            [
              'order_id' => $order_id,
              'order_date' => $order_date_str,
              'order_status' => $order_status,
              'ticket_name' => $ticket_name,
              'ticket_index' => $ticket_index,
              'qty' => $qty,
              'unit_price' => $unit_price,
              'line_total' => $line_total,
              'currency' => $currency,
            ]
          );
        }
      }

      $page++;
    } while (! empty($orders) && count($orders) === $per_page);
  }

  /**
   * @param int $event_id
   * @param string[] $statuses
   * @param array{after?:string,before?:string} $date_range
   * @param callable $callback
   */
  private function iterate_orders(int $event_id, array $statuses, array $date_range, callable $callback): void
  {
    if (! function_exists('wc_get_orders')) {
      return;
    }

    $statuses = $this->normalize_statuses($statuses);
    $page = 1;
    $per_page = 50;

    do {
      $args = [
        'limit' => $per_page,
        'page' => $page,
        'status' => $statuses,
        'orderby' => 'date',
        'order' => 'DESC',
      ];

      if (! empty($date_range['after'])) {
        $args['date_created'] = $args['date_created'] ?? [];
        $args['date_created']['after'] = $date_range['after'];
      }
      if (! empty($date_range['before'])) {
        $args['date_created'] = $args['date_created'] ?? [];
        $args['date_created']['before'] = $date_range['before'];
      }

      $orders = wc_get_orders($args);
      if (empty($orders)) {
        break;
      }

      foreach ($orders as $order) {
        $callback($order);
      }

      $page++;
    } while (! empty($orders) && count($orders) === $per_page);
  }

  /**
   * @param \WC_Order_Item_Product|\WC_Order_Item $item
   * @return array{event_id:int,ticket_index:string,ticket_name:string}
   */
  private function get_item_ticket_context($item): array
  {
    $event_id = $item->get_meta('_oras_ticket_event_id', true);
    $item_index = $item->get_meta('_oras_ticket_index', true);

    if ($event_id === '' || $event_id === null || (int) $event_id <= 0) {
      $product_id = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
      if ($product_id > 0) {
        $event_id = get_post_meta($product_id, '_oras_ticket_event_id', true);
        $item_index = get_post_meta($product_id, '_oras_ticket_index', true);
      }
    }

    $ticket_name = (string) $item->get_meta('_oras_ticket_name', true);
    if ($ticket_name === '') {
      $ticket_name = method_exists($item, 'get_name') ? (string) $item->get_name() : '';
    }

    return [
      'event_id' => (int) $event_id,
      'ticket_index' => $item_index !== '' ? (string) $item_index : '',
      'ticket_name' => $ticket_name,
    ];
  }

  /**
   * @param string[] $statuses
   * @return string[]
   */
  private function normalize_statuses(array $statuses): array
  {
    $allowed = ['processing', 'completed', 'refunded', 'cancelled'];
    $clean = [];

    foreach ($statuses as $status) {
      $status = (string) $status;
      if (in_array($status, $allowed, true)) {
        $clean[] = $status;
      }
    }

    return ! empty($clean) ? array_values(array_unique($clean)) : $allowed;
  }

  /**
   * @param int $event_id
   * @param string[] $statuses
   * @param array{after?:string,before?:string} $date_range
   */
  private function get_cache_key(int $event_id, array $statuses, array $date_range): string
  {
    $key = $event_id . '|' . implode(',', $statuses) . '|' . ($date_range['after'] ?? '') . '|' . ($date_range['before'] ?? '');
    return 'oras_tickets_reports_' . md5($key);
  }
}
