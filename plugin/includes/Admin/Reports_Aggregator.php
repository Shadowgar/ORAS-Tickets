<?php

namespace ORAS\Tickets\Admin;

require_once ORAS_TICKETS_DIR . 'includes/Domain/Ticket_Collection.php';

use ORAS\Tickets\Domain\Ticket_Collection;

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
   * @return array{summary:array,by_ticket:array,phase_breakdown:array}
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
    $phase_breakdown = [];

    // Presale is defined as the earliest configured phase key by start datetime per ticket.
    $presale_key_by_ticket_index = $this->get_presale_key_map($event_id);

    $refund_statuses = array_unique(array_merge($statuses, ['cancelled', 'refunded']));

    $this->iterate_orders(
      $event_id,
      $refund_statuses,
      $date_range,
      function ($order) use (&$summary, &$by_ticket, &$phase_breakdown, $event_id, $statuses, $presale_key_by_ticket_index): void {
        if (! $order || ! method_exists($order, 'get_refunds')) {
          return;
        }

        $order_status = (string) $order->get_status();
        $status_allowed = in_array($order_status, $statuses, true);

        $has_event_items = false;
        $order_oras_gross = 0.0;
        $order_oras_qty = 0;
        $order_by_ticket = [];
        $order_by_phase = [];

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

            $ti = (string) $context['ticket_index'];
            if ($ti === '') {
              $ti = (string) $context['ticket_name'];
            }
            // Normalize ticket index key so presale and phase breakdown stay aligned.
            if (! isset($order_by_ticket[$ti])) {
              $order_by_ticket[$ti] = [
                'ticket_name' => (string) $context['ticket_name'],
                'ticket_index' => (string) $context['ticket_index'],
                'sold_qty' => 0,
                'gross' => 0.0,
                'presale_qty' => 0,
                'after_qty' => 0,
              ];
            }
            $order_by_ticket[$ti]['sold_qty'] += $qty;
            $order_by_ticket[$ti]['gross'] += $line_total;

            $phase_snapshot = $this->get_phase_snapshot($item);
            $phase_key = $phase_snapshot['key'];
            $phase_label = $phase_snapshot['label'];

            if (! isset($order_by_phase[$ti])) {
              $order_by_phase[$ti] = [];
            }
            if (! isset($order_by_phase[$ti][$phase_key])) {
              $order_by_phase[$ti][$phase_key] = [
                'label' => $phase_label,
                'qty' => 0,
                'gross' => 0.0,
                'refunded_qty' => 0,
                'refunded_amount' => 0.0,
                'net_qty' => 0,
                'net' => 0.0,
              ];
            }
            $order_by_phase[$ti][$phase_key]['qty'] += $qty;
            $order_by_phase[$ti][$phase_key]['gross'] += $line_total;

            $presale_key = $presale_key_by_ticket_index[$ti] ?? null;
            if ($presale_key !== null && $phase_key === $presale_key) {
              $order_by_ticket[$ti]['presale_qty'] += $qty;
            } else {
              $order_by_ticket[$ti]['after_qty'] += $qty;
            }
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

            $ti = (string) $context['ticket_index'];
            if ($ti === '') {
              $ti = (string) $context['ticket_name'];
            }
            if (! isset($by_ticket[$ti])) {
              $by_ticket[$ti] = [
                'ticket_name'     => (string) $context['ticket_name'],
                'ticket_index'    => (string) $context['ticket_index'],
                'sold_qty'        => 0,
                'gross'           => 0.0,
                'refunded_qty'    => 0,
                'refunded_amount' => 0.0,
                'presale_qty'     => 0,
                'after_qty'       => 0,
                'net'             => 0.0,
              ];
            }

            $ref_qty = abs((int) $ref_item->get_quantity());
            $ref_total = abs((float) $ref_item->get_total());

            $by_ticket[$ti]['refunded_qty'] += $ref_qty;
            $by_ticket[$ti]['refunded_amount'] += $ref_total;
            $mapped_this_refund += $ref_total;
            $mapped_qty_sum += $ref_qty;

            $phase_snapshot = $this->get_phase_snapshot($orig_item);
            $phase_key = $phase_snapshot['key'];
            $phase_label = $phase_snapshot['label'];
            if (! isset($phase_breakdown[$ti])) {
              $phase_breakdown[$ti] = [];
            }
            if (! isset($phase_breakdown[$ti][$phase_key])) {
              $phase_breakdown[$ti][$phase_key] = [
                'label' => $phase_label,
                'qty' => 0,
                'gross' => 0.0,
                'refunded_qty' => 0,
                'refunded_amount' => 0.0,
                'net_qty' => 0,
                'net' => 0.0,
              ];
            }
            $phase_breakdown[$ti][$phase_key]['refunded_qty'] += $ref_qty;
            $phase_breakdown[$ti][$phase_key]['refunded_amount'] += $ref_total;
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
                'presale_qty'     => 0,
                'after_qty'       => 0,
                'net'             => 0.0,
              ];
            }
            $by_ticket[$ticket_key]['sold_qty'] += $data['sold_qty'];
            $by_ticket[$ticket_key]['gross'] += $data['gross'];
            $by_ticket[$ticket_key]['presale_qty'] += $data['presale_qty'];
            $by_ticket[$ticket_key]['after_qty'] += $data['after_qty'];
          }

          foreach ($order_by_phase as $phase_ticket_key => $phase_rows) {
            if (! isset($phase_breakdown[$phase_ticket_key])) {
              $phase_breakdown[$phase_ticket_key] = [];
            }
            foreach ($phase_rows as $phase_key => $phase_row) {
              if (! isset($phase_breakdown[$phase_ticket_key][$phase_key])) {
                $phase_breakdown[$phase_ticket_key][$phase_key] = [
                  'label' => $phase_row['label'],
                  'qty' => 0,
                  'gross' => 0.0,
                  'refunded_qty' => 0,
                  'refunded_amount' => 0.0,
                  'net_qty' => 0,
                  'net' => 0.0,
                ];
              }
              $phase_breakdown[$phase_ticket_key][$phase_key]['qty'] += $phase_row['qty'];
              $phase_breakdown[$phase_ticket_key][$phase_key]['gross'] += $phase_row['gross'];
            }
          }
        }
      }
    );

    $summary['refunded_amount'] = $summary['refunded_mapped_total'] + $summary['unattributed_refunds_amount'];
    $summary['net_sales'] = $summary['gross_sales'] - $summary['refunded_amount'];

    foreach ($by_ticket as $ticket_key => $data) {
      $by_ticket[$ticket_key]['net'] = $by_ticket[$ticket_key]['gross'] - $by_ticket[$ticket_key]['refunded_amount'];
      $by_ticket[$ticket_key]['presale_qty'] = (int) ($by_ticket[$ticket_key]['presale_qty'] ?? 0);
      $by_ticket[$ticket_key]['after_qty'] = (int) ($by_ticket[$ticket_key]['after_qty'] ?? 0);
    }

    foreach ($phase_breakdown as $ticket_key => $phases) {
      foreach ($phases as $phase_key => $phase_row) {
        $qty = isset($phase_row['qty']) ? (int) $phase_row['qty'] : 0;
        $ref_qty = isset($phase_row['refunded_qty']) ? (int) $phase_row['refunded_qty'] : 0;
        $gross = isset($phase_row['gross']) ? (float) $phase_row['gross'] : 0.0;
        $ref_amount = isset($phase_row['refunded_amount']) ? (float) $phase_row['refunded_amount'] : 0.0;

        $phase_breakdown[$ticket_key][$phase_key]['net_qty'] = max(0, $qty - $ref_qty);
        $phase_breakdown[$ticket_key][$phase_key]['net'] = $gross - $ref_amount;
      }
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
        'presale_qty' => 0,
        'after_qty' => 0,
        'net' => 0.0 - $summary['unattributed_refunds_amount'],
      ];
    }

    $result = [
      'summary' => $summary,
      'by_ticket' => $rows,
      'phase_breakdown' => $phase_breakdown,
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

      $date_created = $this->build_date_created_arg($date_range);
      if ($date_created !== '') {
        $args['date_created'] = $date_created;
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
          $phase_snapshot = $this->get_phase_snapshot($item);

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
              'phase_key' => $phase_snapshot['key'],
              'phase_label' => $phase_snapshot['label'],
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

      $date_created = $this->build_date_created_arg($date_range);
      if ($date_created !== '') {
        $args['date_created'] = $date_created;
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
    $filters = [
      'event_id' => $event_id,
      'statuses' => $statuses,
      'date_range' => $date_range,
    ];

    return $this->build_cache_key($filters, 'single');
  }

  /**
   * @param array<string,mixed> $filters
   */
  private function build_cache_key(array $filters, string $scope): string
  {
    $sorted = $this->sort_filter_array($filters);
    $payload = wp_json_encode($sorted);
    return 'oras_tickets_reports_' . $scope . '_' . md5((string) $payload);
  }

  /**
   * @param array<string,mixed> $filters
   * @return array<string,mixed>
   */
  private function sort_filter_array(array $filters): array
  {
    foreach ($filters as $key => $value) {
      if (is_array($value)) {
        $filters[$key] = $this->sort_filter_array($value);
      }
    }

    ksort($filters);

    return $filters;
  }

  /**
   * @param array{after?:string,before?:string} $date_range
   */
  private function build_date_created_arg(array $date_range): string
  {
    $after = isset($date_range['after']) ? (string) $date_range['after'] : '';
    $before = isset($date_range['before']) ? (string) $date_range['before'] : '';

    if ($after !== '' && $before !== '') {
      return $after . '...' . $before;
    }

    if ($after !== '') {
      return '>=' . $after;
    }

    if ($before !== '') {
      return '<=' . $before;
    }

    return '';
  }

  /**
   * Presale is the earliest configured phase key by start datetime per ticket.
   *
   * @return array<string,string|null>
   */
  private function get_presale_key_map(int $event_id): array
  {
    $envelope = Ticket_Collection::load_envelope_for_event($event_id);
    $tickets = isset($envelope['tickets']) && is_array($envelope['tickets']) ? $envelope['tickets'] : [];
    $presale = [];

    foreach ($tickets as $index => $ticket) {
      if (! is_array($ticket)) {
        $presale[(string) $index] = null;
        continue;
      }

      $phases = isset($ticket['price_phases']) && is_array($ticket['price_phases']) ? $ticket['price_phases'] : [];
      $earliest_ts = null;
      $earliest_key = null;
      $first_key = null;

      foreach ($phases as $phase) {
        if (! is_array($phase)) {
          continue;
        }

        $phase_key = isset($phase['key']) ? (string) $phase['key'] : '';
        $start_raw = isset($phase['start']) ? (string) $phase['start'] : '';
        if ($phase_key === '') {
          continue;
        }

        if ($first_key === null) {
          $first_key = $phase_key;
        }

        $start_ts = $this->phase_start_to_timestamp($start_raw);
        if ($start_ts === null) {
          continue;
        }
        if ($earliest_ts === null || $start_ts < $earliest_ts) {
          $earliest_ts = $start_ts;
          $earliest_key = $phase_key;
        }
      }

      if ($earliest_key !== null) {
        $presale[(string) $index] = $earliest_key;
      } elseif ($first_key !== null) {
        $presale[(string) $index] = $first_key;
      } else {
        $presale[(string) $index] = null;
      }
    }

    return $presale;
  }

  /**
   * '__none__' indicates no phase snapshot meta was present on the order item.
   *
   * @param \WC_Order_Item_Product|\WC_Order_Item $item
   * @return array{key:string,label:string}
   */
  private function get_phase_snapshot($item): array
  {
    $phase_key = $item->get_meta('_oras_ticket_price_phase_key', true);
    $phase_label = $item->get_meta('_oras_ticket_price_phase_label', true);

    $phase_key = is_string($phase_key) ? $phase_key : '';
    $phase_label = is_string($phase_label) ? $phase_label : '';

    if ($phase_key === '') {
      $phase_key = '__none__';
    }

    if ($phase_label === '') {
      $phase_label = 'No phase snapshot';
    }

    return [
      'key' => $phase_key,
      'label' => $phase_label,
    ];
  }

  private function phase_start_to_timestamp(string $value): ?int
  {
    $raw = trim($value);
    if ($raw === '') {
      return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
      $raw .= ' 00:00:00';
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $raw)) {
      $raw .= ':00';
    } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $raw)) {
      return null;
    }

    $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw, wp_timezone());
    if (! $dt instanceof \DateTimeImmutable) {
      return null;
    }

    return $dt->getTimestamp();
  }
}
